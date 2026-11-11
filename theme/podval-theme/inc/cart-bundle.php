<?php
/**
 * Bundle ↔ WooCommerce Cart integration.
 * ─────────────────────────────────────────────────────────────────────
 * Реализует:
 *  - AJAX endpoint podval_bundle_add_to_cart (через wp_loaded — bypass admin-ajax
 *    auth, как и остальной AJAX на сайте)
 *  - Server-side валидация payload от bundle-single.js (защита от подделки цен)
 *  - Server-side пересчёт цены по шкале скидок _bundle_discount_scale
 *  - Add to cart: первый shirt из состава = anchor product, _bundle_meta в
 *    cart_item_data, уникальный hash гарантирует что каждая конфигурация =
 *    отдельная позиция
 *  - Persistence через woocommerce_get_cart_item_from_session
 *  - Cart line item rendering: название «Бандл: …», обложка, состав,
 *    pricing override через woocommerce_before_calculate_totals
 *  - Sum весов для shipping через woocommerce_cart_contents_weight filter
 *  - Coupon exclusion: чекбокс «Не применять к бандлам» в WC coupon admin +
 *    filter woocommerce_coupon_is_valid_for_product
 *
 * Anchor стратегия: первый product из состава используется как WC product_id
 * для cart item. Если в дальнейшем этот product удалён — бандл в корзине
 * сломается; защита в hook before_delete_post (см. ниже).
 *
 * @package podval-print
 *
 */

defined( 'ABSPATH' ) || exit;

/* ════════════════════════════════════════════════════════════════════
   1. AJAX ENDPOINT — podval_bundle_add_to_cart
   ════════════════════════════════════════════════════════════════════ */

add_action( 'wp_loaded', function() {
	if ( ! isset( $_POST['action'] ) || $_POST['action'] !== 'podval_bundle_add_to_cart' ) {
		return;
	}

	// Nonce check
	$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'podval_bundle_add_to_cart' ) ) {
		wp_send_json_error( array( 'message' => 'Сессия истекла. Перезагрузите страницу.' ), 403 );
	}

	// WC available?
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		wp_send_json_error( array( 'message' => 'Корзина временно недоступна.' ), 500 );
	}

	// Parse payload
	$raw_payload = isset( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : '';
	$payload     = json_decode( $raw_payload, true );

	if ( ! is_array( $payload ) || empty( $payload['bundle_id'] ) || empty( $payload['shirts'] ) || ! is_array( $payload['shirts'] ) ) {
		wp_send_json_error( array( 'message' => 'Некорректные данные.' ), 400 );
	}

	$bundle_id   = (int) $payload['bundle_id'];
	$bundle_post = get_post( $bundle_id );
	if ( ! $bundle_post || $bundle_post->post_type !== 'podval_bundle' || $bundle_post->post_status !== 'publish' ) {
		wp_send_json_error( array( 'message' => 'Бандл не найден.' ), 404 );
	}

	// Получить базовый shirts массив из CPT для cross-check
	$bundle_shirts = podval_bundle_get_shirts( $bundle_id );
	if ( empty( $bundle_shirts ) ) {
		wp_send_json_error( array( 'message' => 'В бандле нет товаров.' ), 400 );
	}

	// Whitelist product_id из бандла
	$allowed_pids = array();
	foreach ( $bundle_shirts as $bs ) {
		$allowed_pids[] = (int) $bs['product_id'];
	}

	// Валидация и пересчёт shirts
	$validated_shirts = array();
	$subtotal         = 0.0;

	foreach ( $payload['shirts'] as $row ) {
		if ( ! is_array( $row ) ) continue;

		$pid = isset( $row['product_id'] ) ? (int) $row['product_id'] : 0;
		$vid = isset( $row['variation_id'] ) ? (int) $row['variation_id'] : 0;

		if ( ! $pid || ! in_array( $pid, $allowed_pids, true ) ) {
			continue;
		}

		$product = wc_get_product( $pid );
		if ( ! $product || ! $product->is_purchasable() ) {
			wp_send_json_error( array( 'message' => 'Один из товаров недоступен.' ), 400 );
		}

		// Match variation если variable + есть variation_id
		$variation_obj = null;
		$item_price    = (float) $product->get_price();

		if ( $product->is_type( 'variable' ) && $vid ) {
			$variation_obj = wc_get_product( $vid );
			if ( $variation_obj && $variation_obj->get_parent_id() === $pid && $variation_obj->is_purchasable() ) {
				$vp = (float) $variation_obj->get_price();
				if ( $vp > 0 ) {
					$item_price = $vp;
				}
			} else {
				$variation_obj = null;
				$vid           = 0;
			}
		}

		// Соберём отображаемые attrs для UI (название variation/atrs)
		$attrs_clean = array(
			'color' => isset( $row['color'] ) ? sanitize_text_field( $row['color'] ) : '',
			'size'  => isset( $row['size'] )  ? sanitize_text_field( $row['size'] )  : '',
			'type'  => isset( $row['type'] )  ? sanitize_text_field( $row['type'] )  : '',
		);

		$validated_shirts[] = array(
			'slot_idx'     => isset( $row['slot_idx'] ) ? (int) $row['slot_idx'] : count( $validated_shirts ),
			'product_id'   => $pid,
			'variation_id' => $vid,
			'name'         => $product->get_name(),
			'color'        => $attrs_clean['color'],
			'size'         => $attrs_clean['size'],
			'type'         => $attrs_clean['type'],
			'price'        => round( $item_price, 2 ),
			'image'        => wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) ?: '',
			'weight'       => (float) ( $variation_obj ? $variation_obj->get_weight() : $product->get_weight() ),
		);
		$subtotal += $item_price;
	}

	if ( empty( $validated_shirts ) ) {
		wp_send_json_error( array( 'message' => 'Не удалось обработать состав бандла.' ), 400 );
	}

	$active_count = count( $validated_shirts );

	// Server-side discount calc (через единую resolve-функцию,
	// иначе тип 'fixed' разъедется между витриной и корзиной)
	$resolved     = podval_bundle_resolve_discount( $bundle_id, $subtotal, $active_count );
	$discount_pct = (int) round( $resolved['percent'] );

	$total_price = $resolved['total'];
	$saved       = $resolved['amount'];

	// Anchor product = первый из validated_shirts
	$anchor_pid = (int) $validated_shirts[0]['product_id'];
	$anchor_vid = (int) $validated_shirts[0]['variation_id'];

	// Pre-flight: anchor variable но без variation_id — это ошибка
	$anchor_product = wc_get_product( $anchor_pid );
	if ( $anchor_product && $anchor_product->is_type( 'variable' ) && ! $anchor_vid ) {
		wp_send_json_error( array(
			'message' => 'Не выбраны все параметры для «' . esc_html( $anchor_product->get_name() ) . '». Заполните цвет/размер/крой и попробуйте снова.',
		), 400 );
	}

	// Cart-item-level UUID (ВНУТРИ _bundle_meta, чтобы wc generate_cart_id увидел
	// его через http_build_query даже если внешние ключи cart_item_data будут
	// отфильтрованы сторонними плагинами/темами через woocommerce_add_cart_item_data).
	$cart_unique = wp_generate_uuid4();

	// Bundle meta — будет жить в cart_item['_bundle_meta']
	$bundle_meta = array(
		'_cart_unique'     => $cart_unique,    // FIRST — попадает в http_build_query hash
		'bundle_id'        => $bundle_id,
		'bundle_name'      => $bundle_post->post_title,
		'bundle_slug'      => $bundle_post->post_name,
		'bundle_url'       => get_permalink( $bundle_id ),
		'thumbnail_id'     => (int) get_post_thumbnail_id( $bundle_id ),
		'discount_percent' => $discount_pct,
		'discount_type'    => $resolved['type'],
		'discount_amount'  => $resolved['amount'],
		'active_count'     => $active_count,
		'shirts'           => $validated_shirts,
		'subtotal'         => round( $subtotal, 2 ),
		'total_price'      => $total_price,
		'saved'            => $saved,
		'added_at'         => microtime( true ),
	);

	$cart_item_data = array(
		'_bundle_meta' => $bundle_meta,
		'_bundle_hash' => $cart_unique,
		'unique_key'   => $cart_unique,
	);

	$attempt_vid   = $anchor_vid;
	$attempt_attrs = array();

	if ( $anchor_vid ) {
		$vobj = wc_get_product( $anchor_vid );
		if ( $vobj && method_exists( $vobj, 'get_variation_attributes' ) ) {
			$attempt_attrs = $vobj->get_variation_attributes();

			/*
			 * WC variation may declare some attributes as "any" — get_variation_attributes()
			 * returns '' for them. WC->add_to_cart() will then reject with "select attribute"
			 * even though we provided a variation_id. Fill those from user choice.
			 *
			 * Keys are 'attribute_pa_color' / 'attribute_Модель' / URL-encoded cyrillic
			 * like 'attribute_%d1%86%d0%b2%d0%b5%d1%82' (для cyrillic taxonomy/non-taxonomy).
			 */
			$shirt_choice = $validated_shirts[0];
			foreach ( $attempt_attrs as $attr_taxonomy => $attr_value ) {
				if ( $attr_value !== '' && $attr_value !== null ) {
					continue;
				}
				$attr_name_raw     = preg_replace( '/^attribute_/', '', $attr_taxonomy );
				// Cyrillic keys в WC URL-encoded — decode для classify
				$attr_name_decoded = urldecode( $attr_name_raw );
				$ui_role           = podval_bundle_classify_attr( $attr_name_decoded, $attr_name_decoded );
				if ( $ui_role && ! empty( $shirt_choice[ $ui_role ] ) ) {
					$attempt_attrs[ $attr_taxonomy ] = $shirt_choice[ $ui_role ];
				}
			}

			// Финальная проверка — все ли атрибуты заполнены
			$missing = array();
			foreach ( $attempt_attrs as $attr_taxonomy => $attr_value ) {
				if ( $attr_value === '' || $attr_value === null ) {
					$bare = preg_replace( '/^attribute_/', '', $attr_taxonomy );
					$missing[] = urldecode( $bare );
				}
			}
			if ( ! empty( $missing ) ) {
				wp_send_json_error( array(
					'message' => 'Для «' . esc_html( $anchor_product->get_name() ) . '» не заполнены: ' . esc_html( implode( ', ', $missing ) ),
				), 400 );
			}
		}
	}

	$cart_item_key = WC()->cart->add_to_cart( $anchor_pid, 1, $attempt_vid, $attempt_attrs, $cart_item_data );

	if ( ! $cart_item_key ) {
		// Получить WC notices как одну строку
		$wc_msg = '';
		$raw_notices = array();
		if ( function_exists( 'wc_get_notices' ) ) {
			$notices = wc_get_notices( 'error' );
			foreach ( $notices as $n ) {
				$n_text = ( is_array( $n ) && isset( $n['notice'] ) ) ? wp_strip_all_tags( $n['notice'] ) : wp_strip_all_tags( (string) $n );
				$wc_msg .= $n_text . ' ';
				$raw_notices[] = $n_text;
			}
			wc_clear_notices();
		}
		wp_send_json_error( array(
			'message' => trim( $wc_msg ) ?: 'Не удалось добавить бандл в корзину.',
			'debug'   => array(
				'wc_notices'      => $raw_notices,
				'anchor_pid'      => $anchor_pid,
				'anchor_vid'      => $anchor_vid,
				'attempt_attrs'   => $attempt_attrs,
				'cart_unique'     => $cart_unique,
				'cart_count'      => WC()->cart->get_cart_contents_count(),
				'cart_items_count'=> count( WC()->cart->get_cart() ),
			),
		), 500 );
	}

	/*
	 * MERGE DETECTION & FORCE SPLIT.
	 * Если сторонний фильтр на `woocommerce_add_cart_item_data` удалил наши
	 * unique-ключи из cart_item_data, WC сгенерирует одинаковый cart_id для
	 * двух идентичных конфигураций бандла и сольёт их в одну позицию с
	 * увеличенным quantity. Это ломает bundle (qty всегда должен быть 1).
	 *
	 * Защита: после add_to_cart смотрим что произошло. Если quantity > 1 —
	 * было слияние. Откатываем qty=1 на существующей позиции и вручную
	 * push'им ещё одну позицию в cart_contents с гарантированно уникальным
	 * key, минуя WC дедупликацию.
	 */
	$existing_item = WC()->cart->get_cart_item( $cart_item_key );
	$was_merged = false;
	if ( $existing_item && (int) $existing_item['quantity'] > 1 ) {
		$was_merged = true;

		// 1) Откатываем quantity у существующей позиции до 1
		WC()->cart->set_quantity( $cart_item_key, 1, false );

		// 2) Делаем копию исходной позиции с новым key и нашим _bundle_meta
		$new_key  = md5( wp_generate_uuid4() . microtime( true ) );
		$new_item = WC()->cart->cart_contents[ $cart_item_key ];
		$new_item['key']           = $new_key;
		$new_item['quantity']      = 1;
		$new_item['_bundle_meta']  = $bundle_meta;
		$new_item['_bundle_hash']  = $cart_unique;
		$new_item['unique_key']    = $cart_unique;

		// 3) Push в cart_contents напрямую — обходим generate_cart_id
		WC()->cart->cart_contents[ $new_key ] = $new_item;
		WC()->cart->calculate_totals();

		// 4) Сохраняем session
		if ( WC()->session ) {
			WC()->session->set( 'cart', WC()->cart->get_cart_for_session() );
		}

		$cart_item_key = $new_key;
	}

	/*
	 * ════════════════════════════════════════════════════════════════════
	 * КРИТИЧНО: гарантируем сохранение сессии в БД и наличие session cookie
	 * ════════════════════════════════════════════════════════════════════
	 *
	 * Background bug (исправлен сменой priority с 5 на 20 ниже):
	 *   WC_Cart_Session::get_cart_from_session хукается на wp_loaded БЕЗ
	 *   priority — значит priority = 10 (default). Если наш handler сидит
	 *   на priority < 10, мы запускаемся ДО загрузки корзины из сессии.
	 *   cart_contents пустой, add_to_cart создаёт первый item, save_data
	 *   перезатирает прежнюю сессию. Результат: каждый AJAX-add «теряет»
	 *   все предыдущие позиции (response cart_count всегда = 1).
	 *
	 * Defense-in-depth (этот блок):
	 *   1. set_customer_session_cookie(true) — на случай если этот handler
	 *      когда-нибудь запустится для guest без активного cookie. WC сам
	 *      ставит cookie на woocommerce_add_to_cart action, но дублируем
	 *      для случаев когда что-то этот хук снимает.
	 *   2. calculate_totals + save_data — гарантируем что cart в DB
	 *      обновится до того как мы умрём через wp_send_json_success
	 *      (вместо надежды на shutdown).
	 *
	 * Для logged-in users безвредно: has_session() через is_user_logged_in
	 * уже true, save_data отработает.
	 */
	if ( WC()->session && method_exists( WC()->session, 'set_customer_session_cookie' ) ) {
		WC()->session->set_customer_session_cookie( true );
	}
	WC()->cart->calculate_totals();
	if ( WC()->session && method_exists( WC()->session, 'save_data' ) ) {
		WC()->session->save_data();
	}

	wp_send_json_success( array(
		'message'         => 'Бандл «' . esc_html( $bundle_post->post_title ) . '» добавлен в корзину.',
		'cart_item_key'   => $cart_item_key,
		'total_price'     => $total_price,
		'active_count'    => $active_count,
		'cart_count'      => WC()->cart->get_cart_contents_count(),
		'cart_items_count'=> count( WC()->cart->get_cart() ),
		'merge_recovered' => $was_merged,
	) );
/*
 * priority 20 — критически важно: ДОЛЖНО быть >10, чтобы запускаться
 * ПОСЛЕ WC_Cart_Session::get_cart_from_session (priority 10, default).
 * Иначе наш handler видит пустой cart_contents, и каждый AJAX-add
 * перезатирает прежнюю сессию.
 *
 * См. WC source: class-wc-cart-session.php :: init() ::
 *   add_action( 'wp_loaded', array( $this, 'get_cart_from_session' ) );
 *                                                              ↑ no priority = 10
 *
 * Аналогичный handler для обычных товаров (podval_add_item в
 * functions.php) тоже на priority 15 по той же причине.
 */
}, 20 );

/* ════════════════════════════════════════════════════════════════════
   2. CART ITEM PERSISTENCE (session)
   ════════════════════════════════════════════════════════════════════ */

/**
 * При восстановлении cart_item из session WC передаёт raw $cart_item.
 * Возвращаем _bundle_meta обратно в item — оно сохраняется в session
 * автоматически, но WC не пере-инициализирует кастомные поля без callback'а.
 */
add_filter( 'woocommerce_get_cart_item_from_session', function( $cart_item, $values, $key ) {
	if ( isset( $values['_bundle_meta'] ) && is_array( $values['_bundle_meta'] ) ) {
		$cart_item['_bundle_meta'] = $values['_bundle_meta'];
		$cart_item['_bundle_hash'] = isset( $values['_bundle_hash'] ) ? $values['_bundle_hash'] : '';
	}
	return $cart_item;
}, 10, 3 );

/* ════════════════════════════════════════════════════════════════════
   3. ЦЕНА — override через before_calculate_totals
   ════════════════════════════════════════════════════════════════════ */

add_action( 'woocommerce_before_calculate_totals', function( $cart ) {
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
	if ( ! $cart || ! method_exists( $cart, 'get_cart' ) ) return;

	foreach ( $cart->get_cart() as $key => $item ) {
		if ( empty( $item['_bundle_meta']['total_price'] ) ) continue;
		$bm = $item['_bundle_meta'];
		if ( ! isset( $item['data'] ) || ! is_a( $item['data'], 'WC_Product' ) ) continue;

		/*
		 * КРИТИЧНО: клонируем product, чтобы set_price не модифицировал глобальный
		 * WC product cache. Без clone — anchor product получает bundle-цену
		 * глобально, что ломает добавление второго бандла (data_hash коллизии)
		 * и любые другие места где читается родная цена anchor товара.
		 */
		$cloned_product = clone $item['data'];
		$cloned_product->set_price( (float) $bm['total_price'] );
		$cart->cart_contents[ $key ]['data'] = $cloned_product;
	}
}, 10, 1 );

/* ════════════════════════════════════════════════════════════════════
   4. CART ITEM RENDERING (name, thumbnail, item-data, qty)
   ════════════════════════════════════════════════════════════════════ */

/**
 * Override названия: «Бандл: <name>» + ссылка на single страницу.
 */
add_filter( 'woocommerce_cart_item_name', function( $name, $cart_item, $cart_item_key ) {
	if ( empty( $cart_item['_bundle_meta'] ) ) return $name;
	$bm = $cart_item['_bundle_meta'];
	$title = 'Бандл: ' . esc_html( $bm['bundle_name'] );
	$url   = ! empty( $bm['bundle_url'] ) ? $bm['bundle_url'] : '';
	if ( $url ) {
		return '<a href="' . esc_url( $url ) . '" class="bundle-cart-item__title">' . $title . '</a>';
	}
	return '<span class="bundle-cart-item__title">' . $title . '</span>';
}, 10, 3 );

/**
 * Override миниатюры: используем обложку бандла, не anchor product.
 */
add_filter( 'woocommerce_cart_item_thumbnail', function( $thumb, $cart_item, $cart_item_key ) {
	if ( empty( $cart_item['_bundle_meta'] ) ) return $thumb;
	$bm = $cart_item['_bundle_meta'];
	$thumb_id = (int) ( $bm['thumbnail_id'] ?? 0 );
	if ( $thumb_id ) {
		$new_thumb = wp_get_attachment_image( $thumb_id, 'woocommerce_thumbnail', false, array(
			'class' => 'bundle-cart-item__thumb',
			'alt'   => esc_attr( $bm['bundle_name'] ),
		) );
		if ( $new_thumb ) return $new_thumb;
	}
	// Fallback — иконка бандла или первый shirt thumb
	if ( ! empty( $bm['shirts'][0]['image'] ) ) {
		return '<img src="' . esc_url( $bm['shirts'][0]['image'] ) . '" alt="' . esc_attr( $bm['bundle_name'] ) . '" class="bundle-cart-item__thumb">';
	}
	return $thumb;
}, 10, 3 );

/**
 * Состав бандла + скидка как meta-строки в cart/checkout.
 */
add_filter( 'woocommerce_get_item_data', function( $item_data, $cart_item ) {
	if ( empty( $cart_item['_bundle_meta'] ) ) return $item_data;
	$bm = $cart_item['_bundle_meta'];

	// Состав — компактный список
	$lines = array();
	if ( ! empty( $bm['shirts'] ) && is_array( $bm['shirts'] ) ) {
		foreach ( $bm['shirts'] as $n => $s ) {
			$parts = array();
			if ( ! empty( $s['color'] ) ) $parts[] = $s['color'];
			if ( ! empty( $s['size'] ) )  $parts[] = $s['size'];
			if ( ! empty( $s['type'] ) )  $parts[] = $s['type'];
			$line = ( $n + 1 ) . '. ' . $s['name'];
			if ( $parts ) $line .= ' (' . implode( ', ', $parts ) . ')';
			$lines[] = $line;
		}
	}
	if ( $lines ) {
		$item_data[] = array(
			'key'     => 'Состав',
			'value'   => implode( '<br>', array_map( 'esc_html', $lines ) ),
			'display' => implode( '<br>', array_map( 'esc_html', $lines ) ),
		);
	}

	if ( ! empty( $bm['discount_percent'] ) || ! empty( $bm['discount_amount'] ) ) {
		$label = podval_bundle_meta_disc_label( $bm );
		$item_data[] = array(
			'key'     => 'Скидка набора',
			'value'   => $label,
			'display' => $label,
		);
	}

	if ( ! empty( $bm['saved'] ) && (float) $bm['saved'] > 0 ) {
		$saved_byn = (int) round( $bm['saved'] );
		$item_data[] = array(
			'key'     => 'Экономия',
			'value'   => number_format( $bm['saved'], 2, ',', '' ) . '&nbsp;Br',
			'display' => '<span data-price-byn="' . esc_attr( $saved_byn ) . '">' . number_format( $bm['saved'], 2, ',', '' ) . '&nbsp;Br</span>',
		);
	}

	return $item_data;
}, 10, 2 );

/**
 * Quantity в cart для бандла = 1, не редактируется.
 */
add_filter( 'woocommerce_cart_item_quantity', function( $product_quantity, $cart_item_key, $cart_item ) {
	if ( empty( $cart_item['_bundle_meta'] ) ) return $product_quantity;
	return '<span class="bundle-cart-item__qty">1 набор</span>';
}, 10, 3 );

/**
 * CSS class на cart row.
 */
add_filter( 'woocommerce_cart_item_class', function( $class, $cart_item, $cart_item_key ) {
	if ( ! empty( $cart_item['_bundle_meta'] ) ) {
		$class .= ' bundle-cart-item';
	}
	return $class;
}, 10, 3 );

/* ════════════════════════════════════════════════════════════════════
   5. ДОСТАВКА — sum весов всех shirts вместо одного anchor
   ════════════════════════════════════════════════════════════════════ */

/**
 * WC считает cart weight как Σ product->get_weight() × qty.
 * Anchor product даёт только свой weight; добавляем diff = sum_shirt_weights - anchor_weight
 * для каждого бандла в корзине.
 */
add_filter( 'woocommerce_cart_contents_weight', function( $weight ) {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) return $weight;

	foreach ( WC()->cart->get_cart() as $item ) {
		if ( empty( $item['_bundle_meta']['shirts'] ) ) continue;
		$bm = $item['_bundle_meta'];

		// Anchor weight: уже учтён WC через item['data']
		$anchor_weight = 0.0;
		if ( isset( $item['data'] ) && is_a( $item['data'], 'WC_Product' ) ) {
			$anchor_weight = (float) $item['data']->get_weight();
		}

		// Sum фактических весов всех shirts
		$sum_weights = 0.0;
		foreach ( $bm['shirts'] as $s ) {
			$sum_weights += (float) ( $s['weight'] ?? 0 );
		}

		$diff = $sum_weights - $anchor_weight;
		if ( $diff > 0 ) {
			$weight += $diff;
		}
	}
	return $weight;
}, 10, 1 );

/* ════════════════════════════════════════════════════════════════════
   6. COUPON EXCLUSION — meta-box + filter
   ════════════════════════════════════════════════════════════════════ */

/**
 * Чекбокс «Не применять к бандлам» в WC coupon admin (вкладка Usage Restriction).
 */
add_action( 'woocommerce_coupon_options_usage_restriction', function( $coupon_get_id, $coupon ) {
	woocommerce_wp_checkbox( array(
		'id'          => '_exclude_bundles',
		'label'       => 'Исключить бандлы',
		'description' => 'Купон не будет применяться к товарам-бандлам (CPT podval_bundle).',
	) );
}, 10, 2 );

/**
 * Save чекбокс.
 */
add_action( 'woocommerce_coupon_options_save', function( $post_id, $coupon ) {
	$val = isset( $_POST['_exclude_bundles'] ) ? 'yes' : 'no';
	if ( is_a( $coupon, 'WC_Coupon' ) ) {
		$coupon->update_meta_data( '_exclude_bundles', $val );
		$coupon->save();
	} else {
		update_post_meta( $post_id, '_exclude_bundles', $val );
	}
}, 10, 2 );

/**
 * Filter: купон с _exclude_bundles=yes не валиден для cart-item с _bundle_meta.
 */
add_filter( 'woocommerce_coupon_is_valid_for_product', function( $valid, $product, $coupon, $values ) {
	if ( ! is_a( $coupon, 'WC_Coupon' ) ) return $valid;
	$exclude = $coupon->get_meta( '_exclude_bundles' );
	if ( $exclude !== 'yes' ) return $valid;
	if ( ! empty( $values['_bundle_meta'] ) ) {
		return false;
	}
	return $valid;
}, 10, 4 );

/* ════════════════════════════════════════════════════════════════════
   7. ЗАЩИТА от удаления anchor product (если product в составе бандла
   удаляется, бандл-позиции в активных сессиях ломаются — на чек-аут
   они упадут с ошибкой; здесь только логируем)
   ════════════════════════════════════════════════════════════════════ */

add_action( 'before_delete_post', function( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post || $post->post_type !== 'product' ) return;
	// Никаких глобальных дергалок — заказы/сессии остаются. Просто debug log если нужно.
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
		error_log( '[podval-bundle] Product #' . $post_id . ' deleted — может ломать активные cart-сессии с бандлами.' );
	}
}, 5, 1 );

/* ════════════════════════════════════════════════════════════════════
   8. ORDER META — копируем _bundle_meta из cart_item в order_item
   (чтобы заказы знали что это был бандл — для TG-патчей прежней версии/прежней версии)
   ════════════════════════════════════════════════════════════════════ */

add_action( 'woocommerce_checkout_create_order_line_item', function( $item, $cart_item_key, $values, $order ) {
	if ( empty( $values['_bundle_meta'] ) ) return;
	$item->add_meta_data( '_bundle_meta', $values['_bundle_meta'], true );
	$item->add_meta_data( '_bundle_id',   (int) $values['_bundle_meta']['bundle_id'], true );
	// Переименовываем сам order_item — это сохраняется в БД, и все WC-templates
	// (admin order edit, my-account, thank-you, emails) читают через get_name().
	$item->set_name( 'Бандл: ' . $values['_bundle_meta']['bundle_name'] );
}, 10, 4 );

/**
 * Кастомное название позиции в админ-заказе.
 */
add_filter( 'woocommerce_order_item_name', function( $item_name, $item, $is_visible ) {
	if ( ! method_exists( $item, 'get_meta' ) ) return $item_name;
	$bm = $item->get_meta( '_bundle_meta' );
	if ( ! is_array( $bm ) || empty( $bm['bundle_name'] ) ) return $item_name;
	return 'Бандл: ' . esc_html( $bm['bundle_name'] );
}, 10, 3 );

/**
 * Скрываем сырое _bundle_meta из публичного meta-display заказа (оно техническое).
 */
add_filter( 'woocommerce_hidden_order_itemmeta', function( $hidden ) {
	$hidden[] = '_bundle_meta';
	$hidden[] = '_bundle_id';
	return $hidden;
} );

/**
 * Helper: HTML-блок «Состав бандла» (используется в нескольких контекстах).
 * Plural-form Russian word + escape for safe HTML.
 *
 *
 * @param array  $bm      Bundle meta из order_item->get_meta('_bundle_meta').
 * @param string $context 'html' для thank-you/admin/my-account, 'email' для email.
 * @return string HTML.
 */
function podval_bundle_render_order_composition( $bm, $context = 'html' ) {
	if ( ! is_array( $bm ) || empty( $bm['shirts'] ) ) return '';

	$count = count( $bm['shirts'] );
	$n10   = $count % 10;
	$n100  = $count % 100;
	if ( $n100 >= 11 && $n100 <= 14 )      $word = 'футболок';
	elseif ( $n10 === 1 )                  $word = 'футболка';
	elseif ( $n10 >= 2 && $n10 <= 4 )      $word = 'футболки';
	else                                   $word = 'футболок';

	$pct   = isset( $bm['discount_percent'] ) ? (int) $bm['discount_percent'] : 0;
	$saved = isset( $bm['saved'] ) ? (float) $bm['saved'] : 0;

	if ( $context === 'email' ) {
		$out  = '<div style="margin-top:6px;padding:8px 10px;background:#1a1a1a;border-left:2px solid #ff6a00;font-size:12px;line-height:1.5;">';
		if ( $pct > 0 || $saved > 0 ) {
			$out .= '<div style="color:#ff6a00;font-weight:700;margin-bottom:6px;">Скидка набора ' . podval_bundle_meta_disc_label( $bm );
			if ( $saved > 0 ) {
				$out .= ' · экономия ' . number_format( $saved, 2, ',', '' ) . '&nbsp;Br';
			}
			$out .= '</div>';
		}
		$out .= '<div style="color:#999;margin-bottom:4px;">Состав (' . (int) $count . ' ' . esc_html( $word ) . '):</div>';
		$out .= '<ol style="margin:0;padding:0 0 0 18px;color:#fff;">';
		foreach ( $bm['shirts'] as $sh ) {
			$parts = array();
			if ( ! empty( $sh['color'] ) ) $parts[] = 'Цвет: ' . esc_html( $sh['color'] );
			if ( ! empty( $sh['size'] ) )  $parts[] = 'Размер: ' . esc_html( $sh['size'] );
			if ( ! empty( $sh['type'] ) )  $parts[] = 'Модель: ' . esc_html( $sh['type'] );
			$out .= '<li style="margin:2px 0;"><strong>' . esc_html( $sh['name'] ) . '</strong>';
			if ( $parts ) {
				$out .= '<br><span style="color:#999;font-size:11px;">' . implode( ' · ', $parts ) . '</span>';
			}
			$out .= '</li>';
		}
		$out .= '</ol></div>';
		return $out;
	}

	// 'html' — thank-you / my-account / admin
	$out  = '<div class="bundle-order-composition">';
	if ( $pct > 0 || $saved > 0 ) {
		$out .= '<div class="bundle-order-composition__discount">Скидка набора ' . podval_bundle_meta_disc_label( $bm );
		if ( $saved > 0 ) {
			$out .= ' · экономия ' . number_format( $saved, 2, ',', '' ) . '&nbsp;Br';
		}
		$out .= '</div>';
	}
	$out .= '<div class="bundle-order-composition__header">Состав (' . (int) $count . ' ' . esc_html( $word ) . '):</div>';
	$out .= '<ol class="bundle-order-composition__list">';
	foreach ( $bm['shirts'] as $sh ) {
		$parts = array();
		if ( ! empty( $sh['color'] ) ) $parts[] = 'Цвет: ' . esc_html( $sh['color'] );
		if ( ! empty( $sh['size'] ) )  $parts[] = 'Размер: ' . esc_html( $sh['size'] );
		if ( ! empty( $sh['type'] ) )  $parts[] = 'Модель: ' . esc_html( $sh['type'] );
		$out .= '<li><strong>' . esc_html( $sh['name'] ) . '</strong>';
		if ( $parts ) {
			$out .= '<span class="bundle-order-composition__attrs">' . implode( ' · ', $parts ) . '</span>';
		}
		$out .= '</li>';
	}
	$out .= '</ol></div>';
	return $out;
}

/**
 * Универсальный hook: добавляем состав бандла под meta каждого order_item
 * в стандартных WC templates (thank-you, my-account, admin order edit).
 * Кастомный email-шаблон ИСПОЛЬЗУЕТ свой явный branch (см. email template).
 */
add_action( 'woocommerce_order_item_meta_end', function( $item_id, $item, $order, $plain_text = false ) {
	if ( $plain_text ) return; // text-emails — skip
	if ( ! method_exists( $item, 'get_meta' ) ) return;
	$bm = $item->get_meta( '_bundle_meta' );
	if ( ! is_array( $bm ) ) return;
	echo podval_bundle_render_order_composition( $bm, 'html' );
}, 10, 4 );

/**
 * Admin order edit metabox — отдельный action для бэкенда.
 * Структура почти та же что и frontend, но обернуто div'ом для админ-стилей.
 */
add_action( 'woocommerce_after_order_itemmeta', function( $item_id, $item, $product ) {
	if ( ! is_admin() ) return;
	if ( ! method_exists( $item, 'get_meta' ) ) return;
	$bm = $item->get_meta( '_bundle_meta' );
	if ( ! is_array( $bm ) ) return;
	echo '<div class="bundle-order-composition--admin" style="margin-top:8px;padding:8px 10px;background:#fef3e7;border-left:3px solid #ff6a00;font-size:12px;line-height:1.5;color:#1d2327;">';
	echo podval_bundle_render_order_composition( $bm, 'html' );
	echo '</div>';
}, 10, 3 );

/**
 * Подпись скидки из _bundle_meta корзины/заказа.
 *
 * Заказы, оформленные до прежней версии, не содержат discount_type —
 * для них молча откатываемся на проценты, чтобы старые письма
 * и страницы «Спасибо за заказ» не сломались.
 *
 * @param array $bm _bundle_meta
 * @return string «−15%» либо «−20,00 Br»
 */
function podval_bundle_meta_disc_label( $bm ) {
	$type = isset( $bm['discount_type'] ) ? (string) $bm['discount_type'] : 'percent';
	if ( $type === 'fixed' ) {
		$amount = isset( $bm['discount_amount'] ) ? (float) $bm['discount_amount'] : (float) ( $bm['saved'] ?? 0 );
		return '−' . number_format( $amount, 2, ',', '' ) . '&nbsp;Br';
	}
	return '−' . (int) ( $bm['discount_percent'] ?? 0 ) . '%';
}

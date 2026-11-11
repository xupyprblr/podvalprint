<?php
/**
 * Конструктор-лайт шаблонных принтов — интеграция.
 *
 * Товар помечается метой `_podval_tpl_group` (dad / mom / couple / kids).
 * Страница такого товара роутится на page-tpl-builder.php через template_include.
 *
 * Цвет / тип / размер изделия хранятся как мета cart item, НЕ как вариации WC —
 * это удерживает каталог от взрыва вариаций (2 цвета × 2 типа × 7 размеров = 28).
 *
 * Данные конструктора едут в заказ под ключом `_podval_tpl` (schema_v: 2),
 * рядом с существующим `_artwork_*` пайплайном — они не конфликтуют.
 *
 * @package Podval Print
 *
 */

defined( 'ABSPATH' ) || exit;

/* ═══════════════════════════════════════════
   1. МЕТА-БОКС ТОВАРА
   ═══════════════════════════════════════════ */

add_action( 'add_meta_boxes', function() {
	add_meta_box(
		'podval_tpl_group_box',
		'Конструктор шаблона',
		'podval_tpl_group_box_render',
		'product',
		'side',
		'default'
	);
} );

/**
 * Рендер мета-бокса выбора группы шаблонов.
 *
 * @param WP_Post $post Товар.
 */
function podval_tpl_group_box_render( $post ) {
	wp_nonce_field( 'podval_tpl_group_save', 'podval_tpl_group_nonce' );

	$current = get_post_meta( $post->ID, '_podval_tpl_group', true );
	$groups  = podval_tpl_groups();

	echo '<p><label for="podval_tpl_group"><strong>Группа шаблонов</strong></label></p>';
	echo '<select name="podval_tpl_group" id="podval_tpl_group" style="width:100%">';
	foreach ( $groups as $key => $label ) {
		printf(
			'<option value="%s"%s>%s</option>',
			esc_attr( $key ),
			selected( $current, $key, false ),
			esc_html( $label )
		);
	}
	echo '</select>';
	echo '<p class="description" style="margin-top:8px">Если группа выбрана, страница товара заменяется конструктором шаблона. Товар должен быть <strong>простым</strong> (не вариативным) — цвет, тип и размер выбираются в конструкторе.</p>';

	if ( ! $current ) {
		return;
	}

	$tpls = podval_tpl_by_group( $current );

	/* ── Пресет: товар открывает конструктор на конкретной основе ──
	 * Так «Футболка Polaroid» и «Футболка папе с фото» — два разных товара
	 * (свой URL, своя карточка, своя позиция в фиде), но один движок:
	 * товар лишь предустанавливает основу и стили.
	 */
	$preset_tpl    = get_post_meta( $post->ID, '_podval_tpl_preset_tpl', true );
	$preset_lock   = '1' === get_post_meta( $post->ID, '_podval_tpl_preset_lock', true );
	$preset_styles = get_post_meta( $post->ID, '_podval_tpl_preset_styles', true );
	$preset_styles = is_array( $preset_styles ) ? $preset_styles : array();

	echo '<hr style="margin:14px 0">';
	echo '<p><label for="podval_tpl_preset_tpl"><strong>Открывать на основе</strong></label></p>';
	echo '<select name="podval_tpl_preset_tpl" id="podval_tpl_preset_tpl" style="width:100%">';
	echo '<option value="">— первая по списку —</option>';
	foreach ( $tpls as $id => $tpl ) {
		printf(
			'<option value="%s"%s>%s</option>',
			esc_attr( $id ),
			selected( $preset_tpl, $id, false ),
			esc_html( $tpl['title'] )
		);
	}
	echo '</select>';

	echo '<p style="margin-top:8px"><label><input type="checkbox" name="podval_tpl_preset_lock" value="1" ' . checked( $preset_lock, true, false ) . '> Показывать только эту основу</label></p>';
	echo '<p class="description">Шаг «Выберите основу» скрывается, нумерация шагов пересчитывается. Нужно для товаров-лендингов вроде «Футболка Polaroid».</p>';

	/* ── Сторона печати по умолчанию ── */
	$side_defs = podval_tpl_garment_options();
	if ( isset( $side_defs['side'] ) ) {
		$side_cur = get_post_meta( $post->ID, '_podval_tpl_default_side', true );
		$side_def = $side_defs['side'];
		echo '<p style="margin-top:10px"><label for="podval_tpl_default_side"><strong>Печать по умолчанию</strong></label></p>';
		echo '<select name="podval_tpl_default_side" id="podval_tpl_default_side" style="width:100%">';
		printf(
			'<option value="">— общая (%s) —</option>',
			esc_html( $side_def['options'][ $side_def['default'] ]['label'] ?? $side_def['default'] )
		);
		foreach ( $side_def['options'] as $val => $o ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $val ),
				selected( $side_cur, $val, false ),
				esc_html( $o['label'] )
			);
		}
		echo '</select>';
		echo '<p class="description">Какая сторона выбрана сразу на карточке и в конструкторе. Ставьте под то, где принт на фото товара.</p>';
	}

	// Стили пресета показываем только для уже сохранённой основы — иначе
	// пришлось бы рисовать в боксе опции всех шаблонов группы разом.
	if ( $preset_tpl && isset( $tpls[ $preset_tpl ]['styles'] ) && $tpls[ $preset_tpl ]['styles'] ) {
		echo '<p style="margin-top:10px"><strong>Стили по умолчанию</strong></p>';
		foreach ( $tpls[ $preset_tpl ]['styles'] as $key => $def ) {
			$cur = isset( $preset_styles[ $key ] ) ? $preset_styles[ $key ] : '';
			echo '<p style="margin:6px 0"><label style="font-size:12px;display:block;margin-bottom:2px">' . esc_html( $def['label'] ) . '</label>';
			echo '<select name="podval_tpl_preset_styles[' . esc_attr( $key ) . ']" style="width:100%">';
			printf(
				'<option value="">— по умолчанию (%s) —</option>',
				esc_html( $def['options'][ $def['default'] ] ?? $def['default'] )
			);
			foreach ( $def['options'] as $val => $label ) {
				printf(
					'<option value="%s"%s>%s</option>',
					esc_attr( $val ),
					selected( $cur, $val, false ),
					esc_html( $label )
				);
			}
			echo '</select></p>';
		}
	} elseif ( $preset_tpl ) {
		echo '<p class="description" style="margin-top:8px">У этой основы нет стилевых опций.</p>';
	} else {
		echo '<p class="description" style="margin-top:8px">Сохраните товар с выбранной основой — появятся её стилевые опции.</p>';
	}

	echo '<p style="margin-top:10px"><strong>Доступные основы (' . count( $tpls ) . '):</strong></p><ul style="margin:0;padding-left:16px;list-style:disc">';
	foreach ( $tpls as $id => $tpl ) {
		echo '<li style="font-size:12px">' . esc_html( $tpl['title'] ) . ' <code style="font-size:11px">' . esc_html( $id ) . '</code></li>';
	}
	echo '</ul>';
}

add_action( 'save_post_product', function( $post_id ) {
	if ( ! isset( $_POST['podval_tpl_group_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_key( $_POST['podval_tpl_group_nonce'] ), 'podval_tpl_group_save' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$group  = isset( $_POST['podval_tpl_group'] ) ? sanitize_key( $_POST['podval_tpl_group'] ) : '';
	$groups = podval_tpl_groups();

	if ( $group && isset( $groups[ $group ] ) ) {
		update_post_meta( $post_id, '_podval_tpl_group', $group );
	} else {
		delete_post_meta( $post_id, '_podval_tpl_group' );
	}

	/* ── Пресет основы и стилей ─────────────────────────────── */
	$preset_tpl = isset( $_POST['podval_tpl_preset_tpl'] ) ? sanitize_key( $_POST['podval_tpl_preset_tpl'] ) : '';
	$allowed    = $group ? podval_tpl_by_group( $group ) : array();

	if ( $preset_tpl && isset( $allowed[ $preset_tpl ] ) ) {
		update_post_meta( $post_id, '_podval_tpl_preset_tpl', $preset_tpl );
	} else {
		$preset_tpl = '';
		delete_post_meta( $post_id, '_podval_tpl_preset_tpl' );
	}

	if ( ! empty( $_POST['podval_tpl_preset_lock'] ) && $preset_tpl ) {
		update_post_meta( $post_id, '_podval_tpl_preset_lock', '1' );
	} else {
		delete_post_meta( $post_id, '_podval_tpl_preset_lock' );
	}

	$side_in   = isset( $_POST['podval_tpl_default_side'] ) ? sanitize_key( wp_unslash( $_POST['podval_tpl_default_side'] ) ) : '';
	$side_opts = podval_tpl_garment_options();
	if ( $side_in && isset( $side_opts['side']['options'][ $side_in ] ) ) {
		update_post_meta( $post_id, '_podval_tpl_default_side', $side_in );
	} else {
		delete_post_meta( $post_id, '_podval_tpl_default_side' );
	}

	$styles_in  = isset( $_POST['podval_tpl_preset_styles'] ) ? (array) wp_unslash( $_POST['podval_tpl_preset_styles'] ) : array();
	$styles_out = array();
	if ( $preset_tpl && ! empty( $allowed[ $preset_tpl ]['styles'] ) ) {
		foreach ( $allowed[ $preset_tpl ]['styles'] as $key => $def ) {
			$val = isset( $styles_in[ $key ] ) ? sanitize_key( $styles_in[ $key ] ) : '';
			if ( $val && isset( $def['options'][ $val ] ) ) {
				$styles_out[ $key ] = $val;
			}
		}
	}
	if ( $styles_out ) {
		update_post_meta( $post_id, '_podval_tpl_preset_styles', $styles_out );
	} else {
		delete_post_meta( $post_id, '_podval_tpl_preset_styles' );
	}

	if ( function_exists( 'podval_flush_all_cache' ) ) {
		podval_flush_all_cache( 'tpl_group_save' );
	}
} );

/* ═══════════════════════════════════════════
   2. РОУТИНГ СТРАНИЦЫ ТОВАРА
   ═══════════════════════════════════════════ */

/**
 * Группа шаблонов текущего товара (пустая строка — обычный товар).
 *
 * @param int $product_id ID товара.
 * @return string
 */
function podval_tpl_product_group( $product_id ) {
	$group = get_post_meta( (int) $product_id, '_podval_tpl_group', true );
	if ( ! $group ) {
		return '';
	}
	$groups = podval_tpl_groups();
	return isset( $groups[ $group ] ) ? $group : '';
}

/**
 * Запрошен ли конструктор для текущей страницы товара.
 *
 * Раньше товар с подключённым шаблоном сразу проваливался в конструктор,
 * минуя карточку. Человек попадал в форму из пяти шагов, не увидев ни
 * фотографий изделия, ни описания, — и не понимал, что вообще покупает.
 * Теперь карточка остаётся обычной, а конструктор открывается кнопкой.
 *
 * @return bool
 */
function podval_tpl_builder_requested() {
	// Строго `builder=1`, а не любое непустое значение. Страница кешируется
	// WP-Optimize с этим параметром в имени файла: при `!empty()` любой
	// `?builder=999` от бота порождал бы отдельную копию кеша с тем же
	// содержимым. Ссылки темы всегда отдают ровно единицу.
	return isset( $_GET['builder'] ) && '1' === (string) $_GET['builder']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
}

/**
 * Ссылка на конструктор для товара, с предвыбранными параметрами изделия.
 *
 * Цвет / тип / размер выбираются уже на карточке, поэтому уезжают в URL
 * и подставляются в первый шаг — переспрашивать одно и то же дважды
 * незачем.
 *
 * @param int   $product_id ID товара.
 * @param array $garment    Ключи выбранных опций изделия.
 * @return string
 */
function podval_tpl_builder_url( $product_id, $garment = array() ) {
	$url = add_query_arg( array( 'builder' => 1 ), get_permalink( $product_id ) );

	// Предвыбор изделия уезжает во ФРАГМЕНТ, а не в query-строку.
	// WP-Optimize отказывается кешировать любой URL с незнакомым GET-параметром,
	// и карточка с `?g_color=black&g_type=classic&g_size=m` отдавалась мимо кеша:
	// TTFB 1.5 с вместо 0.2 с. Фрагмент на сервер вообще не уходит — остаётся
	// один кеш-файл на товар, а выбор читает tpl-builder.js из location.hash.
	$frag = array();
	foreach ( array( 'color', 'type', 'size' ) as $key ) {
		if ( ! empty( $garment[ $key ] ) ) {
			$frag[] = 'g_' . $key . '=' . rawurlencode( sanitize_key( $garment[ $key ] ) );
		}
	}

	return $frag ? $url . '#' . implode( '&', $frag ) : $url;
}

/* Конструктор живёт на URL товара с ?builder=1 — для поисковика это
   дубль карточки: тот же адрес, то же название, другой набор блоков.
   Закрываем его от индексации на уровне robots-мета. Канонический адрес
   при этом остаётся у карточки, его выставляет SEO-модуль темы. */
add_filter( 'wp_robots', function( $robots ) {
	if ( function_exists( 'is_product' ) && is_product() && podval_tpl_builder_requested() ) {
		$robots['noindex']  = true;
		$robots['nofollow'] = true;
	}
	return $robots;
} );

/* ── Кеширование страницы конструктора ──────────────────────
   WP-Optimize по умолчанию не кеширует НИ ОДИН URL с GET-параметрами:
   в заголовках ответа было
     wpo-cache-status: not cached
     wpo-cache-message: In the settings, caching is disabled for matches
                        for one of the current request's GET parameters
   и TTFB 1.5 с против 0.2 с у той же карточки без query-строки.

   Фильтр `wpo_cache_query_variables` — белый список: перечисленные
   параметры и разрешают кеширование, и попадают в имя кеш-файла
   (`wpo_cache_filename()`, строка вида `-builder=1`). Именно это нам и
   нужно: конструктор и карточка живут по одному адресу и обязаны иметь
   РАЗНЫЕ кеш-файлы.

   ВАЖНО: не путать с `wpo_cache_ignore_query_variables` — тот, наоборот,
   выбрасывает параметр из ключа, и конструктор начал бы отдаваться из
   кеша обычной карточки.

   `g_color / g_type / g_size` сюда сознательно НЕ добавлены: они
   размножили бы кеш на все сочетания цвет × тип × размер. Предвыбор
   уехал во фрагмент URL, см. podval_tpl_builder_url(). */
add_filter( 'wpo_cache_query_variables', function( $vars ) {
	if ( ! is_array( $vars ) ) {
		$vars = array();
	}
	if ( ! in_array( 'builder', $vars, true ) ) {
		$vars[] = 'builder';
	}
	return $vars;
} );

/* Фильтр выше срабатывает только в момент WPO_Cache_Config::update() —
   значение запекается в wp-content/wpo-cache/config/config-*.php. Без
   пересохранения настроек плагина новый белый список не заработал бы,
   а «почему не кешируется» ушло бы в долгую отладку. Пересобираем конфиг
   один раз на версию темы. */
add_action( 'init', function() {
	if ( ! class_exists( 'WPO_Cache_Config' ) ) {
		return;
	}
	$theme_ver = wp_get_theme()->get( 'Version' );
	if ( get_option( 'podval_wpo_query_vars_synced' ) === $theme_ver ) {
		return;
	}

	$config = WPO_Cache_Config::instance();
	$config->update( $config->get(), true );

	update_option( 'podval_wpo_query_vars_synced', $theme_ver, false );
}, 99 );

add_filter( 'template_include', function( $template ) {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return $template;
	}
	if ( ! podval_tpl_builder_requested() ) {
		return $template;
	}
	$group = podval_tpl_product_group( get_queried_object_id() );
	if ( ! $group ) {
		return $template;
	}
	$custom = get_stylesheet_directory() . '/page-tpl-builder.php';
	return file_exists( $custom ) ? $custom : $template;
}, 96 );

/* ═══════════════════════════════════════════
   3. ASSETS
   ═══════════════════════════════════════════ */

add_action( 'wp_enqueue_scripts', function() {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}
	// Тяжёлые ассеты конструктора — только когда он реально открыт.
	// На карточке товара они лежали бы мёртвым грузом.
	if ( ! podval_tpl_builder_requested() ) {
		return;
	}
	$product_id = get_queried_object_id();
	$group      = podval_tpl_product_group( $product_id );
	if ( ! $group ) {
		return;
	}

	$theme_ver = wp_get_theme()->get( 'Version' );
	$uri       = get_template_directory_uri();

	wp_enqueue_style(
		'podval-style-tpl-builder',
		$uri . '/assets/css/style-tpl-builder.css',
		array( 'podval-style-core' ),
		$theme_ver
	);

	$js = function_exists( 'podval_js_file' )
		? podval_js_file( 'tpl-builder.js' )
		: $uri . '/assets/js/tpl-builder.js';

	wp_enqueue_script( 'podval-tpl-builder', $js, array( 'podval-main' ), $theme_ver, true );

	wp_localize_script( 'podval-tpl-builder', 'PodvalTpl', podval_tpl_frontend_config( $product_id, $group ) );
}, 20 );

/**
 * Галерея товара на странице конструктора не нужна.
 *
 * page-tpl-builder.php показывает превью собираемого макета, а не слайдер
 * фотографий товара — но WooCommerce всё равно грузила photoswipe (два CSS,
 * оба блокировали рендер), flexslider, zoom и single-product: ~30 КБ сжатыми
 * на странице, где ни один из них не выполняет работы.
 *
 * Снимаем через remove_theme_support, а не dequeue по хэндлам: WooCommerce
 * сама проверяет поддержку темы в WC_Frontend_Scripts перед постановкой в
 * очередь, так что скрипты просто не появятся. Хук `wp` — до wp_enqueue_scripts.
 * Отдельно уходит wc-single-product: он ставится по is_product() независимо
 * от поддержки галереи, а его работа здесь — инициализация той же галереи.
 */
add_action( 'wp', function() {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}
	if ( ! podval_tpl_builder_requested() ) {
		return;
	}
	if ( ! podval_tpl_product_group( get_queried_object_id() ) ) {
		return;
	}

	remove_theme_support( 'wc-product-gallery-zoom' );
	remove_theme_support( 'wc-product-gallery-lightbox' );
	remove_theme_support( 'wc-product-gallery-slider' );

	add_action( 'wp_enqueue_scripts', function() {
		wp_dequeue_script( 'wc-single-product' );
	}, 99 );
} );

/**
 * Конфиг для фронтенда конструктора.
 *
 * @param int    $product_id ID товара.
 * @param string $group      Группа шаблонов.
 * @return array
 */
function podval_tpl_frontend_config( $product_id, $group ) {
	$product   = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
	$base_price = $product ? (float) $product->get_price() : 0.0;

	$templates = array();
	foreach ( podval_tpl_by_group( $group ) as $id => $tpl ) {
		$templates[ $id ] = array(
			'title'     => $tpl['title'],
			'subtitle'  => $tpl['subtitle'] ?? '',
			'engine'    => $tpl['engine'],
			'preview'   => podval_tpl_preview_url( $tpl ),
			'colors'    => $tpl['colors'],
			'photos'    => $tpl['photos'],
			'slotRatio' => $tpl['slot_ratio'] ?? 1,
			'mergeTexts' => ! empty( $tpl['merge_texts'] ),
			'stepTitle'  => $tpl['step_title'] ?? '',
			'printWidthCm' => (float) ( $tpl['print_width_cm'] ?? 30 ),
			// Движок papa: пара букв в ячейке (PA у папы, MA у мамы).
			'papaLetters'  => array_values( array_map( 'strval', $tpl['papa_letters'] ?? array( 'P', 'A' ) ) ),
			'papaLettersCyr' => array_values( array_map( 'strval', $tpl['papa_letters_cyr'] ?? array() ) ),
			// Откуда заголовок-повтор и имя блочного низа берут текст.
			'titleKey'    => $tpl['title_key'] ?? 'headline',
			'footNameKey' => $tpl['foot_name_key'] ?? 'name',
			'texts'     => $tpl['texts'],
			'layouts'   => $tpl['layouts'],
			'styles'    => $tpl['styles'],
		);
	}

	// Что человек выбрал ещё на карточке товара.
	//
	// Основной путь с фрагмент URL, его разбирает tpl-builder.js:
	// страница отдаётся из кеша, одинаковая для всех, и запечь сюда чей-то
	// выбор было бы прямой ошибкой. Чтение $_GET оставлено запасом для
	// старых ссылок из мессенджеров и закладок — такие адреса просто
	// проходят мимо кеша, как и раньше.
	//
	// Ключи проверяются на клиенте по списку допустимых опций — в URL
	// может прилететь что угодно.
	$picked = array();
	foreach ( array( 'color', 'type', 'size' ) as $key ) {
		if ( ! empty( $_GET[ 'g_' . $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$picked[ $key ] = sanitize_key( wp_unslash( $_GET[ 'g_' . $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}

	return array(
		'preset'    => podval_tpl_product_preset( $product_id, $group ),
		'picked'    => $picked,
		// Ссылку на таблицу размеров рисуем только если она у товара задана.
		'sizeChart' => (bool) get_post_meta( (int) $product_id, '_podval_size_chart', true ),
		'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
		'nonce'     => wp_create_nonce( 'podval_nonce' ),
		'cartUrl'   => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart/' ),
		'productId' => (int) $product_id,
		'group'     => $group,
		'basePrice' => $base_price,
		'currency'  => 'Br',
		'templates' => $templates,
		// Семейства @font-face для рукописных подписей: конструктор
		// переключает их по выбору клиента, растр берёт то же имя.
		'scriptFonts' => function_exists( 'podval_tpl_script_fonts' )
			? array_map(
				function ( $f ) { return $f['family']; },
				podval_tpl_script_fonts()
			)
			: array(),
		'garment'   => podval_tpl_garment_options( $product_id ),
		'addons'    => podval_tpl_addons(),
		'maxUpload' => 25,
		// Реальный лимит PHP в байтах (min upload_max_filesize / post_max_size).
		// Фото крупнее конструктор ужмёт в браузере перед отправкой.
		'serverMax' => (int) wp_max_upload_size(),
		'minPixels' => 900, // порог «мягкое качество» по короткой стороне
	);
}

/**
 * Пресет товара: с какой основы открывать конструктор и с какими стилями.
 *
 * Позволяет держать несколько товаров-лендингов на одном движке:
 * «Футболка Polaroid» = dad_single + frame:polaroid + lock,
 * «Футболка папе с фото» = тот же конструктор без пресета.
 *
 * @param int    $product_id ID товара.
 * @param string $group      Группа шаблонов.
 * @return array{tplId:string,styles:array,lock:bool}
 */
function podval_tpl_product_preset( $product_id, $group ) {
	$empty = array( 'tplId' => '', 'styles' => (object) array(), 'lock' => false );

	$tpl_id = get_post_meta( (int) $product_id, '_podval_tpl_preset_tpl', true );
	if ( ! $tpl_id ) {
		return $empty;
	}

	$allowed = podval_tpl_by_group( $group );
	if ( ! isset( $allowed[ $tpl_id ] ) ) {
		return $empty;
	}

	$styles = get_post_meta( (int) $product_id, '_podval_tpl_preset_styles', true );
	$clean  = array();
	if ( is_array( $styles ) ) {
		foreach ( $styles as $key => $val ) {
			if ( isset( $allowed[ $tpl_id ]['styles'][ $key ]['options'][ $val ] ) ) {
				$clean[ $key ] = $val;
			}
		}
	}

	return array(
		'tplId'  => $tpl_id,
		// Пустой массив в JSON стал бы [], а не {} — фронту удобнее объект.
		'styles' => $clean ? $clean : (object) array(),
		'lock'   => '1' === get_post_meta( (int) $product_id, '_podval_tpl_preset_lock', true ),
	);
}

/* ═══════════════════════════════════════════
   4. AJAX — ДОБАВЛЕНИЕ В КОРЗИНУ
   ═══════════════════════════════════════════ */

add_action( 'wp_ajax_podval_tpl_add_to_cart', 'podval_tpl_add_to_cart' );
add_action( 'wp_ajax_nopriv_podval_tpl_add_to_cart', 'podval_tpl_add_to_cart' );

/**
 * Шаблонный товар нельзя положить в корзину «голым».
 *
 * Единственный легальный путь — podval_tpl_add_to_cart(): он всегда передаёт
 * cart_item_data['podval_tpl']. Всё остальное кладёт позицию без данных
 * конструктора, и она доезжает до заказа как обычная футболка — печатать
 * нечего (прод, заказ #422). Известные обходы:
 *   1. быстрое добавление с карточки каталога (podval_add_item в functions.php);
 *   2. нативная ссылка ?add-to-cart=ID — WC обрабатывает её всегда;
 *   3. «Заказать ещё раз» — WC копирует товар без пользовательских данных.
 *
 * Проверка стоит здесь, а не в каждой точке входа: любой новый вызов
 * WC()->cart->add_to_cart() проходит через этот фильтр автоматически.
 *
 * @param bool  $passed         Результат предыдущих проверок.
 * @param int   $product_id     ID товара.
 * @param int   $quantity       Количество.
 * @param int   $variation_id   ID вариации.
 * @param array $variations     Атрибуты вариации.
 * @param array $cart_item_data Пользовательские данные позиции.
 * @return bool
 */
add_filter( 'woocommerce_add_to_cart_validation', function( $passed, $product_id, $quantity, $variation_id = 0, $variations = array(), $cart_item_data = array() ) {
	if ( ! $passed ) {
		return $passed;
	}
	if ( ! podval_tpl_product_group( $product_id ) ) {
		return $passed;
	}
	if ( ! empty( $cart_item_data['podval_tpl'] ) ) {
		return $passed;
	}

	if ( function_exists( 'wc_add_notice' ) ) {
		wc_add_notice(
			sprintf(
				'«%s» собирается в конструкторе. Откройте товар и нажмите «Создать принт».',
				get_the_title( (int) $product_id )
			),
			'error'
		);
	}

	return false;
}, 10, 6 );

/**
 * Подчистка корзин, набранных до фикса.
 *
 * Фильтр выше закрывает вход, но позиции, уже лежащие в сессиях клиентов,
 * он не трогает: сессия живёт двое суток, и заказ из такой корзины ушёл бы
 * пустым уже после деплоя. Хук срабатывает и на корзине, и на чекауте.
 */
add_action( 'woocommerce_check_cart_items', function() {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return;
	}

	foreach ( WC()->cart->get_cart() as $key => $item ) {
		$pid = (int) ( $item['product_id'] ?? 0 );
		if ( ! $pid || ! podval_tpl_product_group( $pid ) ) {
			continue;
		}
		if ( ! empty( $item['podval_tpl'] ) ) {
			continue;
		}

		WC()->cart->remove_cart_item( $key );

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice(
				sprintf(
					'«%s» убран из корзины: макет не собран. Откройте товар и нажмите «Создать принт».',
					get_the_title( $pid )
				),
				'error'
			);
		}
	}
} );

/**
 * Добавление собранного шаблона в корзину.
 */
function podval_tpl_add_to_cart() {
	check_ajax_referer( 'podval_nonce', '_wpnonce' );

	$product_id = absint( $_POST['product_id'] ?? 0 );

	if ( ! $product_id || ! function_exists( 'WC' ) || ! WC()->cart ) {
		wp_send_json_error( array( 'message' => 'Товар не найден.' ) );
	}

	if ( ! podval_tpl_product_group( $product_id ) ) {
		wp_send_json_error( array( 'message' => 'Товар не подключён к конструктору.' ) );
	}

	// Сессия для гостей.
	if ( WC()->session && ! WC()->session->has_session() ) {
		WC()->session->set_customer_session_cookie( true );
	}

	$raw = isset( $_POST['tpl_data'] ) ? wp_unslash( $_POST['tpl_data'] ) : '';
	$payload = json_decode( $raw, true );

	if ( ! is_array( $payload ) ) {
		wp_send_json_error( array( 'message' => 'Не удалось прочитать данные конструктора.' ) );
	}

	$data = podval_tpl_validate( $payload );
	if ( is_wp_error( $data ) ) {
		wp_send_json_error( array( 'message' => $data->get_error_message() ) );
	}

	$data['addons_resolved'] = podval_tpl_calc_addons( $data );

	// Превью, отрендеренное на клиенте (data URL) — сохраняем как файл-схему.
	$preview_id = 0;
	if ( ! empty( $_POST['tpl_preview'] ) ) {
		$preview_id = podval_tpl_store_preview( wp_unslash( $_POST['tpl_preview'] ), $data );
		if ( $preview_id ) {
			$data['preview_id'] = $preview_id;
		}
	}

	// Печатный файл 300 ppi — отдельно от схемы. Схема остаётся
	// как была: её читают корзина, админка и Telegram. Печатный файл
	// большой (до ~5000 px по стороне), в Telegram его не шлём.
	if ( ! empty( $_FILES['tpl_print']['tmp_name'] ) ) {
		$ppi      = isset( $_POST['tpl_print_ppi'] ) ? absint( $_POST['tpl_print_ppi'] ) : 0;
		$print_id = podval_tpl_store_print_file( $_FILES['tpl_print'], $ppi, $data );
		if ( $print_id ) {
			$data['print_id'] = $print_id;
		}
	}

	$cart_item_data = array(
		'podval_tpl'  => $data,
		'unique_key' => 'tpl_' . md5( wp_json_encode( $data ) . microtime( true ) ),
	);

	// Предварительные проверки — до add_to_cart, пока причина ещё известна.
	// Сам add_to_cart() на отказ отдаёт только false, а объяснение кладёт
	// в уведомления WC, которые мы ниже вычищаем.
	$product_obj = wc_get_product( $product_id );
	if ( ! $product_obj ) {
		wp_send_json_error( array( 'message' => 'Товар не найден.' ) );
	}
	if ( $product_obj->is_type( 'variable' ) ) {
		// Конструктор кладёт товар без variation_id, и WC такой заказ
		// отклоняет. Изделие выбирается опциями конструктора, а не
		// вариациями, поэтому товар должен быть простым.
		wp_send_json_error( array(
			'message' => 'Товар настроен как вариативный. Для конструктора нужен простой товар — параметры изделия задаются в нём самом.',
		) );
	}
	if ( ! $product_obj->is_purchasable() ) {
		wp_send_json_error( array( 'message' => 'Товар недоступен для покупки: проверьте цену и статус публикации.' ) );
	}
	if ( ! $product_obj->is_in_stock() ) {
		wp_send_json_error( array( 'message' => 'Товара нет в наличии.' ) );
	}

	if ( function_exists( 'wc_clear_notices' ) ) {
		wc_clear_notices();
	}

	$added = WC()->cart->add_to_cart( $product_id, 1, 0, array(), $cart_item_data );

	// Причину отказа забираем из уведомлений ДО очистки: без неё
	// пользователь и разработчик видят только «попробуйте ещё раз».
	$reason = '';
	if ( ! $added && function_exists( 'wc_get_notices' ) ) {
		foreach ( wc_get_notices( 'error' ) as $notice ) {
			$text = is_array( $notice ) ? ( $notice['notice'] ?? '' ) : $notice;
			$text = trim( wp_strip_all_tags( (string) $text ) );
			if ( $text ) {
				$reason = $text;
				break;
			}
		}
	}

	if ( function_exists( 'wc_clear_notices' ) ) {
		wc_clear_notices();
	}

	if ( ! $added ) {
		wp_send_json_error( array(
			'message' => $reason ?: 'Не удалось добавить в корзину. Попробуйте ещё раз.',
		) );
	}

	WC()->cart->calculate_totals();
	if ( WC()->session ) {
		WC()->session->save_data();
	}

	wp_send_json_success( array(
		'cart_count' => WC()->cart->get_cart_contents_count(),
		'cart_url'   => wc_get_cart_url(),
	) );
}

/**
 * Проставить плотность в заголовок JFIF, чтобы файл открывался в
 * Photoshop в физическом размере печати (30 × 44 см), а не 72 ppi.
 * Пиксели не трогаются — меняются 5 байт заголовка.
 *
 * @param string $bin JPEG.
 * @param int    $ppi Плотность.
 * @return string
 */
function podval_tpl_jpeg_set_ppi( $bin, $ppi ) {
	$ppi = max( 1, min( 65535, (int) $ppi ) );
	if ( strlen( $bin ) < 20 || "\xFF\xD8" !== substr( $bin, 0, 2 ) ) {
		return $bin;
	}
	$dens = chr( 1 ) . pack( 'n', $ppi ) . pack( 'n', $ppi ); // units=dpi, X, Y

	// Есть APP0/JFIF — правим поля плотности на месте.
	if ( "\xFF\xE0" === substr( $bin, 2, 2 ) && "JFIF\0" === substr( $bin, 6, 5 ) ) {
		return substr( $bin, 0, 13 ) . $dens . substr( $bin, 18 );
	}

	// Нет — вставляем свой APP0 сразу после SOI.
	$app0 = "\xFF\xE0" . pack( 'n', 16 ) . "JFIF\0" . "\x01\x01" . $dens . "\x00\x00";
	return "\xFF\xD8" . $app0 . substr( $bin, 2 );
}

/**
 * Проставить плотность в PNG (чанк pHYs) — то же, что JFIF у JPEG
 *. Существующий pHYs заменяется, иначе вставляется сразу
 * после IHDR. Пиксели не трогаются.
 *
 * @param string $bin PNG.
 * @param int    $ppi Плотность.
 * @return string
 */
function podval_tpl_png_set_ppi( $bin, $ppi ) {
	$ppi = max( 1, min( 65535, (int) $ppi ) );
	if ( strlen( $bin ) < 33 || "\x89PNG\r\n\x1a\n" !== substr( $bin, 0, 8 ) ) {
		return $bin;
	}
	$ppm   = (int) round( $ppi / 0.0254 );
	$data  = pack( 'NN', $ppm, $ppm ) . "\x01"; // единица — метр
	$chunk = pack( 'N', 9 ) . 'pHYs' . $data . pack( 'N', crc32( 'pHYs' . $data ) );

	// Обход чанков: убираем старый pHYs, новый — после IHDR.
	$out = substr( $bin, 0, 8 );
	$pos = 8;
	$len = strlen( $bin );
	while ( $pos + 12 <= $len ) {
		$clen = unpack( 'N', substr( $bin, $pos, 4 ) )[1];
		$type = substr( $bin, $pos + 4, 4 );
		$size = 12 + $clen;
		if ( $pos + $size > $len ) {
			return $bin; // битый файл — не трогаем
		}
		if ( 'pHYs' !== $type ) {
			$out .= substr( $bin, $pos, $size );
		}
		if ( 'IHDR' === $type ) {
			$out .= $chunk;
		}
		if ( 'IEND' === $type ) {
			return $out;
		}
		$pos += $size;
	}
	return $bin;
}

/**
 * Печатный файл макета: в размере печати, 300 ppi.
 *
 * основной формат — PNG с прозрачным фоном; JPEG на фоне
 * футболки приходит, только если PNG не влез в лимит загрузки.
 *
 * Приходит обычной загрузкой файла (Blob), не data URL. Валидация —
 * реальный JPEG по getimagesize, лимит 30 МБ. Помечается
 * `_podval_print_upload` (байпас EWWW — пережатие убило бы смысл файла)
 * и `_podval_tpl_print`, но НЕ `_podval_placement`: это не схема.
 *
 * @param array $file Элемент $_FILES.
 * @param int   $ppi  Фактическая плотность, посчитанная клиентом.
 * @param array $data Нормализованный payload.
 * @return int Attachment ID либо 0.
 */
function podval_tpl_store_print_file( $file, $ppi, $data ) {
	if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
		return 0;
	}
	if ( ! empty( $file['error'] ) || (int) $file['size'] < 1024 || (int) $file['size'] > 30 * 1024 * 1024 ) {
		return 0;
	}

	$info = @getimagesize( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( ! $info || ! in_array( $info[2], array( IMAGETYPE_JPEG, IMAGETYPE_PNG ), true ) ) {
		return 0;
	}
	$is_png = IMAGETYPE_PNG === $info[2];

	$binary = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( false === $binary ) {
		return 0;
	}
	$ppi    = ( $ppi >= 72 && $ppi <= 600 ) ? $ppi : 300;
	$binary = $is_png ? podval_tpl_png_set_ppi( $binary, $ppi ) : podval_tpl_jpeg_set_ppi( $binary, $ppi );

	$upload = wp_upload_dir();
	$subdir = '/customer-prints/' . gmdate( 'Y' ) . '/' . gmdate( 'm' );
	$dir    = $upload['basedir'] . $subdir;
	if ( ! file_exists( $dir ) ) {
		wp_mkdir_p( $dir );
	}

	$name = 'cp-tpl-' . sanitize_key( $data['tpl_id'] ) . '-print-' . gmdate( 'Ymd-His' ) . '-' . wp_rand( 100, 999 ) . ( $is_png ? '.png' : '.jpg' );
	$path = $dir . '/' . $name;
	if ( false === file_put_contents( $path, $binary ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
		return 0;
	}
	chmod( $path, 0644 );

	$att_id = wp_insert_post( array(
		'post_type'      => 'attachment',
		'post_mime_type' => $is_png ? 'image/png' : 'image/jpeg',
		'post_title'     => preg_replace( '/\.[^.]+$/', '', $name ),
		'post_status'    => 'inherit',
		'guid'           => $upload['baseurl'] . $subdir . '/' . $name,
	), true );
	if ( is_wp_error( $att_id ) ) {
		@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return 0;
	}

	$rel = ltrim( $subdir . '/' . $name, '/' );
	update_post_meta( $att_id, '_wp_attached_file', $rel );
	update_post_meta( $att_id, '_wp_attachment_metadata', array(
		'file'     => $rel,
		'filesize' => filesize( $path ),
		'width'    => (int) $info[0],
		'height'   => (int) $info[1],
	) );

	update_post_meta( $att_id, '_podval_print_upload', '1' );
	update_post_meta( $att_id, '_podval_tpl_print', '1' );
	update_post_meta( $att_id, '_podval_tpl_ppi', $ppi );

	return (int) $att_id;
}

/**
 * Сохранение клиентского превью-макета как attachment в customer-prints/.
 * Помечается `_podval_placement` — так его уже умеют показывать админка
 * и Telegram-плагин (блок «Расположение»).
 *
 * @param string $data_url data:image/jpeg;base64,…
 * @param array  $data     Нормализованный payload.
 * @return int Attachment ID либо 0.
 */
function podval_tpl_store_preview( $data_url, $data ) {
	if ( ! preg_match( '#^data:image/(jpe?g|png);base64,#i', $data_url, $m ) ) {
		return 0;
	}

	$ext    = ( stripos( $m[1], 'png' ) === 0 ) ? 'png' : 'jpg';
	$base64 = substr( $data_url, strpos( $data_url, ',' ) + 1 );
	$binary = base64_decode( $base64, true );

	if ( false === $binary || strlen( $binary ) < 512 || strlen( $binary ) > 8 * 1024 * 1024 ) {
		return 0;
	}

	$upload  = wp_upload_dir();
	$subdir  = '/customer-prints/' . gmdate( 'Y' ) . '/' . gmdate( 'm' );
	$dir     = $upload['basedir'] . $subdir;

	if ( ! file_exists( $dir ) ) {
		wp_mkdir_p( $dir );
	}

	$name = 'cp-tpl-' . sanitize_key( $data['tpl_id'] ) . '-' . gmdate( 'Ymd-His' ) . '-' . wp_rand( 100, 999 ) . '.' . $ext;
	$path = $dir . '/' . $name;

	if ( false === file_put_contents( $path, $binary ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
		return 0;
	}
	chmod( $path, 0644 );

	$att_id = wp_insert_post( array(
		'post_type'      => 'attachment',
		'post_mime_type' => ( 'png' === $ext ) ? 'image/png' : 'image/jpeg',
		'post_title'     => preg_replace( '/\.[^.]+$/', '', $name ),
		'post_status'    => 'inherit',
		'guid'           => $upload['baseurl'] . $subdir . '/' . $name,
	), true );

	if ( is_wp_error( $att_id ) ) {
		@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return 0;
	}

	$rel = ltrim( $subdir . '/' . $name, '/' );
	update_post_meta( $att_id, '_wp_attached_file', $rel );

	$meta = array( 'file' => $rel, 'filesize' => filesize( $path ) );
	$size = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( $size ) {
		$meta['width']  = $size[0];
		$meta['height'] = $size[1];
	}
	update_post_meta( $att_id, '_wp_attachment_metadata', $meta );

	// Ключевые меты: EWWW-байпас + распознавание как схемы расположения.
	update_post_meta( $att_id, '_podval_print_upload', '1' );
	update_post_meta( $att_id, '_podval_placement', '1' );
	update_post_meta( $att_id, '_podval_tpl_preview', '1' );

	return (int) $att_id;
}

/* ═══════════════════════════════════════════
   5. КОРЗИНА — ПЕРЕНОС ЧЕРЕЗ СЕССИЮ, ЦЕНА, ВЫВОД
   ═══════════════════════════════════════════ */

add_filter( 'woocommerce_get_cart_item_from_session', function( $cart_item, $values ) {
	if ( isset( $values['podval_tpl'] ) ) {
		$cart_item['podval_tpl'] = $values['podval_tpl'];
	}
	return $cart_item;
}, 10, 2 );

/**
 * Надбавки — пересчёт цены позиции.
 * Приоритет 20: WC_Cart_Session уже восстановил корзину.
 */
add_action( 'woocommerce_before_calculate_totals', function( $cart ) {
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
		return;
	}
	if ( did_action( 'woocommerce_before_calculate_totals' ) >= 2 ) {
		return;
	}

	foreach ( $cart->get_cart() as $cart_item ) {
		if ( empty( $cart_item['podval_tpl'] ) || empty( $cart_item['data'] ) ) {
			continue;
		}

		$data   = $cart_item['podval_tpl'];
		$addons = ! empty( $data['addons_resolved'] )
			? $data['addons_resolved']
			: podval_tpl_calc_addons( $data );

		$extra = 0.0;
		foreach ( $addons as $addon ) {
			$extra += (float) $addon['amount'];
		}

		if ( $extra > 0 ) {
			$base = (float) $cart_item['data']->get_price( 'edit' );
			$cart_item['data']->set_price( $base + $extra );
		}
	}
}, 20 );

/**
 * Отображение в корзине / мини-корзине / чекауте.
 */
add_filter( 'woocommerce_get_item_data', function( $item_data, $cart_item ) {
	if ( empty( $cart_item['podval_tpl'] ) ) {
		return $item_data;
	}

	$data = $cart_item['podval_tpl'];

	foreach ( podval_tpl_summary( $data ) as $row ) {
		$value = $row['value'];
		if ( mb_strlen( $value, 'UTF-8' ) > 60 ) {
			$value = mb_substr( $value, 0, 58, 'UTF-8' ) . '…';
		}
		$item_data[] = array( 'key' => $row['label'], 'value' => $value );
	}

	$addons = ! empty( $data['addons_resolved'] ) ? $data['addons_resolved'] : podval_tpl_calc_addons( $data );
	foreach ( $addons as $addon ) {
		$item_data[] = array(
			'key'   => $addon['title'],
			'value' => '+' . number_format( (float) $addon['amount'], 2, ',', ' ' ) . ' Br',
		);
	}

	if ( ! empty( $data['preview_id'] ) ) {
		$item_data[] = array( 'key' => 'Макет', 'value' => 'превью приложено ✓' );
	}

	return $item_data;
}, 10, 2 );

/* ═══════════════════════════════════════════
   6. ЗАКАЗ
   ═══════════════════════════════════════════ */

add_action( 'woocommerce_checkout_create_order_line_item', function( $item, $cart_item_key, $values, $order ) {
	if ( empty( $values['podval_tpl'] ) ) {
		// Шаблонный товар без данных конструктора — аномалия, а не обычная
		// позиция. Два слоя защиты выше это уже не пропускают, но если
		// появится третий обход, он должен быть виден в админке, а не
		// всплыть у печатника с непечатаемым заказом.
		if ( podval_tpl_product_group( $item->get_product_id() ) ) {
			$item->add_meta_data( '_podval_tpl_missing', '1', true );
			$order->update_meta_data( '_podval_tpl_missing', '1' );
		}
		return;
	}

	$data = $values['podval_tpl'];

	$item->add_meta_data( '_podval_tpl', $data, true );

	// Отдельная мета: заказ ждёт фото от клиента. Читается в списке заказов
	// и доступна плагину Telegram без разбора всего payload.
	if ( ! empty( $data['photos_later'] ) ) {
		$item->add_meta_data( '_podval_tpl_photos_later', '1', true );
		$order->update_meta_data( '_podval_awaiting_photos', '1' );
	}

	// Собираем ID всех вложений позиции.
	$file_ids = array();
	foreach ( (array) ( $data['slots'] ?? array() ) as $slot ) {
		if ( ! empty( $slot['attachment_id'] ) ) {
			$file_ids[] = (int) $slot['attachment_id'];
		}
	}
	if ( ! empty( $data['preview_id'] ) ) {
		$file_ids[] = (int) $data['preview_id'];
	}
	$file_ids = array_values( array_unique( array_filter( $file_ids ) ) );

	// Печатный файл — своей метой, не в _artwork_extra_files: тот список
	// читает Telegram-плагин, а файл ~5000 px по стороне Telegram как фото
	// не примет. В _podval_print_file_ids (cleanup) и родителя — добавляем.
	$print_id = (int) ( $data['print_id'] ?? 0 );
	if ( $print_id ) {
		$item->add_meta_data( '_podval_tpl_print_file', $print_id, true );
		wp_update_post( array( 'ID' => $print_id, 'post_parent' => $order->get_id() ) );
		$ids = $order->get_meta( '_podval_print_file_ids' );
		$ids = is_array( $ids ) ? $ids : array();
		if ( ! in_array( $print_id, $ids, true ) ) {
			$ids[] = $print_id;
			$order->update_meta_data( '_podval_print_file_ids', $ids );
		}
	}

	if ( ! $file_ids ) {
		return;
	}

	// Совместимость с существующим пайплайном: плагин Telegram и админка
	// читают _artwork_extra_files, а podval-print-cleanup — _podval_print_file_ids.
	$item->add_meta_data( '_artwork_extra_files', $file_ids, true );

	$existing = $order->get_meta( '_podval_print_file_ids' );
	if ( ! is_array( $existing ) ) {
		$existing = array();
	}
	foreach ( $file_ids as $fid ) {
		if ( ! in_array( $fid, $existing, true ) ) {
			$existing[] = $fid;
		}
		wp_update_post( array( 'ID' => $fid, 'post_parent' => $order->get_id() ) );
	}
	$order->update_meta_data( '_podval_print_file_ids', $existing );
}, 10, 4 );

/**
 * WooCommerce перетирает post_parent вложений при создании позиции.
 * Восстанавливаем после сохранения заказа — иначе cleanup потеряет файлы.
 */
add_action( 'woocommerce_checkout_order_created', function( $order ) {
	$ids = $order->get_meta( '_podval_print_file_ids' );
	if ( ! is_array( $ids ) ) {
		return;
	}
	foreach ( $ids as $fid ) {
		$fid = (int) $fid;
		if ( ! $fid ) {
			continue;
		}
		if ( (int) get_post_field( 'post_parent', $fid ) !== $order->get_id() ) {
			wp_update_post( array( 'ID' => $fid, 'post_parent' => $order->get_id() ) );
		}
	}
}, 20 );

/* ═══════════════════════════════════════════
   7. АДМИНКА ЗАКАЗА
   ═══════════════════════════════════════════ */

add_action( 'woocommerce_after_order_itemmeta', function( $item_id, $item, $product ) {
	if ( ! is_admin() || ! ( $item instanceof WC_Order_Item_Product ) ) {
		return;
	}

	if ( $item->get_meta( '_podval_tpl_missing' ) ) {
		echo '<div style="margin-top:10px;padding:8px;border-left:3px solid #d63638;background:#fcf0f1;font-size:12px">';
		echo '<strong>⚠ Данные конструктора отсутствуют.</strong> Позиция попала в корзину минуя конструктор — макет, размер и фото нужно согласовать с клиентом вручную.';
		echo '</div>';
	}

	$data = $item->get_meta( '_podval_tpl' );
	if ( empty( $data ) || ! is_array( $data ) ) {
		return;
	}

	echo '<div style="margin-top:10px;padding:8px;border-left:3px solid #ff6a00;background:#fafafa">';
	echo '<div style="font-weight:600;margin-bottom:6px">🎨 Конструктор шаблона</div>';

	if ( ! empty( $data['photos_later'] ) ) {
		$have = count( (array) ( $data['slots'] ?? array() ) );
		$need = (int) ( $data['slots_needed'] ?? 0 );
		echo '<div style="margin:0 0 8px;padding:6px 9px;background:#fff3cd;border:1px solid #ffe08a;border-radius:3px;font-size:12px">';
		echo '<strong>⚠ Клиент пришлёт фото позже.</strong> Загружено ' . (int) $have . ' из ' . $need . ' — запросить недостающие в Telegram.';
		echo '</div>';
	}

	foreach ( podval_tpl_summary( $data ) as $row ) {
		echo '<div style="font-size:12px;margin:2px 0"><strong>' . esc_html( $row['label'] ) . ':</strong> ' . esc_html( $row['value'] ) . '</div>';
	}

	$addons = ! empty( $data['addons_resolved'] ) ? $data['addons_resolved'] : podval_tpl_calc_addons( $data );
	if ( $addons ) {
		echo '<div style="font-size:12px;margin-top:4px"><strong>Надбавки:</strong> ';
		$parts = array();
		foreach ( $addons as $addon ) {
			$parts[] = esc_html( $addon['title'] ) . ' +' . number_format( (float) $addon['amount'], 2, ',', ' ' ) . ' Br';
		}
		echo implode( ', ', $parts ) . '</div>';
	}

	if ( ! empty( $data['preview_id'] ) ) {
		$url = wp_get_attachment_url( (int) $data['preview_id'] );
		if ( $url ) {
			// Крупнее, чем миниатюры слотов: с этой картинки печатник снимает
			// компоновку, поэтому она в блоке главная. Полный размер — по клику.
			echo '<div style="margin-top:10px"><strong style="font-size:12px">Собранный макет (схема для переноса):</strong><br>';
			echo '<a href="' . esc_url( $url ) . '" target="_blank">';
			echo '<img src="' . esc_url( $url ) . '" style="max-width:300px;height:auto;border:1px solid #ddd;margin-top:4px;background:#111"></a>';
			echo '<div style="font-size:11px;color:#666;margin-top:3px">Пропорции соответствуют превью в конструкторе. Тексты и разбивка — как выбрал клиент.</div></div>';
		}
	}

	// Печатный файл 300 ppi. Читаем мету позиции: после
	// переименования заказа там ID копии с номером заказа.
	$print_id = (int) $item->get_meta( '_podval_tpl_print_file' );
	if ( ! $print_id ) {
		$print_id = (int) ( $data['print_id'] ?? 0 );
	}
	if ( $print_id ) {
		$p_url = wp_get_attachment_url( $print_id );
		if ( $p_url ) {
			$p_meta = wp_get_attachment_metadata( $print_id );
			$p_ppi  = (int) get_post_meta( $print_id, '_podval_tpl_ppi', true );
			$p_dims = '';
			if ( ! empty( $p_meta['width'] ) && $p_ppi ) {
				$p_dims = sprintf(
					'%d × %d px · %.1f × %.1f см · %d ppi',
					(int) $p_meta['width'],
					(int) $p_meta['height'],
					$p_meta['width'] / $p_ppi * 2.54,
					$p_meta['height'] / $p_ppi * 2.54,
					$p_ppi
				);
			}
			echo '<div style="margin-top:8px;font-size:12px"><strong>Печатный файл:</strong> ';
			$p_fmt = ( 'image/png' === get_post_mime_type( $print_id ) ) ? 'PNG, прозрачный фон' : 'JPEG';
			echo '<a href="' . esc_url( $p_url ) . '" target="_blank" download>скачать ' . esc_html( $p_fmt ) . '</a>';
			if ( $p_dims ) {
				echo ' <span style="color:#666">(' . esc_html( $p_dims ) . ')</span>';
			}
			echo '</div>';
		}
	}

	if ( ! empty( $data['slots'] ) && is_array( $data['slots'] ) ) {
		echo '<div style="margin-top:8px"><strong style="font-size:12px">Фото по слотам:</strong><br>';
		foreach ( $data['slots'] as $i => $slot ) {
			$fid = (int) ( $slot['attachment_id'] ?? 0 );
			$url = $fid ? wp_get_attachment_url( $fid ) : '';
			if ( ! $url ) {
				continue;
			}
			echo '<a href="' . esc_url( $url ) . '" target="_blank" style="display:inline-block;margin:3px 3px 0 0" title="' . esc_attr( $slot['key'] ?? ( $i + 1 ) ) . '">';
			echo '<img src="' . esc_url( $url ) . '" style="width:64px;height:64px;object-fit:cover;border:1px solid #ddd"></a>';
		}
		echo '</div>';
	}

	echo '</div>';
}, 20, 3 );

/* ═══════════════════════════════════════════
   8. ИМЕНА ФАЙЛОВ ПРИ СОХРАНЕНИИ ЗАКАЗА
   ═══════════════════════════════════════════ */

/**
 * Токен шаблона для схемы переименования print-filenames.php.
 * Пример: 205-BLK-OVR-M-TPLLET-01.jpg
 *
 * @param array $data Нормализованный payload.
 * @return string
 */
function podval_tpl_filename_token( $data ) {
	$map = apply_filters( 'podval_tpl_filename_tokens', array(
		'dad_letters'   => 'TPLLET',
		'dad_poster'    => 'TPLPST',
		'dad_single'    => 'TPLSGL',
		'dad_papa'      => 'TPLPAP',
		'mom_letters'   => 'TPLLET',
		'mom_poster'    => 'TPLPST',
		'mom_single'    => 'TPLSGL',
		'mom_mama'      => 'TPLMAM',
		'polaroid_pair' => 'TPLPLR',
		'only_you'      => 'TPLONL',
		'girlfriend'    => 'TPLGRL',
		'boyfriend'     => 'TPLBOY',
	) );
	$id = $data['tpl_id'] ?? '';
	return isset( $map[ $id ] ) ? $map[ $id ] : 'TPL';
}

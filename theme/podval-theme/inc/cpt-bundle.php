<?php
/**
 * CPT: podval_bundle
 * ──────────────────────────────────────────────────────────────────────
 * Регистрация CPT «Бандлы» + таксономии «Темы бандлов» + admin meta-box'ы.
 * Backend-only в прежней версии. Фронт-миграция шаблонов — прежней версии.
 *
 * Хранимая meta-структура:
 *   _bundle_subtitle           string   подзаголовок (опц., иначе автоген)
 *   _bundle_status             enum     '' | 'new' | 'hot' | 'limited'
 *   _bundle_featured           bool     показывать в верхней featured-сетке
 *   _bundle_featured_size      enum     'tall' | 'wide' | 'small'
 *   _bundle_shirts             json     [{product_id, default_color, default_size, default_type}]
 *   _bundle_discount_scale     json     [0,5,10,15,20]  (индекс = count-1)
 *   _bundle_discount_type      enum     'percent' | 'fixed'
 *   _bundle_use_default_scale  bool     если 1 — _bundle_discount_scale игнорируется
 *   _bundle_badges             json     [{type, label}, ...] (max 3)
 *
 * Также используется штатное:
 *   post_title           — название бандла
 *   post_content         — описание (info-card на single)
 *   post_name            — slug → URL /bundles/<slug>/
 *   menu_order           — сортировка на /bundles/
 *   _thumbnail_id        — обложка карточки/single (опц.)
 *   bundle_theme taxonomy — категория для фильтра pill'ами
 *
 * @package podval-print
 *
 */
defined( 'ABSPATH' ) || exit;

/* =====================================================================
   1. РЕГИСТРАЦИЯ CPT + ТАКСОНОМИИ
   ===================================================================== */
add_action( 'init', 'podval_bundle_register' );
function podval_bundle_register() {

	register_post_type( 'podval_bundle', array(
		'labels' => array(
			'name'                  => 'Бандлы',
			'singular_name'         => 'Бандл',
			'add_new'               => 'Добавить бандл',
			'add_new_item'          => 'Новый бандл',
			'edit_item'             => 'Редактировать бандл',
			'new_item'              => 'Новый бандл',
			'view_item'             => 'Посмотреть бандл',
			'all_items'             => 'Все бандлы',
			'search_items'          => 'Поиск бандлов',
			'not_found'             => 'Бандлы не найдены',
			'not_found_in_trash'    => 'В корзине бандлов нет',
			'menu_name'             => 'Бандлы',
			'featured_image'        => 'Обложка бандла',
			'set_featured_image'    => 'Задать обложку',
			'remove_featured_image' => 'Убрать обложку',
		),
		'public'              => true,
		'show_in_rest'        => false,
		'menu_position'       => 28,
		'menu_icon'           => 'dashicons-products',
		'supports'            => array( 'title', 'editor', 'thumbnail', 'page-attributes' ),
		'has_archive'         => false,
		'rewrite'             => array( 'slug' => podval_route_slug( 'bundles' ), 'with_front' => false ),
		'publicly_queryable'  => true,
		'capability_type'     => 'post',
	) );

	register_taxonomy( 'bundle_theme', 'podval_bundle', array(
		'labels' => array(
			'name'              => 'Темы бандлов',
			'singular_name'     => 'Тема',
			'add_new_item'      => 'Новая тема',
			'edit_item'         => 'Редактировать тему',
			'all_items'         => 'Все темы',
			'search_items'      => 'Поиск тем',
			'menu_name'         => 'Темы',
		),
		'public'            => true,
		'hierarchical'      => false,
		'show_admin_column' => true,
		'show_in_rest'      => false,
		'rewrite'           => array( 'slug' => 'bundle-theme', 'with_front' => false ),
	) );
}

/* =====================================================================
   2. ONE-SHOT FLUSH REWRITE RULES
   ─────────────────────────────────────────────────────────────────────
   После регистрации нового CPT нужно flush rewrite чтобы /bundles/<slug>/
   заработал. Делаем один раз, без необходимости заходить в Постоянные
   ссылки → Сохранить.
   ===================================================================== */
add_action( 'init', function() {
	if ( get_option( 'podval_bundle_rewrite_flushed_v1' ) ) {
		return;
	}
	flush_rewrite_rules( false );
	update_option( 'podval_bundle_rewrite_flushed_v1', 1, false );
}, 99 );

/* =====================================================================
   3. ХЕЛПЕРЫ
   ===================================================================== */

/**
 * Дефолтная шкала скидок. Подключается фильтром, чтобы можно было
 * переопределить из functions.php / плагина без правки темы.
 *
 * @return array  [0,5,10,15,20] — индекс N даёт скидку при (N+1) футболках
 */
function podval_bundle_default_discount_scale() {
	return apply_filters( 'podval_bundle_default_discount_scale', array( 0, 5, 10, 15, 20 ) );
}

/**
 * Получить состав бандла (декодированный массив shirts).
 * Поддерживает оба формата хранения:
 *   - native array — приходит из get_post_meta() как массив сразу
 *   - JSON-string (legacy 2.5.1) — для обратной совместимости со
 *     старыми записями. При следующем сохранении автоматически
 *     переедет в native array.
 *
 * @param int $post_id
 * @return array
 */
function podval_bundle_get_shirts( $post_id ) {
	$raw = get_post_meta( $post_id, '_bundle_shirts', true );
	if ( is_array( $raw ) ) {
		return $raw;
	}
	if ( is_string( $raw ) && $raw !== '' ) {
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}
	}
	return array();
}

/**
 * Шкала скидок для конкретного бандла. Учитывает чекбокс
 * «Использовать дефолтную шкалу».
 *
 * @param int $post_id
 * @return array  [0,5,10,15,20]
 */
function podval_bundle_get_discount_scale( $post_id ) {
	if ( (int) get_post_meta( $post_id, '_bundle_use_default_scale', true ) === 1 ) {
		return podval_bundle_default_discount_scale();
	}
	$raw   = get_post_meta( $post_id, '_bundle_discount_scale', true );
	$scale = array();
	if ( is_array( $raw ) ) {
		$scale = $raw;
	} elseif ( is_string( $raw ) && $raw !== '' ) {
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			$scale = $decoded;
		}
	}
	if ( empty( $scale ) ) {
		return podval_bundle_default_discount_scale();
	}
	// Нормализуем длину до 5. Верхняя граница зависит от типа скидки:
	// проценты 0..100, фиксированная сумма 0..99999 (BYN).
	$max   = ( podval_bundle_get_discount_type( $post_id ) === 'fixed' ) ? 99999 : 100;
	$scale = array_slice( array_pad( $scale, 5, 0 ), 0, 5 );
	return array_map( function( $v ) use ( $max ) {
		return max( 0, min( $max, (int) $v ) );
	}, $scale );
}

/**
 * Тип скидки бандла.
 *
 * 'percent' — значения шкалы трактуются как проценты (legacy-поведение,
 *             дефолт для всех записей, созданных до прежней версии).
 * 'fixed'   — значения шкалы трактуются как сумма в BYN, которая
 *             вычитается из суммы футболок.
 *
 * @param int $post_id
 * @return string 'percent'|'fixed'
 */
function podval_bundle_get_discount_type( $post_id ) {
	$type = (string) get_post_meta( $post_id, '_bundle_discount_type', true );
	return ( $type === 'fixed' ) ? 'fixed' : 'percent';
}

/**
 * ЕДИНАЯ точка расчёта скидки.
 *
 * Все места, где считается цена бандла (single-шаблон, корзина, листинг,
 * похожие бандлы), обязаны ходить сюда, а не умножать на (100-pct)/100
 * вручную — иначе тип 'fixed' разъедется между витриной и корзиной.
 *
 * Для 'fixed' сумма клампится по subtotal: скидка не может увести
 * итог в минус. Эффективный процент считается обратно — чтобы старая
 * вёрстка, которая умеет рисовать только «−N%», продолжала работать.
 *
 * @param int   $bundle_id
 * @param float $subtotal  Сумма футболок без скидки.
 * @param int   $count     Кол-во активных футболок (не исключённых).
 * @return array{type:string,raw:int,amount:float,percent:float,total:float}
 */
function podval_bundle_resolve_discount( $bundle_id, $subtotal, $count ) {
	$subtotal = max( 0.0, (float) $subtotal );
	$count    = (int) $count;
	$type     = podval_bundle_get_discount_type( $bundle_id );
	$scale    = podval_bundle_get_discount_scale( $bundle_id );

	$raw = 0;
	if ( $count > 0 ) {
		$idx = min( $count, count( $scale ) ) - 1;
		$raw = isset( $scale[ $idx ] ) ? (int) $scale[ $idx ] : 0;
	}

	if ( $type === 'fixed' ) {
		$amount = min( (float) $raw, $subtotal );
	} else {
		$amount = $subtotal * max( 0, min( 100, $raw ) ) / 100;
	}

	$amount  = round( $amount, 2 );
	$total   = round( $subtotal - $amount, 2 );
	$percent = ( $subtotal > 0 ) ? round( $amount / $subtotal * 100, 2 ) : 0.0;

	return array(
		'type'    => $type,
		'raw'     => $raw,
		'amount'  => $amount,
		'percent' => $percent,
		'total'   => $total,
	);
}

/**
 * Человекочитаемая подпись скидки: «−15%» либо «−20 Br».
 *
 * @param array $resolved Результат podval_bundle_resolve_discount().
 * @param bool  $with_span Обернуть сумму в span[data-price-byn] для currency.js.
 * @return string
 */
function podval_bundle_format_discount( $resolved, $with_span = false ) {
	if ( ( $resolved['type'] ?? 'percent' ) === 'fixed' ) {
		$amount = (float) ( $resolved['amount'] ?? 0 );
		$txt    = number_format( $amount, 2, ',', ' ' ) . '&nbsp;Br';
		if ( $with_span ) {
			return '−<span data-price-byn="' . esc_attr( $amount ) . '">' . $txt . '</span>';
		}
		return '−' . $txt;
	}
	return '−' . (int) round( (float) ( $resolved['percent'] ?? 0 ) ) . '%';
}

/**
 * Получить бейджи бандла. Backward-compat для array/JSON storage.
 *
 * @param int $post_id
 * @return array  [{type, label}, ...]
 */
function podval_bundle_get_badges( $post_id ) {
	$raw = get_post_meta( $post_id, '_bundle_badges', true );
	if ( is_array( $raw ) ) {
		return $raw;
	}
	if ( is_string( $raw ) && $raw !== '' ) {
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}
	}
	return array();
}

/* =====================================================================
   4. META BOX'Ы (РЕГИСТРАЦИЯ)
   ===================================================================== */
add_action( 'add_meta_boxes', function() {
	add_meta_box( 'podval_bundle_main',     'Основные настройки', 'podval_bundle_mb_main',     'podval_bundle', 'normal', 'high' );
	add_meta_box( 'podval_bundle_shirts',   'Состав бандла',       'podval_bundle_mb_shirts',   'podval_bundle', 'normal', 'high' );
	add_meta_box( 'podval_bundle_discount', 'Шкала скидок',        'podval_bundle_mb_discount', 'podval_bundle', 'normal', 'default' );
	add_meta_box( 'podval_bundle_badges',   'Бейджи',              'podval_bundle_mb_badges',   'podval_bundle', 'normal', 'low' );
} );

/* ── 4.1 — Основные настройки ────────────────────────────────────── */
function podval_bundle_mb_main( $post ) {
	wp_nonce_field( 'podval_bundle_save', 'podval_bundle_nonce' );

	$subtitle      = (string) get_post_meta( $post->ID, '_bundle_subtitle', true );
	$status        = (string) get_post_meta( $post->ID, '_bundle_status', true );
	$featured      = (int)    get_post_meta( $post->ID, '_bundle_featured', true );
	$featured_size = (string) get_post_meta( $post->ID, '_bundle_featured_size', true );
	if ( $featured_size === '' ) {
		$featured_size = 'small';
	}
	?>
	<table class="form-table podval-bundle-main">
		<tr>
			<th><label for="bundle_subtitle">Подзаголовок</label></th>
			<td>
				<input type="text" id="bundle_subtitle" name="_bundle_subtitle" value="<?php echo esc_attr( $subtitle ); ?>" class="regular-text" placeholder="например: 4 футболки в наборе">
				<p class="description">Если оставить пустым — сгенерируется автоматически: «<em>N футболок в наборе</em>».</p>
			</td>
		</tr>
		<tr>
			<th>Статус</th>
			<td>
				<label class="podval-radio"><input type="radio" name="_bundle_status" value=""        <?php checked( $status, '' ); ?>> <span class="podval-status-none">Без статуса</span></label>
				<label class="podval-radio"><input type="radio" name="_bundle_status" value="new"     <?php checked( $status, 'new' ); ?>> <span class="podval-status-pill podval-status-pill--new">NEW</span></label>
				<label class="podval-radio"><input type="radio" name="_bundle_status" value="hot"     <?php checked( $status, 'hot' ); ?>> <span class="podval-status-pill podval-status-pill--hot">HOT</span></label>
				<label class="podval-radio"><input type="radio" name="_bundle_status" value="limited" <?php checked( $status, 'limited' ); ?>> <span class="podval-status-pill podval-status-pill--limited">LIMITED</span></label>
				<p class="description">Один основной статус. Дополнительные пометки (Бестселлер и т.п.) — в блоке «Бейджи» ниже.</p>
			</td>
		</tr>
		<tr>
			<th>Featured</th>
			<td>
				<label>
					<input type="checkbox" id="bundle_featured" name="_bundle_featured" value="1" <?php checked( $featured, 1 ); ?> data-podval-featured-toggle>
					Показать в верхней featured-сетке на <code>/bundles/</code>
				</label>
				<div data-podval-featured-size <?php echo $featured ? '' : 'style="display:none"'; ?>>
					<p style="margin-top:12px"><strong>Размер карточки в featured-сетке:</strong></p>
					<label class="podval-radio"><input type="radio" name="_bundle_featured_size" value="tall"  <?php checked( $featured_size, 'tall' ); ?>> <strong>Tall</strong> (высокая, в два ряда)</label>
					<label class="podval-radio"><input type="radio" name="_bundle_featured_size" value="wide"  <?php checked( $featured_size, 'wide' ); ?>> <strong>Wide</strong> (широкая, на две колонки)</label>
					<label class="podval-radio"><input type="radio" name="_bundle_featured_size" value="small" <?php checked( $featured_size, 'small' ); ?>> <strong>Small</strong> (стандартная)</label>
				</div>
			</td>
		</tr>
	</table>
	<?php
}

/* ── 4.2 — Состав бандла ─────────────────────────────────────────── */
function podval_bundle_mb_shirts( $post ) {
	$shirts = podval_bundle_get_shirts( $post->ID );

	// Подтянем preview-данные для уже сохранённых товаров, чтобы JS показал
	// миниатюры без дополнительного AJAX-запроса при открытии редактора.
	$preview = array();
	if ( function_exists( 'wc_get_product' ) ) {
		foreach ( $shirts as $s ) {
			$pid = isset( $s['product_id'] ) ? (int) $s['product_id'] : 0;
			if ( ! $pid || isset( $preview[ $pid ] ) ) {
				continue;
			}
			$product = wc_get_product( $pid );
			if ( ! $product ) {
				continue;
			}
			$preview[ $pid ] = array(
				'id'    => $pid,
				'title' => get_the_title( $pid ),
				'thumb' => wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ),
				'price' => wp_strip_all_tags( $product->get_price_html() ),
			);
		}
	}
	?>
	<div id="podval-bundle-shirts" class="podval-shirts-wrap"
		data-initial='<?php echo esc_attr( wp_json_encode( $shirts, JSON_UNESCAPED_UNICODE ) ); ?>'
		data-preview='<?php echo esc_attr( wp_json_encode( $preview, JSON_UNESCAPED_UNICODE ) ); ?>'>

		<p class="description podval-shirts-help">
			Каждая строка — одна футболка в бандле. Найдите товар по названию.
			Перетаскивайте строки за иконку <code>⋮⋮</code> для изменения порядка.
			<br>Рекомендуется 3–5 футболок. <strong>Цены, доступные цвета, размеры и типы
			берутся автоматически из настроек товара</strong> (вариации). Дефолтные значения —
			те, что заданы как «default attributes» в карточке товара.
		</p>

		<div class="podval-shirts-list" data-shirts-list>
			<!-- JS наполняет из data-initial -->
		</div>

		<div class="podval-shirts-empty" data-shirts-empty>
			Пока нет ни одной футболки. Добавьте первую кнопкой ниже.
		</div>

		<p>
			<button type="button" class="button button-primary" data-shirts-add>
				➕ Добавить футболку
			</button>
		</p>

		<input type="hidden" name="_bundle_shirts" value="<?php echo esc_attr( wp_json_encode( $shirts, JSON_UNESCAPED_UNICODE ) ); ?>" data-shirts-payload>
	</div>

	<template id="podval-shirt-row-tpl">
		<div class="podval-shirt-row" data-shirt-row draggable="true">
			<div class="podval-shirt-row__handle" title="Перетащить">⋮⋮</div>
			<div class="podval-shirt-row__num">#<span data-shirt-num>1</span></div>

			<div class="podval-shirt-row__product">
				<select data-shirt-product class="podval-shirt-product-select">
					<option></option>
				</select>
				<div class="podval-shirt-row__preview" data-shirt-preview hidden>
					<img data-shirt-preview-img alt="">
					<div class="podval-shirt-row__preview-meta">
						<div data-shirt-preview-title></div>
						<div data-shirt-preview-price class="podval-shirt-row__preview-price"></div>
					</div>
				</div>
			</div>

			<button type="button" class="podval-shirt-row__remove" data-shirt-remove title="Удалить">✕</button>
		</div>
	</template>
	<?php
}

/* ── 4.3 — Шкала скидок ──────────────────────────────────────────── */
function podval_bundle_mb_discount( $post ) {
	$scale       = podval_bundle_get_discount_scale( $post->ID );
	$use_default = (int) get_post_meta( $post->ID, '_bundle_use_default_scale', true );
	$defaults    = podval_bundle_default_discount_scale();
	$type        = podval_bundle_get_discount_type( $post->ID );
	?>
	<p>
		<strong>Тип скидки</strong><br>
		<label style="margin-right:18px">
			<input type="radio" name="_bundle_discount_type" value="percent" <?php checked( $type, 'percent' ); ?> data-discount-type>
			Процент от суммы (%)
		</label>
		<label>
			<input type="radio" name="_bundle_discount_type" value="fixed" <?php checked( $type, 'fixed' ); ?> data-discount-type>
			Фиксированная сумма (Br)
		</label>
	</p>
	<p class="description" data-type-hint-percent<?php echo $type === 'fixed' ? ' style="display:none"' : ''; ?>>
		Скидка считается как процент от суммы футболок. Итог округляется до копеек.
	</p>
	<p class="description" data-type-hint-fixed<?php echo $type === 'percent' ? ' style="display:none"' : ''; ?>>
		Из суммы футболок вычитается указанная сумма в рублях. Если скидка больше суммы набора,
		она автоматически урезается до неё — итог не уходит в минус. На витрине рядом с ценой
		показывается «−N Br» вместо процента.
	</p>
	<hr>
	<p>
		<label>
			<input type="checkbox" id="bundle_use_default_scale" name="_bundle_use_default_scale" value="1" <?php checked( $use_default, 1 ); ?> data-scale-default-toggle>
			<strong>Использовать дефолтную шкалу</strong>
			(<?php echo esc_html( implode( ' / ', $defaults ) ); ?> %)
		</label>
		<br>
		<span class="description" data-default-scale-warn<?php echo $type === 'fixed' ? '' : ' style="display:none"'; ?>>
			⚠ Дефолтная шкала задана в процентах. При типе «Фиксированная сумма» её значения
			будут прочитаны как рубли — снимите галку и задайте суммы вручную.
		</span>
	</p>
	<p class="description">
		Шкала применяется в зависимости от количества футболок, которые клиент оставил в наборе.
		Например, набор из 4 футболок: если клиент исключил одну → применяется ступень «3 футболки».
		<br><strong>Тонкая настройка</strong> позволяет задать индивидуальную шкалу — полезно для бандлов
		«слив неликвида» с агрессивной скидкой или премиум-бандлов с минимальной выгодой.
	</p>

	<table class="form-table podval-bundle-scale" data-scale-table <?php echo $use_default ? 'data-scale-disabled' : ''; ?>>
		<?php for ( $i = 0; $i < 5; $i++ ) :
			$count = $i + 1;
			$label = $count === 1 ? '1 футболка' : ( $count < 5 ? $count . ' футболки' : '5 футболок' );
			$val   = isset( $scale[ $i ] ) ? (int) $scale[ $i ] : (int) ( $defaults[ $i ] ?? 0 );
		?>
			<tr>
				<th><?php echo esc_html( $label ); ?></th>
				<td>
					<input type="number"
						name="_bundle_discount_scale[<?php echo $i; ?>]"
						value="<?php echo esc_attr( $val ); ?>"
						min="0" max="<?php echo $type === 'fixed' ? 99999 : 100; ?>" step="1" class="small-text"
						data-scale-input
						<?php echo $use_default ? 'disabled' : ''; ?>>
					<span class="podval-percent-suffix" data-scale-suffix><?php echo $type === 'fixed' ? 'Br' : '%'; ?></span>
				</td>
			</tr>
		<?php endfor; ?>
	</table>
	<?php
}

/* ── 4.4 — Бейджи ────────────────────────────────────────────────── */
function podval_bundle_mb_badges( $post ) {
	$badges    = podval_bundle_get_badges( $post->ID );
	$json_init = wp_json_encode( $badges, JSON_UNESCAPED_UNICODE );
	?>
	<div id="podval-bundle-badges" class="podval-badges-wrap" data-initial='<?php echo esc_attr( $json_init ); ?>'>
		<p class="description">
			Дополнительные бейджи на карточке бандла, выводятся рядом со статусом.
			Максимум <strong>3 бейджа</strong>. Поле «Тип» определяет CSS-класс (можно использовать
			готовые: <code>bestseller</code>, <code>preorder</code>, <code>sale</code>) — стилизация в теме.
		</p>

		<div class="podval-badges-list" data-badges-list></div>

		<p>
			<button type="button" class="button" data-badges-add>➕ Добавить бейдж</button>
			<span class="podval-badges-counter description"></span>
		</p>

		<input type="hidden" name="_bundle_badges" value="<?php echo esc_attr( $json_init ); ?>" data-badges-payload>
	</div>

	<template id="podval-badge-row-tpl">
		<div class="podval-badge-row" data-badge-row>
			<label>
				<span>Тип</span>
				<input type="text" data-badge-type placeholder="bestseller">
			</label>
			<label>
				<span>Лейбл</span>
				<input type="text" data-badge-label placeholder="Бестселлер">
			</label>
			<button type="button" class="podval-badge-row__remove" data-badge-remove title="Удалить">✕</button>
		</div>
	</template>
	<?php
}

/* =====================================================================
   5. SAVE HANDLER
   ===================================================================== */
add_action( 'save_post_podval_bundle', function( $post_id ) {

	// Безопасность
	if ( ! isset( $_POST['podval_bundle_nonce'] ) || ! wp_verify_nonce( $_POST['podval_bundle_nonce'], 'podval_bundle_save' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	/* ── 5.1 Основные ─────────────────────────────────────────────── */
	update_post_meta( $post_id, '_bundle_subtitle', sanitize_text_field( wp_unslash( $_POST['_bundle_subtitle'] ?? '' ) ) );

	$status = sanitize_text_field( wp_unslash( $_POST['_bundle_status'] ?? '' ) );
	if ( ! in_array( $status, array( '', 'new', 'hot', 'limited' ), true ) ) {
		$status = '';
	}
	update_post_meta( $post_id, '_bundle_status', $status );

	update_post_meta( $post_id, '_bundle_featured', isset( $_POST['_bundle_featured'] ) ? 1 : 0 );

	$featured_size = sanitize_text_field( wp_unslash( $_POST['_bundle_featured_size'] ?? 'small' ) );
	if ( ! in_array( $featured_size, array( 'tall', 'wide', 'small' ), true ) ) {
		$featured_size = 'small';
	}
	update_post_meta( $post_id, '_bundle_featured_size', $featured_size );

	/* ── 5.2 Состав бандла ────────────────────────────────────────── */
	$shirts_raw = isset( $_POST['_bundle_shirts'] ) ? wp_unslash( $_POST['_bundle_shirts'] ) : '';
	$shirts     = json_decode( $shirts_raw, true );
	if ( ! is_array( $shirts ) ) {
		$shirts = array();
	}
	$shirts_clean = array();
	foreach ( $shirts as $s ) {
		$pid = isset( $s['product_id'] ) ? (int) $s['product_id'] : 0;
		if ( $pid <= 0 ) {
			continue;
		}
		$shirts_clean[] = array(
			'product_id' => $pid,
		);
	}
	update_post_meta( $post_id, '_bundle_shirts', $shirts_clean );

	/* ── 5.3 Скидки ───────────────────────────────────────────────── */
	update_post_meta( $post_id, '_bundle_use_default_scale', isset( $_POST['_bundle_use_default_scale'] ) ? 1 : 0 );

	$disc_type = sanitize_text_field( wp_unslash( $_POST['_bundle_discount_type'] ?? 'percent' ) );
	if ( ! in_array( $disc_type, array( 'percent', 'fixed' ), true ) ) {
		$disc_type = 'percent';
	}
	update_post_meta( $post_id, '_bundle_discount_type', $disc_type );

	if ( isset( $_POST['_bundle_discount_scale'] ) && is_array( $_POST['_bundle_discount_scale'] ) ) {
		$scale_max   = ( $disc_type === 'fixed' ) ? 99999 : 100;
		$scale_raw   = array_values( $_POST['_bundle_discount_scale'] );
		$scale_clean = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$scale_clean[ $i ] = isset( $scale_raw[ $i ] ) ? max( 0, min( $scale_max, (int) $scale_raw[ $i ] ) ) : 0;
		}
		update_post_meta( $post_id, '_bundle_discount_scale', $scale_clean );
	}

	/* ── 5.4 Бейджи ───────────────────────────────────────────────── */
	$badges_raw = isset( $_POST['_bundle_badges'] ) ? wp_unslash( $_POST['_bundle_badges'] ) : '';
	$badges     = json_decode( $badges_raw, true );
	if ( ! is_array( $badges ) ) {
		$badges = array();
	}
	$badges_clean = array();
	foreach ( array_slice( $badges, 0, 3 ) as $b ) {
		$type  = sanitize_key( $b['type']  ?? '' );
		$label = sanitize_text_field( $b['label'] ?? '' );
		if ( $type === '' || $label === '' ) {
			continue;
		}
		$badges_clean[] = array( 'type' => $type, 'label' => $label );
	}
	update_post_meta( $post_id, '_bundle_badges', $badges_clean );

	// Сбрасываем кэш темы — на случай, если в будущих версиях фронт начнёт
	// кэшировать список бандлов в transient.
	if ( function_exists( 'podval_flush_all_cache' ) ) {
		podval_flush_all_cache( 'podval_bundle save_post #' . $post_id );
	}
}, 10, 1 );

/* =====================================================================
   6. AJAX: ПОИСК WC-ТОВАРОВ ДЛЯ SELECT2
   ─────────────────────────────────────────────────────────────────────
   Используется в meta-box «Состав бандла» — поиск по названию товара.
   Если WC недоступен — возвращаем пустой результат.
   ===================================================================== */
add_action( 'wp_ajax_podval_bundle_product_search', function() {

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json( array( 'results' => array() ) );
	}
	if ( ! check_ajax_referer( 'podval_bundle_admin', 'nonce', false ) ) {
		wp_send_json( array( 'results' => array() ) );
	}
	if ( ! function_exists( 'wc_get_product' ) ) {
		wp_send_json( array( 'results' => array() ) );
	}

	$q   = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );
	$ids = array();

	// Используем WC product data store — корректно ищет по SKU + title +
	// мета-полям. Лимит 20 результатов для Select2.
	if ( class_exists( 'WC_Data_Store' ) ) {
		try {
			$data_store = WC_Data_Store::load( 'product' );
			$ids        = $data_store->search_products( $q, '', false, false, 20 );
		} catch ( Exception $e ) {
			$ids = array();
		}
	}

	// Fallback: если data_store недоступен — обычный WP_Query
	if ( empty( $ids ) && $q !== '' ) {
		$wp_query = new WP_Query( array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			's'              => $q,
			'posts_per_page' => 20,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );
		$ids = $wp_query->posts;
	}

	$results = array();
	foreach ( $ids as $id ) {
		$id      = (int) $id;
		$product = wc_get_product( $id );
		if ( ! $product || $product->get_status() !== 'publish' ) {
			continue;
		}
		$results[] = array(
			'id'    => $id,
			'text'  => $product->get_name(),
			'thumb' => wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) ?: '',
			'price' => wp_strip_all_tags( $product->get_price_html() ),
		);
	}

	wp_send_json( array( 'results' => $results ) );
} );

/* =====================================================================
   7. ADMIN ENQUEUE (CSS + JS только на экране редактирования бандла)
   ===================================================================== */
add_action( 'admin_enqueue_scripts', function( $hook ) {
	global $post;

	// Только на post.php / post-new.php
	if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
		return;
	}
	// Только для podval_bundle
	if ( ! $post || $post->post_type !== 'podval_bundle' ) {
		return;
	}

	$theme    = wp_get_theme();
	$ver      = $theme->get( 'Version' );
	$base_uri = get_template_directory_uri();

	// Select2 — берём из WC (он точно установлен)
	if ( function_exists( 'WC' ) ) {
		$wc_uri = WC()->plugin_url();
		wp_enqueue_style(  'select2', $wc_uri . '/assets/css/select2.css', array(), WC()->version );
		wp_enqueue_script( 'select2', $wc_uri . '/assets/js/select2/select2.full.min.js', array( 'jquery' ), WC()->version, true );
	}

	wp_enqueue_style(
		'podval-admin-bundle',
		$base_uri . '/assets/css/admin-bundle.css',
		array(),
		$ver
	);

	wp_enqueue_script(
		'podval-admin-bundle',
		$base_uri . '/assets/js/admin-bundle.js',
		array( 'jquery', 'select2' ),
		$ver,
		true
	);

	wp_localize_script( 'podval-admin-bundle', 'PodvalBundleAdmin', array(
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'podval_bundle_admin' ),
		'i18n'    => array(
			'searching'    => 'Поиск товара...',
			'noResults'    => 'Товары не найдены',
			'errorLoading' => 'Ошибка загрузки',
			'placeholder'  => 'Найти товар по названию...',
			'confirmDel'   => 'Удалить эту футболку из бандла?',
			'badgeMax'     => 'Можно добавить максимум 3 бейджа.',
			'badgeCount'   => 'Бейджей: %d / 3',
		),
	) );
} );

/* =====================================================================
   8. КОЛОНКИ В СПИСКЕ БАНДЛОВ В АДМИНКЕ
   ===================================================================== */
add_filter( 'manage_podval_bundle_posts_columns', function( $cols ) {
	$new = array();
	foreach ( $cols as $key => $label ) {
		$new[ $key ] = $label;
		if ( $key === 'title' ) {
			$new['bundle_shirts']  = 'Футболок';
			$new['bundle_status']  = 'Статус';
			$new['bundle_featured']= 'Featured';
		}
	}
	return $new;
} );

add_action( 'manage_podval_bundle_posts_custom_column', function( $col, $post_id ) {
	switch ( $col ) {
		case 'bundle_shirts':
			$shirts = podval_bundle_get_shirts( $post_id );
			echo count( $shirts ) > 0 ? '<strong>' . count( $shirts ) . '</strong>' : '<span style="color:#a00">—</span>';
			break;
		case 'bundle_status':
			$s = get_post_meta( $post_id, '_bundle_status', true );
			if ( $s ) {
				echo '<span class="podval-status-pill podval-status-pill--' . esc_attr( $s ) . '">' . esc_html( strtoupper( $s ) ) . '</span>';
			} else {
				echo '<span style="color:#999">—</span>';
			}
			break;
		case 'bundle_featured':
			$f = (int) get_post_meta( $post_id, '_bundle_featured', true );
			if ( $f ) {
				$size = get_post_meta( $post_id, '_bundle_featured_size', true ) ?: 'small';
				echo '★ <small>' . esc_html( $size ) . '</small>';
			} else {
				echo '<span style="color:#999">—</span>';
			}
			break;
	}
}, 10, 2 );

/* =====================================================================
   9. FRONTEND HELPERS
   ─────────────────────────────────────────────────────────────────────
   Используются в page-bundles.php и single-podval_bundle.php
   для миграции с моков на реальные CPT-данные.
   ===================================================================== */

/**
 * WP_Query всех опубликованных бандлов, отсортированных по menu_order.
 *
 * @param array $args  Override параметров.
 * @return WP_Query
 */
function podval_bundle_get_query( $args = array() ) {
	$defaults = array(
		'post_type'      => 'podval_bundle',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'menu_order title',
		'order'          => 'ASC',
		'no_found_rows'  => true,
	);
	return new WP_Query( array_merge( $defaults, $args ) );
}

/**
 * Получить дефолтный SVG-fallback для slot'а (когда у WC product
 * нет картинки). Чередуется чёрный/белый и принты A..E.
 *
 * @param int $index Позиция в бандле (0-based)
 * @return array  ['shirt' => 'black'|'white', 'print' => 'A'..'E']
 */
function podval_bundle_get_svg_fallback( $index ) {
	static $shirts = array( 'black', 'white', 'black', 'white', 'black' );
	static $prints = array( 'A', 'E', 'C', 'G', 'B' );
	$i = ( (int) $index ) % 5;
	return array( 'shirt' => $shirts[ $i ], 'print' => $prints[ $i ] );
}

/**
 * Находит цену variation, матчащей default_type.
 * Если variation не найдена — возвращает минимальную цену продукта.
 *
 * @internal helper зарезервирован для прежней версии (полный configurator
 *           с real-time recalc по выбору клиента). В прежней версии/прежней версии lite
 *           используется простой $product->get_price().
 *
 * @param WC_Product $product
 * @param string     $type  'Classic' | 'Oversize' (или любой свободный)
 * @return float
 */
function podval_bundle_get_variation_price_by_type( $product, $type ) {
	if ( ! $product ) {
		return 0.0;
	}
	if ( ! $product->is_type( 'variable' ) ) {
		return (float) $product->get_price();
	}

	$variations = $product->get_available_variations();
	$type_lc    = mb_strtolower( $type );

	foreach ( $variations as $v ) {
		$attrs = isset( $v['attributes'] ) ? $v['attributes'] : array();
		foreach ( $attrs as $key => $val ) {
			if ( stripos( $key, 'type' ) !== false && mb_strtolower( (string) $val ) === $type_lc ) {
				return (float) ( $v['display_price'] ?? 0 );
			}
		}
	}
	// Не нашли — берём минимум
	return (float) $product->get_variation_price( 'min', true );
}

/**
 * Расчёт цены бандла. Сумма цен из WC products (для default_type каждого
 * slot'а), затем применение скидки из шкалы.
 *
 * @param int   $bundle_id
 * @param array $shirts_meta  Результат podval_bundle_get_shirts()
 * @return array  ['price' => int, 'old_price' => int, 'discount' => int (%)]
 */
function podval_bundle_calc_prices( $bundle_id, $shirts_meta ) {
	$sum = 0.0;

	if ( function_exists( 'wc_get_product' ) ) {
		foreach ( $shirts_meta as $sm ) {
			$product = wc_get_product( (int) $sm['product_id'] );
			if ( ! $product ) {
				continue;
			}
			// прежней версии lite: берём стартовую WC-цену (для variable products это
			// min из всех variations). Realtime пересчёт по выбранному типу/
			// размеру переедет в прежней версии с полным configurator.
			$price = $product->get_price();
			if ( $price === '' || $price === null ) {
				$price = 0;
			}
			$sum += (float) $price;
		}
	}

	$count    = count( $shirts_meta );
	$resolved = podval_bundle_resolve_discount( $bundle_id, $sum, $count );

	return array(
		'price'       => (int) round( $resolved['total'] ),
		'old_price'   => (int) round( $sum ),
		'discount'    => (int) round( $resolved['percent'] ),
		// сырые данные для вывода «−N Br» и расчёта выгоды
		'disc_type'   => $resolved['type'],
		'disc_amount' => $resolved['amount'],
		'resolved'    => $resolved,
	);
}

/**
 * Автогенерация подзаголовка по количеству футболок (русское склонение).
 *
 * @param int $count
 * @return string
 */
function podval_bundle_autogen_subtitle( $count ) {
	$count = (int) $count;
	if ( $count <= 0 ) {
		return '';
	}

	$n10  = $count % 10;
	$n100 = $count % 100;

	if ( $n100 >= 11 && $n100 <= 14 ) {
		$word = 'футболок';
	} elseif ( $n10 === 1 ) {
		$word = 'футболка';
	} elseif ( $n10 >= 2 && $n10 <= 4 ) {
		$word = 'футболки';
	} else {
		$word = 'футболок';
	}

	return $count . ' ' . $word . ' в наборе';
}

/**
 * Pills фильтра для /bundles/. Возвращает ['all' => 'Все', '<slug>' => '<name>', ...]
 * на основе таксономии bundle_theme.
 *
 * @return array
 */
function podval_bundle_get_pills() {
	$pills = array( 'all' => 'Все' );

	$terms = get_terms( array(
		'taxonomy'   => 'bundle_theme',
		'hide_empty' => true,
		'orderby'    => 'name',
		'order'      => 'ASC',
	) );

	if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
		foreach ( $terms as $term ) {
			$pills[ $term->slug ] = $term->name;
		}
	}
	return $pills;
}

/**
 * Конверсия CPT-поста бандла в array, совместимый со старыми render-
 * функциями. Удобно для итеративной миграции — старые helper'ы
 * продолжают работать поверх этой структуры.
 *
 * @param WP_Post|int $post
 * @return array
 */
function podval_bundle_to_array( $post ) {
	$post = get_post( $post );
	if ( ! $post || $post->post_type !== 'podval_bundle' ) {
		return array();
	}

	$shirts_meta  = podval_bundle_get_shirts( $post->ID );
	$shirts_count = count( $shirts_meta );

	/* ── Slot data: image + SVG fallback ────────────────────────── */
	$shirts_data = array();
	foreach ( $shirts_meta as $i => $sm ) {
		$product   = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $sm['product_id'] ) : null;
		$image_url = '';
		$name      = '';

		if ( $product ) {
			$name      = $product->get_name();
			$image_id  = $product->get_image_id();
			if ( $image_id ) {
				$image_url = wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' );
			}
		}

		$fallback = podval_bundle_get_svg_fallback( $i );

		$shirts_data[] = array(
			'product_id' => (int) $sm['product_id'],
			'name'       => $name,
			'image'      => (string) $image_url,
			// SVG-fallback поля для рендера в карточках на /bundles/
			'shirt'      => $fallback['shirt'],
			'print'      => $fallback['print'],
		);
	}

	/* ── Цена ──────────────────────────────────────────────────── */
	$pricing = podval_bundle_calc_prices( $post->ID, $shirts_meta );

	/* ── Категория (первый bundle_theme term) ──────────────────── */
	$terms    = wp_get_post_terms( $post->ID, 'bundle_theme' );
	$cat      = ( ! is_wp_error( $terms ) && ! empty( $terms ) ) ? $terms[0]->name : '';
	$cat_slug = ( ! is_wp_error( $terms ) && ! empty( $terms ) ) ? $terms[0]->slug : 'all';

	/* ── Подзаголовок ──────────────────────────────────────────── */
	$subtitle = (string) get_post_meta( $post->ID, '_bundle_subtitle', true );
	if ( $subtitle === '' ) {
		$subtitle = podval_bundle_autogen_subtitle( $shirts_count );
	}

	/* ── Badges ────────────────────────────────────────────────── */
	$badges = podval_bundle_get_badges( $post->ID );

	/* ── Featured image (post thumbnail) ───────────────────────── */
	$thumbnail = '';
	$thumb_alt = '';
	if ( has_post_thumbnail( $post->ID ) ) {
		$thumb_id  = get_post_thumbnail_id( $post->ID );
		$thumbnail = wp_get_attachment_image_url( $thumb_id, 'large' );
		$thumb_alt = (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true );
	}

	return array(
		'id'            => $post->ID,
		'slug'          => $post->post_name,
		'url'           => get_permalink( $post ),
		'name'          => $post->post_title,
		'subtitle'      => $subtitle,
		// безопасная подготовка контента вместо apply_filters('the_content').
		// the_content прогоняет десятки фильтров (Yoast, WPO, Jetpack и т.д.),
		// часть из них требует setup_postdata + may fatal на CPT без loop.
		// wpautop + wp_kses_post достаточно для абзацев в описании бандла.
		'description'   => wpautop( wp_kses_post( $post->post_content ) ),
		'cat'           => $cat,
		'cat_slug'      => $cat_slug,
		'status'        => (string) get_post_meta( $post->ID, '_bundle_status', true ),
		'count'         => $shirts_count,
		'price'         => $pricing['price'],
		'old_price'     => $pricing['old_price'],
		'save'          => $pricing['discount'],
		'shirts'        => $shirts_data,
		'shirts_meta'   => $shirts_meta,  // raw meta, для прежней версии single-page
		'featured'     => (bool) get_post_meta( $post->ID, '_bundle_featured', true ),
		'stage'         => (string) get_post_meta( $post->ID, '_bundle_featured_size', true ) ?: 'small',
		'thumbnail'     => $thumbnail,
		'thumbnail_alt' => $thumb_alt,
		'badges'        => $badges,
	);
}

/* =====================================================================
   10. CONFIGURATOR DATA
   ─────────────────────────────────────────────────────────────────────
   Сбор полной информации о слоте бандла для single-page UI:
   доступные атрибуты (цвет/размер/тип), их варианты, default'ы,
   список variations с ценами для real-time recalc.
   ===================================================================== */

/**
 * Маппинг названий цветов в HEX-коды для UI swatches.
 * Если цвета нет в маппинге — фронт покажет текстовый чип
 * (без круга с заливкой).
 *
 * @param string $slug_or_label  Slug или название цвета.
 * @return string|null  '#rrggbb' либо null
 */
function podval_bundle_color_hex( $slug_or_label ) {
	$map = apply_filters( 'podval_bundle_color_hex_map', array(
		// EN
		'black'      => '#0a0a0a',
		'white'      => '#f5f5f5',
		'gray'       => '#808080',
		'grey'       => '#808080',
		'graphite'   => '#3a3a3a',
		'red'        => '#dc2626',
		'blue'       => '#2563eb',
		'navy'       => '#1e3a8a',
		'green'      => '#16a34a',
		'yellow'     => '#facc15',
		'orange'     => '#f97316',
		'purple'     => '#9333ea',
		'pink'       => '#ec4899',
		'beige'      => '#e7d7b8',
		'brown'      => '#78350f',
		'olive'      => '#65733b',
		// RU
		'черный'     => '#0a0a0a',
		'чёрный'     => '#0a0a0a',
		'белый'      => '#f5f5f5',
		'серый'      => '#808080',
		'графит'     => '#3a3a3a',
		'графитовый' => '#3a3a3a',
		'красный'    => '#dc2626',
		'синий'      => '#2563eb',
		'тёмно-синий'=> '#1e3a8a',
		'темно-синий'=> '#1e3a8a',
		'зелёный'    => '#16a34a',
		'зеленый'    => '#16a34a',
		'жёлтый'     => '#facc15',
		'желтый'     => '#facc15',
		'оранжевый'  => '#f97316',
		'фиолетовый' => '#9333ea',
		'розовый'    => '#ec4899',
		'бежевый'    => '#e7d7b8',
		'коричневый' => '#78350f',
		'хаки'       => '#65733b',
	) );
	$key = mb_strtolower( trim( (string) $slug_or_label ) );
	return isset( $map[ $key ] ) ? $map[ $key ] : null;
}

/**
 * Определяет UI-роль атрибута WC по его таксономии/названию.
 * Возвращает 'color' / 'size' / 'type' или null (для непонятных).
 *
 * Маппинг по подстрокам в имени атрибута:
 *   - содержит "color" / "цвет"            → 'color'
 *   - содержит "size" / "размер"           → 'size'
 *   - содержит "type" / "model" /
 *     "модель" / "тип" / "fit" / "крой"     → 'type'
 *
 * @param string $attr_name  Имя атрибута (label, e.g. "Цвет" или "color")
 * @param string $attr_key   Тех-ключ (e.g. "pa_color", "color")
 * @return string|null
 */
function podval_bundle_classify_attr( $attr_name, $attr_key ) {
	$haystack = mb_strtolower( $attr_name . ' ' . $attr_key );
	if ( mb_strpos( $haystack, 'color' ) !== false || mb_strpos( $haystack, 'цвет' ) !== false ) {
		return 'color';
	}
	if ( mb_strpos( $haystack, 'size' ) !== false || mb_strpos( $haystack, 'размер' ) !== false ) {
		return 'size';
	}
	if ( mb_strpos( $haystack, 'type' ) !== false
	  || mb_strpos( $haystack, 'model' ) !== false
	  || mb_strpos( $haystack, 'модель' ) !== false
	  || mb_strpos( $haystack, 'тип' )   !== false
	  || mb_strpos( $haystack, 'fit' )   !== false
	  || mb_strpos( $haystack, 'крой' )  !== false ) {
		return 'type';
	}
	return null;
}

/**
 * Собирает полную информацию о слоте бандла для рендера на single-page.
 * Возвращает массив:
 *   - product_id, name, image, url
 *   - price        — стартовая цена (default variation либо $product->get_price())
 *   - attributes   — ['color' => ['key', 'label', 'options' => [{value, label, hex}]], 'size' => ..., 'type' => ...]
 *   - defaults     — ['color' => 'black', 'size' => 'M', 'type' => 'classic']
 *   - variations   — массив variations с ценами для realtime recalc:
 *                    [{id, attributes: {color, size, type}, price, image_url, in_stock}, ...]
 *   - is_variable  — bool
 *   - error        — string|null (если что-то не так)
 *
 * @param array $shirt_meta  Slot meta — {product_id}
 * @return array
 */
function podval_bundle_get_shirt_full_data( $shirt_meta ) {
	$result = array(
		'product_id'  => 0,
		'name'        => '',
		'image'       => '',
		'url'         => '',
		'price'       => 0.0,
		'attributes'  => array(),
		'defaults'    => array(),
		'variations'  => array(),
		'is_variable' => false,
		'size_chart'  => '',   // мета _podval_size_chart товара
		'error'       => null,
	);

	$pid = isset( $shirt_meta['product_id'] ) ? (int) $shirt_meta['product_id'] : 0;
	if ( $pid <= 0 || ! function_exists( 'wc_get_product' ) ) {
		$result['error'] = 'invalid_product_id';
		return $result;
	}

	try {
		$product = wc_get_product( $pid );
		if ( ! $product || $product->get_status() !== 'publish' ) {
			$result['error'] = 'product_not_found';
			return $result;
		}

		$result['product_id'] = $pid;
		$result['name']       = $product->get_name();
		$result['url']        = get_permalink( $pid );
		$result['size_chart'] = (string) get_post_meta( $pid, '_podval_size_chart', true );

		$image_id = $product->get_image_id();
		if ( $image_id ) {
			$result['image'] = wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' );
		}

		$result['price']       = (float) $product->get_price();
		$result['is_variable'] = $product->is_type( 'variable' );

		/* ── Simple product: атрибутов вариаций нет, цена фиксирована ─── */
		if ( ! $result['is_variable'] ) {
			return $result;
		}

		/* ── Variable product: разбираем атрибуты ───────────────────── */
		$wc_attrs = $product->get_attributes();
		$defaults_raw = $product->get_default_attributes();

		foreach ( $wc_attrs as $attr_key => $attr_obj ) {
			// Берём только variation-используемые атрибуты
			if ( ! $attr_obj->get_variation() ) {
				continue;
			}

			// taxonomy-based (pa_color, pa_size) или custom
			$is_taxonomy = $attr_obj->is_taxonomy();
			$label       = wc_attribute_label( $attr_obj->get_name(), $product );
			$ui_role     = podval_bundle_classify_attr( $label, $attr_obj->get_name() );

			if ( ! $ui_role ) {
				continue; // не понимаем — пропускаем
			}

			// Список значений
			$options = array();
			if ( $is_taxonomy ) {
				$terms = wp_get_post_terms( $pid, $attr_obj->get_name() );
				if ( ! is_wp_error( $terms ) ) {
					foreach ( $terms as $term ) {
						$opt = array(
							'value' => $term->slug,
							'label' => $term->name,
						);
						if ( $ui_role === 'color' ) {
							$hex = podval_bundle_color_hex( $term->slug );
							if ( ! $hex ) {
								$hex = podval_bundle_color_hex( $term->name );
							}
							if ( $hex ) {
								$opt['hex'] = $hex;
							}
						}
						$options[] = $opt;
					}
				}
			} else {
				// Non-taxonomy custom attribute: WC хранит variation attribute
				// как plain value (label), не как slug. Поэтому options.value
				// тоже должны быть plain — иначе matching variations провалится
				// (sanitize_title('Классическая') !== 'Классическая').
				foreach ( $attr_obj->get_options() as $val ) {
					$val_str = (string) $val;
					$opt = array(
						'value' => $val_str,  // PLAIN — match variation attribute
						'label' => $val_str,
					);
					if ( $ui_role === 'color' ) {
						$hex = podval_bundle_color_hex( $val_str );
						if ( $hex ) {
							$opt['hex'] = $hex;
						}
					}
					$options[] = $opt;
				}
			}

			if ( empty( $options ) ) {
				continue;
			}

			$result['attributes'][ $ui_role ] = array(
				'key'     => $attr_key, // e.g. "pa_color" — для WC variation matching
				'label'   => $label,
				'options' => $options,
			);

			// Default из WC
			$default_key = 'attribute_' . sanitize_title( $attr_obj->get_name() );
			if ( isset( $defaults_raw[ $attr_obj->get_name() ] ) ) {
				$result['defaults'][ $ui_role ] = $defaults_raw[ $attr_obj->get_name() ];
			} else {
				// Берём первый доступный вариант
				$result['defaults'][ $ui_role ] = $options[0]['value'];
			}
		}

		/* ── Variations с ценами для JS recalc ──────────────────────── */
		$variations = $product->get_available_variations( 'array' );
		foreach ( $variations as $v ) {
			if ( ! is_array( $v ) ) {
				continue;
			}

			$var_attrs = array();
			$raw_attrs = isset( $v['attributes'] ) ? (array) $v['attributes'] : array();
			foreach ( $raw_attrs as $k => $val ) {
				// Ключ типа "attribute_pa_color" → определяем UI-роль
				$attr_name = preg_replace( '/^attribute_/', '', $k );
				$ui        = null;
				foreach ( $result['attributes'] as $role => $cfg ) {
					if ( $cfg['key'] === $attr_name ) {
						$ui = $role;
						break;
					}
				}
				if ( $ui ) {
					$var_attrs[ $ui ] = (string) $val;
				}
			}

			$result['variations'][] = array(
				'id'         => isset( $v['variation_id'] ) ? (int) $v['variation_id'] : 0,
				'attributes' => $var_attrs,
				'price'      => isset( $v['display_price'] ) ? (float) $v['display_price'] : 0.0,
				'image'      => isset( $v['image']['url'] ) ? $v['image']['url'] : '',
				'in_stock'   => ! empty( $v['is_in_stock'] ),
			);
		}

		// Если defaults пусты или не матчат ни одну variation — берём
		// из первой available variation. На случай когда WC default-атрибуты
		// сохранены в другом формате (slug vs plain) или не заданы вообще.
		foreach ( $result['attributes'] as $role => $cfg ) {
			$current = isset( $result['defaults'][ $role ] ) ? $result['defaults'][ $role ] : '';
			$matches = false;
			if ( $current !== '' ) {
				foreach ( $result['variations'] as $v ) {
					if ( isset( $v['attributes'][ $role ] ) && (string) $v['attributes'][ $role ] === (string) $current ) {
						$matches = true;
						break;
					}
				}
			}
			if ( ! $matches && ! empty( $result['variations'] ) ) {
				$first = reset( $result['variations'] );
				if ( ! empty( $first['attributes'][ $role ] ) ) {
					$result['defaults'][ $role ] = $first['attributes'][ $role ];
				}
			}
		}

		// Стартовая цена — variation матчащая дефолты
		$default_price = podval_bundle_match_variation_price( $result['variations'], $result['defaults'] );
		if ( $default_price > 0 ) {
			$result['price'] = $default_price;
		}

	} catch ( Exception $e ) {
		$result['error'] = 'exception: ' . $e->getMessage();
	} catch ( Error $e ) {
		$result['error'] = 'fatal: ' . $e->getMessage();
	}

	return $result;
}

/**
 * Находит variation по заданным attributes и возвращает её цену.
 * Если точного совпадения нет — возвращает 0.
 *
 * @param array $variations  Variations array из get_shirt_full_data
 * @param array $attrs       {color: 'black', size: 'M', type: 'classic'}
 * @return float
 */
function podval_bundle_match_variation_price( $variations, $attrs ) {
	if ( empty( $variations ) || empty( $attrs ) ) {
		return 0.0;
	}
	foreach ( $variations as $v ) {
		$ok = true;
		foreach ( $attrs as $role => $val ) {
			$variation_val = isset( $v['attributes'][ $role ] ) ? (string) $v['attributes'][ $role ] : '';
			// WC хранит variation-attribute как slug, defaults тоже slug
			if ( $variation_val !== '' && $variation_val !== (string) $val ) {
				$ok = false;
				break;
			}
		}
		if ( $ok ) {
			return (float) $v['price'];
		}
	}
	return 0.0;
}

/**
 * Собирает полную data для single-page render: все slots с variations.
 *
 * @param int $bundle_id
 * @return array
 */
function podval_bundle_get_single_data( $bundle_id ) {
	$shirts_meta = podval_bundle_get_shirts( $bundle_id );
	$shirts_full = array();
	foreach ( $shirts_meta as $i => $sm ) {
		$shirts_full[] = podval_bundle_get_shirt_full_data( $sm );
	}
	return $shirts_full;
}

/**
 * Returns union of attribute UI-roles present across all shirts.
 * Used by single-template to align cards: if any shirt in bundle has 'color',
 * cards without it render an empty placeholder of the same height.
 *
 * Order is fixed for visual consistency: type → color → size.
 *
 *
 * @param array $shirts_full Output of podval_bundle_get_single_data().
 * @return array Ordered list of UI-roles, e.g. ['type','color','size'].
 */
function podval_bundle_get_attr_union( $shirts_full ) {
	$roles = array();
	foreach ( $shirts_full as $sf ) {
		if ( ! empty( $sf['attributes'] ) && is_array( $sf['attributes'] ) ) {
			foreach ( $sf['attributes'] as $role => $cfg ) {
				$roles[ $role ] = true;
			}
		}
	}
	$ordered = array();
	foreach ( array( 'type', 'color', 'size' ) as $r ) {
		if ( isset( $roles[ $r ] ) ) {
			$ordered[] = $r;
		}
	}
	return $ordered;
}

/**
 * Fetches similar bundles for the single-bundle page.
 * Primary filter — same bundle_theme term. If the result is short of $limit,
 * top-up with featured bundles from other themes (excluding current bundle
 * and bundles already included).
 *
 *
 * @param int $bundle_id Current bundle ID (excluded from result).
 * @param int $limit     Target number of bundles.
 * @return WP_Post[]
 */
function podval_bundle_get_similar( $bundle_id, $limit = 4 ) {
	$bundle_id = (int) $bundle_id;
	$limit     = max( 1, (int) $limit );

	$term_ids = wp_get_post_terms( $bundle_id, 'bundle_theme', array( 'fields' => 'ids' ) );
	if ( is_wp_error( $term_ids ) ) {
		$term_ids = array();
	}

	$collected = array();
	$seen_ids  = array( $bundle_id );

	// 1) Same theme.
	if ( ! empty( $term_ids ) ) {
		$q1 = new WP_Query( array(
			'post_type'           => 'podval_bundle',
			'post_status'         => 'publish',
			'posts_per_page'      => $limit,
			'post__not_in'        => $seen_ids,
			'orderby'             => 'menu_order date',
			'order'               => 'DESC',
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'tax_query'           => array(
				array(
					'taxonomy' => 'bundle_theme',
					'field'    => 'term_id',
					'terms'    => $term_ids,
				),
			),
		) );
		if ( $q1->have_posts() ) {
			foreach ( $q1->posts as $p ) {
				$collected[] = $p;
				$seen_ids[]  = (int) $p->ID;
			}
		}
		wp_reset_postdata();
	}

	// 2) Top-up with featured from any theme.
	$need = $limit - count( $collected );
	if ( $need > 0 ) {
		$q2 = new WP_Query( array(
			'post_type'           => 'podval_bundle',
			'post_status'         => 'publish',
			'posts_per_page'      => $need,
			'post__not_in'        => $seen_ids,
			'orderby'             => 'menu_order date',
			'order'               => 'DESC',
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
			'meta_query'          => array(
				array(
					'key'   => '_bundle_featured',
					'value' => '1',
				),
			),
		) );
		if ( $q2->have_posts() ) {
			foreach ( $q2->posts as $p ) {
				$collected[] = $p;
				$seen_ids[]  = (int) $p->ID;
			}
		}
		wp_reset_postdata();
	}

	// 3) If still short, top-up with any recent bundles.
	$need = $limit - count( $collected );
	if ( $need > 0 ) {
		$q3 = new WP_Query( array(
			'post_type'           => 'podval_bundle',
			'post_status'         => 'publish',
			'posts_per_page'      => $need,
			'post__not_in'        => $seen_ids,
			'orderby'             => 'date',
			'order'               => 'DESC',
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		) );
		if ( $q3->have_posts() ) {
			foreach ( $q3->posts as $p ) {
				$collected[] = $p;
			}
		}
		wp_reset_postdata();
	}

	return $collected;
}

/* =====================================================================
   10. ОБРАТНЫЙ ИНДЕКС «ТОВАР → БАНДЛЫ»
   ---------------------------------------------------------------------
   Задача: на странице товара показать «есть в наборе — выгода N Br».
   Прямой поиск по _bundle_shirts невозможен: мета лежит сериализованным
   массивом, а meta_query LIKE '%12%' поймает и product_id 120, и 512.
   Поэтому держим обратную мету на самом товаре:

       _podval_in_bundles  => array( bundle_id, bundle_id, ... )

   Она перестраивается при каждом сохранении бандла (с диффом, чтобы
   снятые из состава товары чистились) и при смене статуса/удалении.
   ===================================================================== */

/**
 * Пересобрать обратный индекс для одного бандла.
 * Диффит старый и новый состав: у выбывших товаров ссылка снимается.
 *
 * @param int $bundle_id
 * @return void
 */
function podval_bundle_sync_product_index( $bundle_id ) {
	$bundle_id = (int) $bundle_id;
	if ( $bundle_id <= 0 || get_post_type( $bundle_id ) !== 'podval_bundle' ) {
		return;
	}

	$prev = get_post_meta( $bundle_id, '_bundle_indexed_products', true );
	$prev = is_array( $prev ) ? array_map( 'intval', $prev ) : array();

	$next = array();
	// Индексируем только опубликованные бандлы. Черновик/корзина —
	// снимаем ссылки, иначе на витрине вылезет ссылка в 404.
	if ( get_post_status( $bundle_id ) === 'publish' ) {
		foreach ( podval_bundle_get_shirts( $bundle_id ) as $s ) {
			$pid = isset( $s['product_id'] ) ? (int) $s['product_id'] : 0;
			if ( $pid > 0 ) {
				$next[] = $pid;
			}
		}
		$next = array_values( array_unique( $next ) );
	}

	foreach ( array_diff( $prev, $next ) as $pid ) {
		podval_bundle_index_unlink( $pid, $bundle_id );
	}
	foreach ( array_diff( $next, $prev ) as $pid ) {
		podval_bundle_index_link( $pid, $bundle_id );
	}

	update_post_meta( $bundle_id, '_bundle_indexed_products', $next );
	delete_transient( 'podval_bundle_xsell_' . $bundle_id );
	foreach ( array_unique( array_merge( $prev, $next ) ) as $pid ) {
		delete_transient( 'podval_prod_bundles_' . (int) $pid );
	}
}

function podval_bundle_index_link( $product_id, $bundle_id ) {
	$list = get_post_meta( $product_id, '_podval_in_bundles', true );
	$list = is_array( $list ) ? array_map( 'intval', $list ) : array();
	if ( ! in_array( (int) $bundle_id, $list, true ) ) {
		$list[] = (int) $bundle_id;
		update_post_meta( $product_id, '_podval_in_bundles', array_values( $list ) );
	}
}

function podval_bundle_index_unlink( $product_id, $bundle_id ) {
	$list = get_post_meta( $product_id, '_podval_in_bundles', true );
	$list = is_array( $list ) ? array_map( 'intval', $list ) : array();
	$list = array_values( array_diff( $list, array( (int) $bundle_id ) ) );
	if ( empty( $list ) ) {
		delete_post_meta( $product_id, '_podval_in_bundles' );
	} else {
		update_post_meta( $product_id, '_podval_in_bundles', $list );
	}
}

/* Хуки синхронизации. Приоритет 20 — после podval_bundle_save_meta (10),
   чтобы читать уже обновлённый _bundle_shirts. */
add_action( 'save_post_podval_bundle', 'podval_bundle_sync_product_index', 20 );
add_action( 'trashed_post', 'podval_bundle_sync_product_index' );
add_action( 'untrashed_post', 'podval_bundle_sync_product_index' );
add_action( 'before_delete_post', function( $post_id ) {
	if ( get_post_type( $post_id ) !== 'podval_bundle' ) {
		return;
	}
	$prev = get_post_meta( $post_id, '_bundle_indexed_products', true );
	if ( is_array( $prev ) ) {
		foreach ( $prev as $pid ) {
			podval_bundle_index_unlink( (int) $pid, $post_id );
			delete_transient( 'podval_prod_bundles_' . (int) $pid );
		}
	}
} );

/**
 * Полная перестройка индекса по всем бандлам.
 * Вызывается разово при обновлении темы (см. ниже) и вручную
 * из Инструменты → Пересобрать индекс бандлов.
 *
 * @return int Кол-во обработанных бандлов.
 */
function podval_bundle_rebuild_index() {
	// Сначала снимаем все существующие ссылки, чтобы не осталось «хвостов»
	// от бандлов, удалённых в обход хуков (например, через SQL).
	global $wpdb;
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_podval_in_bundles' ) );

	$ids = get_posts( array(
		'post_type'      => 'podval_bundle',
		'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
		'posts_per_page' => -1,
		'fields'         => 'ids',
	) );
	foreach ( $ids as $bid ) {
		delete_post_meta( $bid, '_bundle_indexed_products' );
		podval_bundle_sync_product_index( $bid );
	}
	return count( $ids );
}

/* Разовый бэкфилл после деплоя прежней версии */
add_action( 'admin_init', function() {
	if ( get_option( 'podval_bundle_index_v1' ) === 'done' ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	podval_bundle_rebuild_index();
	update_option( 'podval_bundle_index_v1', 'done', false );
} );

/**
 * Бандлы, в которые входит товар — с посчитанной выгодой.
 * Результат кэшируется в транзиент на 12 часов.
 *
 * @param int $product_id
 * @return array [ ['id','title','url','count','saving','disc_label'], ... ]
 */
function podval_bundle_get_for_product( $product_id ) {
	$product_id = (int) $product_id;
	if ( $product_id <= 0 ) {
		return array();
	}
	// Раздел скрыт — ссылки не показываем вообще: /bundles/ отдаёт 404 гостям.
	if ( function_exists( 'podval_bundles_are_public' ) && ! podval_bundles_are_public() ) {
		return array();
	}

	$cache_key = 'podval_prod_bundles_' . $product_id;
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$ids = get_post_meta( $product_id, '_podval_in_bundles', true );
	$ids = is_array( $ids ) ? array_map( 'intval', $ids ) : array();

	$out = array();
	foreach ( $ids as $bid ) {
		if ( get_post_status( $bid ) !== 'publish' ) {
			continue;
		}
		$shirts = podval_bundle_get_shirts( $bid );
		if ( count( $shirts ) < 2 ) {
			continue; // бандл из одной футболки — не кросс-селлим
		}
		$prices = podval_bundle_calc_prices( $bid, $shirts );
		$saving = (float) ( $prices['disc_amount'] ?? 0 );
		if ( $saving <= 0 ) {
			continue; // нулевая скидка — сообщать не о чем
		}
		$out[] = array(
			'id'         => (int) $bid,
			'title'      => get_the_title( $bid ),
			'url'        => get_permalink( $bid ),
			'count'      => count( $shirts ),
			'saving'     => $saving,
			'disc_label' => podval_bundle_format_discount( $prices['resolved'] ),
		);
	}

	// Самая выгодная — первой
	usort( $out, function( $a, $b ) {
		return $b['saving'] <=> $a['saving'];
	} );

	set_transient( $cache_key, $out, 12 * HOUR_IN_SECONDS );
	return $out;
}

/* Цены футболок меняются → выгода протухает. Сбрасываем кэш не только
   у изменённого товара, но и у всех его «соседей» по бандлам: их выгода
   считается от общей суммы набора и тоже поехала. */
add_action( 'woocommerce_update_product', function( $product_id ) {
	$product_id = (int) $product_id;
	delete_transient( 'podval_prod_bundles_' . $product_id );

	$bids = get_post_meta( $product_id, '_podval_in_bundles', true );
	if ( ! is_array( $bids ) ) {
		return;
	}
	foreach ( $bids as $bid ) {
		foreach ( podval_bundle_get_shirts( (int) $bid ) as $s ) {
			$pid = isset( $s['product_id'] ) ? (int) $s['product_id'] : 0;
			if ( $pid > 0 ) {
				delete_transient( 'podval_prod_bundles_' . $pid );
			}
		}
	}
} );

/* ── Ручная пересборка индекса: Бандлы → Пересобрать индекс ───────── */
add_action( 'admin_menu', function() {
	add_submenu_page(
		PODVAL_ADMIN_MENU, // было Бандлы
		'Индекс бандлов',
		'Индекс бандлов',
		'manage_options',
		'podval-bundle-reindex',
		'podval_bundle_reindex_page',
		4
	);
} );

function podval_bundle_reindex_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Недостаточно прав.' );
	}
	$done = 0;
	if ( isset( $_POST['podval_reindex'] ) && check_admin_referer( 'podval_bundle_reindex' ) ) {
		$done = podval_bundle_rebuild_index();
	}
	?>
	<div class="wrap">
		<h1>Индекс «товар → бандлы»</h1>
		<?php if ( $done ) : ?>
			<div class="notice notice-success"><p>Обработано бандлов: <strong><?php echo (int) $done; ?></strong>. Кэш выгоды сброшен.</p></div>
		<?php endif; ?>
		<p>Индекс питает блок «Есть в наборе — выгода N Br» на страницах товаров.
		Он обновляется сам при сохранении бандла. Пересборка нужна, только если состав
		правился в обход админки (импорт, SQL) или блок не появился там, где должен.</p>
		<form method="post">
			<?php wp_nonce_field( 'podval_bundle_reindex' ); ?>
			<p><button type="submit" name="podval_reindex" value="1" class="button button-primary">Пересобрать индекс</button></p>
		</form>
	</div>
	<?php
}

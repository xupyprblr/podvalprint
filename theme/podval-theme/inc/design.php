<?php
/**
 * Дизайн-система «Подвальный Принт» на сайте: стили, шрифты, иконки, логотип,
 * меню шапки и подвала, ссылки из текстов.
 *
 * Источник системы — design/system в репозитории; в тему её переносит
 * scripts/sync-design.sh (assets/css/pp/, assets/icons/).
 *
 * @package Podval Print
 */

defined( 'ABSPATH' ) || exit;

/* =============================================
   СТИЛИ И ШРИФТЫ
   ============================================= */

/**
 * Токены, компоненты и слой сайта — блокирующими стилями: шапка, подвал и
 * главная свёрстаны только на них. Старые модули style-*.css грузятся после.
 */
add_action( 'wp_enqueue_scripts', function () {
	$uri = get_template_directory_uri() . '/assets';
	$ver = wp_get_theme( get_template() )->get( 'Version' ) ?: '0.1.0';

	wp_enqueue_style( 'podval-pp-tokens', $uri . '/css/pp/tokens.css', array(), $ver );
	wp_enqueue_style( 'podval-pp-bundle', $uri . '/css/pp/bundle.css', array( 'podval-pp-tokens' ), $ver );
	wp_enqueue_style( 'podval-pp-site', $uri . '/css/pp-site.css', array( 'podval-pp-bundle' ), $ver );

	wp_enqueue_script( 'podval-pp-shell', $uri . '/js/pp-shell.js', array(), $ver, array( 'in_footer' => true, 'strategy' => 'defer' ) );
}, 5 );

/** Старые модули грузятся после слоя сайта, чтобы их глобальные правила не перебивали систему. */
add_action( 'wp_enqueue_scripts', function () {
	global $wp_styles;
	if ( ! $wp_styles instanceof WP_Styles ) {
		return;
	}
	foreach ( $wp_styles->queue as $handle ) {
		if ( 0 === strpos( $handle, 'podval-style-' ) && isset( $wp_styles->registered[ $handle ] ) ) {
			$deps = &$wp_styles->registered[ $handle ]->deps;
			if ( ! in_array( 'podval-pp-site', $deps, true ) ) {
				$deps[] = 'podval-pp-site';
			}
		}
	}
}, 100 );

/** Предзагрузка шрифтов первого экрана: текст Golos Text и заголовки Onest, кириллица. */
add_action( 'wp_head', function () {
	$dir = get_template_directory_uri() . '/assets/fonts';
	foreach ( array( 'golos-text-cyrillic', 'onest-cyrillic' ) as $font ) {
		echo '<link rel="preload" href="' . esc_url( $dir . '/' . $font . '.woff2' ) . '" as="font" type="font/woff2" crossorigin>' . "\n";
	}
}, 2 );

/** Класс-метка для стилей сайта и тема раздела на body. */
add_filter( 'body_class', function ( $classes ) {
	$classes[] = 'podval-pp';
	return $classes;
} );

/**
 * Тема раздела для атрибута data-theme у body: light по умолчанию,
 * dark — для раздела, который фильтр отнесёт к Deadlock.
 */
function podval_pp_body_theme(): string {
	$theme = (string) apply_filters( 'podval_pp_body_theme', 'light' );
	return in_array( $theme, array( 'light', 'dark' ), true ) ? $theme : 'light';
}

/* =============================================
   ИКОНКИ, ЛОГОТИП, ИЛЛЮСТРАЦИИ
   ============================================= */

/**
 * Inline SVG-иконка из набора системы (assets/icons/<имя>.svg).
 *
 * @param string $name Имя файла без расширения: cart, search, menu, arrow-right…
 * @param string $size '' (20px), 's' (16px) или 'l' (24px).
 */
function podval_pp_icon( string $name, string $size = '' ): string {
	static $cache = array();
	$name = sanitize_key( $name );
	if ( ! isset( $cache[ $name ] ) ) {
		$file           = get_template_directory() . '/assets/icons/' . $name . '.svg';
		$cache[ $name ] = is_readable( $file ) ? (string) file_get_contents( $file ) : '';
	}
	if ( '' === $cache[ $name ] ) {
		return '';
	}
	$class = 'pp-icon' . ( $size ? ' pp-icon--' . sanitize_html_class( $size ) : '' );
	$svg   = preg_replace( '/\s(xmlns|width|height)="[^"]*"/', '', $cache[ $name ] );
	return preg_replace( '/^<svg/', '<svg class="' . esc_attr( $class ) . '" aria-hidden="true" focusable="false"', trim( (string) $svg ), 1 );
}

/**
 * Inline SVG-иллюстрация темы (assets/img/pp/<имя>.svg).
 *
 * @param string $name Имя файла без расширения.
 */
function podval_pp_svg( string $name ): string {
	$file = get_template_directory() . '/assets/img/pp/' . sanitize_file_name( $name ) . '.svg';
	return is_readable( $file ) ? trim( (string) file_get_contents( $file ) ) : '';
}

/**
 * Словесный знак — inline, цвет берётся из темы (fill: currentColor).
 * Если в Customizer задан свой логотип, выводится он.
 */
function podval_pp_logo(): string {
	$name = (string) podval_brand( 'name', get_bloginfo( 'name' ) );
	if ( has_custom_logo() ) {
		$id  = (int) get_theme_mod( 'custom_logo' );
		$img = wp_get_attachment_image( $id, 'full', false, array( 'alt' => $name, 'loading' => 'eager', 'style' => 'height:40px;width:auto' ) );
		if ( $img ) {
			return $img;
		}
	}
	$svg = podval_pp_svg( 'logo-wordmark' );
	if ( '' === $svg ) {
		return '<span class="pp-h pp-h--3">' . esc_html( $name ) . '</span>';
	}
	return (string) preg_replace( '/^<svg/', '<svg role="img" aria-label="' . esc_attr( $name ) . '"', $svg, 1 );
}

/* =============================================
   ССЫЛКИ ИЗ ТЕКСТОВ
   ============================================= */

/**
 * URL по ссылке-описанию из inc/copy-data.php.
 *
 * Форматы: 'catalog', 'cart', 'home', 'route:<ключ>' (маршрут из brand.php),
 * 'cat:<слаг категории>', 'product:<слаг товара>', 'page:<слаг страницы>',
 * 'template:<файл шаблона страницы>', '#якорь', '/путь/' (от корня сайта),
 * 'tel' (телефон бренда), 'telegram' и 'instagram' (из бренда), https://… — как есть.
 * Страницы учитываются только опубликованные.
 * Пустая строка — ссылка не найдена: кнопку не выводить.
 *
 * @param string $spec Описание ссылки.
 */
function podval_pp_link( string $spec ): string {
	// Запасные варианты через «|»: 'cat:custom-design|catalog' — первая найденная ссылка.
	if ( false !== strpos( $spec, '|' ) ) {
		foreach ( explode( '|', $spec ) as $alt ) {
			$url = podval_pp_link( $alt );
			if ( '' !== $url ) {
				return $url;
			}
		}
		return '';
	}
	$spec = trim( $spec );
	if ( '' === $spec ) {
		return '';
	}
	if ( '#' === $spec[0] ) {
		return $spec;
	}
	if ( '/' === $spec[0] ) {
		return home_url( $spec );
	}
	if ( preg_match( '#^https?://#', $spec ) ) {
		return $spec;
	}

	list( $type, $arg ) = array_pad( explode( ':', $spec, 2 ), 2, '' );
	switch ( $type ) {
		case 'home':
			return home_url( '/' );
		case 'catalog':
			return function_exists( 'podval_get_catalog_url' ) ? (string) podval_get_catalog_url() : home_url( '/' );
		case 'cart':
			return function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '';
		case 'tel':
			$tel = podval_brand_tel( (string) get_theme_mod( 'podval_header_phone', podval_brand( 'phone' ) ) );
			return '' !== $tel ? 'tel:' . $tel : '';
		case 'telegram':
		case 'instagram':
			return (string) podval_brand( $type );
		case 'route':
			$page = podval_route_slug( $arg ) ? get_page_by_path( podval_route_slug( $arg ) ) : null;
			return $page && 'publish' === $page->post_status ? podval_url( $arg ) : '';
		case 'page':
			$page = get_page_by_path( $arg );
			return $page && 'publish' === $page->post_status ? (string) get_permalink( $page ) : '';
		case 'template':
			$pages = get_posts( array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'meta_key'       => '_wp_page_template',
				'meta_value'     => $arg,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			) );
			return $pages ? (string) get_permalink( $pages[0] ) : '';
		case 'cat':
			$term = get_term_by( 'slug', $arg, 'product_cat' );
			$link = $term ? get_term_link( $term ) : '';
			return is_string( $link ) ? $link : '';
		case 'product':
			$post = get_page_by_path( $arg, OBJECT, 'product' );
			return $post && 'publish' === $post->post_status ? (string) get_permalink( $post ) : '';
	}
	return '';
}

/* =============================================
   МЕНЮ
   ============================================= */

/**
 * Пункты меню из области WordPress-меню: [ [ 'label', 'url', 'current', 'children' => [...] ], … ].
 *
 * @param string $location Область меню (primary, footer, footer_2).
 * @return array|null null — меню в области не назначено.
 */
function podval_pp_menu_items( string $location ): ?array {
	$locations = get_nav_menu_locations();
	if ( empty( $locations[ $location ] ) ) {
		return null;
	}
	$items = wp_get_nav_menu_items( $locations[ $location ] );
	if ( ! $items ) {
		return null;
	}
	_wp_menu_item_classes_by_context( $items );

	$tree = array();
	$refs = array();
	foreach ( $items as $item ) {
		$refs[ $item->ID ] = array(
			'label'    => $item->title,
			'desc'     => $item->description,
			'url'      => $item->url,
			'current'  => in_array( 'current-menu-item', (array) $item->classes, true ),
			'children' => array(),
			'parent'   => (int) $item->menu_item_parent,
		);
	}
	foreach ( $refs as $id => &$ref ) {
		if ( $ref['parent'] && isset( $refs[ $ref['parent'] ] ) ) {
			$refs[ $ref['parent'] ]['children'][] = &$ref;
		}
	}
	unset( $ref );
	foreach ( $refs as $id => $ref ) {
		if ( ! $ref['parent'] || ! isset( $refs[ $ref['parent'] ] ) ) {
			$tree[] = $ref;
		}
	}
	return $tree;
}

/**
 * Основное меню. Пока меню «Основное» не собрано в админке, строится из того,
 * что уже есть на сайте: каталог с корневыми категориями и служебные страницы.
 */
function podval_pp_primary_nav(): array {
	$menu = podval_pp_menu_items( 'primary' );
	if ( null !== $menu ) {
		return $menu;
	}

	$nav      = array();
	$children = array();
	$cats     = get_terms( array(
		'taxonomy'   => 'product_cat',
		'parent'     => 0,
		'hide_empty' => true,
		'number'     => 8,
		'orderby'    => 'menu_order',
		'exclude'    => array( (int) get_option( 'default_product_cat' ) ),
	) );
	if ( ! is_wp_error( $cats ) ) {
		foreach ( $cats as $cat ) {
			$link = get_term_link( $cat );
			if ( is_string( $link ) ) {
				$children[] = array( 'label' => $cat->name, 'desc' => '', 'url' => $link, 'current' => is_tax( 'product_cat', $cat->term_id ), 'children' => array() );
			}
		}
	}
	$nav[] = array(
		'label'    => __( 'Каталог', 'podval-print' ),
		'desc'     => '',
		'url'      => podval_pp_link( 'catalog' ),
		'current'  => function_exists( 'is_shop' ) && is_shop(),
		'children' => count( $children ) > 1 ? $children : array(),
	);

	$labels = array(
		'delivery' => __( 'Доставка', 'podval-print' ),
		'about'    => __( 'О нас', 'podval-print' ),
		'contacts' => __( 'Контакты', 'podval-print' ),
	);
	foreach ( $labels as $route => $label ) {
		$url = podval_pp_link( 'route:' . $route );
		if ( '' !== $url ) {
			$nav[] = array( 'label' => $label, 'desc' => '', 'url' => $url, 'current' => is_page( podval_route_slug( $route ) ), 'children' => array() );
		}
	}
	return $nav;
}

/**
 * Колонки подвала: [ [ 'title', 'items' => [ [ 'label', 'url' ], … ] ], … ].
 * Области «Футер — магазин» и «Футер — информация» заменяют первые две колонки.
 */
function podval_pp_footer_columns(): array {
	$cols = array();

	$shop = podval_pp_menu_items( 'footer' );
	if ( null === $shop ) {
		$shop = array( array( 'label' => __( 'Каталог', 'podval-print' ), 'url' => podval_pp_link( 'catalog' ) ) );
		$cats = get_terms( array( 'taxonomy' => 'product_cat', 'parent' => 0, 'hide_empty' => true, 'number' => 5, 'orderby' => 'menu_order', 'exclude' => array( (int) get_option( 'default_product_cat' ) ) ) );
		if ( ! is_wp_error( $cats ) ) {
			foreach ( $cats as $cat ) {
				$link = get_term_link( $cat );
				if ( is_string( $link ) ) {
					$shop[] = array( 'label' => $cat->name, 'url' => $link );
				}
			}
		}
	}
	$cols[] = array( 'title' => __( 'Печать', 'podval-print' ), 'items' => $shop );

	$info = podval_pp_menu_items( 'footer_2' );
	if ( null === $info ) {
		$info   = array();
		$routes = array(
			'delivery' => __( 'Доставка и оплата', 'podval-print' ),
			'returns'  => __( 'Гарантия и возврат', 'podval-print' ),
			'about'    => __( 'О нас', 'podval-print' ),
			'oferta'   => __( 'Публичная оферта', 'podval-print' ),
			'privacy'  => __( 'Политика конфиденциальности', 'podval-print' ),
		);
		foreach ( $routes as $route => $label ) {
			$url = podval_pp_link( 'route:' . $route );
			if ( '' !== $url ) {
				$info[] = array( 'label' => $label, 'url' => $url );
			}
		}
	}
	$cols[] = array( 'title' => __( 'Покупателям', 'podval-print' ), 'items' => $info );

	$contact = array();
	$phone   = (string) get_theme_mod( 'podval_header_phone', podval_brand( 'phone' ) );
	if ( '' !== $phone ) {
		$contact[] = array( 'label' => $phone, 'url' => 'tel:' . podval_brand_tel( $phone ) );
	}
	$email = (string) podval_brand( 'email' );
	if ( '' !== $email ) {
		$contact[] = array( 'label' => $email, 'url' => 'mailto:' . $email );
	}
	foreach ( array( 'telegram' => 'Telegram', 'instagram' => 'Instagram' ) as $key => $label ) {
		$url = (string) podval_brand( $key );
		if ( '' !== $url ) {
			$contact[] = array( 'label' => $label, 'url' => $url );
		}
	}
	$url = podval_pp_link( 'route:contacts' );
	if ( '' !== $url ) {
		$contact[] = array( 'label' => __( 'Все контакты', 'podval-print' ), 'url' => $url );
	}
	$cols[] = array( 'title' => __( 'Связаться', 'podval-print' ), 'items' => $contact );

	return array_values( array_filter( $cols, function ( $c ) {
		return ! empty( $c['items'] );
	} ) );
}

/* =============================================
   ЦЕНЫ В ТЕКСТАХ И ТОВАРЫ
   ============================================= */

/** Текст с шорткодами (цены [podval_price]) → безопасный HTML. */
function podval_pp_text( string $text ): string {
	return wp_kses_post( do_shortcode( $text ) );
}

/** Цена в разметке системы: «от 55 Br». Пусто — цены нет. */
function podval_pp_price_html( ?float $price, string $from = '', string $size = '' ): string {
	if ( null === $price ) {
		return '';
	}
	$class = 'pp-price' . ( $size ? ' pp-price--' . sanitize_html_class( $size ) : '' );
	$out   = '<span class="' . esc_attr( $class ) . '">';
	if ( '' !== $from ) {
		$out .= '<span class="pp-price__from">' . esc_html( $from ) . '</span>';
	}
	return $out . esc_html( podval_price_num( $price ) ) . "\u{00A0}Br</span>";
}

/**
 * Товары WooCommerce для витрины главной: по слагу категории.
 *
 * @param string $cat   Слаг категории.
 * @param int    $limit Сколько.
 * @return WC_Product[]
 */
function podval_pp_products( string $cat, int $limit = 4 ): array {
	if ( '' === $cat || ! function_exists( 'wc_get_products' ) ) {
		return array();
	}
	return (array) wc_get_products( array(
		'status'   => 'publish',
		'limit'    => $limit,
		'category' => array( $cat ),
		'orderby'  => 'menu_order',
		'order'    => 'ASC',
	) );
}

/**
 * Карточка товара в разметке системы.
 *
 * @param WC_Product $product Товар.
 * @param string     $button  Текст кнопки.
 * @param bool       $deco    Карточка тёмного раздела.
 */
function podval_pp_product_card( $product, string $button, bool $deco = false ): string {
	$url   = $product->get_permalink();
	$img   = $product->get_image_id() ? wp_get_attachment_image( $product->get_image_id(), 'woocommerce_thumbnail', false, array( 'loading' => 'lazy', 'decoding' => 'async' ) ) : '';
	$price = $product->is_type( 'variable' ) ? $product->get_variation_price( 'min', true ) : $product->get_price();
	$from  = $product->is_type( 'variable' ) ? __( 'от', 'podval-print' ) : '';

	$out  = '<article class="pp-card' . ( $deco ? ' pp-card--deco' : '' ) . '">';
	$out .= '<div class="pp-card__media pp-card__media--sq ' . ( $deco ? 'pp-tex-night' : 'pp-tex-concrete' ) . '">' . ( $img ?: podval_pp_svg( 'tpl-word' ) ) . '</div>';
	$out .= '<div class="pp-card__body"><h3 class="pp-card__title"><a href="' . esc_url( $url ) . '">' . esc_html( $product->get_name() ) . '</a></h3>';
	$short = wp_strip_all_tags( (string) $product->get_short_description() );
	if ( '' !== $short ) {
		$out .= '<p class="pp-card__meta">' . esc_html( wp_trim_words( $short, 14 ) ) . '</p>';
	}
	$out .= '<div class="pp-card__foot">' . podval_pp_price_html( is_numeric( $price ) ? (float) $price : null, $from, 's' );
	$out .= '<a class="pp-btn pp-btn--secondary pp-btn--s" href="' . esc_url( $url ) . '">' . esc_html( $button ) . '</a></div></div></article>';
	return $out;
}

/* =============================================
   СЛУЖЕБНЫЕ СТРАНИЦЫ ПРИ АКТИВАЦИИ ТЕМЫ
   ============================================= */

/**
 * Создаёт черновики служебных страниц со слагами из маршрутов brand.php, если их нет.
 * Текст пишется в редакторе WordPress; пока страница — черновик, ссылки на неё не выводятся.
 */
function podval_pp_create_service_pages(): void {
	$titles = array(
		'about'    => __( 'О нас', 'podval-print' ),
		'contacts' => __( 'Контакты', 'podval-print' ),
		'delivery' => __( 'Доставка и оплата', 'podval-print' ),
		'returns'  => __( 'Гарантия и возврат', 'podval-print' ),
		'oferta'   => __( 'Публичная оферта', 'podval-print' ),
		'privacy'  => __( 'Политика конфиденциальности', 'podval-print' ),
	);
	foreach ( $titles as $route => $title ) {
		$slug = podval_route_slug( $route );
		if ( '' === $slug || false !== strpos( $slug, '/' ) || get_page_by_path( $slug ) ) {
			continue;
		}
		wp_insert_post( array(
			'post_type'   => 'page',
			'post_status' => 'draft',
			'post_title'  => $title,
			'post_name'   => $slug,
		) );
	}
}
add_action( 'after_switch_theme', 'podval_pp_create_service_pages' );

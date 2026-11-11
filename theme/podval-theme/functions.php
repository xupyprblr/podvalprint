<?php
/**
 * Скрываем deprecated-предупреждение плагина AlfabankBY (PHP 8.2+)
 * Не трогаем остальной debug — только этот конкретный плагин
 */
$old_error_handler = set_error_handler( function( $errno, $errstr, $errfile ) use ( &$old_error_handler ) {
    if ( $errno === E_DEPRECATED && strpos( $errfile, 'woocommerce-rbspayment-AlfabankBY' ) !== false ) {
        return true; // подавляем
    }
    // Всё остальное — стандартная обработка
    if ( $old_error_handler ) {
        return call_user_func_array( $old_error_handler, func_get_args() );
    }
    return false;
}, E_DEPRECATED );

/**
 * Podval Print — functions.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/inc/brand.php'; // бренд, реквизиты, маршруты — до всего остального

/* =============================================
   ДЕВ-ОКРУЖЕНИЕ — закрыто от индексации.
   Дев — это любой хост из podval_brand( 'dev_hosts' ) либо окружение, где
   WP_ENVIRONMENT_TYPE не production. Хелпер используется ещё и реестром
   цен (inc/prices.php): на проде цены-заглушки считаются отсутствующими.
   ============================================= */
if ( ! function_exists( 'podval_is_dev_host' ) ) {
    /**
     * Дев-хост? Хосты из конфига бренда (включая поддомены) или не-production окружение.
     *
     * @param string|null $host Хост для проверки; null — текущий HTTP_HOST.
     */
    function podval_is_dev_host( $host = null ) {
        if ( null === $host ) {
            $host = isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : '';
        }
        $host = strtolower( preg_replace( '/:\d+$/', '', trim( (string) $host ) ) );
        if ( $host === '' ) {
            return false;
        }
        if ( function_exists( 'wp_get_environment_type' ) && 'production' !== wp_get_environment_type() ) {
            return true;
        }
        $dev = (array) podval_brand( 'dev_hosts', array() );
        if ( function_exists( 'apply_filters' ) ) {
            $dev = (array) apply_filters( 'podval_dev_hosts', $dev );
        }
        foreach ( $dev as $d ) {
            $d = strtolower( (string) $d );
            if ( $host === $d || substr( $host, -strlen( '.' . $d ) ) === '.' . $d ) {
                return true;
            }
        }
        return false;
    }
}

add_action( 'wp_head', function() {
    if ( podval_is_dev_host() && ! defined( 'WPSEO_VERSION' ) ) {
        echo '<meta name="robots" content="noindex, nofollow">' . "\n";
    }
    if ( podval_is_dev_host() ) {
        echo '<meta name="googlebot" content="noindex, nofollow">' . "\n";
    }
}, 1 );
// При активном Yoast его robots-мета — единственная; второй тег рядом
// поисковик мог бы прочитать как противоречие. Перебиваем значение Yoast.
add_filter( 'wpseo_robots', function( $robots ) {
    return podval_is_dev_host() ? 'noindex, nofollow' : $robots;
}, 100 );

/* =============================================
   BUNDLES — noindex single pages while section is hidden
   Раздел скрыт (podval_bundles_public = OFF): single-бандлы доступны
   по прямой ссылке для проверки, но не должны попадать в индекс.
   Перекрываем и Yoast (wpseo_robots), и даём fallback-мету.
   ============================================= */
add_filter( 'wpseo_robots', function( $robots ) {
    if ( is_singular( 'podval_bundle' )
        && function_exists( 'podval_bundles_are_public' )
        && ! podval_bundles_are_public() ) {
        return 'noindex, nofollow';
    }
    return $robots;
}, 99 );

add_action( 'wp_head', function() {
    if ( is_singular( 'podval_bundle' )
        && function_exists( 'podval_bundles_are_public' )
        && ! podval_bundles_are_public()
        && ! defined( 'WPSEO_VERSION' ) ) {
        echo '<meta name="robots" content="noindex, nofollow">' . "\n";
    }
}, 1 );

/* =============================================
   CATALOG URL HELPER
   ============================================= */
/**
 * Возвращает URL главной страницы каталога.
 * WooCommerce shop page (/shop/) — техническая; публичный каталог
 * живёт на term-ссылке корневой категории «Магазин» (слаг shop).
 */
function podval_get_catalog_url() {
    if ( function_exists( 'get_term_link' ) ) {
        $term = get_term_by( 'slug', 'shop', 'product_cat' );
        if ( $term && ! is_wp_error( $term ) ) {
            return get_term_link( $term );
        }
    }
    return function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
}

/**
 * Публичность раздела «Бандлы».
 *
 * Единая точка истины: показывать ли раздел бандлов обычным
 * посетителям (пункт меню + листинг /bundles/). Управляется
 * чекбоксом Customizer `podval_bundles_public` (по умолчанию OFF —
 * бандлы скрыты на проде, пока наполняются).
 *
 * Single-страницы бандлов (single-podval_bundle.php) остаются
 * доступными по прямой ссылке всегда — для проверки. При скрытом
 * разделе они получают noindex (см. inc/seo.php / wp_head).
 *
 * @return bool true — раздел публичен; false — скрыт от гостей.
 */
function podval_bundles_are_public() {
    return (bool) get_theme_mod( 'podval_bundles_public', false );
}

/* =============================================
   THEME SETUP
   ============================================= */
function podval_setup() {
    load_theme_textdomain( 'podval-print', get_template_directory() . '/languages' );

    add_theme_support( 'title-tag' );
    add_theme_support( 'post-thumbnails' );
    add_theme_support( 'html5', [ 'search-form','comment-form','comment-list','gallery','caption','style','script' ] );
    add_theme_support( 'custom-logo', [
        'height'      => 80,
        'width'       => 240,
        'flex-height' => true,
        'flex-width'  => true,
    ] );
    add_theme_support( 'customize-selective-refresh-widgets' );

    // WooCommerce support
    add_theme_support( 'woocommerce', [
        'thumbnail_image_width' => 600,
        'single_image_width'    => 900,
    ] );
    add_theme_support( 'wc-product-gallery-zoom' );
    add_theme_support( 'wc-product-gallery-lightbox' );
    add_theme_support( 'wc-product-gallery-slider' );

    // Menus
    register_nav_menus( [
        'primary'  => __( 'Основное меню', 'podval-print' ),
        'footer'   => __( 'Футер — магазин', 'podval-print' ),
        'footer_2' => __( 'Футер — информация', 'podval-print' ),
    ] );
}

add_action( 'wp_enqueue_scripts', function() {
    wp_dequeue_style( 'woocommerce-layout' );
    wp_dequeue_style( 'woocommerce-smallscreen' );
    wp_dequeue_style( 'woocommerce-general' );
}, 100 );
add_action( 'after_setup_theme', 'podval_setup' );

/* =============================================
   IMAGE SIZES
   ============================================= */
add_image_size( 'podval-card-xs', 400,  533,  true ); // mobile cards optimized for DPR 2 (~185px slot × 2 = 370 → 400)
add_image_size( 'podval-card-sm', 320,  427,  true ); // mobile cards (~200px slot)
add_image_size( 'podval-card',    600,  800,  true );
add_image_size( 'podval-card-2x', 1200, 1600, true );
add_image_size( 'podval-tag',     480,  360,  true ); // collection tag cards 4:3
add_image_size( 'podval-hero',    1920, 1080, true );
add_image_size( 'podval-thumb',   400,  400,  true );

/* =============================================
   EWWW: skip customer print uploads
   ============================================= */
// 1. Bypass optimization by file path (works for async/cron too)
add_filter( 'ewww_image_optimizer_bypass', function( $bypass, $filename ) {
    if ( strpos( $filename, 'customer-prints' ) !== false ) {
        return true;
    }
    if ( preg_match( '/[\/\\\\]cp-/', $filename ) ) {
        return true;
    }
    return $bypass;
}, 10, 2 );

// 2. Prevent EWWW from generating WebP for customer prints
add_filter( 'ewww_image_optimizer_bypass_webp', function( $bypass, $filename ) {
    if ( strpos( $filename, 'customer-prints' ) !== false ) {
        return true;
    }
    return $bypass;
}, 10, 2 );

// 3. Prevent resizing of customer prints
add_filter( 'ewww_image_optimizer_resize_dimensions', function( $dimensions, $filename ) {
    if ( strpos( $filename, 'customer-prints' ) !== false ) {
        return array( 0, 0 );
    }
    return $dimensions;
}, 10, 2 );

// 4. Skip generating intermediate sizes for customer prints
add_filter( 'intermediate_image_sizes_advanced', function( $sizes, $metadata, $attachment_id = 0 ) {
    if ( $attachment_id && get_post_meta( $attachment_id, '_podval_print_upload', true ) === '1' ) {
        return array(); // no thumbnails needed
    }
    return $sizes;
}, 10, 3 );

// 5. Prevent WP from downscaling large customer print images (-scaled)
add_filter( 'big_image_size_threshold', function( $threshold, $imagesize, $file, $attachment_id ) {
    if ( strpos( $file, 'customer-prints' ) !== false || preg_match( '/[\/\\\\]cp-/', $file ) ) {
        return false; // disable threshold
    }
    return $threshold;
}, 10, 4 );

// 6. Tell EWWW to completely skip attachments with _podval_print_upload meta
add_filter( 'ewww_image_optimizer_should_resize_other_image', function( $should, $file ) {
    if ( strpos( $file, 'customer-prints' ) !== false || preg_match( '/[\/\\\\]cp-/', $file ) ) {
        return false;
    }
    return $should;
}, 10, 2 );

// 7. Prevent EWWW from converting PNG→JPG for customer prints
add_filter( 'ewww_image_optimizer_skip_png_to_jpg', function( $skip, $filename ) {
    if ( strpos( $filename, 'customer-prints' ) !== false || preg_match( '/[\/\\\\]cp-/', $filename ) ) {
        return true;
    }
    return $skip;
}, 10, 2 );

/* =============================================
   AUTO IMAGE META (alt, title) FROM FILENAME
   ============================================= */
add_action( 'add_attachment', function( $attachment_id ) {
    if ( ! wp_attachment_is_image( $attachment_id ) ) return;

    $post = get_post( $attachment_id );
    if ( ! $post ) return;

    // Берём имя файла без расширения
    $filename = pathinfo( get_attached_file( $attachment_id ), PATHINFO_FILENAME );

    // Убираем наш timestamp суффикс (-20260318-210500-123)
    $filename = preg_replace( '/-\d{8}-\d{6}-\d{2,3}$/', '', $filename );

    // Убираем стандартные WP суффиксы (-1, -2, -scaled, -rotated, размеры)
    $filename = preg_replace( '/-\d+x\d+$/', '', $filename );
    $filename = preg_replace( '/-(scaled|rotated|\d+)$/', '', $filename );

    // Заменяем дефисы, подчёркивания, точки на пробелы
    $clean = str_replace( array( '-', '_', '.', '+' ), ' ', $filename );

    // Убираем множественные пробелы
    $clean = preg_replace( '/\s+/', ' ', trim( $clean ) );

    // Делаем первую букву каждого слова заглавной
    $title = mb_convert_case( $clean, MB_CASE_TITLE, 'UTF-8' );

    // Обновляем только если поля пустые (не перезаписываем ручной ввод)
    $current_alt   = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
    $current_title = $post->post_title;

    // WP по умолчанию ставит в title имя файла как есть — проверяем что это не ручной ввод
    $default_title = sanitize_title( $filename );
    $is_default_title = ( sanitize_title( $current_title ) === $default_title || $current_title === $filename );

    if ( empty( $current_alt ) ) {
        update_post_meta( $attachment_id, '_wp_attachment_image_alt', $title );
    }

    if ( $is_default_title || empty( $current_title ) ) {
        wp_update_post( array(
            'ID'           => $attachment_id,
            'post_title'   => $title,
            'post_excerpt' => '', // caption — оставляем пустым
        ) );
    }
} );

/* =============================================
   ENQUEUE STYLES & SCRIPTS
   ============================================= */

/**
 * Возвращает URL для JS-файла: .min.js если существует, иначе оригинал.
 * минифицированные версии main/product/currency/checkout/artwork-upload/service
 * лежат рядом как *.min.js (terser, ~50% меньше). Если разработчик хочет дебажить —
 * достаточно удалить .min.js файл, и подхватится исходник.
 */
function podval_js_file( $name ) {
    $base_dir = get_template_directory() . '/assets/js/';
    $base_uri = get_template_directory_uri() . '/assets/js/';
    $min      = preg_replace( '/\.js$/', '.min.js', $name );
    if ( file_exists( $base_dir . $min ) ) {
        return $base_uri . $min;
    }
    return $base_uri . $name;
}

function podval_enqueue() {
    // Fonts are self-hosted via @font-face in style.css (no Google Fonts CDN)

    // Theme version from style.css
    $theme_ver = wp_get_theme()->get( 'Version' ) ?: '0.1.0';

    // ── Main stylesheet split into conditional modules ──
    // style.css больше НЕ подключается напрямую — он раздроблен на модули:
    // - style-core.css     (~130 KB) — везде: header, footer, hero, общие компоненты,
    //                                  catalog, misc (cd-*, podval-svc-*, podval-about-*)
    // - style-product.css  (~16 KB)  — только страница товара
    // - style-checkout.css (~25 KB)  — корзина, оформление, спасибо за заказ, my-account
    // - style-artwork.css  (~13 KB)  — только page-artwork-upload.php
    //
    // ВАЖНО: catalog и misc вернулись в core, потому что их классы
    // (product-card-*, podval-svc-*, currency-switch.is-active) реально используются
    // на главной странице — без них поедет вёрстка.
    //
    // Главная грузит только style-core.css → -31% CSS-трафика на главной (130 vs 188 KB).
    // Все модули применяют тот же style_loader_tag фильтр (preload + onload swap).
    $css_uri = get_template_directory_uri() . '/assets/css';

    // 1. CORE — всегда
    wp_enqueue_style( 'podval-style-core', $css_uri . '/style-core.css', array(), $theme_ver );

    // 2. PRODUCT — только страница товара
    if ( function_exists( 'is_product' ) && is_product() ) {
        wp_enqueue_style( 'podval-style-product', $css_uri . '/style-product.css', array( 'podval-style-core' ), $theme_ver );
    }

    // 2b. SIZE CHART — попап таблицы размеров.
    // Нужен и на карточке товара, и на странице бандла, поэтому вынесен
    // из style-product.css в отдельный модуль (~2 KB).
    if ( ( function_exists( 'is_product' ) && is_product() ) || is_singular( 'podval_bundle' ) ) {
        wp_enqueue_style( 'podval-style-size-chart', $css_uri . '/style-size-chart.css', array( 'podval-style-core' ), $theme_ver );
    }

    // 3. CHECKOUT — cart, checkout, thank-you, my-account
    if ( ( function_exists( 'is_cart' ) && is_cart() )
      || ( function_exists( 'is_checkout' ) && is_checkout() )
      || ( function_exists( 'is_account_page' ) && is_account_page() )
      || ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) ) {
        wp_enqueue_style( 'podval-style-checkout', $css_uri . '/style-checkout.css', array( 'podval-style-core' ), $theme_ver );
    }

    // 4. ARTWORK — только page-artwork-upload.php
    if ( is_page_template( 'page-artwork-upload.php' ) ) {
        wp_enqueue_style( 'podval-style-artwork', $css_uri . '/style-artwork.css', array( 'podval-style-core' ), $theme_ver );
    }

    // 5. BUNDLES — только page-bundles.php (раздел «Наборы футболок»)
    // добавлена родительская страница раздела бандлов.
    // Стили подгружаются только когда выбран шаблон page-bundles.php.
    if ( is_page_template( 'page-bundles.php' ) ) {
        wp_enqueue_style( 'podval-style-bundles', $css_uri . '/style-bundles.css', array( 'podval-style-core' ), $theme_ver );
    }

    // 6. BUNDLE SINGLE (CPT) — см. блок 6b ниже.
    // старый mock-шаблон page-bundle-single.php удалён,
    // single-страница рендерится через single-podval_bundle.php
    // по WP template hierarchy для CPT podval_bundle.

    // 6b. SINGLE-PODVAL_BUNDLE — CPT single page.
    // Full configurator: цвет/размер/тип переключатели + realtime recalc
    // цены, sticky bottom bar, exclude toggle.
    //
    // Стили:
    //  - style-bundles.css       → .bcard, .mini-tee и пр. (для related blocks)
    //  - style-bundle-single.css → hero, bi-card, configurator, sticky, swatches
    // JS:
    //  - bundle-single.js        → realtime пересчёт, обработчики UI
    if ( is_singular( 'podval_bundle' ) ) {
        wp_enqueue_style( 'podval-style-bundles',       $css_uri . '/style-bundles.css',       array( 'podval-style-core' ),    $theme_ver );
        wp_enqueue_style( 'podval-style-bundle-single', $css_uri . '/style-bundle-single.css', array( 'podval-style-bundles' ), $theme_ver );

        wp_enqueue_script(
            'podval-bundle-single',
            get_template_directory_uri() . '/assets/js/bundle-single.js',
            array(),
            $theme_ver,
            true
        );
    }

    // 7. BUNDLE BUILDER — только page-bundle-builder.php (конструктор «Собери свой бандл»)
    // добавлена страница конструктора.
    // На builder page нужны ТРИ модуля стилей:
    //  - style-bundles.css         → .bcard, .benefits, .mini-tee, .tnail
    //  - style-bundle-single.css   → .bi-num, .size-btn, .sw, .bi-select, .m-stickybar, .btn--lg/full/ic, .crumbs, .sect-head
    //  - style-bundle-builder.css  → уникальные компоненты builder'а
    // Корневой <main> на странице имеет ТРИ класса:
    //   "bundles-page bundle-single-page bundle-builder-page"
    if ( is_page_template( 'page-bundle-builder.php' ) ) {
        wp_enqueue_style( 'podval-style-bundles',        $css_uri . '/style-bundles.css',        array( 'podval-style-core' ),           $theme_ver );
        wp_enqueue_style( 'podval-style-bundle-single',  $css_uri . '/style-bundle-single.css',  array( 'podval-style-bundles' ),        $theme_ver );
        wp_enqueue_style( 'podval-style-bundle-builder', $css_uri . '/style-bundle-builder.css', array( 'podval-style-bundle-single' ),  $theme_ver );
    }

    // 8. SERVICE LANDING — page-service-landing.php.
    // Подключается БЛОКИРУЮЩЕ (handle не в $non_blocking_handles): hero и
    // калькулятор живут в этом файле, а не в critical.css (Р7 ТЗ). Всё, что
    // видно на первом экране, свёрстано на собственных .sl-* классах — core
    // грузится асинхронно, и классы из него на первом экране дали бы сдвиг.
    $is_sl = function_exists( 'podval_sl_is_current' ) && podval_sl_is_current();
    if ( $is_sl ) {
        wp_enqueue_style( 'podval-style-service-landing', $css_uri . '/style-service-landing.css', array( 'podval-style-core' ), $theme_ver );
    }

    // Core JS — загружается на всех страницах (~16 KB после минификации)
    // вынесли секцию "СТРАНИЦА ТОВАРА" (~9.5 KB) в product.js,
    // которая подключается только на странице товара.
    // Минифицированы через terser (~50% экономии).
    wp_enqueue_script(
        'podval-main',
        podval_js_file( 'main.js' ),
        [],
        $theme_ver,
        true
    );

    // Home anchors JS — только на главной (~2 KB):
    // sticky-меню якорей под header'ом для быстрой навигации между секциями.
    // CSS прячет .home-anchors на десктопе (>1024px), так что грузим везде на front,
    // но скрипт на десктопе фактически no-op (nav невидим, observer работает в пустую — но мы его всё равно создаём дёшево).
    if ( is_front_page() || is_home() ) {
        wp_enqueue_script(
            'podval-home-anchors',
            podval_js_file( 'home-anchors.js' ),
            [ 'podval-main' ],
            $theme_ver,
            true
        );
    }

    // Product page JS — только на странице товара (~4 KB):
    // галерея, аккордеон, выбор вариаций, цены, размеры
    if ( function_exists( 'is_product' ) && is_product() ) {
        wp_enqueue_script(
            'podval-product',
            podval_js_file( 'product.js' ),
            [ 'podval-main' ],
            $theme_ver,
            true
        );
    }

    // Checkout + Cart JS — только на страницах оформления/корзины (~14 KB)
    if ( function_exists( 'is_checkout' ) && ( is_checkout() || is_cart() ) ) {
        wp_enqueue_script(
            'podval-checkout',
            podval_js_file( 'checkout.js' ),
            [ 'podval-main' ],
            $theme_ver,
            true
        );
    }

    // Service JS — только на страницах услуг (~11 KB)
    if ( is_page() || ( function_exists( 'is_product' ) && is_product() ) ) {
        // Подключаем на всех страницах товаров-услуг и обычных страницах
        // (service form может быть на page-about, single-product-service и т.д.)
        // IIFE сами проверяют наличие DOM-элементов и выходят если их нет
        $load_service = false;
        if ( function_exists( 'is_product_category' ) && is_product_category( array( 'uslugi', 'dtf-print', 'podval-production' ) ) ) {
            $load_service = true;
        }
        if ( function_exists( 'is_product' ) && is_product() ) {
            // Набор категорий ДОЛЖЕН совпадать с роутингом сервисного шаблона
            // в woocommerce/single-product.php — иначе шаблон с формой отрисуется,
            // а service.js не подключится (форма загрузки файлов «мертва»).
            $svc_cats = array( 'uslugi', 'dtf-print', 'podval-production' );
            if ( has_term( $svc_cats, 'product_cat', get_the_ID() ) ) {
                $load_service = true;
            }
        }
        // Проще: service.js IIFEs проверяют DOM-элементы, загрузка безвредна на обычных страницах
        // но чтобы не грузить на каталоге/главной, ограничим singular + uslugi
        // лендинги услуг — форма заявки с загрузкой файлов
        if ( $is_sl ) {
            $load_service = true;
        }
        if ( $load_service || is_page_template( 'page-o-nas.php' ) || is_page( podval_route_slug( 'about' ) ) ) {
            wp_enqueue_script(
                'podval-service',
                podval_js_file( 'service.js' ),
                [ 'podval-main' ],
                $theme_ver,
                true
            );
        }

        // Service landing JS — калькулятор, галерея, sticky-бар, fetch-отправка
        if ( $is_sl ) {
            wp_enqueue_script(
                'podval-service-landing',
                podval_js_file( 'service-landing.js' ),
                [ 'podval-main', 'podval-service' ],
                $theme_ver,
                true
            );
            wp_localize_script( 'podval-service-landing', 'PodvalSL', [
                'ymId' => (int) trim( (string) get_theme_mod( 'podval_ym_id', '' ) ),
                'tg'   => function_exists( 'podval_sl_tg_url' ) ? podval_sl_tg_url() : '',
            ] );
        }

        // Artwork Upload JS — only on artwork upload page template
        if ( is_page_template( 'page-artwork-upload.php' ) ) {
            wp_enqueue_script(
                'podval-artwork-upload',
                podval_js_file( 'artwork-upload.js' ),
                [ 'podval-main' ],
                $theme_ver,
                true
            );
        }
    }

    // Pass data to JS — проверяем WC перед вызовом его функций
    wp_localize_script( 'podval-main', 'PodvalStore', [
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'podval_nonce' ),
        'restNonce'    => wp_create_nonce( 'wc_store_api' ),
        'cartUrl' => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart/' ),
    ] );
}
add_action( 'wp_enqueue_scripts', 'podval_enqueue' );

/* =============================================
   PERF: Non-blocking main stylesheet
   Critical CSS уже инлайнится в header.php — все модули style-*.css можно
   загружать без блокировки рендера через preload + onload swap.
   Это ровно тот паттерн, что использовал Jetpack Boost.
   ============================================= */
add_filter( 'style_loader_tag', function( $tag, $handle, $href, $media ) {
    // Применяем ко всем нашим CSS-модулям
    $non_blocking_handles = array(
        'podval-style',           // legacy — на случай если где-то ещё подключён
        'podval-style-core',
        'podval-style-product',
        'podval-style-checkout',
        'podval-style-artwork',
        'podval-style-bundles',   // страница раздела «Наборы»
        'podval-style-bundle-single', // детальная страница бандла
        'podval-style-bundle-builder', // конструктор бандла
        'podval-card-carousel',   // 1.5 КБ файла блокировали рендер на 1033 мс
        'podval-style-size-chart', // таблица размеров живёт в модалке, до открытия не нужна
    );
    if ( ! in_array( $handle, $non_blocking_handles, true ) ) {
        return $tag;
    }
    // Не трогаем для админки/кастомайзера
    if ( is_admin() || is_customize_preview() ) {
        return $tag;
    }
    // Преобразуем в неблокирующую загрузку
    $href_esc = esc_url( $href );
    $new_tag  = '<link rel="preload" href="' . $href_esc . '" as="style" onload="this.onload=null;this.rel=\'stylesheet\'">' . "\n";
    $new_tag .= '<noscript><link rel="stylesheet" href="' . $href_esc . '"></noscript>' . "\n";
    return $new_tag;
}, 10, 4 );

/* =============================================
   WIDGET AREAS
   ============================================= */
function podval_widgets_init() {
    register_sidebar( [
        'name'          => __( 'Sidebar каталога', 'podval-print' ),
        'id'            => 'catalog-sidebar',
        'before_widget' => '<div class="widget %2$s">',
        'after_widget'  => '</div>',
        'before_title'  => '<h3 class="widget-title">',
        'after_title'   => '</h3>',
    ] );
}
add_action( 'widgets_init', 'podval_widgets_init' );

/* =============================================
   КАСТОМНЫЕ МЕТАПОЛЯ В РЕДАКТОРЕ ТОВАРА
   ============================================= */
function podval_product_meta_box() {
    add_meta_box(
        'podval_product_options',
        __( 'Настройки Podval Print', 'podval-print' ),
        'podval_product_meta_box_html',
        'product',
        'side',
        'default'
    );
}
add_action( 'add_meta_boxes', 'podval_product_meta_box' );

function podval_product_meta_box_html( $post ) {
    wp_nonce_field( 'podval_save_meta', 'podval_meta_nonce' );
    $badge      = get_post_meta( $post->ID, '_podval_badge', true );
    $brand_name = get_post_meta( $post->ID, '_podval_brand', true );
    $size_chart = get_post_meta( $post->ID, '_podval_size_chart', true );
    // Редактор размещения принта: пустое значение = включено (legacy-товары не ломаются).
    // Выключаем только при явном '0'.
    $placement_enabled = ( get_post_meta( $post->ID, '_podval_placement_enabled', true ) !== '0' );
    ?>
    <p>
        <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">
            <?php esc_html_e( 'Бейдж', 'podval-print' ); ?>
        </label>
        <select name="podval_badge" style="width:100%">
            <option value="" <?php selected( $badge, '' ); ?>><?php esc_html_e( '— нет —', 'podval-print' ); ?></option>
            <option value="new"       <?php selected( $badge, 'new'       ); ?>>Новинка</option>
            <option value="limited"   <?php selected( $badge, 'limited'   ); ?>>Limited</option>
            <option value="preorder"  <?php selected( $badge, 'preorder'  ); ?>>Предзаказ</option>
            <option value="custom"    <?php selected( $badge, 'custom'    ); ?>>Custom</option>
            <option value="yourphoto" <?php selected( $badge, 'yourphoto' ); ?>>Ваше фото</option>
            <option value="promo"     <?php selected( $badge, 'promo'     ); ?>>Промо (технический, не виден клиентам)</option>
        </select>
    </p>
    <p>
        <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">
            <?php esc_html_e( 'Бренд / коллаб', 'podval-print' ); ?>
        </label>
        <input type="text" name="podval_brand" value="<?php echo esc_attr( $brand_name ); ?>"
               style="width:100%" placeholder="Название бренда или автора" />
    </p>
    <p>
        <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">
            <?php esc_html_e( 'Таблица размеров', 'podval-print' ); ?>
        </label>
        <select name="podval_size_chart" style="width:100%">
            <option value="" <?php selected( $size_chart, '' ); ?>><?php esc_html_e( '— нет —', 'podval-print' ); ?></option>
            <optgroup label="Встроенные">
                <option value="tshirt" <?php selected( $size_chart, 'tshirt' ); ?>>Футболка (Classic + Oversize)</option>
                <option value="hoodie" <?php selected( $size_chart, 'hoodie' ); ?>>Худи</option>
            </optgroup>
            <?php
            $sc_posts = get_posts( array( 'post_type' => 'podval_size_chart', 'posts_per_page' => 50, 'orderby' => 'title', 'order' => 'ASC' ) );
            if ( $sc_posts ) : ?>
            <optgroup label="Свои таблицы">
            <?php foreach ( $sc_posts as $sc_post ) : ?>
                <option value="<?php echo esc_attr( $sc_post->ID ); ?>" <?php selected( $size_chart, (string) $sc_post->ID ); ?>><?php echo esc_html( $sc_post->post_title ); ?></option>
            <?php endforeach; ?>
            </optgroup>
            <?php endif; ?>
        </select>
    </p>
    <p style="margin-top:14px;padding-top:12px;border-top:1px solid #e2e4e7;">
        <label style="font-size:12px;font-weight:600;display:flex;align-items:flex-start;gap:8px;cursor:pointer;">
            <input type="checkbox" name="podval_placement_enabled" value="1" <?php checked( $placement_enabled ); ?> style="margin-top:1px;" />
            <span>
                <?php esc_html_e( 'Редактор «примерное расположение»', 'podval-print' ); ?>
                <span style="display:block;font-weight:400;color:#646970;margin-top:3px;line-height:1.4;">
                    <?php esc_html_e( 'Кнопка визуального размещения принта на схеме изделия (страница загрузки дизайна). Отключите для товаров-шаблонов, где клиент просто прикладывает набор фото.', 'podval-print' ); ?>
                </span>
            </span>
        </label>
    </p>
    <?php
}

function podval_save_product_meta( $post_id ) {
    if ( ! isset( $_POST['podval_meta_nonce'] ) ) return;
    if ( ! wp_verify_nonce( $_POST['podval_meta_nonce'], 'podval_save_meta' ) ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    $allowed_badges = [ '', 'new', 'limited', 'preorder', 'custom', 'yourphoto', 'promo' ];
    $badge = sanitize_text_field( $_POST['podval_badge'] ?? '' );
    if ( in_array( $badge, $allowed_badges, true ) ) {
        update_post_meta( $post_id, '_podval_badge', $badge );
    }
    $brand = sanitize_text_field( $_POST['podval_brand'] ?? '' );
    update_post_meta( $post_id, '_podval_brand', $brand );

    $allowed_charts = [ '', 'tshirt', 'hoodie' ];
    $size_chart = sanitize_text_field( $_POST['podval_size_chart'] ?? '' );
    // Accept CPT post ID or legacy values
    if ( $size_chart === '' || in_array( $size_chart, $allowed_charts, true ) || ( is_numeric( $size_chart ) && get_post_type( (int) $size_chart ) === 'podval_size_chart' ) ) {
        update_post_meta( $post_id, '_podval_size_chart', $size_chart );
    }

    // Редактор размещения: '0' = выключен, '1' = включён.
    // Храним явное '1' тоже (а не удаляем мету), чтобы потом легко
    // отличать «осознанно включено» от legacy-товаров без меты.
    $placement_enabled = ! empty( $_POST['podval_placement_enabled'] ) ? '1' : '0';
    update_post_meta( $post_id, '_podval_placement_enabled', $placement_enabled );
}
add_action( 'save_post_product', 'podval_save_product_meta' );

/* =============================================
   WooCommerce HOOKS — только когда WC загружен
   ============================================= */
add_action( 'woocommerce_loaded', function() {

    // Убрать sidebar
    remove_action( 'woocommerce_sidebar', 'woocommerce_get_sidebar', 10 );

    // Количество колонок и товаров
    add_filter( 'loop_shop_columns',  function() { return 4; } );
    add_filter( 'loop_shop_per_page', function() { return 999; }, 9999 );

    // Принудительно убираем лимит на товары в каталоге
    add_action( 'pre_get_posts', function( $q ) {
        if ( ! is_admin() && $q->is_main_query() && ( is_shop() || is_product_category() || is_product_tag() ) ) {
            $q->set( 'posts_per_page', 999 );
        }
    }, 9999 );

    /* поиск по сайту = поиск по товарам (search.php).
       Без этого ?s= отдавал посты/страницы вперемешку через index.php-fallback. */
    add_action( 'pre_get_posts', function( $q ) {
        if ( is_admin() || ! $q->is_main_query() || ! $q->is_search() ) return;
        $q->set( 'post_type', 'product' );
        $q->set( 'post_status', 'publish' );
        $q->set( 'posts_per_page', 48 );
    }, 20 );

    // Хлебные крошки
    add_filter( 'woocommerce_breadcrumb_defaults', function( $defaults ) {
        $defaults['delimiter']   = ' / ';
        $defaults['wrap_before'] = '<nav class="breadcrumb-nav" aria-label="breadcrumb">';
        $defaults['wrap_after']  = '</nav>';
        return $defaults;
    } );

    // Убрать дефолтные стили WC (мы стилизуем сами)
    add_filter( 'woocommerce_enqueue_styles', '__return_empty_array' );

    // Бейджи товаров в каталоге
    add_action( 'woocommerce_before_shop_loop_item', function() {
        global $product;
        if ( ! $product ) return;
        $badge   = get_post_meta( $product->get_id(), '_podval_badge', true );
        $badges  = [];

        if ( $badge === 'new' ) {
            $badges[] = '<span class="badge-tag badge-new">Новинка</span>';
        }
        if ( $badge === 'limited' ) {
            $badges[] = '<span class="badge-tag badge-limited">Limited</span>';
        }
        if ( $badge === 'preorder' ) {
            $badges[] = '<span class="badge-tag badge-preorder">Предзаказ</span>';
        }
        if ( $badge === 'yourphoto' ) {
            $badges[] = '<span class="badge-tag badge-yourphoto">Ваше фото</span>';
        }
        if ( $product->is_on_sale() ) {
            $badges[] = '<span class="badge-tag badge-sale">Sale</span>';
        }
        if ( ! $product->is_in_stock() ) {
            $badges[] = '<span class="badge-tag badge-sold-out">Sold Out</span>';
        }
        if ( $badges ) {
            echo '<div class="product-badges">' . implode( '', $badges ) . '</div>';
        }
    }, 5 );

    // Бренд перед названием в loop
    add_action( 'woocommerce_shop_loop_item_title', function() {
        global $product;
        if ( ! $product ) return;
        $brand = get_post_meta( $product->get_id(), '_podval_brand', true );
        if ( $brand ) {
            echo '<div class="product-brand-tag">' . esc_html( $brand ) . '</div>';
        }
    }, 5 );

} );

/* =============================================
   AJAX: ДОБАВЛЕНИЕ В КОРЗИНУ
   ============================================= */


/* =============================================
   КАСТОМНАЯ ТАКСОНОМИЯ: Бренды
   ============================================= */
function podval_register_brand_taxonomy() {
    register_taxonomy( 'product_brand', 'product', [
        'labels' => [
            'name'          => __( 'Бренды', 'podval-print' ),
            'singular_name' => __( 'Бренд', 'podval-print' ),
            'menu_name'     => __( 'Бренды', 'podval-print' ),
            'add_new_item'  => __( 'Добавить бренд', 'podval-print' ),
            'edit_item'     => __( 'Редактировать бренд', 'podval-print' ),
        ],
        'hierarchical'      => true,
        'show_ui'           => true,
        'show_admin_column' => true,
        'query_var'         => true,
        'rewrite'           => [ 'slug' => 'brand' ],
        'show_in_rest'      => true,
    ] );
}
add_action( 'init', 'podval_register_brand_taxonomy' );
add_action( 'init', function() { if(get_option('woocommerce_coming_soon')==='yes') update_option('woocommerce_coming_soon','no'); if(get_option('woocommerce_store_pages_only')==='yes') update_option('woocommerce_store_pages_only','no'); } );

/* =============================================
   HELPER: SVG ICONS
   ============================================= */
function podval_icon( $name, $class = '' ) {
    $icons = [
        'cart'   => '<svg class="icon ' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>',
        'heart'  => '<svg class="icon ' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>',
        'search' => '<svg class="icon ' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>',
        'user'   => '<svg class="icon ' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
        'arrow'  => '<svg class="icon ' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>',
        'home'   => '<svg class="icon ' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
        'grid'   => '<svg class="icon ' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>',
    ];
    return $icons[ $name ] ?? '';
}


/* =============================================
   ОТКЛЮЧАЕМ РЕЖИМ "МАГАЗИН СКОРО ОТКРОЕТСЯ"
   WooCommerce 8.x включает его по умолчанию
   ============================================= */
add_filter( 'woocommerce_coming_soon', '__return_false' );
add_filter( 'woocommerce_store_pages_only', '__return_false' );

// Жёстко выключаем coming soon через update_option
// Примечание: __return_true убран — он делал ВСЕ товары покупаемыми,
// включая те, где цена=0 или не указана. Фильтры coming_soon выше
// уже решают проблему блокировки "скоро откроемся".


/* =============================================
   ПРИНУДИТЕЛЬНО ПОДКЛЮЧАЕМ НАШ ШАБЛОН АРХИВА
   Работает даже без ЧПУ-ссылок
   ============================================= */
// template_include filter removed — WC handles template routing automatically


/* =============================================
   КАСТОМНЫЕ ШАБЛОНЫ СТРАНИЦ WC (корзина, каталог, оформление)
   ============================================= */
add_filter( 'template_include', function( $template ) {
    if ( ! function_exists( 'is_woocommerce' ) ) return $template;

    // Страница оформления заказа
    if ( is_checkout() && ! is_wc_endpoint_url() ) {
        $custom = get_stylesheet_directory() . '/woocommerce/checkout/page-checkout.php';
        if ( file_exists( $custom ) ) return $custom;
    }

    // Страница корзины
    if ( is_cart() ) {
        $custom = get_stylesheet_directory() . '/woocommerce/cart/page-cart.php';
        if ( file_exists( $custom ) ) return $custom;
    }

    // Категория "Услуги" — кастомный шаблон
    if ( is_product_category( 'uslugi' ) ) {
        $custom = get_stylesheet_directory() . '/woocommerce/taxonomy-product_cat-uslugi.php';
        if ( file_exists( $custom ) ) return $custom;
    }

    // Каталог / категории
    if ( ( is_product_category() || is_shop() || is_product_tag() ) && ! is_singular() ) {
        $custom = get_stylesheet_directory() . '/woocommerce/archive-product.php';
        if ( file_exists( $custom ) ) return $custom;
    }

    return $template;
}, 99 );




/* =============================================
   ХЕЛПЕР: настройки баннера категории
   ============================================= */
if ( ! function_exists('podval_cat_banner') ) {
    /**
     * Получить настройку баннера для категории или тега.
     * Приоритет: точный slug → фолбэк на 'shop'.
     * Для тегов slug приходит с префиксом 'tag__'.
     */
    function podval_cat_banner( $key, $slug ) {
        $safe = sanitize_key( $slug );
        $val = get_theme_mod( "podval_cat_banner_{$key}_{$safe}", '' );
        // Фолбэк на общий каталог
        if ( $val === '' && $safe !== 'shop' ) {
            $val = get_theme_mod( "podval_cat_banner_{$key}_shop", '' );
        }
        return $val;
    }
}


/* Load Customizer */
require get_template_directory() . '/inc/copy.php';       // тексты сайта — слой данных
require get_template_directory() . '/inc/admin-menu.php'; // раздел сайта в админке
require get_template_directory() . '/inc/customizer.php';

/* Реестр цен — раньше лендингов: они строятся на его API */
require get_template_directory() . '/inc/prices.php';

/* Лендинги услуг: мета-бокс, хелперы, schema, редиректы, сидер */
require get_template_directory() . '/inc/service-landing.php';

/* Load SEO module (JSON-LD schemas) */
require get_template_directory() . '/inc/seo.php';

/* Load Analytics module (gtag, Metrika, FB Pixel) */
require get_template_directory() . '/inc/analytics.php';

// Currency switcher (BYN / RUB)
require get_template_directory() . '/inc/currency.php';

/* Mail debug module — активируется через define('PODVAL_DEBUG_MAIL', true) в wp-config.php */
require get_template_directory() . '/inc/debug-mail.php';

/* Google Merchant Center XML feed — /feed/google.xml */
require get_template_directory() . '/inc/google-feed.php';

/* CPT «Бандлы» — регистрация, мета-боксы, AJAX product search */
require get_template_directory() . '/inc/cpt-bundle.php';

/* Бандлы ↔ WC Cart — AJAX add-to-cart, cart hooks, coupon exclusion */
require get_template_directory() . '/inc/cart-bundle.php';

/* Переименование файлов принта по производственной маске
   {НОМЕР}-{ТИП}-{РАЗМЕР}-{ЦВЕТ}-{СТОРОНА}, напр. 280-OVR-L-BLK-FRONT */
require get_template_directory() . '/inc/print-filenames.php';

/* Конструктор-лайт шаблонных принтов:
   реестр основ + интеграция (мета-бокс, роутинг, AJAX, корзина, заказ) */
require get_template_directory() . '/inc/print-templates.php';
require get_template_directory() . '/inc/tpl-builder.php';
require get_template_directory() . '/inc/tpl-admin.php';

/* Карусель вариантов дизайна в карточке каталога */
require get_template_directory() . '/inc/card-carousel.php';

/* ═══════════════════════════════════════════
   КОРЗИНА — AJAX handlers
   ═══════════════════════════════════════════ */













/* ═══════════════════════════════════════════
   КОРЗИНА — обработка действий через init
   (срабатывает ДО template_include)
   ═══════════════════════════════════════════ */
add_action( 'wp_loaded', function() {
    // Только если есть наши параметры
    $has_action = ! empty( $_GET['podval_remove'] )
               || ! empty( $_GET['podval_clear'] )
               || ! empty( $_POST['podval_update_qty'] )
               || ! empty( $_POST['podval_add_item'] )
               || ! empty( $_GET['podval_coupon'] );

    if ( ! $has_action ) return;
    if ( ! function_exists('WC') || ! WC()->cart ) return;

    // Удаление товара: ?podval_remove=KEY
    if ( ! empty( $_GET['podval_remove'] ) ) {
        $key = sanitize_text_field( wp_unslash( $_GET['podval_remove'] ) );
        if ( WC()->cart->get_cart_item( $key ) ) {
            WC()->cart->remove_cart_item( $key );
            WC()->cart->calculate_totals();
        }
        wp_safe_redirect( wc_get_cart_url() );
        exit;
    }

    // Очистка: ?podval_clear=1
    if ( ! empty( $_GET['podval_clear'] ) ) {
        WC()->cart->empty_cart();
        wp_safe_redirect( wc_get_cart_url() );
        exit;
    }

    // Обновление количества: POST podval_update_qty
    if ( ! empty( $_POST['podval_update_qty'] ) ) {
        $key = sanitize_text_field( $_POST['podval_update_qty'] );
        $qty = max( 0, intval( $_POST['podval_qty'] ?? 0 ) );
        $cart_item = WC()->cart->get_cart_item( $key );
        $is_bundle = ! empty( $cart_item['_bundle_meta'] );

        if ( $qty === 0 ) {
            WC()->cart->remove_cart_item( $key );
        } elseif ( $is_bundle ) {
            // Бандл — quantity всегда 1, изменения игнорируем
            WC()->cart->set_quantity( $key, 1, true );
        } else {
            WC()->cart->set_quantity( $key, $qty, true );
        }
        WC()->cart->calculate_totals();
        wp_safe_redirect( wc_get_cart_url() );
        exit;
    }

    // Промокод: ?podval_coupon=CODE
    if ( ! empty( $_GET['podval_coupon'] ) ) {
        $code = sanitize_text_field( wp_unslash( $_GET['podval_coupon'] ) );
        $result = WC()->cart->apply_coupon( $code );
        if ( ! $result ) {
            wc_add_notice( 'Промокод не найден или недействителен', 'error' );
        }
        wp_safe_redirect( wc_get_cart_url() );
        exit;
    }

    // Добавление товара в корзину (AJAX POST)
    if ( empty( $_POST['podval_add_item'] ) ) return;
    if ( ! function_exists('WC') || ! WC()->cart ) return;

    // Инициализируем сессию WC если она ещё не стартовала (для гостей)
    if ( WC()->session && ! WC()->session->has_session() ) {
        WC()->session->set_customer_session_cookie( true );
    }

    $product_id   = absint( $_POST['podval_product_id'] ?? 0 );
    $variation_id = absint( $_POST['podval_variation_id'] ?? 0 );
    $quantity     = max( 1, absint( $_POST['podval_quantity'] ?? 1 ) );

    if ( ! $product_id ) { status_header(400); echo 'error: no product_id'; exit; }

    // Собираем атрибуты вариации
    // ВАЖНО: ключи атрибутов хранятся в WC как URL-encoded кириллица
    // (attribute_%d0%bc%d0%be%d0%b4%d0%b5%d0%bb%d1%8c)
    // Нужно передавать их в add_to_cart() РОВНО в таком виде!
    // Но пустые значения ("Any") нужно заполнить из POST данных.

    $variation = array();

    if ( $variation_id > 0 ) {
        $var_product = wc_get_product( $variation_id );
        if ( $var_product && $var_product->is_type( 'variation' ) ) {
            $raw_attrs = $var_product->get_variation_attributes();
            // raw_attrs: { 'attribute_%d0%bc...' => 'Классическая футболка', 'attribute_%d1%80...' => '' }

            // Собираем POST podval_attr_* → { 'размер' => 'L', 'модель' => '...' }
            $js_attrs = array();
            foreach ( $_POST as $pk => $pv ) {
                if ( strpos( $pk, 'podval_attr_' ) === 0 ) {
                    $js_key = mb_strtolower( sanitize_text_field( substr( $pk, strlen('podval_attr_') ) ) );
                    $js_attrs[ $js_key ] = sanitize_text_field( $pv );
                }
            }

            foreach ( $raw_attrs as $original_key => $attr_val ) {
                // Оставляем оригинальный ключ как есть (URL-encoded или нет)
                if ( $attr_val !== '' ) {
                    // Атрибут заполнен — используем как есть
                    $variation[ $original_key ] = $attr_val;
                } else {
                    // Атрибут пуст = "Any" — ищем в JS POST данных
                    // Декодируем ключ только для СОПОСТАВЛЕНИЯ с JS данными
                    $decoded = urldecode( $original_key );
                    $clean_name = mb_strtolower( str_replace( 'attribute_', '', $decoded ) );

                    $found = '';
                    // Прямое совпадение: "размер" == "размер"
                    if ( isset( $js_attrs[ $clean_name ] ) ) {
                        $found = $js_attrs[ $clean_name ];
                    }
                    // Без pa_ prefix
                    if ( $found === '' ) {
                        $no_pa = str_replace( 'pa_', '', $clean_name );
                        if ( isset( $js_attrs[ $no_pa ] ) ) {
                            $found = $js_attrs[ $no_pa ];
                        }
                    }
                    // По label WC атрибута (для taxonomy атрибутов)
                    if ( $found === '' && taxonomy_exists( $clean_name ) ) {
                        $wc_label = mb_strtolower( wc_attribute_label( $clean_name ) );
                        if ( isset( $js_attrs[ $wc_label ] ) ) {
                            $found = $js_attrs[ $wc_label ];
                        }
                    }

                    $variation[ $original_key ] = $found;
                }
            }
        }
    }

    // Очищаем WC notices перед добавлением (чтобы старые ошибки не копились)
    if ( function_exists( 'wc_clear_notices' ) ) {
        wc_clear_notices();
    }

    $added = false;
    if ( $variation_id > 0 ) {
        $added = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variation );
    } else {
        $added = WC()->cart->add_to_cart( $product_id, $quantity );
    }

    // Очищаем notices после тоже — они не нужны для AJAX ответа
    $notices = '';
    if ( function_exists( 'wc_get_notices' ) ) {
        $all_notices = wc_get_notices();
        foreach ( $all_notices as $type => $msgs ) {
            foreach ( $msgs as $msg ) {
                $notices .= ( is_array($msg) ? ($msg['notice'] ?? '') : $msg ) . '; ';
            }
        }
        wc_clear_notices();
    }

    if ( $added ) {
        WC()->cart->calculate_totals();
        if ( WC()->session ) {
            WC()->session->save_data();
        }
    }

    status_header(200);
    header( 'Content-Type: text/plain; charset=utf-8' );
    if ( $added ) {
        echo 'ok|' . WC()->cart->get_cart_contents_count();
    } else {
        // Дебаг: показываем что было отправлено в add_to_cart
        $debug = 'pid=' . $product_id . ' vid=' . $variation_id . ' variation=' . wp_json_encode( $variation, JSON_UNESCAPED_UNICODE );
        echo 'error|' . trim( $notices ) . '|' . $debug;
    }
    exit;

}, 15 );

/* ═══════════════════════════════════════════
   CHECKOUT — кастомные поля, доставка, оплата
   ═══════════════════════════════════════════ */

/**
 * Нормализация мессенджера: добавляет @ если похоже на username.
 * Если значение начинается с + или цифры — это номер, не трогаем.
 * Если уже начинается с @ — не дублируем.
 */
function podval_normalize_messenger( $value ) {
    $value = trim( $value );
    if ( $value === '' ) return $value;

    // Если уже начинается с @ или +, или выглядит как номер — не трогаем
    if ( preg_match( '/^[@+]/', $value ) ) return $value;
    if ( preg_match( '/^\d/', $value ) ) return $value;

    // Похоже на username (содержит буквы, нет пробелов в начале) — добавляем @
    return '@' . $value;
}

// Регистрируем кастомные платёжные шлюзы: наличные (cod) и терминал
add_filter( 'woocommerce_payment_gateways', function( $gateways ) {
    // cod уже есть в WC, но нужно добавить terminal
    // Кастомный шлюз: Оплата наличными
    if ( ! class_exists( 'WC_Gateway_Podval_Cash' ) ) {
        class WC_Gateway_Podval_Cash extends WC_Payment_Gateway {
            public function __construct() {
                $this->id                 = 'podval_cash';
                $this->method_title       = 'Оплата наличными';
                $this->method_description = 'Оплата наличными при самовывозе';
                $this->has_fields         = false;

                $this->init_form_fields();
                $this->init_settings();

                $this->title   = $this->get_option( 'title', 'Оплата наличными' );
                $this->enabled = $this->get_option( 'enabled', 'yes' );

                add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            }
            public function init_form_fields() {
                $this->form_fields = array(
                    'enabled' => array( 'title' => 'Включить/Выключить', 'type' => 'checkbox', 'label' => 'Включить оплату наличными', 'default' => 'yes' ),
                    'title'   => array( 'title' => 'Название', 'type' => 'text', 'default' => 'Оплата наличными' ),
                );
            }
            public function process_payment( $order_id ) {
                $order = wc_get_order( $order_id );
                $order->update_status( 'on-hold', 'Ожидает оплату наличными при получении.' );
                WC()->cart->empty_cart();
                return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
            }
        }
    }
    $gateways[] = 'WC_Gateway_Podval_Cash';

    // Кастомный шлюз: Оплата картой через терминал
    if ( ! class_exists( 'WC_Gateway_Terminal' ) ) {
        class WC_Gateway_Terminal extends WC_Payment_Gateway {
            public function __construct() {
                $this->id                 = 'terminal';
                $this->method_title       = 'Оплата картой через терминал';
                $this->method_description = 'Оплата банковской картой через терминал при самовывозе';
                $this->has_fields         = false;

                $this->init_form_fields();
                $this->init_settings();

                $this->title   = $this->get_option( 'title', 'Оплата картой через терминал' );
                $this->enabled = $this->get_option( 'enabled', 'yes' );

                add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            }
            public function init_form_fields() {
                $this->form_fields = array(
                    'enabled' => array(
                        'title'   => 'Включить/Выключить',
                        'type'    => 'checkbox',
                        'label'   => 'Включить оплату картой через терминал',
                        'default' => 'yes',
                    ),
                    'title' => array(
                        'title'   => 'Название',
                        'type'    => 'text',
                        'default' => 'Оплата картой через терминал',
                    ),
                );
            }
            public function process_payment( $order_id ) {
                $order = wc_get_order( $order_id );
                $order->update_status( 'on-hold', 'Ожидает оплату картой при получении.' );
                WC()->cart->empty_cart();
                return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
            }
        }
    }
    $gateways[] = 'WC_Gateway_Terminal';

    // Кастомный шлюз: Оплата по ссылке (после согласования макета)
    if ( ! class_exists( 'WC_Gateway_Podval_Pay_Link' ) ) {
        class WC_Gateway_Podval_Pay_Link extends WC_Payment_Gateway {
            public function __construct() {
                $this->id                 = 'podval_pay_link';
                $this->method_title       = 'Оплата после согласования';
                $this->method_description = 'Клиент получает ссылку на оплату после согласования макета';
                $this->has_fields         = false;

                $this->init_form_fields();
                $this->init_settings();

                $this->title   = $this->get_option( 'title', 'Оплата после согласования' );
                $this->enabled = $this->get_option( 'enabled', 'yes' );

                add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            }
            public function init_form_fields() {
                $this->form_fields = array(
                    'enabled' => array( 'title' => 'Включить/Выключить', 'type' => 'checkbox', 'label' => 'Включить оплату после согласования', 'default' => 'yes' ),
                    'title'   => array( 'title' => 'Название', 'type' => 'text', 'default' => 'Оплата после согласования' ),
                );
            }
            public function process_payment( $order_id ) {
                $order = wc_get_order( $order_id );
                $order->update_status( 'on-hold', 'Ожидает согласования макета и оплаты по ссылке.' );
                WC()->cart->empty_cart();
                return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
            }
        }
    }
    $gateways[] = 'WC_Gateway_Podval_Pay_Link';

    return $gateways;
});

// ── Пропускаем промежуточный pending для оффлайн-шлюзов ──────────────────
// WooCommerce создаёт заказ со статусом pending, затем process_payment() ставит on-hold.
// Переход pending→on-hold вызывает woocommerce_order_status_changed и бот шлёт 2 сообщения.
// Этот фильтр сразу создаёт заказ в on-hold для наших оффлайн-шлюзов.
add_filter( 'woocommerce_default_order_status', function( $status ) {
    if ( ! WC()->session ) return $status;
    $chosen = WC()->session->get( 'chosen_payment_method' );
    if ( in_array( $chosen, array( 'podval_cash', 'terminal', 'podval_pay_link' ), true ) ) {
        return 'on-hold';
    }
    return $status;
});

// ── Ручной триггер WC_Email_New_Order для заказов в on-hold ──────────────
// Стандартный WC_Email_New_Order привязан к переходам pending→* и failed→*,
// но НЕ к on-hold→*. Так как наши оффлайн-шлюзы создают заказ сразу в on-hold,
// минуя pending, админское письмо "Новый заказ" никогда не отправляется.
// Этот хук восстанавливает отправку.
add_action( 'woocommerce_new_order', function( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;

    // Только для заказов, созданных сразу в on-hold (наши оффлайн-шлюзы)
    if ( $order->get_status() !== 'on-hold' ) return;

    // Дедупликация — один раз на заказ
    if ( $order->get_meta( '_podval_new_order_email_sent' ) === 'yes' ) return;

    if ( ! function_exists( 'WC' ) ) return;
    $mailer = WC()->mailer();
    if ( ! $mailer ) return;

    $emails = $mailer->get_emails();
    if ( empty( $emails['WC_Email_New_Order'] ) ) return;

    $email = $emails['WC_Email_New_Order'];
    if ( ! $email->is_enabled() ) return;

    $email->trigger( $order_id, $order );

    $order->update_meta_data( '_podval_new_order_email_sent', 'yes' );
    $order->save();
}, 20, 1 );

// Убираем поля WC которые нам не нужны
add_filter( 'woocommerce_checkout_fields', function( $fields ) {
    // Убираем ненужные billing поля
    unset( $fields['billing']['billing_last_name'] );
    unset( $fields['billing']['billing_company'] );
    unset( $fields['billing']['billing_country'] );
    unset( $fields['billing']['billing_address_1'] );
    unset( $fields['billing']['billing_address_2'] );
    unset( $fields['billing']['billing_city'] );
    unset( $fields['billing']['billing_state'] );
    unset( $fields['billing']['billing_postcode'] );
    unset( $fields['billing']['billing_email'] );

    // Делаем billing_first_name и billing_phone обязательными/необязательными
    if ( isset( $fields['billing']['billing_first_name'] ) ) {
        $fields['billing']['billing_first_name']['required'] = true;
    }
    if ( isset( $fields['billing']['billing_phone'] ) ) {
        $fields['billing']['billing_phone']['required'] = true;
    }

    // Добавляем кастомное поле мессенджера
    $fields['billing']['billing_messenger'] = array(
        'type'     => 'text',
        'label'    => 'Telegram',
        'required' => true,
        'priority' => 25,
    );

    // Убираем shipping поля (мы управляем доставкой сами)
    unset( $fields['shipping'] );

    return $fields;
});

// Кастомные поля (мессенджер, доставка, адрес, файлы) сохраняются
// через woocommerce_checkout_create_order (ниже) — он работает и с AJAX, и с обычным checkout.
// Дублировать через update_order_meta НЕ нужно.

// Показываем кастомные поля в админке заказа (HPOS-совместимо)
add_action( 'woocommerce_admin_order_data_after_billing_address', function( $order ) {
    $messenger = $order->get_meta( '_billing_messenger', true );
    $shipping  = $order->get_meta( '_podval_shipping_label', true );
    $address   = $order->get_shipping_address_1();

    if ( $messenger ) {
        echo '<p><strong>Мессенджер:</strong> ' . esc_html( $messenger ) . '</p>';
    }
    if ( $shipping ) {
        echo '<p><strong>Способ доставки:</strong> ' . esc_html( $shipping ) . '</p>';
    }
    if ( $address ) {
        echo '<p><strong>Адрес доставки:</strong> ' . esc_html( $address ) . '</p>';
    }
}, 5 );

// Показываем мессенджер в emails заказа
add_action( 'woocommerce_email_after_order_table', function( $order ) {
    $messenger = $order->get_meta( '_billing_messenger', true );
    $shipping  = $order->get_meta( '_podval_shipping_label', true );
    $address   = $order->get_shipping_address_1();

    if ( $messenger ) echo '<p><strong>Мессенджер:</strong> ' . esc_html( $messenger ) . '</p>';
    if ( $shipping )  echo '<p><strong>Доставка:</strong> ' . esc_html( $shipping ) . '</p>';
    if ( $address )   echo '<p><strong>Адрес:</strong> ' . esc_html( $address ) . '</p>';
});

// Валидация: мессенджер обязателен
add_action( 'woocommerce_checkout_process', function() {
    if ( empty( $_POST['billing_messenger'] ) ) {
        wc_add_notice( 'Укажите Telegram для связи.', 'error' );
    }
});

// Примечание: обязательность скрытых полей (billing_last_name, email, country, city и т.д.)
// уже снята через woocommerce_checkout_fields и woocommerce_default_address_fields ниже.

// Убираем обязательность email и lastname на уровне WC
add_filter( 'woocommerce_checkout_posted_data', function( $data ) {
    if ( empty( $data['billing_last_name'] ) ) $data['billing_last_name'] = '';
    if ( empty( $data['billing_email'] ) ) $data['billing_email'] = '';
    if ( empty( $data['billing_country'] ) ) $data['billing_country'] = 'BY';
    return $data;
});

// Убираем валидацию обязательных полей, которые мы скрыли
add_filter( 'woocommerce_default_address_fields', function( $fields ) {
    if ( isset( $fields['last_name'] ) )  $fields['last_name']['required'] = false;
    if ( isset( $fields['country'] ) )    $fields['country']['required'] = false;
    if ( isset( $fields['city'] ) )       $fields['city']['required'] = false;
    if ( isset( $fields['address_1'] ) )  $fields['address_1']['required'] = false;
    if ( isset( $fields['postcode'] ) )   $fields['postcode']['required'] = false;
    if ( isset( $fields['state'] ) )      $fields['state']['required'] = false;
    return $fields;
});

// Email не обязателен
add_filter( 'woocommerce_billing_fields', function( $fields ) {
    if ( isset( $fields['billing_email'] ) ) {
        $fields['billing_email']['required'] = false;
    }
    return $fields;
});

/* ═══════════════════════════════════════════
   CHECKOUT — загрузка файлов принта через AJAX
   ═══════════════════════════════════════════ */

/**
 * прежней версии (безопасность): заказ страницы «Спасибо» — только при верном ключе.
 *
 * page-thankyou.php подключается через template_include в обход шорткода
 * WooCommerce, поэтому штатная проверка order key не выполнялась: перебор
 * /checkout/order-received/{id}/ показывал имя, телефон, Telegram и адрес
 * чужих заказов. Ключ из ?key= сравниваем с ключом заказа.
 *
 * @return WC_Order|false
 */
function podval_get_thankyou_order() {
    global $wp;
    $order_id = absint( $wp->query_vars['order-received'] ?? 0 );
    if ( ! $order_id || ! function_exists( 'wc_get_order' ) ) {
        return false;
    }
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return false;
    }
    $key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
    if ( ! is_string( $key ) || $key === '' || ! hash_equals( (string) $order->get_order_key(), $key ) ) {
        return false;
    }
    return $order;
}

/**
 * P0: белый список ID файлов принта из POST.
 *
 * Пропускает только вложения, созданные podval_upload_print
 * (_podval_print_upload === '1'), без родителя и без владельца-заказа,
 * не больше 20 штук. Исключение — файлы, уже принадлежащие $order_id:
 * при повторной отправке checkout WC переиспользует тот же заказ, и его
 * файлы к этому моменту привязаны к нему.
 *
 * @param array $ids      Сырые ID из POST.
 * @param int   $order_id Заказ, для которого сохраняем (0 — новый).
 * @return int[]
 */
function podval_filter_print_file_ids( array $ids, $order_id = 0 ) {
    $order_id = (int) $order_id;
    $out      = array();
    foreach ( $ids as $raw ) {
        $id = absint( $raw );
        if ( ! $id || in_array( $id, $out, true ) ) {
            continue;
        }
        $att = get_post( $id );
        if ( ! $att || 'attachment' !== $att->post_type ) {
            continue;
        }
        if ( get_post_meta( $id, '_podval_print_upload', true ) !== '1' ) {
            continue;
        }
        $owner  = (int) get_post_meta( $id, '_podval_print_order', true );
        $parent = (int) $att->post_parent;
        $mine   = $order_id && ( $owner === $order_id || $parent === $order_id );
        if ( ! $mine && ( $parent !== 0 || $owner ) ) {
            continue;
        }
        $out[] = $id;
        if ( count( $out ) >= 20 ) {
            break;
        }
    }
    return $out;
}

/**
 * Растровое превью для форматов, которые браузер не рисует в <img>.
 *
 * PDF / AI / EPS / PSD / TIFF уходили в редактор расположения как обычная
 * картинка: <img src=".pdf"> не грузился, наверху стояла «битая» иконка, а
 * ctx.drawImage() в схеме молча падал — клиент не видел ни принта, ни рамки
 * (прод, заказ #424). Рендерим первую страницу в JPEG рядом с оригиналом.
 *
 * Imagick для PDF/AI/EPS нужен с делегатом Ghostscript, а политика
 * ImageMagick на многих хостингах его запрещает. Любая осечка — не ошибка
 * загрузки: возвращаем '' и фронт показывает заглушку с рамкой.
 *
 * @param string $src_path Путь к загруженному файлу.
 * @param string $ext      Расширение в нижнем регистре.
 * @param string $dest_dir Папка, куда класть превью.
 * @param string $new_name Имя оригинала (от него берётся имя превью).
 * @return string Имя файла превью в $dest_dir, либо '' если сделать не вышло.
 */
function podval_make_print_preview( $src_path, $ext, $dest_dir, $new_name ) {
    $needs = array( 'pdf', 'ai', 'eps', 'psd', 'tiff', 'tif' );
    if ( ! in_array( $ext, $needs, true ) || ! class_exists( 'Imagick' ) ) {
        return '';
    }

    $preview_name = preg_replace( '/\.[^.]+$/', '', $new_name ) . '-preview.jpg';
    $preview_path = $dest_dir . '/' . $preview_name;
    $ok           = false;

    // Растеризация A3-макета занимает секунды — запас к лимиту PHP.
    @set_time_limit( 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

    try {
        $im = new Imagick();
        // Разрешение выставляется ДО readImage: для векторных PDF/AI/EPS
        // именно оно задаёт размер растра. 120 DPI — компромисс между
        // читаемостью превью и памятью.
        $im->setResolution( 120, 120 );
        // [0] — только первая страница/слой. Без индекса многостраничный PDF
        // читается целиком, и 10 МБ файла разворачиваются в гигабайты.
        $im->readImage( $src_path . '[0]' );

        $im->setImageBackgroundColor( new ImagickPixel( 'white' ) );
        if ( method_exists( $im, 'mergeImageLayers' ) && defined( 'Imagick::LAYERMETHOD_FLATTEN' ) ) {
            $merged = $im->mergeImageLayers( Imagick::LAYERMETHOD_FLATTEN );
            $im->clear();
            $im = $merged;
        }
        if ( defined( 'Imagick::ALPHACHANNEL_REMOVE' ) ) {
            $im->setImageAlphaChannel( Imagick::ALPHACHANNEL_REMOVE );
        }

        // Макеты для печати часто в CMYK. JPEG в CMYK браузеры показывают
        // с вывернутыми цветами — переводим в sRGB.
        if ( defined( 'Imagick::COLORSPACE_CMYK' ) && Imagick::COLORSPACE_CMYK === $im->getImageColorspace() ) {
            $im->transformImageColorspace( Imagick::COLORSPACE_SRGB );
        }

        $im->setImageFormat( 'jpeg' );
        $im->setImageCompressionQuality( 80 );
        // 1200 px хватает и сцене редактора (~520 CSS-px), и миниатюре в админке.
        $im->thumbnailImage( 1200, 1200, true );
        $ok = (bool) $im->writeImage( $preview_path );
        $im->clear();
        $im->destroy();
    } catch ( \Throwable $e ) {
        $ok = false;
    }

    // Пустой или огрызок файла не лучше отсутствующего — фронт нарисует рамку.
    if ( ! $ok || ! file_exists( $preview_path ) || filesize( $preview_path ) < 256 ) {
        if ( file_exists( $preview_path ) ) {
            @unlink( $preview_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
        return '';
    }

    chmod( $preview_path, 0644 );
    return $preview_name;
}

/**
 * Превью — файл-спутник без своего вложения, поэтому удаляем его руками
 * вместе с оригиналом. Иначе podval-print-cleanup снесёт принт,
 * а JPEG останется в customer-prints/ навсегда.
 */
add_action( 'delete_attachment', function( $att_id ) {
    $rel = get_post_meta( (int) $att_id, '_podval_print_preview', true );
    if ( ! $rel ) {
        return;
    }
    $base = wp_upload_dir();
    $path = trailingslashit( $base['basedir'] ) . ltrim( $rel, '/' );
    if ( strpos( $path, 'customer-prints' ) !== false && file_exists( $path ) ) {
        @unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
    }
} );

// AJAX handler для загрузки файлов
add_action( 'wp_ajax_podval_upload_print', 'podval_handle_print_upload' );
add_action( 'wp_ajax_nopriv_podval_upload_print', 'podval_handle_print_upload' );

function podval_handle_print_upload() {
    check_ajax_referer( 'podval_nonce', '_wpnonce' );

    if ( empty( $_FILES['podval_print_file'] ) ) {
        wp_send_json_error( array( 'message' => 'Файл не получен' ) );
    }

    $file = $_FILES['podval_print_file'];

    // Проверка ошибки загрузки PHP
    if ( $file['error'] !== UPLOAD_ERR_OK ) {
        $php_errors = array(
            // вместо технического «upload_max_filesize» — что делать.
            1 => sprintf( 'Файл больше, чем сейчас принимает сервер (до %s). Попробуйте фото поменьше или отметьте «Пришлю фото позже».', size_format( wp_max_upload_size() ) ),
            2 => 'Файл превышает MAX_FILE_SIZE',
            3 => 'Файл загружен частично',
            4 => 'Файл не был загружен',
            6 => 'Отсутствует временная папка',
            7 => 'Ошибка записи на диск',
        );
        wp_send_json_error( array(
            'message' => $php_errors[ $file['error'] ] ?? 'Ошибка загрузки #' . $file['error'],
        ) );
    }

    // Проверка размера (25 МБ)
    if ( $file['size'] > 25 * 1024 * 1024 ) {
        wp_send_json_error( array( 'message' => 'Файл превышает 25 МБ' ) );
    }

    // Проверка типа
    $allowed_exts = array( 'jpg', 'jpeg', 'png', 'webp', 'tiff', 'tif', 'bmp', 'svg', 'pdf', 'ai', 'eps', 'psd' );
    $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
    if ( ! in_array( $ext, $allowed_exts, true ) ) {
        wp_send_json_error( array( 'message' => 'Неподдерживаемый формат: .' . $ext ) );
    }

    // Формируем уникальное имя с префиксом cp-
    $base  = sanitize_file_name( pathinfo( $file['name'], PATHINFO_FILENAME ) );
    $stamp = date( 'Ymd-His' ) . '-' . wp_rand( 100, 999 );
    $new_name = 'cp-' . $base . '-' . $stamp . '.' . $ext;

    // Формируем путь к папке customer-prints/YYYY/MM
    $upload_base = wp_upload_dir();
    $subdir  = '/customer-prints/' . date( 'Y' ) . '/' . date( 'm' );
    $dest_dir = $upload_base['basedir'] . $subdir;
    $dest_url_base = $upload_base['baseurl'] . $subdir;

    if ( ! file_exists( $dest_dir ) ) {
        wp_mkdir_p( $dest_dir );
    }

    $dest_path = $dest_dir . '/' . $new_name;
    $dest_url  = $dest_url_base . '/' . $new_name;

    // === КЛЮЧЕВОЕ: move_uploaded_file — полный обход WordPress и EWWW ===
    if ( ! move_uploaded_file( $file['tmp_name'], $dest_path ) ) {
        wp_send_json_error( array( 'message' => 'Не удалось переместить файл' ) );
    }

    // Устанавливаем правильные права
    chmod( $dest_path, 0644 );

    // MIME-тип
    $mime_map = array(
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'webp' => 'image/webp', 'tiff' => 'image/tiff', 'tif' => 'image/tiff',
        'bmp' => 'image/bmp', 'svg' => 'image/svg+xml', 'pdf' => 'application/pdf',
        'ai' => 'application/postscript', 'eps' => 'application/postscript',
        'psd' => 'image/vnd.adobe.photoshop',
    );
    $mime_type = isset( $mime_map[ $ext ] ) ? $mime_map[ $ext ] : 'application/octet-stream';

    // Создаём запись attachment напрямую через wp_insert_post (не wp_insert_attachment!)
    // чтобы избежать хуков которые EWWW вешает на wp_insert_attachment
    $attachment_id = wp_insert_post( array(
        'post_type'      => 'attachment',
        'post_mime_type' => $mime_type,
        'post_title'     => preg_replace( '/\.[^.]+$/', '', $new_name ),
        'post_content'   => '',
        'post_status'    => 'inherit',
        'guid'           => $dest_url,
    ), true );

    if ( is_wp_error( $attachment_id ) ) {
        @unlink( $dest_path );
        wp_send_json_error( array( 'message' => $attachment_id->get_error_message() ) );
    }

    // Записываем _wp_attached_file мета вручную
    $relative_path = $subdir . '/' . $new_name;
    update_post_meta( $attachment_id, '_wp_attached_file', ltrim( $relative_path, '/' ) );

    // Минимальные метаданные (без wp_generate_attachment_metadata и без wp_update_attachment_metadata)
    $meta = array( 'file' => ltrim( $relative_path, '/' ) );
    $image_exts = array( 'jpg', 'jpeg', 'png', 'webp', 'tiff', 'tif', 'bmp' );
    if ( in_array( $ext, $image_exts, true ) && function_exists( 'getimagesize' ) ) {
        $imgsize = @getimagesize( $dest_path );
        if ( $imgsize ) {
            $meta['width']  = $imgsize[0];
            $meta['height'] = $imgsize[1];
        }
    }
    $meta['filesize'] = filesize( $dest_path );
    // Записываем мету напрямую, минуя wp_update_attachment_metadata (EWWW хукает его)
    update_post_meta( $attachment_id, '_wp_attachment_metadata', $meta );

    // Помечаем как файл принта заказа
    update_post_meta( $attachment_id, '_podval_print_upload', '1' );

    // Превью для форматов, которые браузер не рисует сам.
    // Лежит рядом с оригиналом в customer-prints/ — значит попадает под
    // тот же байпас EWWW (фильтры по пути и по префиксу cp-).
    $preview_url = '';
    $preview_name = podval_make_print_preview( $dest_path, $ext, $dest_dir, $new_name );
    if ( $preview_name ) {
        $preview_url = $dest_url_base . '/' . $preview_name;
        update_post_meta( $attachment_id, '_podval_print_preview', ltrim( $subdir . '/' . $preview_name, '/' ) );
    }

    // Placement editor reference schematic — distinguish from
    // real print files so admin can label it "схема расположения".
    if ( ! empty( $_POST['podval_is_placement'] ) ) {
        update_post_meta( $attachment_id, '_podval_placement', '1' );
    }

    wp_send_json_success( array(
        'id'       => $attachment_id,
        'url'      => $dest_url,
        'thumb'    => '',
        'is_image' => in_array( $ext, $image_exts, true ),
        // прежней версии. can_preview — браузер нарисует сам файл в <img>. Это НЕ
        // is_image: SVG рисуется, а метаданных ширины у него нет; TIFF, наоборот,
        // читается getimagesize(), но Chrome и Firefox его не показывают.
        'can_preview' => in_array( $ext, array( 'jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'svg' ), true ),
        'preview'     => $preview_url,
        'ext'         => $ext,
    ) );
}

// Файлы принта сохраняются через woocommerce_checkout_create_order
// и привязываются через woocommerce_checkout_order_created (ниже).

// Показываем файлы принта в админке заказа
add_action( 'woocommerce_admin_order_data_after_billing_address', function( $order ) {
    $file_ids = $order->get_meta( '_podval_print_file_ids', true );
    if ( empty( $file_ids ) || ! is_array( $file_ids ) ) return;

    echo '<p><strong>Файлы принта:</strong></p><div style="display:flex;gap:8px;flex-wrap:wrap;">';
    foreach ( $file_ids as $att_id ) {
        $url  = wp_get_attachment_url( $att_id );
        $name = basename( get_attached_file( $att_id ) );
        if ( ! $url ) continue;

        // у PDF/AI/EPS/PSD есть сгенерированное превью — показываем его
        // миниатюрой вместо голой ссылки, ссылка ведёт на оригинал.
        $preview_rel = get_post_meta( $att_id, '_podval_print_preview', true );
        if ( $preview_rel ) {
            $up_base = wp_upload_dir();
            echo '<a href="' . esc_url( $url ) . '" target="_blank" style="display:block;position:relative">';
            echo '<img src="' . esc_url( trailingslashit( $up_base['baseurl'] ) . ltrim( $preview_rel, '/' ) ) . '" style="width:80px;height:80px;object-fit:cover;border-radius:4px;border:1px solid #ddd;background:#fff;">';
            echo '<span style="position:absolute;left:3px;bottom:3px;padding:1px 4px;background:#ff6a00;color:#fff;font-size:9px;font-weight:700;border-radius:2px">' . esc_html( strtoupper( pathinfo( $name, PATHINFO_EXTENSION ) ) ) . '</span>';
            echo '</a>';
            continue;
        }

        $is_image = wp_attachment_is_image( $att_id );
        if ( $is_image ) {
            $thumb = wp_get_attachment_image_url( $att_id, 'thumbnail' );
            echo '<a href="' . esc_url( $url ) . '" target="_blank" style="display:block;">';
            echo '<img src="' . esc_url( $thumb ) . '" style="width:80px;height:80px;object-fit:cover;border-radius:4px;border:1px solid #ddd;">';
            echo '</a>';
        } else {
            echo '<a href="' . esc_url( $url ) . '" target="_blank" style="padding:8px 12px;background:#f0f0f0;border-radius:4px;font-size:12px;text-decoration:none;color:#333;">';
            echo esc_html( $name );
            echo '</a>';
        }
    }
    echo '</div>';
}, 20 );

// Показываем файлы в email заказа
add_action( 'woocommerce_email_after_order_table', function( $order ) {
    $file_ids = $order->get_meta( '_podval_print_file_ids', true );
    if ( empty( $file_ids ) || ! is_array( $file_ids ) ) return;

    echo '<p><strong>Файлы принта:</strong></p><ul>';
    foreach ( $file_ids as $att_id ) {
        $url  = wp_get_attachment_url( $att_id );
        $name = basename( get_attached_file( $att_id ) );
        if ( $url ) {
            echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a></li>';
        }
    }
    echo '</ul>';
}, 20 );

// Подключаем WC checkout скрипты на нашей кастомной checkout странице
add_action( 'wp_enqueue_scripts', function() {
    if ( function_exists( 'is_checkout' ) && is_checkout() ) {
        wp_enqueue_script( 'wc-checkout' );
    }
});

// Фикс: WC AJAX checkout ожидает стандартный формат ответа
// Наши кастомные поля нужно передать в process_checkout
add_action( 'woocommerce_checkout_create_order', function( $order, $data ) {
    // Сохраняем мессенджер (с автоматическим @)
    if ( ! empty( $_POST['billing_messenger'] ) ) {
        $messenger = podval_normalize_messenger( sanitize_text_field( $_POST['billing_messenger'] ) );
        $order->update_meta_data( '_billing_messenger', $messenger );
    }
    // Сохраняем способ доставки
    if ( ! empty( $_POST['podval_shipping'] ) ) {
        $shipping_labels = array(
            'pickup'   => 'Самовывоз',
            'yandex'   => 'Яндекс доставка (оплата клиентом)',
            'europost' => 'Евро-Почта до отделения',
        );
        $method = sanitize_text_field( $_POST['podval_shipping'] );
        $order->update_meta_data( '_podval_shipping_method', $method );
        $order->update_meta_data( '_podval_shipping_label', $shipping_labels[ $method ] ?? $method );

        // Евро-Почта: +5 BYN за оплату посылки
        if ( $method === 'europost' ) {
            $fee = new \WC_Order_Item_Fee();
            $fee->set_name( 'Оплата посылки (Евро-Почта)' );
            $fee->set_amount( 5 );
            $fee->set_total( 5 );
            $order->add_item( $fee );
            $order->set_total( (float) $order->get_total() + 5 );
        }

        // СДЭК: стоимость рассчитывает плагин podval-cdek и пишет её в hidden input
        // (podval-cdek-cost-input → podval_cdek_cost в POST). Добавляем как fee.
        if ( $method === 'cdek' ) {
            $cdek_cost = isset( $_POST['podval_cdek_cost'] ) ? (float) $_POST['podval_cdek_cost'] : 0;
            // Защита от дубля: не добавлять если уже есть наш fee (на случай повторной обработки)
            $has_cdek_fee = false;
            foreach ( $order->get_fees() as $existing_fee ) {
                if ( $existing_fee->get_name() === 'Доставка СДЭК' ) {
                    $has_cdek_fee = true;
                    break;
                }
            }
            if ( $cdek_cost > 0 && ! $has_cdek_fee ) {
                $fee = new \WC_Order_Item_Fee();
                $fee->set_name( 'Доставка СДЭК' );
                $fee->set_amount( $cdek_cost );
                $fee->set_total( $cdek_cost );
                $order->add_item( $fee );
                $order->set_total( (float) $order->get_total() + $cdek_cost );
            }
        }
    }
    // Сохраняем адрес доставки
    if ( ! empty( $_POST['shipping_address_1'] ) ) {
        $order->set_shipping_address_1( sanitize_text_field( $_POST['shipping_address_1'] ) );
    }
    // Сохраняем файлы принта.
    // прежней версии (P0): только файлы, загруженные клиентом через podval_upload_print и
    // ещё не принадлежащие другому заказу. Раньше сюда проходил любой ID
    // вложения, и подделанный POST переименовывал на диске фото товара.
    if ( ! empty( $_POST['podval_print_files'] ) && is_array( $_POST['podval_print_files'] ) ) {
        $ids = podval_filter_print_file_ids( (array) wp_unslash( $_POST['podval_print_files'] ), (int) $order->get_id() );
        if ( ! empty( $ids ) ) {
            $order->update_meta_data( '_podval_print_file_ids', $ids );
        }
    }
}, 10, 2 );

// После создания заказа — привязываем файлы
add_action( 'woocommerce_checkout_order_created', function( $order ) {
    $file_ids = $order->get_meta( '_podval_print_file_ids' );
    if ( ! empty( $file_ids ) && is_array( $file_ids ) ) {
        foreach ( $file_ids as $att_id ) {
            wp_update_post( array( 'ID' => $att_id, 'post_parent' => $order->get_id() ) );
        }
    }
});

// Автоматически включаем COD если ещё не настроен
add_action( 'woocommerce_loaded', function() {
    $cod_settings = get_option( 'woocommerce_cod_settings', array() );
    if ( empty( $cod_settings ) || ! isset( $cod_settings['enabled'] ) ) {
        $cod_settings['enabled'] = 'yes';
        $cod_settings['title']   = 'Оплата наличными';
        $cod_settings['description'] = 'Оплата наличными при самовывозе';
        update_option( 'woocommerce_cod_settings', $cod_settings );
    }
});

// Кастомная страница "Спасибо за заказ"
add_filter( 'template_include', function( $template ) {
    if ( function_exists( 'is_checkout' ) && is_checkout() && is_wc_endpoint_url( 'order-received' ) ) {
        $custom = get_stylesheet_directory() . '/woocommerce/checkout/page-thankyou.php';
        if ( file_exists( $custom ) ) return $custom;
    }
    return $template;
}, 98 );

// Серверная валидация checkout полей
add_action( 'woocommerce_checkout_process', function() {
    // Телефон — обязателен, проверяем формат BY/RU
    if ( empty( $_POST['billing_phone'] ) ) {
        wc_add_notice( 'Введите номер телефона.', 'error' );
    } else {
        $phone = preg_replace( '/[\s\-\(\)]/', '', sanitize_text_field( $_POST['billing_phone'] ) );
        if ( ! preg_match( '/^(\+375\d{9}|\+7\d{10}|375\d{9}|80\d{9})$/', $phone ) ) {
            wc_add_notice( 'Введите корректный белорусский (+375) или российский (+7) номер телефона.', 'error' );
        }
    }

    // Мессенджер — обязателен, без кириллицы
    if ( empty( $_POST['billing_messenger'] ) ) {
        wc_add_notice( 'Укажите Telegram для связи.', 'error' );
    } elseif ( preg_match( '/[\x{0400}-\x{04FF}]/u', $_POST['billing_messenger'] ) ) {
        wc_add_notice( 'В поле Telegram используйте латиницу: @username или номер телефона.', 'error' );
    }

    // Email — если заполнен, проверяем формат
    if ( ! empty( $_POST['billing_email'] ) && ! is_email( $_POST['billing_email'] ) ) {
        wc_add_notice( 'Введите корректный e-mail адрес.', 'error' );
    }
}, 5 );

// Санитизация комментария к заказу — убираем всё опасное
add_filter( 'woocommerce_checkout_posted_data', function( $data ) {
    if ( ! empty( $data['order_comments'] ) ) {
        // Убираем HTML, скрипты, SQL-инъекции
        $comment = $data['order_comments'];
        $comment = wp_strip_all_tags( $comment );                    // Убираем HTML
        $comment = preg_replace( '/javascript\s*:/i', '', $comment ); // javascript:
        $comment = preg_replace( '/on\w+\s*=/i', '', $comment );     // event handlers
        $comment = preg_replace( '/\{\{.*?\}\}/', '', $comment );     // template injection
        $comment = preg_replace( '/\$\{.*?\}/', '', $comment );       // template literals
        $comment = sanitize_textarea_field( $comment );               // WP sanitization
        $data['order_comments'] = $comment;
    }
    return $data;
});

/* ═══════════════════════════════════════════
   ПРОИЗВОДИТЕЛЬНОСТЬ И КЭШИРОВАНИЕ
   ═══════════════════════════════════════════ */

// 1. HTTP-заголовки — кеширование
add_action( 'send_headers', function() {
    if ( is_admin() ) return;

    // В dev-режиме — жёсткий no-cache
    if ( defined( 'PODVAL_DEV_MODE' ) && PODVAL_DEV_MODE ) {
        header( 'Cache-Control: no-cache, no-store, must-revalidate' );
        header( 'Pragma: no-cache' );
        return;
    }

    // В продакшне — разрешаем кешировать, но с ревалидацией
    // Page cache плагин перехватит раньше и отдаст статический файл
    // Для динамических страниц (корзина, checkout, my-account) — no-cache
    if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
        header( 'Cache-Control: no-cache, no-store, must-revalidate' );
        header( 'Pragma: no-cache' );
    } else {
        header( 'Cache-Control: public, max-age=0, s-maxage=3600, must-revalidate' );
    }
});

// 2. Preload критических шрифтов (self-hosted)
add_action( 'wp_head', function() {
    $dir = get_template_directory_uri() . '/assets/fonts';
    // Preload only the most critical fonts (above-the-fold: Oswald 700 for headings, Unbounded 600 for UI, Inter 400 for body text)
    // Inter cyrillic 400 — на критическом пути LCP (был 438ms), preload убирает задержку на парсинг CSS
    echo '<link rel="preload" href="' . $dir . '/oswald-cyrillic-700.woff2" as="font" type="font/woff2" crossorigin>' . "\n";
    echo '<link rel="preload" href="' . $dir . '/oswald-latin-700.woff2" as="font" type="font/woff2" crossorigin>' . "\n";
    echo '<link rel="preload" href="' . $dir . '/unbounded-cyrillic-600.woff2" as="font" type="font/woff2" crossorigin>' . "\n";
    echo '<link rel="preload" href="' . $dir . '/inter-cyrillic-400.woff2" as="font" type="font/woff2" crossorigin>' . "\n";
}, 1 );

// 2b. Универсальная система инвалидации кеша
// Работает с WP Super Cache, WP Fastest Cache, LiteSpeed Cache, W3 Total Cache
// + наши transient-кеши (навигация, footer strip)

/**
 * Сброс ВСЕХ кешей: page cache + transients
 * Вызывается при любом изменении контента/настроек
 */
function podval_flush_all_cache( $reason = '' ) {
    // Dev-режим: define('PODVAL_DEV_MODE', true) в wp-config.php
    // В dev-режиме page cache не создаётся (см. фильтр ниже), чистить нечего

    // ── 1. Наши transient-кеши ──
    // ВАЖНО: ключ должен совпадать с $nav_cache_key в header.php.
    // При смене версии кэша (v4 → v5 и т.д.) обновить в ОБОИХ местах.
    // v4 → v5 после добавления пункта меню «Бандлы» в навигацию.
    delete_transient( 'podval_nav_data_v4' );
    delete_transient( 'podval_nav_data_v5' );
    // v5 → v6 — мега-меню «Услуги» строится из лендингов
    delete_transient( 'podval_nav_data_v6' );
    delete_transient( 'podval_sl_landings_v1' );
    delete_transient( 'podval_sl_catmap_v1' );
    delete_transient( 'podval_footer_strip_tags' );
    delete_transient( 'podval_footer_strip_custom' );
    // query-кэши главной (podval_get_products_cached + collections)
    delete_transient( 'podval_home_collections_v1' );
    // Bulk delete всех podval_q_* транзиентов через SQL (их может быть много —
    // по одному на каждый уникальный набор args)
    global $wpdb;
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '_transient_podval_q_%'
            OR option_name LIKE '_transient_timeout_podval_q_%'"
    );

    // ── 2. WP-Optimize page cache (основной кеш страниц) ──
    // добавлено после того как обнаружилось, что вызовы API
    // WP Super Cache (ниже) на проде ничего не делают — там WP-Optimize.
    if ( class_exists( 'WPO_Page_Cache' ) ) {
        $wpo = WPO_Page_Cache::instance();
        if ( $wpo && method_exists( $wpo, 'purge' ) ) {
            $wpo->purge();
        }
    }
    // Альтернативный публичный API в новых версиях WP-Optimize
    if ( function_exists( 'wpo_cache_flush' ) ) {
        wpo_cache_flush();
    }

    // ── 3. WP Super Cache (fallback, если когда-то будет включён вместо/вместе с WP-Optimize) ──
    if ( function_exists( 'wp_cache_clear_cache' ) ) {
        wp_cache_clear_cache();
    }

    // ── 4. WP Fastest Cache ──
    if ( function_exists( 'wpfc_clear_all_cache' ) ) {
        wpfc_clear_all_cache();
    } elseif ( isset( $GLOBALS['wp_fastest_cache'] ) && method_exists( $GLOBALS['wp_fastest_cache'], 'deleteCache' ) ) {
        $GLOBALS['wp_fastest_cache']->deleteCache( true );
    }

    // ── 5. LiteSpeed Cache ──
    if ( class_exists( 'LiteSpeed\Purge' ) ) {
        do_action( 'litespeed_purge_all' );
    }

    // ── 6. W3 Total Cache ──
    if ( function_exists( 'w3tc_flush_all' ) ) {
        w3tc_flush_all();
    }

    // ── 7. Object cache (Redis/Memcached) ──
    if ( function_exists( 'wp_cache_flush' ) ) {
        wp_cache_flush();
    }

    // Логируем для отладки.
    // По умолчанию пишется только в WP_DEBUG, но для изменений nav/taxonomy
    // пишем всегда — чтобы можно было в error_log подтвердить, что сброс реально случился.
    if ( $reason ) {
        $is_nav_related = ( strpos( $reason, 'nav' ) !== false || strpos( $reason, 'taxonomy' ) !== false );
        if ( ( defined( 'WP_DEBUG' ) && WP_DEBUG ) || $is_nav_related ) {
            error_log( '[podval-theme] Cache flushed: ' . $reason );
        }
    }
}

// Алиас для обратной совместимости
function podval_flush_nav_cache() {
    podval_flush_all_cache( 'nav/taxonomy change' );
}

// ── Триггеры инвалидации ──

// Категории, теги товаров
add_action( 'created_product_cat', 'podval_flush_nav_cache' );
add_action( 'edited_product_cat', 'podval_flush_nav_cache' );
add_action( 'delete_product_cat', 'podval_flush_nav_cache' );
add_action( 'created_product_tag', 'podval_flush_nav_cache' );
add_action( 'edited_product_tag', 'podval_flush_nav_cache' );
add_action( 'delete_product_tag', 'podval_flush_nav_cache' );

// Сохранение товара
add_action( 'save_post_product', function() { podval_flush_all_cache( 'product save' ); } );
add_action( 'woocommerce_after_product_object_save', function() { podval_flush_all_cache( 'wc product save' ); } );

// drag-n-drop сортировка товаров в WC → Товары → Сортировка.
// Этот action триггерится после AJAX-апдейта menu_order и НЕ покрывается
// save_post_product (WC обновляет menu_order прямым SQL UPDATE).
add_action( 'woocommerce_after_product_ordering', function() { podval_flush_all_cache( 'wc product reorder' ); } );

// Обновление/переключение темы
add_action( 'switch_theme', function() { podval_flush_all_cache( 'theme switch' ); } );
add_action( 'upgrader_process_complete', function( $upgrader, $options ) {
    if ( isset( $options['type'] ) && $options['type'] === 'theme' ) {
        podval_flush_all_cache( 'theme update' );
    }
}, 10, 2 );

// Customizer: при сохранении
add_action( 'customize_save_after', function() { podval_flush_all_cache( 'customizer save' ); } );

// Меню WP
add_action( 'wp_update_nav_menu', function() { podval_flush_all_cache( 'menu update' ); } );

// WooCommerce настройки
add_action( 'woocommerce_update_options', function() { podval_flush_all_cache( 'wc settings' ); } );

// Виджеты
add_action( 'update_option_sidebars_widgets', function() { podval_flush_all_cache( 'widget update' ); } );

// ── Dev-режим: блокируем page cache при разработке ──
// В wp-config.php: define('PODVAL_DEV_MODE', true);
if ( defined( 'PODVAL_DEV_MODE' ) && PODVAL_DEV_MODE ) {
    // WP Super Cache — не кешировать
    if ( ! defined( 'DONOTCACHEPAGE' ) ) {
        define( 'DONOTCACHEPAGE', true );
    }
    // LiteSpeed Cache — не кешировать
    add_action( 'init', function() {
        do_action( 'litespeed_disable_all' );
    } );
    // WP Fastest Cache — не кешировать
    add_filter( 'wpfc_exclude_current_page', '__return_true' );
}

// 3. Отложенная загрузка скриптов (defer) + async CSS
add_filter( 'script_loader_tag', function( $tag, $handle ) {
    // Не трогаем только чекаут-скрипт (нужен синхронно для формы оплаты)
    $skip = array( 'wc-checkout', 'wp-hooks' );
    if ( in_array( $handle, $skip, true ) ) return $tag;
    if ( is_admin() ) return $tag;

    // Добавляем defer если нет async/defer
    if ( strpos( $tag, 'defer' ) === false && strpos( $tag, 'async' ) === false ) {
        $tag = str_replace( ' src=', ' defer src=', $tag );
    }
    return $tag;
}, 10, 2 );

// 3b. Основной CSS грузим асинхронно (critical CSS уже inline в <head>)
add_filter( 'style_loader_tag', function( $tag, $handle, $src ) {
    if ( $handle !== 'podval-style' || is_admin() ) return $tag;

    $id = 'podval-style-css';
    return '<link rel="stylesheet" id="' . $id . '" href="' . esc_attr( $src ) . '" media="print" onload="this.media=\'all\'">' . "\n"
         . '<noscript><link rel="stylesheet" href="' . esc_attr( $src ) . '"></noscript>' . "\n";
}, 10, 3 );

// 4. Убираем лишние ресурсы WP
add_action( 'wp_enqueue_scripts', function() {
    // Убираем emoji скрипты
    remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
    remove_action( 'wp_print_styles', 'print_emoji_styles' );

    // Убираем WP embed
    wp_deregister_script( 'wp-embed' );

    // Убираем Dashicons для незалогиненных
    if ( ! is_user_logged_in() ) {
        wp_deregister_style( 'dashicons' );
    }
}, 100 );

// Убираем generator meta, RSD, wlwmanifest
remove_action( 'wp_head', 'wp_generator' );
remove_action( 'wp_head', 'rsd_link' );
remove_action( 'wp_head', 'wlwmanifest_link' );
remove_action( 'wp_head', 'wp_shortlink_wp_head' );
remove_action( 'wp_head', 'rest_output_link_wp_head' );

// 4b. Preconnect — намеренно пусто.
// убраны googletagmanager.com и mc.yandex.ru (unused preconnect — Lighthouse).
// убран ipwho.is (XHR на геолокацию вырезан из currency.js — дефолт BYN).
// Если в будущем понадобится preconnect к третьему домену — добавлять сюда.

// 4c. Preload hero image — LCP оптимизация
// (a) Страница «Свой Дизайн» — категория custom-design
add_action( 'wp_head', function() {
    if ( is_admin() ) return;
    if ( ! function_exists( 'is_product_category' ) ) return;
    if ( ! is_product_category( 'custom-design' ) ) return;

    $hero_bg_id = get_theme_mod( 'podval_cd_hero_bg', '' );
    if ( ! $hero_bg_id ) return;

    $hero_url = wp_get_attachment_image_url( $hero_bg_id, 'full' );
    if ( ! $hero_url ) return;

    // EWWW может подменять URL на WebP в <img>, но не в <link preload>
    // Проверяем наличие WebP-версии на диске
    $upload_dir = wp_upload_dir();
    $hero_path  = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $hero_url );

    // Вариант 1: file.jpg.webp (EWWW стиль)
    if ( file_exists( $hero_path . '.webp' ) ) {
        $hero_url = $hero_url . '.webp';
    }

    // Определяем MIME по расширению
    $type = '';
    if ( preg_match( '/\.(webp)$/i', $hero_url ) ) {
        $type = ' type="image/webp"';
    } elseif ( preg_match( '/\.(jpe?g)$/i', $hero_url ) ) {
        $type = ' type="image/jpeg"';
    } elseif ( preg_match( '/\.(png)$/i', $hero_url ) ) {
        $type = ' type="image/png"';
    }

    echo '<link rel="preload" as="image" href="' . esc_url( $hero_url ) . '"' . $type . ' fetchpriority="high">' . "\n";
}, 0 );

// (b) Главная страница — первый слайд hero-слайдера = LCP элемент
// КРИТИЧНО: URL preload должен СОВПАДАТЬ с URL который рендерит index.php в <img>.
// Иначе браузер скачивает один файл, а <img> грузит другой → preload бесполезен.
add_action( 'wp_head', function() {
    if ( is_admin() ) return;
    if ( ! is_front_page() && ! is_home() ) return;

    // Найти URL первого валидного слайда (тот же порядок, что в index.php)
    $slides_count = absint( get_theme_mod( 'podval_slides_count', 3 ) );
    $slides_count = max( 1, min( 5, $slides_count ) );
    $first_url = '';
    for ( $i = 1; $i <= $slides_count; $i++ ) {
        $title = get_theme_mod( "podval_slide_{$i}_title1", '' );
        $url   = get_theme_mod( "podval_slide_{$i}_image", '' );
        if ( $title && $url ) {
            $first_url = $url;
            break;
        }
    }
    if ( ! $first_url ) return;

    // Преобразуем URL слайда в URL размера 'podval-hero' (1920×1080)
    // — это тот же размер, что использует index.php через wp_get_attachment_image()
    $cache_key = 'podval_hero_first_slide_v1_' . md5( $first_url );
    $att_id    = get_transient( $cache_key );
    if ( false === $att_id ) {
        $att_id = attachment_url_to_postid( preg_replace( '/\.webp$/', '', $first_url ) );
        if ( ! $att_id ) $att_id = attachment_url_to_postid( $first_url );
        set_transient( $cache_key, (int) $att_id, DAY_IN_SECONDS );
    }

    // srcset/sizes: без них preload тянул полноразмерный файл (1920w, 92 КБ),
    // а <img> по тому же srcset выбирал вариант под вьюпорт (768w, 41 КБ) —
    // качались ОБА, причём лишний с fetchpriority=high, прямо поперёк LCP.
    // imagesrcset + imagesizes заставляют preload пройти тот же отбор
    // кандидата, что и <img>: значения обязаны совпадать с index.php.
    $srcset = '';
    if ( $att_id ) {
        // Получаем URL и размеры размера 'podval-hero'
        $img = wp_get_attachment_image_src( $att_id, 'podval-hero' );
        if ( $img ) {
            $first_url = $img[0];
        }
        $srcset = wp_get_attachment_image_srcset( $att_id, 'podval-hero' );
    }

    // WebP-вариант, если есть на диске (EWWW Replace mode: file.webp вместо file.jpg)
    $upload_dir = wp_upload_dir();
    $local_path = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $first_url );

    // Replace mode: file.jpg → file.webp
    $webp_replace = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $local_path );
    if ( $webp_replace && $webp_replace !== $local_path && file_exists( $webp_replace ) ) {
        $first_url = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $first_url );
    }
    // Append mode (резерв): file.jpg.webp
    elseif ( file_exists( $local_path . '.webp' ) ) {
        $first_url = $first_url . '.webp';
    }

    $type = '';
    if ( preg_match( '/\.webp$/i', $first_url ) )      $type = ' type="image/webp"';
    elseif ( preg_match( '/\.jpe?g$/i', $first_url ) ) $type = ' type="image/jpeg"';
    elseif ( preg_match( '/\.png$/i', $first_url ) )   $type = ' type="image/png"';

    // type пропускаем, когда есть srcset: кандидаты могут быть разных форматов,
    // и один общий type заставил бы браузер отбросить preload целиком.
    $responsive = '';
    if ( $srcset ) {
        $responsive = ' imagesrcset="' . esc_attr( $srcset ) . '" imagesizes="100vw"';
        $type       = '';
    }

    echo '<link rel="preload" as="image" href="' . esc_url( $first_url ) . '"' . $responsive . $type . ' fetchpriority="high">' . "\n";
}, 0 );

/* ═══════════════════════════════════════════
   ARTWORK UPLOAD — Add to cart with artwork files
   Handles the new multi-step workflow for custom-design products
   ═══════════════════════════════════════════ */

// AJAX handler: add to cart with artwork metadata
add_action( 'wp_ajax_podval_artwork_add_to_cart', 'podval_artwork_add_to_cart' );
add_action( 'wp_ajax_nopriv_podval_artwork_add_to_cart', 'podval_artwork_add_to_cart' );

function podval_artwork_add_to_cart() {
    check_ajax_referer( 'podval_nonce', '_wpnonce' );

    $product_id   = absint( $_POST['product_id'] ?? 0 );
    $variation_id = absint( $_POST['variation_id'] ?? 0 );

    if ( ! $product_id || ! function_exists( 'WC' ) || ! WC()->cart ) {
        wp_send_json_error( array( 'message' => 'Ошибка: товар не найден' ) );
    }

    // Init WC session for guests
    if ( WC()->session && ! WC()->session->has_session() ) {
        WC()->session->set_customer_session_cookie( true );
    }

    // Artwork files: { front: id, back: id, sleeve: id }
    $artwork_files = array();
    if ( ! empty( $_POST['artwork_files'] ) && is_array( $_POST['artwork_files'] ) ) {
        foreach ( $_POST['artwork_files'] as $slot => $file_id ) {
            $slot = sanitize_key( $slot );
            $file_id = absint( $file_id );
            if ( $file_id && in_array( $slot, array( 'front', 'back', 'sleeve' ), true ) ) {
                $artwork_files[ $slot ] = $file_id;
            }
        }
    }

    // Artwork layout: per-slot placement from the editor —
    // { front: { img:{x,y,w,h}, text:{x,y,size,value} }, ... }.
    $artwork_layout = array();
    if ( ! empty( $_POST['artwork_layout'] ) ) {
        $decoded = json_decode( wp_unslash( $_POST['artwork_layout'] ), true );
        if ( is_array( $decoded ) ) {
            foreach ( $decoded as $slot => $data ) {
                $slot = sanitize_key( $slot );
                if ( ! in_array( $slot, array( 'front', 'back', 'sleeve' ), true ) ) continue;
                if ( ! is_array( $data ) ) continue;
                $entry = array();
                if ( ! empty( $data['img'] ) && is_array( $data['img'] ) ) {
                    $entry['img'] = array(
                        'x' => round( (float) ( $data['img']['x'] ?? 0 ), 4 ),
                        'y' => round( (float) ( $data['img']['y'] ?? 0 ), 4 ),
                        'w' => round( (float) ( $data['img']['w'] ?? 0 ), 4 ),
                        'h' => round( (float) ( $data['img']['h'] ?? 0 ), 4 ),
                    );
                }
                if ( ! empty( $data['text'] ) && is_array( $data['text'] ) && ! empty( $data['text']['value'] ) ) {
                    $align = isset( $data['text']['align'] ) ? sanitize_key( $data['text']['align'] ) : 'left';
                    if ( ! in_array( $align, array( 'left', 'center', 'right' ), true ) ) $align = 'left';
                    $entry['text'] = array(
                        'x'     => round( (float) ( $data['text']['x'] ?? 0 ), 4 ),
                        'y'     => round( (float) ( $data['text']['y'] ?? 0 ), 4 ),
                        'size'  => round( (float) ( $data['text']['size'] ?? 0 ), 4 ),
                        // sanitize_textarea_field keeps line breaks; sanitize_text_field would flatten them
                        'value' => sanitize_textarea_field( $data['text']['value'] ),
                        'align' => $align,
                    );
                }
                if ( $entry ) $artwork_layout[ $slot ] = $entry;
            }
        }
    }

    $artwork_comment = sanitize_textarea_field( $_POST['artwork_comment'] ?? '' );
    $artwork_skipped = ! empty( $_POST['artwork_skipped'] );

    // Artwork texts (per-slot "Добавить надпись"): { front: '…', back: '…', sleeve: '…' }
    $artwork_texts = array();
    if ( ! empty( $_POST['artwork_texts'] ) && is_array( $_POST['artwork_texts'] ) ) {
        foreach ( $_POST['artwork_texts'] as $slot => $text_val ) {
            $slot = sanitize_key( $slot );
            if ( ! in_array( $slot, array( 'front', 'back', 'sleeve' ), true ) ) continue;
            $clean = sanitize_text_field( wp_unslash( $text_val ) );
            if ( $clean === '' ) continue;
            // Cap at 120 chars (matches frontend maxlength)
            if ( mb_strlen( $clean ) > 120 ) {
                $clean = mb_substr( $clean, 0, 120 );
            }
            $artwork_texts[ $slot ] = $clean;
        }
    }

    // Extra files (unlimited additional references, logos, mockups)
    $artwork_extra_files = array();
    if ( ! empty( $_POST['artwork_extra_files'] ) ) {
        $extra_ids = json_decode( sanitize_text_field( wp_unslash( $_POST['artwork_extra_files'] ) ), true );
        if ( is_array( $extra_ids ) ) {
            foreach ( $extra_ids as $eid ) {
                $eid = absint( $eid );
                if ( $eid ) {
                    $artwork_extra_files[] = $eid;
                }
            }
        }
    }

    // Build variation attributes for WC
    $variation = array();
    $attrs_raw = array();
    if ( ! empty( $_POST['attrs'] ) && is_array( $_POST['attrs'] ) ) {
        foreach ( $_POST['attrs'] as $k => $v ) {
            $attrs_raw[ sanitize_text_field( $k ) ] = sanitize_text_field( $v );
        }
    }

    if ( $variation_id > 0 ) {
        // Get the exact attribute keys/values WC expects for this variation
        $variation = wc_get_product_variation_attributes( $variation_id );
        // Fill "any" (empty) attributes with user-selected values from the form.
        // WC requires concrete values for all attributes when adding to cart.
        // $variation keys: "attribute_%d0%bf%d1%80%d0%b8%d0%bd%d1%82" (URL-encoded with prefix)
        // $attrs_raw keys: "Принт" (decoded Cyrillic, no prefix)
        // We need to match them by stripping prefix + URL-decoding.
        foreach ( $variation as $attr_key => &$attr_val ) {
            if ( $attr_val !== '' ) continue;
            // Strip "attribute_" prefix and URL-decode to get clean Cyrillic name
            $clean = urldecode( preg_replace( '/^attribute_/', '', $attr_key ) );
            // Also try sanitize_title version for pa_ taxonomy attributes
            $clean_slug = sanitize_title( $clean );
            foreach ( $attrs_raw as $raw_k => $raw_v ) {
                if ( $raw_v === '' ) continue;
                $raw_clean = urldecode( $raw_k );
                // Normalize: sanitize_title converts spaces to -, but _ stays;
                // WC attribute slugs use - for separators, so also compare with _ → -
                $raw_norm = str_replace( '_', '-', sanitize_title( $raw_clean ) );
                $clean_norm = str_replace( '_', '-', $clean_slug );
                if ( $raw_clean === $clean
                  || sanitize_title( $raw_clean ) === $clean_slug
                  || $raw_norm === $clean_norm
                  || sanitize_title( $raw_clean ) === $clean
                  || $raw_clean === $clean_slug ) {
                    $attr_val = $raw_v;
                    break;
                }
            }
        }
        unset( $attr_val );
    }

    // Cart item data — will be passed to woocommerce_add_cart_item_data filter
    // unique_key ensures each custom-design item gets its own cart entry (not merged)
    $cart_item_data = array(
        'podval_artwork' => array(
            'files'       => $artwork_files,
            'extra_files' => $artwork_extra_files,
            'layout'      => $artwork_layout,
            'texts'       => $artwork_texts,
            'comment'     => $artwork_comment,
            'skipped'     => $artwork_skipped,
        ),
        'unique_key' => 'aw_' . md5( wp_json_encode( $artwork_files ) . wp_json_encode( $artwork_extra_files ) . wp_json_encode( $artwork_texts ) . microtime( true ) ),
    );

    if ( function_exists( 'wc_clear_notices' ) ) wc_clear_notices();

    $added = false;
    if ( $variation_id > 0 ) {
        $added = WC()->cart->add_to_cart( $product_id, 1, $variation_id, $variation, $cart_item_data );
    } else {
        $added = WC()->cart->add_to_cart( $product_id, 1, 0, array(), $cart_item_data );
    }

    if ( function_exists( 'wc_clear_notices' ) ) wc_clear_notices();

    if ( $added ) {
        WC()->cart->calculate_totals();
        if ( WC()->session ) WC()->session->save_data();
        wp_send_json_success( array( 'cart_count' => WC()->cart->get_cart_contents_count() ) );
    } else {
        $notices = function_exists( 'wc_get_notices' ) ? wc_get_notices( 'error' ) : array();
        $notice_text = '';
        if ( ! empty( $notices ) ) {
            foreach ( $notices as $n ) {
                $notice_text .= ( is_array( $n ) ? wp_strip_all_tags( $n['notice'] ?? '' ) : wp_strip_all_tags( $n ) ) . ' ';
            }
        }
        if ( function_exists( 'wc_clear_notices' ) ) wc_clear_notices();

        wp_send_json_error( array( 'message' => trim( $notice_text ) ?: 'Не удалось добавить товар в корзину. Попробуйте ещё раз или свяжитесь с нами.' ) );
    }
}

// Ensure podval_artwork persists through WC cart session
add_filter( 'woocommerce_add_cart_item_data', function( $cart_item_data, $product_id ) {
    return $cart_item_data;
}, 10, 2 );

add_filter( 'woocommerce_get_cart_item_from_session', function( $cart_item, $values ) {
    if ( isset( $values['podval_artwork'] ) ) {
        $cart_item['podval_artwork'] = $values['podval_artwork'];
    }
    if ( isset( $values['unique_key'] ) ) {
        $cart_item['unique_key'] = $values['unique_key'];
    }
    return $cart_item;
}, 10, 2 );

// Display artwork info in checkout review and mini-cart
add_filter( 'woocommerce_get_item_data', function( $item_data, $cart_item ) {
    if ( empty( $cart_item['podval_artwork'] ) ) return $item_data;
    $aw = $cart_item['podval_artwork'];

    $slot_labels = array(
        'front'  => 'Перед',
        'back'   => 'Спина',
        'sleeve' => 'Рукав',
    );

    if ( ! empty( $aw['files'] ) ) {
        foreach ( $aw['files'] as $slot => $file_id ) {
            $fname = basename( get_attached_file( $file_id ) );
            $label = $slot_labels[ $slot ] ?? $slot;
            $item_data[] = array(
                'key'   => $label,
                'value' => $fname,
            );
        }
    } elseif ( ! empty( $aw['skipped'] ) && empty( $aw['texts'] ) ) {
        $item_data[] = array(
            'key'   => 'Файл',
            'value' => 'Будет отправлен позже',
        );
    }

    // Text lines ("Добавить надпись")
    if ( ! empty( $aw['texts'] ) && is_array( $aw['texts'] ) ) {
        foreach ( $aw['texts'] as $slot => $text_val ) {
            if ( ! $text_val ) continue;
            $label = ( $slot_labels[ $slot ] ?? $slot ) . ' (надпись)';
            $preview = mb_strlen( $text_val ) > 40 ? mb_substr( $text_val, 0, 38 ) . '…' : $text_val;
            $item_data[] = array(
                'key'   => $label,
                'value' => '«' . $preview . '»',
            );
        }
    }

    if ( ! empty( $aw['comment'] ) ) {
        $item_data[] = array(
            'key'   => 'Комментарий',
            'value' => wp_trim_words( $aw['comment'], 12 ),
        );
    }

    if ( ! empty( $aw['extra_files'] ) && is_array( $aw['extra_files'] ) ) {
        $extra_names = array();
        $placement_count = 0;
        foreach ( $aw['extra_files'] as $efile_id ) {
            if ( get_post_meta( (int) $efile_id, '_podval_placement', true ) === '1' ) {
                $placement_count++;
                continue;
            }
            $fname = basename( get_attached_file( $efile_id ) );
            if ( $fname ) $extra_names[] = $fname;
        }
        if ( $extra_names ) {
            $item_data[] = array(
                'key'   => 'Доп. файлы',
                'value' => count( $extra_names ) . ' шт.',
            );
        }
        if ( $placement_count ) {
            $item_data[] = array(
                'key'   => 'Расположение принта',
                'value' => 'схема приложена ✓',
            );
        }
    }

    return $item_data;
}, 10, 2 );

// Save artwork data to order line item on checkout
add_action( 'woocommerce_checkout_create_order_line_item', function( $item, $cart_item_key, $values, $order ) {
    $aw = ! empty( $values['podval_artwork'] ) ? $values['podval_artwork'] : null;
    if ( ! $aw ) return;

    // Save file IDs
    if ( ! empty( $aw['files'] ) ) {
        $item->add_meta_data( '_artwork_files', $aw['files'], true );
        // Also add to order-level _podval_print_file_ids for compatibility with admin display & cleanup
        $existing = $order->get_meta( '_podval_print_file_ids' );
        if ( ! is_array( $existing ) ) $existing = array();
        foreach ( $aw['files'] as $file_id ) {
            if ( ! in_array( (int) $file_id, $existing, true ) ) {
                $existing[] = (int) $file_id;
            }
        }
        $order->update_meta_data( '_podval_print_file_ids', $existing );

        // Set post_parent for cleanup compatibility
        foreach ( $aw['files'] as $file_id ) {
            wp_update_post( array( 'ID' => (int) $file_id, 'post_parent' => $order->get_id() ) );
        }
    }

    // Save layout (placement editor coords)
    if ( ! empty( $aw['layout'] ) ) {
        $item->add_meta_data( '_artwork_layout', $aw['layout'], true );
    }

    // Save extra files
    if ( ! empty( $aw['extra_files'] ) && is_array( $aw['extra_files'] ) ) {
        $item->add_meta_data( '_artwork_extra_files', $aw['extra_files'], true );
        // Also add to order-level print file IDs for cleanup
        $existing = $order->get_meta( '_podval_print_file_ids' );
        if ( ! is_array( $existing ) ) $existing = array();
        foreach ( $aw['extra_files'] as $efile_id ) {
            $efile_id = (int) $efile_id;
            if ( $efile_id && ! in_array( $efile_id, $existing, true ) ) {
                $existing[] = $efile_id;
            }
            wp_update_post( array( 'ID' => $efile_id, 'post_parent' => $order->get_id() ) );
        }
        $order->update_meta_data( '_podval_print_file_ids', $existing );
    }

    // Save comment
    if ( ! empty( $aw['comment'] ) ) {
        $item->add_meta_data( '_artwork_comment', $aw['comment'], true );
    }

    // Save text lines ("Добавить надпись" per slot)
    if ( ! empty( $aw['texts'] ) && is_array( $aw['texts'] ) ) {
        $item->add_meta_data( '_artwork_texts', $aw['texts'], true );
    }

    if ( ! empty( $aw['skipped'] ) ) {
        $item->add_meta_data( '_artwork_skipped', '1', true );
    }
}, 10, 4 );

// Display artwork in admin order items
add_action( 'woocommerce_after_order_itemmeta', function( $item_id, $item, $product ) {
    if ( ! is_admin() ) return;
    if ( ! ( $item instanceof WC_Order_Item_Product ) ) return;

    $files = $item->get_meta( '_artwork_files' );
    if ( empty( $files ) || ! is_array( $files ) ) return;

    $slot_labels = array( 'front' => 'Перед', 'back' => 'Зад', 'sleeve' => 'Рукав' );
    echo '<div style="margin-top:8px;"><strong>Файлы дизайна:</strong></div>';
    foreach ( $files as $slot => $file_id ) {
        $url  = wp_get_attachment_url( $file_id );
        $name = basename( get_attached_file( $file_id ) );
        $label = $slot_labels[ $slot ] ?? $slot;
        if ( $url ) {
            echo '<div style="font-size:12px;margin:2px 0;">' . esc_html( $label ) . ': <a href="' . esc_url( $url ) . '" target="_blank">' . esc_html( $name ) . '</a></div>';
        }
    }

    $comment = $item->get_meta( '_artwork_comment' );
    if ( $comment ) {
        echo '<div style="font-size:12px;margin-top:4px;"><strong>Пожелания:</strong> ' . esc_html( $comment ) . '</div>';
    }

    // Artwork texts (надписи) per slot
    $aw_texts = $item->get_meta( '_artwork_texts' );
    if ( ! empty( $aw_texts ) && is_array( $aw_texts ) ) {
        echo '<div style="margin-top:6px;"><strong>Надписи на принте:</strong></div>';
        foreach ( $aw_texts as $slot => $text_val ) {
            if ( ! $text_val ) continue;
            $label = $slot_labels[ $slot ] ?? $slot;
            echo '<div style="font-size:12px;margin:2px 0;">' . esc_html( $label ) . ': «' . esc_html( $text_val ) . '»</div>';
        }
    }

    $extra_files = $item->get_meta( '_artwork_extra_files' );
    if ( ! empty( $extra_files ) && is_array( $extra_files ) ) {
        $shown_extra = array();
        foreach ( $extra_files as $efile_id ) {
            // Skip placement schematics — they're shown in their own block below
            if ( get_post_meta( (int) $efile_id, '_podval_placement', true ) === '1' ) continue;
            $shown_extra[] = $efile_id;
        }
        if ( ! empty( $shown_extra ) ) {
            echo '<div style="margin-top:6px;"><strong>Доп. файлы:</strong></div>';
            foreach ( $shown_extra as $efile_id ) {
                $url  = wp_get_attachment_url( $efile_id );
                $name = basename( get_attached_file( $efile_id ) );
                if ( $url ) {
                    echo '<div style="font-size:12px;margin:2px 0;"><a href="' . esc_url( $url ) . '" target="_blank">' . esc_html( $name ) . '</a></div>';
                }
            }
        }
    }

    // Placement editor: show schematic reference thumbnails + coords
    $layout = $item->get_meta( '_artwork_layout' );
    $placement_thumbs = array();
    if ( ! empty( $extra_files ) && is_array( $extra_files ) ) {
        foreach ( $extra_files as $efile_id ) {
            if ( get_post_meta( (int) $efile_id, '_podval_placement', true ) === '1' ) {
                $purl = wp_get_attachment_url( $efile_id );
                if ( $purl ) $placement_thumbs[] = $purl;
            }
        }
    }
    if ( ! empty( $placement_thumbs ) || ( ! empty( $layout ) && is_array( $layout ) ) ) {
        echo '<div style="margin-top:6px;"><strong>Схема расположения:</strong></div>';
        foreach ( $placement_thumbs as $purl ) {
            echo '<a href="' . esc_url( $purl ) . '" target="_blank" style="display:inline-block;margin:4px 4px 0 0;"><img src="' . esc_url( $purl ) . '" style="width:70px;height:auto;border:1px solid #ddd;border-radius:4px;background:#fff;" alt="схема"></a>';
        }
        if ( ! empty( $layout ) && is_array( $layout ) ) {
            $pct = function( $v ) { return round( (float) $v * 100 ) . '%'; };
            foreach ( $layout as $slot => $data ) {
                $label = $slot_labels[ $slot ] ?? $slot;
                $bits = array();
                if ( ! empty( $data['img'] ) ) {
                    $bits[] = 'изобр. ' . $pct( $data['img']['x'] ?? 0 ) . '/' . $pct( $data['img']['y'] ?? 0 )
                            . ' (' . $pct( $data['img']['w'] ?? 0 ) . ')';
                }
                if ( ! empty( $data['text'] ) ) {
                    $tval = str_replace( array( "\r\n", "\n", "\r" ), ' / ', (string) ( $data['text']['value'] ?? '' ) );
                    $talign = $data['text']['align'] ?? 'left';
                    $bits[] = 'текст «' . esc_html( $tval ) . '» '
                            . $pct( $data['text']['x'] ?? 0 ) . '/' . $pct( $data['text']['y'] ?? 0 )
                            . ', выравн.: ' . esc_html( $talign );
                }
                if ( $bits ) {
                    echo '<div style="font-size:11px;color:#666;margin:2px 0;">' . esc_html( $label )
                        . ' — ' . implode( ', ', $bits ) . '</div>';
                }
            }
        }
    }
}, 10, 3 );


// — Security hardening: скрываем WordPress-сигнатуры —

/* =============================================
   PERF: Disable EWWW lazysizes
   В карточках товаров и других img используется native loading="lazy".
   EWWW Image Optimizer подключает lazysizes.min.js (~828ms eval по Lighthouse)
   и заменяет src="..." на placeholder, что ломает width/height и даёт CLS.
   Native lazy + decoding=async справляются без JS — отключаем агрессивно.
   ============================================= */
// 1. Главные фильтры EWWW для отключения lazy-load
add_filter( 'eio_do_lazyload',     '__return_false', 999 );
add_filter( 'eio_use_lqip',        '__return_false', 999 );
add_filter( 'eio_use_dcip',        '__return_false', 999 );
add_filter( 'eio_use_native_lazy', '__return_true',  999 );  // если уж включено — пусть юзает native
add_filter( 'eio_lazy_load_skip_class', function( $skip ) {
    // Скипаем все классы, какие встречаются в теме
    $extra = array( 'site-footer__payments', 'product-card-thumb', 'mega-news-card__img', 'hero__bg', 'sp-main-img', 'sp-thumb' );
    if ( ! is_array( $skip ) ) $skip = array();
    return array_unique( array_merge( $skip, $extra ) );
}, 999 );

// 2. Принудительно дёргаем зарегистрированные скрипты на случай, если EWWW успел enqueue
add_action( 'wp_enqueue_scripts', function() {
    foreach ( array( 'lazysizes', 'eio-lazy-load', 'ewww-image-optimizer-lazy-load' ) as $h ) {
        if ( wp_script_is( $h, 'enqueued' ) || wp_script_is( $h, 'registered' ) ) {
            wp_dequeue_script( $h );
            wp_deregister_script( $h );
        }
    }
}, 999 );

// 3. Hard kill — снимаем output-buffering обработчики, которые EWWW цепляет
//    через свой класс EWWWIO_Lazy_Load на init
add_action( 'wp', function() {
    if ( ! is_admin() && class_exists( 'EWWWIO_Lazy_Load' ) ) {
        global $eio_lazy_load;
        if ( isset( $eio_lazy_load ) && is_object( $eio_lazy_load ) ) {
            remove_action( 'wp_head',         array( $eio_lazy_load, 'no_js_css' ) );
            remove_action( 'wp_enqueue_scripts', array( $eio_lazy_load, 'wp_enqueue_scripts' ) );
            remove_filter( 'autoptimize_filter_html_after_minify', array( $eio_lazy_load, 'filter_page_output' ) );
            remove_filter( 'wp_lazy_loading_enabled', array( $eio_lazy_load, 'wp_lazy_loading_enabled' ) );
        }
    }
}, 1 );


// — Security hardening: скрываем WordPress-сигнатуры —

// Убираем версию WP из RSS-фидов
add_filter( 'the_generator', '__return_empty_string' );

// Блокируем user enumeration через REST API (?rest_route=/wp/v2/users)
add_filter( 'rest_endpoints', function( $endpoints ) {
    if ( isset( $endpoints['/wp/v2/users'] ) ) {
        unset( $endpoints['/wp/v2/users'] );
    }
    if ( isset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] ) ) {
        unset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
    }
    return $endpoints;
});

// Блокируем user enumeration через ?author=N
add_action( 'template_redirect', function() {
    if ( isset( $_GET['author'] ) && ! is_admin() ) {
        wp_redirect( home_url(), 301 );
        exit;
    }
});

// Отключаем XML-RPC полностью (не нужен для WooCommerce)
add_filter( 'xmlrpc_enabled', '__return_false' );
add_filter( 'wp_headers', function( $headers ) {
    unset( $headers['X-Pingback'] );
    return $headers;
});

// Убираем oembed discovery links
remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
remove_action( 'wp_head', 'wp_oembed_add_host_js' );

// 5. Оптимизация WooCommerce — отключаем лишние скрипты на не-WC страницах
add_action( 'wp_enqueue_scripts', function() {
    // jquery-migrate не нужен на фронте — убираем из зависимостей jquery
    if ( ! is_admin() ) {
        $jquery = wp_scripts()->registered['jquery'] ?? null;
        if ( $jquery ) {
            $jquery->deps = array_diff( $jquery->deps, array( 'jquery-migrate' ) );
        }
        wp_dequeue_script( 'jquery-migrate' );
    }

    // Блок-стили Gutenberg/WC не используются в нашей теме
    wp_dequeue_style( 'wp-block-library' );
    wp_dequeue_style( 'wp-block-library-theme' );
    wp_dequeue_style( 'wc-blocks-style' );
    wp_dequeue_style( 'wc-blocks-style-css' );
    wp_dequeue_style( 'wc-blocks-vendors-style' );
    wp_dequeue_style( 'wc-blocks-packages-style' );
    wp_dequeue_style( 'woocommerce-general' );
    wp_dequeue_style( 'global-styles' );
    wp_dequeue_style( 'classic-theme-styles' );

    if ( function_exists('is_woocommerce') && ! is_woocommerce() && ! is_cart() && ! is_checkout() && ! is_account_page() ) {
        // Убираем WC скрипты с обычных страниц (about, delivery, oferta и т.д.)
        wp_dequeue_script( 'wc-cart-fragments' );
        wp_dequeue_script( 'woocommerce' );
        wp_dequeue_script( 'wc-add-to-cart' );
    }
}, 99 );

// 5b. WC Blocks стили подключаются поздно и под разными handles — убиваем ВСЕ
add_action( 'wp_enqueue_scripts', function() {
    // Перебираем все зарегистрированные стили и убиваем wc-blocks
    $styles = wp_styles();
    foreach ( $styles->registered as $handle => $style ) {
        if ( strpos( $handle, 'wc-blocks' ) !== false ) {
            wp_dequeue_style( $handle );
            wp_deregister_style( $handle );
        }
    }
}, 999 );

// 5c. WC Blocks JS — тема использует классический checkout, блочный не нужен
//     components.min.js (788KB) + date.min.js (766KB) + react-dom (126KB) +
//     wc-cart-checkout-* (350KB+) = ~2 MB лишнего JS
add_action( 'wp_enqueue_scripts', function() {
    if ( is_admin() ) return;

    // Handles WC Blocks JS и их тяжёлых зависимостей
    $wc_blocks_scripts = [
        // WC Blocks checkout/cart frontend
        'wc-blocks-checkout',
        'wc-blocks-cart',
        'wc-cart-checkout-base-frontend',
        'wc-cart-checkout-vendors-frontend',
        'wc-blocks-data',
        'wc-blocks-registry',
        'wc-blocks-middleware',
        'wc-blocks-components',
        'wc-blocks-shared-context',
        'wc-blocks-shared-hocs',
        // Gutenberg/WP зависимости, которые тянут только блоки
        'wp-components',
        'wp-compose',
        'wp-data',
        'wp-date',
        'wp-element',
        'wp-rich-text',
        'wp-primitives',
        'wp-warning',
        'wp-wordcount',
        'wp-notices',
        'wp-redux-routine',
        'wp-priority-queue',
        'wp-a11y',
        'moment',
        'wp-polyfill',
    ];

    $scripts = wp_scripts();
    foreach ( $wc_blocks_scripts as $handle ) {
        if ( isset( $scripts->registered[ $handle ] ) ) {
            wp_dequeue_script( $handle );
            wp_deregister_script( $handle );
        }
    }

    // Wildcard: убиваем всё с wc-blocks- в handle (на случай новых handles в будущих версиях WC)
    foreach ( $scripts->registered as $handle => $script ) {
        if ( strpos( $handle, 'wc-blocks' ) !== false ) {
            wp_dequeue_script( $handle );
            wp_deregister_script( $handle );
        }
    }

    // React/ReactDOM — нужны только если есть блочный checkout (у нас нет)
    // Проверяем что не ломаем Gutenberg в admin (is_admin уже проверен выше)
    if ( ! is_customize_preview() ) {
        wp_dequeue_script( 'react' );
        wp_deregister_script( 'react' );
        wp_dequeue_script( 'react-dom' );
        wp_deregister_script( 'react-dom' );
        wp_dequeue_script( 'react-jsx-runtime' );
        wp_deregister_script( 'react-jsx-runtime' );
    }
}, 999 );

// 5d. WC скрипты: sourcebuster/order-attribution нужны только на checkout
add_action( 'wp_enqueue_scripts', function() {
    if ( function_exists( 'is_checkout' ) && ! is_checkout() ) {
        wp_dequeue_script( 'sourcebuster-js' );
        wp_dequeue_script( 'wc-order-attribution' );
    }
}, 99 );

// 6. Версионирование CSS/JS для cache busting
add_filter( 'style_loader_src', 'podval_cache_bust', 10, 2 );
add_filter( 'script_loader_src', 'podval_cache_bust', 10, 2 );
function podval_cache_bust( $src, $handle ) {
    if ( strpos( $src, 'podval-' ) !== false || $handle === 'podval-style' || $handle === 'podval-main' ) {
        $file = str_replace( get_template_directory_uri(), get_template_directory(), explode( '?', $src )[0] );
        if ( file_exists( $file ) ) {
            $src = add_query_arg( 'v', filemtime( $file ), $src );
        }
    }
    return $src;
}

// Кастомная страница оплаты заказа (order-pay)
add_filter( 'template_include', function( $template ) {
    if ( function_exists( 'is_checkout' ) && is_checkout() && is_wc_endpoint_url( 'order-pay' ) ) {
        $custom = get_stylesheet_directory() . '/woocommerce/checkout/page-order-pay.php';
        if ( file_exists( $custom ) ) return $custom;
    }
    return $template;
}, 97 );

/* ═══════════════════════════════════════════
   УСЛУГИ — Обработка формы заявки
   Создаёт заказ WC с пометкой "Услуга"
   ═══════════════════════════════════════════ */
add_action( 'admin_post_nopriv_podval_service_order', 'podval_handle_service_order' );
add_action( 'admin_post_podval_service_order', 'podval_handle_service_order' );

function podval_handle_service_order() {
    /* форма лендингов отправляется fetch'ем с svc_ajax=1 (Р9 ТЗ) —
       тогда все ответы JSON. Без флага — прежнее поведение: wp_die / редирект. */
    $is_ajax = ! empty( $_POST['svc_ajax'] );

    $fail = function( $message, $title = '' ) use ( $is_ajax ) {
        if ( $is_ajax ) {
            wp_send_json_error( array( 'message' => $message ) );
        }
        wp_die( esc_html( $message ), $title ? esc_html( $title ) : '', array( 'response' => 200, 'back_link' => true ) );
    };
    $done = function( $url ) use ( $is_ajax ) {
        if ( $is_ajax ) {
            wp_send_json_success( array( 'redirect' => $url ) );
        }
        wp_redirect( $url );
        exit;
    };

    /* ── Honeypot ────────────────────────────────────────────
       Поле svc_website спрятано за экраном; человек его не видит. Боту
       отвечаем «успехом», чтобы он не подбирал обход, заказ не создаём. */
    if ( ! empty( $_POST['svc_website'] ) ) {
        $done( home_url( '/' ) );
    }

    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_podval_svc_nonce'] ?? '' ) ), 'podval_service_nonce' ) ) {
        $fail( 'Страница устарела — обновите её и отправьте заявку ещё раз.', 'Ошибка безопасности' );
    }

    /* ── Идемпотентность ──────────────────────────────────────────────
       Защита от дублей: double-click, ретрай POST со стороны WAF/прокси,
       перезагрузка с повторной отправкой формы. Каждая форма несёт
       уникальный токен (svc_token). Первый проход резервирует токен в
       transient; повторный POST с тем же токеном НЕ создаёт новый заказ,
       а редиректит на уже созданный.
       прежней версии (P1): токен генерирует JS в браузере. Раньше он печатался в
       PHP, попадал в кэш WP-Optimize одним на всех, и второй посетитель
       получал thank-you первого — с его именем, телефоном и Telegram.
       ──────────────────────────────────────────────────────────────── */
    $token = sanitize_key( $_POST['svc_token'] ?? '' );
    if ( $token ) {
        $lock_key = 'podval_svc_tok_' . $token;
        $existing = get_transient( $lock_key );

        if ( $existing === 'processing' ) {
            // Параллельный дубль ещё обрабатывается — отдаём «спасибо» без заказа.
            $fail( 'Заявка уже обрабатывается. Обновлять страницу не нужно.', 'Заявка принята' );
        }

        if ( is_numeric( $existing ) ) {
            // Заказ по этому токену уже создан — редиректим на тот же thank-you.
            $done_order = wc_get_order( (int) $existing );
            if ( $done_order ) {
                $done( $done_order->get_checkout_order_received_url() );
            }
        }
    }

    /* ── Rate-limit: не больше 3 заявок за 10 минут с одного IP ── */
    $ip       = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    $rl_key   = 'podval_sl_rl_' . md5( $ip );
    $rl       = get_transient( $rl_key );
    $rl       = is_array( $rl ) ? $rl : array( 'n' => 0, 't' => time() );
    if ( $ip && $rl['n'] >= 3 && ( time() - (int) $rl['t'] ) < 10 * MINUTE_IN_SECONDS ) {
        $fail( 'Слишком много заявок подряд. Напишите нам в Telegram — ответим быстрее.', 'Подождите немного' );
    }

    $product_id = absint( $_POST['service_product_id'] ?? 0 );
    $name       = sanitize_text_field( wp_unslash( $_POST['svc_name'] ?? '' ) );
    $phone      = sanitize_text_field( wp_unslash( $_POST['svc_phone'] ?? '' ) );
    $messenger  = sanitize_text_field( wp_unslash( $_POST['svc_messenger'] ?? '' ) );
    $email      = sanitize_email( wp_unslash( $_POST['svc_email'] ?? '' ) );
    $comment    = sanitize_textarea_field( wp_unslash( $_POST['svc_comment'] ?? '' ) );
    $delivery   = sanitize_text_field( wp_unslash( $_POST['svc_delivery'] ?? 'discuss' ) );
    $del_addr   = sanitize_text_field( wp_unslash( $_POST['svc_delivery_address'] ?? '' ) );
    $qty        = max( 0, min( 100000, absint( $_POST['svc_qty'] ?? 0 ) ) );

    if ( ! $name || ! $messenger || ! $phone ) {
        $fail( 'Пожалуйста, заполните обязательные поля (Имя, Телефон, Telegram)' );
    }

    // Валидация телефона
    $phone_clean = preg_replace( '/[\s\-\(\)]/', '', $phone );
    if ( ! preg_match( '/^(\+375\d{9}|\+7\d{10}|375\d{9}|80\d{9})$/', $phone_clean ) ) {
        $fail( 'Введите корректный белорусский (+375) или российский (+7) номер телефона.' );
    }

    // Telegram — без кириллицы
    if ( preg_match( '/[\x{0400}-\x{04FF}]/u', $messenger ) ) {
        $fail( 'В поле Telegram используйте латиницу: @username или номер телефона.' );
    }

    // Email
    if ( ! empty( $_POST['svc_email'] ) && ! is_email( wp_unslash( $_POST['svc_email'] ) ) ) {
        $fail( 'Введите корректный e-mail адрес.' );
    }

    // Санитизация комментария
    $comment = wp_strip_all_tags( $comment );
    $comment = preg_replace( '/javascript\s*:/i', '', $comment );
    $comment = preg_replace( '/on\w+\s*=/i', '', $comment );
    $comment = preg_replace( '/\{\{.*?\}\}/', '', $comment );
    $comment = preg_replace( '/\$\{.*?\}/', '', $comment );

    /* ── Лендинг и расчёт ────────────────────────────────────
       sl_calc несёт только параметры; цены пересчитываются из реестра.
       Тираж из поля формы важнее тиража из калькулятора — клиент мог
       поправить его после расчёта. */
    $landing_id = absint( $_POST['sl_landing'] ?? 0 );
    if ( $landing_id && ( ! function_exists( 'podval_sl_is_landing' ) || ! podval_sl_is_landing( $landing_id ) || get_post_status( $landing_id ) !== 'publish' ) ) {
        $landing_id = 0;
    }
    $landing_h1 = $landing_id ? podval_sl_h1( $landing_id ) : '';
    $calc       = null;
    $calc_text  = '';
    if ( $landing_id ) {
        $calc = podval_sl_request_calc( $landing_id, wp_unslash( $_POST['sl_calc'] ?? '' ), $qty );
        if ( $calc ) {
            $calc_text = podval_price_calc_summary( $calc, podval_sl_get( $landing_id )['card']['lead_time'] );
            if ( ! $qty ) {
                $qty = (int) $calc['qty'];
            }
        }
    }

    // Все проверки пройдены — ставим краткую блокировку на время обработки.
    // Гонка ретраев (WAF/proxy шлёт второй POST, пока первый ещё не завершён)
    // упрётся в эту блокировку. Ставим только сейчас, чтобы ошибки валидации
    // выше не оставляли «застрявший» лок и не мешали повторной отправке.
    if ( ! empty( $token ) ) {
        set_transient( 'podval_svc_tok_' . $token, 'processing', 60 );
    }

    // Просим фолбэк-хук нумерации (woocommerce_new_order) НЕ трогать этот заказ:
    // wc_create_order() ниже стрельнёт хук синхронно, но номер мы присвоим сами
    // на ЭТОМ же объекте до save() — так номер гарантированно попадёт в Telegram.
    $GLOBALS['podval_skip_auto_order_number'] = true;

    // Создаём заказ WC
    $order = wc_create_order();

    // Добавляем товар-услугу
    if ( $product_id ) {
        $product = wc_get_product( $product_id );
        if ( $product ) {
            $order->add_product( $product, max( 1, $qty ), array( 'subtotal' => 0, 'total' => 0 ) );
        }
    } elseif ( $landing_id && function_exists( 'wc_get_product_id_by_sku' ) ) {
        // заявка с лендинга — скрытый товар «Заявка на печать» (SKU svc-request).
        // Нет товара — заказ без позиции, как раньше.
        $req_id  = wc_get_product_id_by_sku( 'svc-request' );
        $product = $req_id ? wc_get_product( $req_id ) : null;
        if ( $product ) {
            $order->add_product( $product, max( 1, $qty ), array(
                'name'     => 'Заявка: ' . $landing_h1,
                'subtotal' => 0,
                'total'    => 0,
            ) );
        }
    }

    // Устанавливаем данные
    $order->set_billing_first_name( $name );
    $order->set_billing_phone( $phone );
    $order->set_billing_email( $email );

    // Первая строка customer note — лендинг и расчёт: так расчёт гарантированно
    // попадает в Telegram-уведомление без правки плагина.
    $note_lines = array();
    if ( $landing_h1 ) {
        $note_lines[] = '[' . $landing_h1 . ']' . ( $calc_text ? ' ' . $calc_text : ( $qty ? ' тираж ' . $qty . ' шт' : '' ) );
    }
    if ( $comment !== '' ) {
        $note_lines[] = $comment;
    }
    $order->set_customer_note( implode( "\n", $note_lines ) );

    // Мета-данные для идентификации заявки из услуг
    $order->update_meta_data( '_order_type', 'service' );
    $order->update_meta_data( '_billing_messenger', podval_normalize_messenger( $messenger ) );
    if ( $landing_id ) {
        $order->update_meta_data( '_podval_sl_landing', $landing_id );
    }
    if ( $calc ) {
        $order->update_meta_data( '_podval_sl_calc', $calc );
    }

    $delivery_labels = array(
        'discuss'  => 'Обсудим позже',
        'pickup'   => 'Самовывоз',
        'delivery' => 'Нужна доставка',
    );
    $order->update_meta_data( '_podval_shipping_method', $delivery );
    $order->update_meta_data( '_podval_shipping_label', $delivery_labels[ $delivery ] ?? $delivery );
    if ( $del_addr ) {
        $order->set_shipping_address_1( $del_addr );
    }

    // Обработка загруженных файлов — только белый список (P0, прежней версии)
    if ( ! empty( $_POST['podval_print_files'] ) && is_array( $_POST['podval_print_files'] ) ) {
        $file_ids = podval_filter_print_file_ids( (array) wp_unslash( $_POST['podval_print_files'] ), (int) $order->get_id() );
        if ( ! empty( $file_ids ) ) {
            $order->update_meta_data( '_podval_print_file_ids', $file_ids );
            foreach ( $file_ids as $att_id ) {
                wp_update_post( array( 'ID' => $att_id, 'post_parent' => $order->get_id() ) );
            }
        }
    }

    // Присваиваем последовательный номер СИНХРОННО, до save(), на ЭТОМ объекте.
    // Фолбэк-хук пропустил заказ (флаг podval_skip_auto_order_number), поэтому
    // двойного инкремента нет, а мета _podval_order_number гарантированно есть в
    // объекте к моменту смены статуса → плагин Telegram получит верный номер.
    podval_assign_order_number( $order );
    unset( $GLOBALS['podval_skip_auto_order_number'] );

    // Статус и сохранение
    $order->set_status( 'on-hold', $landing_h1 ? 'Заявка с лендинга «' . $landing_h1 . '»' : 'Заявка на услугу (из раздела «Услуги»)' );
    $order->set_total( 0 );
    $order->save();

    if ( $calc_text ) {
        $order->add_order_note( 'Расчёт с лендинга (цены пересчитаны сервером): ' . $calc_text );
    }

    // Привязываем токен идемпотентности к созданному заказу (15 мин).
    // Повторный POST с тем же токеном теперь редиректит сюда же.
    if ( ! empty( $token ) ) {
        set_transient( 'podval_svc_tok_' . $token, $order->get_id(), 15 * MINUTE_IN_SECONDS );
    }

    // Счётчик rate-limit — только за созданные заявки. Окно — 10 минут от первой.
    if ( $ip ) {
        $window = 10 * MINUTE_IN_SECONDS;
        if ( ( time() - (int) $rl['t'] ) >= $window ) {
            $rl = array( 'n' => 0, 't' => time() );
        }
        $rl['n']++;
        set_transient( $rl_key, $rl, max( 60, $window - ( time() - (int) $rl['t'] ) ) );
    }

    // Перенаправляем на Thank You
    $done( $order->get_checkout_order_received_url() );
}

// Показываем тип заказа (Услуга) в админке
add_action( 'woocommerce_admin_order_data_after_order_details', function( $order ) {
    $type = $order->get_meta( '_order_type' );
    if ( $type === 'service' ) {
        echo '<p class="form-field"><strong style="color:#ff6a00;background:#1a1a1a;padding:4px 10px;border-radius:4px;">🛠 ЗАЯВКА НА УСЛУГУ</strong></p>';
    }
} );

/* ═══════════════════════════════════════════
   ПОСЛЕДОВАТЕЛЬНАЯ НУМЕРАЦИЯ ЗАКАЗОВ
   Заказы получают номера 1, 2, 3... вместо post ID
   ═══════════════════════════════════════════ */

/**
 * Атомарно резервирует следующий последовательный номер заказа.
 *
 * Использует прямой SQL UPDATE ... +1 с возвратом значения, чтобы две
 * заявки, пришедшие в одну секунду, не получили один и тот же номер
 * (gap-free, race-safe). НЕ присваивает мету заказу — только резервирует
 * число. Вызывающий код сам пишет _podval_order_number в объект до save().
 *
 * @return int Зарезервированный номер (>= 1).
 */
function podval_reserve_order_number() {
    global $wpdb;

    $option = 'podval_next_order_number';

    // Гарантируем, что строка опции существует (autoload=no).
    if ( get_option( $option, null ) === null ) {
        add_option( $option, 1, '', 'no' );
    }

    // Атомарный инкремент на уровне БД: UPDATE option = option + 1.
    // wp_options.option_value — строка, поэтому приводим через (option_value+0).
    $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$wpdb->options}
             SET option_value = (option_value + 0) + 1
             WHERE option_name = %s",
            $option
        )
    );

    // Читаем НОВОЕ значение напрямую из БД (минуя кэш опций).
    $new_value = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $option
        )
    );

    // Сбрасываем кэш опции, чтобы get_option() отдавал актуальное значение.
    wp_cache_delete( $option, 'options' );

    // Зарезервированный номер — это значение ДО последнего инкремента.
    $reserved = $new_value - 1;
    return $reserved >= 1 ? $reserved : 1;
}

/**
 * Присваивает заказу последовательный номер, если он ещё не присвоен.
 * Резервирует номер атомарно и пишет его в объект (без save — это делает
 * вызывающий код или хук). Возвращает присвоенный номер.
 *
 * @param WC_Order $order
 * @return int
 */
function podval_assign_order_number( $order ) {
    if ( ! $order instanceof WC_Order ) return 0;

    $existing = (int) $order->get_meta( '_podval_order_number', true );
    if ( $existing ) return $existing;

    $num = podval_reserve_order_number();
    $order->update_meta_data( '_podval_order_number', $num );
    return $num;
}

// Фолбэк: присваиваем номер при создании заказа, если обработчик не сделал
// этого явно (обычные заказы чекаута). Защита от двойного инкремента —
// podval_assign_order_number() сам проверяет наличие меты и не резервирует
// номер повторно.
add_action( 'woocommerce_new_order', function( $order_id ) {
    // Сервисный обработчик присваивает номер сам (на своём объекте до save),
    // чтобы он гарантированно попал в Telegram. Пропускаем, чтобы не дублировать.
    if ( ! empty( $GLOBALS['podval_skip_auto_order_number'] ) ) return;

    $order = wc_get_order( $order_id );
    if ( ! $order ) return;

    // Уже присвоен (например, обработчиком услуг до save) — выходим.
    if ( $order->get_meta( '_podval_order_number', true ) ) return;

    podval_assign_order_number( $order );
    $order->save();
}, 10, 1 );

// Подменяем отображаемый номер заказа везде (админка, emails, фронт)
add_filter( 'woocommerce_order_number', function( $order_number, $order ) {
    $custom = $order->get_meta( '_podval_order_number', true );
    return $custom ? $custom : $order_number;
}, 10, 2 );

// Поиск заказа по кастомному номеру в админке
add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', function( $query, $query_vars ) {
    if ( ! empty( $query_vars['meta_query'] ) ) return $query;

    // Если ищут по номеру — проверяем кастомное поле
    if ( ! empty( $query_vars['s'] ) && is_numeric( $query_vars['s'] ) ) {
        $query['meta_query'][] = array(
            'key'   => '_podval_order_number',
            'value' => absint( $query_vars['s'] ),
        );
        // Убираем стандартный поиск по ID чтобы не дублировать
        unset( $query['s'] );
    }
    return $query;
}, 10, 2 );

// Для HPOS — поиск по кастомному номеру
add_filter( 'woocommerce_hpos_admin_search_filters', function( $filters ) {
    return $filters;
} );

// Одноразовая миграция: присваиваем номера существующим заказам
add_action( 'admin_init', function() {
    if ( get_option( 'podval_order_numbers_migrated' ) ) return;

    $orders = wc_get_orders( array(
        'limit'    => -1,
        'orderby'  => 'date',
        'order'    => 'ASC',
        'return'   => 'ids',
    ) );

    $num = 1;
    foreach ( $orders as $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) continue;
        $existing = $order->get_meta( '_podval_order_number', true );
        if ( ! $existing ) {
            $order->update_meta_data( '_podval_order_number', $num );
            $order->save();
        }
        $num++;
    }

    update_option( 'podval_next_order_number', $num );
    update_option( 'podval_order_numbers_migrated', '1' );
});

/* ═══════════════════════════════════════════
   АВТООЧИСТКА ФАЙЛОВ ПРИНТА
   Логика живёт в отдельном плагине Podval Print Cleanup.
   Админ-панель: WooCommerce → 🗑 Очистка принтов.
   Hook "podval_cleanup_print_files" теперь регистрирует и обрабатывает плагин.
   ═══════════════════════════════════════════ */

/* ═══════════════════════════════════════════
   АВТОГЕНЕРАЦИЯ META-ТЕГОВ ИЗОБРАЖЕНИЙ
   Alt, Title, Caption из имени файла
   ═══════════════════════════════════════════ */
add_action( 'add_attachment', function( $attachment_id ) {
    $post = get_post( $attachment_id );
    if ( ! $post || ! wp_attachment_is_image( $attachment_id ) ) return;

    // Берём имя файла без расширения
    $filename = pathinfo( get_attached_file( $attachment_id ), PATHINFO_FILENAME );

    // Убираем наш timestamp суффикс (20260318-212005-347)
    $filename = preg_replace( '/-\d{8}-\d{6}-\d{2,4}$/', '', $filename );

    // Очищаем: дефисы/подчёркивания → пробелы, убираем цифровые префиксы
    $clean = str_replace( array( '-', '_', '.', '+' ), ' ', $filename );
    $clean = preg_replace( '/^\d+\s*/', '', $clean );        // убираем числовой префикс
    $clean = preg_replace( '/\s+/', ' ', trim( $clean ) );   // множественные пробелы
    $clean = mb_convert_case( $clean, MB_CASE_TITLE, 'UTF-8' ); // Каждое Слово С Заглавной

    if ( ! $clean ) return;

    // Заполняем только пустые поля (не перезаписываем ручные значения)
    $update = array( 'ID' => $attachment_id );
    $changed = false;

    // Title
    if ( ! $post->post_title || $post->post_title === $filename ) {
        $update['post_title'] = $clean;
        $changed = true;
    }

    // Caption (post_excerpt)
    if ( ! $post->post_excerpt ) {
        $update['post_excerpt'] = $clean;
        $changed = true;
    }

    if ( $changed ) {
        wp_update_post( $update );
    }

    // Alt text (отдельная мета)
    $existing_alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
    if ( ! $existing_alt ) {
        update_post_meta( $attachment_id, '_wp_attachment_image_alt', $clean );
    }
});

// Также обрабатываем существующие изображения при отображении —
// если alt пустой, генерируем из title товара (для товарных фото)
add_filter( 'wp_get_attachment_image_attributes', function( $attr, $attachment ) {
    if ( ! empty( $attr['alt'] ) ) return $attr;

    // Пробуем взять alt из мета
    $alt = get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true );
    if ( $alt ) {
        $attr['alt'] = $alt;
        return $attr;
    }

    // Если картинка привязана к товару — берём название товара
    $parent_id = $attachment->post_parent;
    if ( $parent_id && get_post_type( $parent_id ) === 'product' ) {
        $attr['alt'] = get_the_title( $parent_id );
        return $attr;
    }

    // Fallback — title вложения
    if ( $attachment->post_title && $attachment->post_title !== pathinfo( get_attached_file( $attachment->ID ), PATHINFO_FILENAME ) ) {
        $attr['alt'] = $attachment->post_title;
    }

    return $attr;
}, 10, 2 );

/* ─── Order thumbnail column in admin orders list ─── */
add_filter( 'manage_woocommerce_page_wc-orders_columns', 'podval_add_order_thumb_column' );
add_filter( 'manage_edit-shop_order_columns', 'podval_add_order_thumb_column' );
function podval_add_order_thumb_column( $columns ) {
    $new = array();
    foreach ( $columns as $key => $label ) {
        if ( $key === 'order_number' ) {
            $new['order_thumb'] = '<span class="dashicons dashicons-format-image" title="Превью"></span>';
        }
        $new[ $key ] = $label;
    }
    return $new;
}

// HPOS table
add_action( 'manage_woocommerce_page_wc-orders_custom_column', 'podval_render_order_thumb_column', 10, 2 );
function podval_render_order_thumb_column( $column_name, $order ) {
    if ( $column_name !== 'order_thumb' ) return;
    if ( ! is_a( $order, 'WC_Order' ) ) $order = wc_get_order( $order );
    if ( ! $order ) return;
    podval_output_order_thumb( $order );
}

// Legacy CPT table
add_action( 'manage_shop_order_posts_custom_column', 'podval_render_order_thumb_column_legacy', 10, 2 );
function podval_render_order_thumb_column_legacy( $column_name, $post_id ) {
    if ( $column_name !== 'order_thumb' ) return;
    $order = wc_get_order( $post_id );
    if ( ! $order ) return;
    podval_output_order_thumb( $order );
}

function podval_output_order_thumb( $order ) {
    $items = $order->get_items();
    if ( empty( $items ) ) return;
    $item    = reset( $items );
    $product = $item->get_product();
    if ( ! $product ) return;
    $thumb = $product->get_image( array( 40, 40 ), array( 'style' => 'border-radius:4px;' ) );
    $count = count( $items );
    echo $thumb;
    if ( $count > 1 ) {
        echo '<span style="color:#999;font-size:11px;margin-left:2px;">+' . ( $count - 1 ) . '</span>';
    }
}

// Style the thumbnail column narrow
add_action( 'admin_head', function() {
    $screen = get_current_screen();
    if ( ! $screen ) return;
    if ( $screen->id === 'woocommerce_page_wc-orders' || $screen->id === 'edit-shop_order' ) {
        echo '<style>.column-order_thumb{width:50px;text-align:center;} .column-order_thumb img{vertical-align:middle;}</style>';
    }
});

/* ─── Hide last_name dash in admin order display ─── */
add_filter( 'woocommerce_order_formatted_billing_address', function( $address, $order ) {
    if ( isset( $address['last_name'] ) && trim( $address['last_name'] ) === '-' ) {
        $address['last_name'] = '';
    }
    return $address;
}, 10, 2 );

/* ─── Russian translations for WooCommerce messages ─── */
add_filter( 'gettext', function( $translated, $text, $domain ) {
    if ( $domain !== 'woocommerce' ) return $translated;
    static $map = null;
    if ( $map === null ) {
        $map = array(
            'Coupon code applied successfully.'          => 'Промокод успешно применён!',
            'Coupon has been removed.'                   => 'Промокод удалён.',
            'Coupon "%s" does not exist!'                => 'Промокод «%s» не существует!',
            'Sorry, this coupon is not applicable to your cart contents.' => 'Этот промокод не применим к товарам в корзине.',
            'Coupon code already applied!'               => 'Этот промокод уже применён!',
            'This coupon has expired.'                   => 'Срок действия промокода истёк.',
            'Coupon usage limit has been reached.'       => 'Лимит использования промокода исчерпан.',
            'Sorry, this coupon is not valid for sale items.' => 'Этот промокод не действует на товары со скидкой.',
            'The minimum spend for this coupon is %s.'   => 'Минимальная сумма заказа для этого промокода: %s.',
            'The maximum spend for this coupon is %s.'   => 'Максимальная сумма заказа для этого промокода: %s.',
        );
    }
    return isset( $map[ $text ] ) ? $map[ $text ] : $translated;
}, 10, 3 );

/* ─── Hide payment gateway configuration errors from customers ─── */
add_filter( 'woocommerce_errors', function( $errors ) {
    if ( is_checkout() ) {
        $errors = array_filter( $errors, function( $msg ) {
            $lower = mb_strtolower( $msg );
            // Hide technical payment errors (gateway misconfiguration)
            if ( strpos( $lower, 'errorcode' ) !== false ) return false;
            if ( strpos( $lower, 'token' ) !== false && strpos( $lower, 'password' ) !== false ) return false;
            if ( strpos( $lower, 'error while processing payment' ) !== false ) return false;
            return true;
        });
    }
    return array_values( $errors );
});

/* Also filter WC notices (covers both AJAX and non-AJAX checkout) */
add_filter( 'woocommerce_notice_types', function( $types ) { return $types; } );
add_action( 'woocommerce_before_checkout_form', function() {
    $notices = wc_get_notices( 'error' );
    if ( empty( $notices ) ) return;
    $filtered = array();
    foreach ( $notices as $notice ) {
        $msg = is_array( $notice ) ? ( $notice['notice'] ?? '' ) : $notice;
        $lower = mb_strtolower( $msg );
        if ( strpos( $lower, 'errorcode' ) !== false ) continue;
        if ( strpos( $lower, 'error while processing payment' ) !== false ) continue;
        $filtered[] = $notice;
    }
    wc_set_notices( $filtered, 'error' );
}, 1 );

/* ─── Декоративные разделители между секциями (главная) ─── */
function podval_render_decor( $slot_num ) {
    $prefix = "podval_decor_{$slot_num}";
    $enabled = get_theme_mod( "{$prefix}_enabled", false );
    if ( ! $enabled ) return;

    $image     = get_theme_mod( "{$prefix}_image", '' );
    $image_mob = get_theme_mod( "{$prefix}_image_mob", '' );
    $height    = absint( get_theme_mod( "{$prefix}_height", 120 ) );
    $height_m  = absint( get_theme_mod( "{$prefix}_height_mob", 60 ) );
    $mode      = get_theme_mod( "{$prefix}_mode", 'cover' );
    $position  = get_theme_mod( "{$prefix}_position", 'center' );
    $overlay   = get_theme_mod( "{$prefix}_overlay", 'none' );

    if ( ! $image ) return;

    // Background CSS
    $bg_size   = ( $mode === 'tile' ) ? 'auto' : ( ( $mode === 'repeat-x' ) ? 'auto 100%' : $mode );
    $bg_repeat = ( $mode === 'tile' ) ? 'repeat' : ( ( $mode === 'repeat-x' ) ? 'repeat-x' : 'no-repeat' );
    $bg_pos    = "center {$position}";

    // Overlay CSS
    $overlay_css = '';
    $overlay_class = '';
    if ( $overlay === 'stripes' ) {
        $overlay_css = 'background-image:repeating-linear-gradient(-45deg,rgba(255,106,0,0.3) 0,rgba(255,106,0,0.3) 8px,transparent 8px,transparent 18px);';
    } elseif ( $overlay === 'fade' ) {
        $overlay_css = 'background:linear-gradient(to right,var(--black) 0%,transparent 15%,transparent 85%,var(--black) 100%);';
    } elseif ( $overlay === 'vignette' ) {
        $overlay_css = 'background:radial-gradient(ellipse at center,transparent 40%,var(--black) 100%);';
    } elseif ( $overlay === 'scanline' ) {
        $overlay_class = 'home-decor__overlay--scanline';
    } elseif ( $overlay === 'pulse' ) {
        $overlay_class = 'home-decor__overlay--pulse';
    } elseif ( $overlay === 'sweep' ) {
        $overlay_class = 'home-decor__overlay--sweep';
    } elseif ( $overlay === 'sparks' ) {
        $overlay_class = 'home-decor__overlay--sparks';
    }

    $uid = 'podval-decor-' . $slot_num;
    ?>
    <div class="home-decor home-decor--custom" id="<?php echo esc_attr( $uid ); ?>" style="height:<?php echo $height; ?>px;">
        <div class="home-decor__bg" style="
            background-image:url(<?php echo esc_url( $image ); ?>);
            background-size:<?php echo esc_attr( $bg_size ); ?>;
            background-repeat:<?php echo esc_attr( $bg_repeat ); ?>;
            background-position:<?php echo esc_attr( $bg_pos ); ?>;
        "></div>
        <?php if ( $overlay !== 'none' ) : ?>
        <div class="home-decor__overlay <?php echo esc_attr( $overlay_class ); ?>" style="<?php echo $overlay_css; ?>"></div>
        <?php endif; ?>
    </div>
    <?php if ( $image_mob || $height_m !== $height ) : ?>
    <style>
    @media(max-width:768px){
        #<?php echo esc_attr( $uid ); ?>{height:<?php echo $height_m; ?>px}
        <?php if ( $image_mob ) : ?>
        #<?php echo esc_attr( $uid ); ?> .home-decor__bg{background-image:url(<?php echo esc_url( $image_mob ); ?>)!important}
        <?php endif; ?>
    }
    </style>
    <?php endif;
}

/* =============================================
   CPT: Кейсы
   ============================================= */

/* =============================================
   TAG THUMBNAIL — изображение тега (product_tag term_meta)
   ============================================= */

// Поле на странице добавления тега
add_action( 'product_tag_add_form_fields', function() { ?>
    <div class="form-field">
        <label><?php esc_html_e( 'Изображение', 'podval-print' ); ?></label>
        <div id="podval-tag-thumb-wrap">
            <img id="podval-tag-thumb-preview" src="" style="max-width:200px;display:none;">
        </div>
        <input type="hidden" id="podval-tag-thumb-id" name="podval_tag_thumbnail_id" value="">
        <button type="button" class="button" id="podval-tag-thumb-btn"><?php esc_html_e( 'Выбрать изображение', 'podval-print' ); ?></button>
        <button type="button" class="button" id="podval-tag-thumb-remove" style="display:none;"><?php esc_html_e( 'Удалить', 'podval-print' ); ?></button>
        <p class="description"><?php esc_html_e( 'Фон плитки тега на главной. Если не задано — возьмётся из первого товара.', 'podval-print' ); ?></p>
    </div>
<?php } );

// Поле на странице редактирования тега
add_action( 'product_tag_edit_form_fields', function( $term ) {
    $thumb_id = absint( get_term_meta( $term->term_id, 'podval_tag_thumbnail_id', true ) );
    $thumb_url = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'medium' ) : '';
    ?>
    <tr class="form-field">
        <th scope="row"><label><?php esc_html_e( 'Изображение', 'podval-print' ); ?></label></th>
        <td>
            <div id="podval-tag-thumb-wrap">
                <img id="podval-tag-thumb-preview" src="<?php echo esc_url( $thumb_url ); ?>" style="max-width:200px;<?php echo $thumb_url ? '' : 'display:none;'; ?>">
            </div>
            <input type="hidden" id="podval-tag-thumb-id" name="podval_tag_thumbnail_id" value="<?php echo esc_attr( $thumb_id ); ?>">
            <button type="button" class="button" id="podval-tag-thumb-btn"><?php esc_html_e( 'Выбрать изображение', 'podval-print' ); ?></button>
            <button type="button" class="button" id="podval-tag-thumb-remove" style="<?php echo $thumb_id ? '' : 'display:none;'; ?>"><?php esc_html_e( 'Удалить', 'podval-print' ); ?></button>
            <p class="description"><?php esc_html_e( 'Фон плитки тега на главной. Если не задано — возьмётся из первого товара.', 'podval-print' ); ?></p>
        </td>
    </tr>
<?php } );

// Сохранение
add_action( 'created_product_tag', 'podval_save_tag_thumbnail' );
add_action( 'edited_product_tag',  'podval_save_tag_thumbnail' );
function podval_save_tag_thumbnail( $term_id ) {
    if ( isset( $_POST['podval_tag_thumbnail_id'] ) ) {
        update_term_meta( $term_id, 'podval_tag_thumbnail_id', absint( $_POST['podval_tag_thumbnail_id'] ) );
    }
}

// JS для media uploader в админке
add_action( 'admin_enqueue_scripts', function( $hook ) {
    // Media library for Size Chart CPT
    if ( in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
        $screen = get_current_screen();
        if ( $screen && $screen->post_type === 'podval_size_chart' ) {
            wp_enqueue_media();
        }
    }
    // Media library for product_tag taxonomy
    if ( $hook !== 'edit-tags.php' && $hook !== 'term.php' ) return;
    $screen = get_current_screen();
    if ( ! $screen || $screen->taxonomy !== 'product_tag' ) return;
    wp_enqueue_media();
    wp_add_inline_script( 'jquery-core', "
        jQuery(function($){
            var frame;
            $('#podval-tag-thumb-btn').on('click',function(e){
                e.preventDefault();
                if(frame){frame.open();return;}
                frame=wp.media({title:'Выбрать изображение',button:{text:'Использовать'},multiple:false});
                frame.on('select',function(){
                    var a=frame.state().get('selection').first().toJSON();
                    $('#podval-tag-thumb-id').val(a.id);
                    $('#podval-tag-thumb-preview').attr('src',a.sizes&&a.sizes.medium?a.sizes.medium.url:a.url).show();
                    $('#podval-tag-thumb-remove').show();
                });
                frame.open();
            });
            $('#podval-tag-thumb-remove').on('click',function(e){
                e.preventDefault();
                $('#podval-tag-thumb-id').val('');
                $('#podval-tag-thumb-preview').attr('src','').hide();
                $(this).hide();
            });
        });
    " );
} );

// ═══════════════════════════════════════════════════════════════
// Хелпер: рендер карточки товара из template-parts/content/product-card.php
// Используется в index.php (home-row), archive-product.php (промо-блок) и др.
// ═══════════════════════════════════════════════════════════════
if ( ! function_exists('podval_render_card') ) {
    function podval_render_card( $product ) {
        if ( ! $product instanceof WC_Product ) return;
        $GLOBALS['product'] = $product;
        $GLOBALS['post'] = get_post( $product->get_id() );
        setup_postdata( $GLOBALS['post'] );
        get_template_part( 'template-parts/content/product', 'card' );
        wp_reset_postdata();
    }
}

// ═══════════════════════════════════════════════════════════════
// Хелпер: получить товары с определённым бейджем (_podval_badge)
// Переиспользуется для блоков «Новинки», «Limited», «Предзаказ» и т.д.
// @param string $badge    Значение бейджа: 'new', 'limited', 'preorder', 'custom', 'yourphoto'
// @param int    $limit    Сколько товаров вернуть
// @param bool   $random   Если товаров больше $limit — брать случайных
// @return WC_Product[]    Массив объектов товаров
// ═══════════════════════════════════════════════════════════════
function podval_get_products_by_badge( $badge, $limit = 4, $random = true ) {
    if ( empty( $badge ) ) return [];

    $args = [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => $random ? -1 : $limit,
        'fields'         => 'ids',
        'meta_query'     => [
            [
                'key'     => '_podval_badge',
                'value'   => $badge,
                'compare' => '=',
            ],
        ],
        'tax_query'      => [
            [
                'taxonomy' => 'product_visibility',
                'field'    => 'name',
                'terms'    => [ 'exclude-from-catalog', 'outofstock' ],
                'operator' => 'NOT IN',
            ],
        ],
        'orderby'        => $random ? 'date' : 'date',
        'order'          => 'DESC',
    ];

    $ids = get_posts( $args );
    if ( empty( $ids ) ) return [];

    if ( $random && count( $ids ) > $limit ) {
        shuffle( $ids );
        $ids = array_slice( $ids, 0, $limit );
    } else {
        $ids = array_slice( $ids, 0, $limit );
    }

    $products = [];
    foreach ( $ids as $pid ) {
        $p = wc_get_product( $pid );
        if ( $p && $p->is_visible() ) $products[] = $p;
    }
    return $products;
}

// Хелпер: получить URL изображения тега (term_meta → fallback из товара)
function podval_get_tag_image_url( $tag ) {
    $data = podval_get_tag_image_data( $tag );
    return $data ? $data['url'] : '';
}

/**
 * Возвращает данные изображения тега: ['id' => int, 'url' => string].
 * id может быть 0 если URL пришёл из Customizer (старая миграция).
 * Используется для рендера <img srcset> через wp_get_attachment_image().
 * Добавлено в прежней версии для оптимизации блока «Коллекции» на главной.
 */
function podval_get_tag_image_data( $tag ) {
    // 1. Из term_meta (приоритет — всегда)
    $thumb_id = absint( get_term_meta( $tag->term_id, 'podval_tag_thumbnail_id', true ) );
    if ( $thumb_id ) {
        $url = wp_get_attachment_image_url( $thumb_id, 'medium_large' );
        if ( $url ) return array( 'id' => $thumb_id, 'url' => $url );
    }
    // Если term_meta существует (даже 0) — значит пользователь
    // уже работал с новой системой, НЕ проваливаемся в Customizer
    $meta_raw = get_term_meta( $tag->term_id, 'podval_tag_thumbnail_id', true );
    if ( $meta_raw !== '' && $meta_raw !== false ) {
        // term_meta задан но attachment не найден — сразу fallback к товару
    } else {
        // 2. Миграция: старый Customizer (только если term_meta ВООБЩЕ не задан)
        $tag_safe = sanitize_key( $tag->slug );
        $customizer_url = get_theme_mod( "podval_tag_img_{$tag_safe}", '' );
        if ( $customizer_url ) {
            // Customizer хранит URL без ID — попробуем найти ID
            $cust_id = attachment_url_to_postid( preg_replace( '/\.webp$/', '', $customizer_url ) );
            return array( 'id' => (int) $cust_id, 'url' => $customizer_url );
        }
    }
    // 3. Fallback: thumbnail первого товара с этим тегом
    $posts = get_posts(array(
        'post_type'      => 'product',
        'posts_per_page' => 1,
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'tax_query'      => array( array( 'taxonomy' => 'product_tag', 'field' => 'term_id', 'terms' => $tag->term_id ) ),
        'orderby'        => 'date',
        'order'          => 'DESC',
    ));
    if ( ! empty( $posts ) ) {
        $p = wc_get_product( $posts[0] );
        if ( $p && $p->get_image_id() ) {
            $img_id = $p->get_image_id();
            return array( 'id' => $img_id, 'url' => wp_get_attachment_image_url( $img_id, 'medium_large' ) );
        }
    }
    return null;
}

/* =============================================
   CPT: регистрация кейсов
   ============================================= */
add_action( 'init', function() {
    register_post_type( 'podval_case', array(
        'labels' => array(
            'name'               => 'Кейсы',
            'singular_name'      => 'Кейс',
            'add_new'            => 'Добавить кейс',
            'add_new_item'       => 'Новый кейс',
            'edit_item'          => 'Редактировать кейс',
            'all_items'          => 'Все кейсы',
            'search_items'       => 'Поиск кейсов',
            'not_found'          => 'Кейсы не найдены',
            'menu_name'          => 'Кейсы',
        ),
        'public'              => true,
        'show_in_rest'        => true,
        'menu_position'       => 26,
        'menu_icon'           => 'dashicons-portfolio',
        'supports'            => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
        'has_archive'         => false,
        'rewrite'             => array( 'slug' => 'cases', 'with_front' => false ),
        'publicly_queryable'  => true,
    ) );

    /* ── Size Chart CPT ── */
    register_post_type( 'podval_size_chart', array(
        'labels' => array(
            'name'               => 'Таблицы размеров',
            'singular_name'      => 'Таблица размеров',
            'add_new'            => 'Добавить таблицу',
            'add_new_item'       => 'Новая таблица размеров',
            'edit_item'          => 'Редактировать таблицу',
            'all_items'          => 'Все таблицы',
            'search_items'       => 'Поиск таблиц',
            'not_found'          => 'Таблицы не найдены',
            'menu_name'          => 'Таблицы размеров',
        ),
        'public'              => false,
        'show_ui'             => true,
        'show_in_menu'        => true,
        'menu_position'       => 27,
        'menu_icon'           => 'dashicons-editor-table',
        'supports'            => array( 'title' ),
        'has_archive'         => false,
        'publicly_queryable'  => false,
    ) );
} );

/* =============================================
   Size Chart CPT — Meta box
   ============================================= */
add_action( 'add_meta_boxes', function() {
    add_meta_box(
        'podval_sc_data',
        'Данные таблицы размеров',
        'podval_sc_meta_box_html',
        'podval_size_chart',
        'normal',
        'high'
    );
} );

function podval_sc_meta_box_html( $post ) {
    wp_nonce_field( 'podval_sc_save', 'podval_sc_nonce' );
    $image_id   = get_post_meta( $post->ID, '_sc_image_id', true );
    $image_url  = $image_id ? wp_get_attachment_image_url( $image_id, 'medium' ) : '';
    $columns    = get_post_meta( $post->ID, '_sc_columns', true );
    $rows       = get_post_meta( $post->ID, '_sc_rows', true );
    $disclaimer = get_post_meta( $post->ID, '_sc_disclaimer', true );
    if ( ! $columns ) $columns = '[]';
    if ( ! $rows )    $rows    = '[]';
    if ( ! $disclaimer ) $disclaimer = '* Фактические размеры одежды могут отличаться на +/- 1 см. Рекомендуем сравнить размеры этого изделия с аналогичной одеждой, которая у вас имеется.';
    ?>
    <style>
        .sc-meta-wrap { max-width: 800px; }
        .sc-meta-wrap label { display: block; font-weight: 600; margin: 14px 0 6px; font-size: 13px; }
        .sc-meta-wrap .sc-img-preview { max-width: 200px; margin: 8px 0; border: 1px solid #ddd; background: #f9f9f9; }
        .sc-meta-wrap .sc-img-preview img { display: block; width: 100%; }
        .sc-meta-wrap .button { margin-right: 6px; }
        .sc-table-editor { width: 100%; border-collapse: collapse; margin: 10px 0; }
        .sc-table-editor th, .sc-table-editor td { border: 1px solid #ddd; padding: 6px 8px; }
        .sc-table-editor th { background: #f1f1f1; }
        .sc-table-editor input { width: 100%; border: 1px solid #ccc; padding: 4px 6px; box-sizing: border-box; }
        .sc-table-editor th input { font-weight: 600; background: #fafafa; }
        .sc-col-actions, .sc-row-actions { text-align: center; }
        .sc-col-actions button, .sc-row-actions button { background: none; border: none; color: #b32d2e; cursor: pointer; font-size: 16px; padding: 2px 6px; }
        .sc-col-actions button:hover, .sc-row-actions button:hover { color: #dc3232; }
        .sc-add-btns { margin: 10px 0; }
        .sc-add-btns .button { margin-right: 8px; }
    </style>
    <div class="sc-meta-wrap">
        <label>Изображение (схема замеров)</label>
        <div class="sc-img-preview" id="sc-img-preview" style="<?php echo $image_url ? '' : 'display:none'; ?>">
            <img src="<?php echo esc_url( $image_url ); ?>" id="sc-img-tag">
        </div>
        <input type="hidden" name="sc_image_id" id="sc-image-id" value="<?php echo esc_attr( $image_id ); ?>">
        <button type="button" class="button" id="sc-pick-img">Выбрать изображение</button>
        <button type="button" class="button" id="sc-remove-img" style="<?php echo $image_id ? '' : 'display:none'; ?>">Удалить</button>

        <label>Столбцы и строки</label>
        <div id="sc-table-wrap"></div>
        <div class="sc-add-btns">
            <button type="button" class="button" id="sc-add-col">+ Столбец</button>
            <button type="button" class="button" id="sc-add-row">+ Строка</button>
        </div>
        <input type="hidden" name="sc_columns" id="sc-columns-json" value="<?php echo esc_attr( $columns ); ?>">
        <input type="hidden" name="sc_rows" id="sc-rows-json" value="<?php echo esc_attr( $rows ); ?>">

        <label>Дисклеймер (внизу таблицы)</label>
        <textarea name="sc_disclaimer" rows="2" style="width:100%"><?php echo esc_textarea( $disclaimer ); ?></textarea>
    </div>

    <script>
    jQuery(function($){
        /* — Image picker — */
        var frame;
        $('#sc-pick-img').on('click', function(e){
            e.preventDefault();
            if (frame) { frame.open(); return; }
            frame = wp.media({ title:'Выберите изображение', multiple:false, library:{type:'image'} });
            frame.on('select', function(){
                var att = frame.state().get('selection').first().toJSON();
                $('#sc-image-id').val(att.id);
                var url = att.sizes && att.sizes.medium ? att.sizes.medium.url : att.url;
                $('#sc-img-tag').attr('src', url);
                $('#sc-img-preview').show();
                $('#sc-remove-img').show();
            });
            frame.open();
        });
        $('#sc-remove-img').on('click', function(){
            $('#sc-image-id').val('');
            $('#sc-img-preview').hide();
            $(this).hide();
        });

        /* — Table editor — */
        var columns = [], rows = [];
        try { columns = JSON.parse($('#sc-columns-json').val()); } catch(e){}
        try { rows = JSON.parse($('#sc-rows-json').val()); } catch(e){}
        if (!columns.length) columns = ['Размер', 'A, Ширина', 'B, Длина'];
        if (!rows.length) rows = [['', '', '']];

        function syncJSON(){
            $('#sc-columns-json').val(JSON.stringify(columns));
            $('#sc-rows-json').val(JSON.stringify(rows));
        }

        function render(){
            var html = '<table class="sc-table-editor"><thead><tr>';
            columns.forEach(function(col, ci){
                html += '<th><input type="text" value="'+escHtml(col)+'" data-ci="'+ci+'" class="sc-col-input"></th>';
            });
            html += '<th class="sc-col-actions"></th></tr>';
            // delete-col row
            html += '<tr>';
            columns.forEach(function(col, ci){
                html += '<td class="sc-col-actions"><button type="button" data-ci="'+ci+'" class="sc-del-col" title="Удалить столбец">✕</button></td>';
            });
            html += '<td></td></tr></thead><tbody>';
            rows.forEach(function(row, ri){
                html += '<tr>';
                columns.forEach(function(col, ci){
                    var val = row[ci] !== undefined ? row[ci] : '';
                    html += '<td><input type="text" value="'+escHtml(val)+'" data-ri="'+ri+'" data-ci="'+ci+'" class="sc-cell-input"></td>';
                });
                html += '<td class="sc-row-actions"><button type="button" data-ri="'+ri+'" class="sc-del-row" title="Удалить строку">✕</button></td>';
                html += '</tr>';
            });
            html += '</tbody></table>';
            $('#sc-table-wrap').html(html);
            syncJSON();
        }

        function escHtml(s){ return $('<span>').text(s).html(); }

        $(document).on('input', '.sc-col-input', function(){
            columns[+$(this).data('ci')] = $(this).val();
            syncJSON();
        });
        $(document).on('input', '.sc-cell-input', function(){
            var ri = +$(this).data('ri'), ci = +$(this).data('ci');
            if (!rows[ri]) rows[ri] = [];
            rows[ri][ci] = $(this).val();
            syncJSON();
        });
        $(document).on('click', '.sc-del-col', function(){
            var ci = +$(this).data('ci');
            if (columns.length <= 1) return;
            columns.splice(ci, 1);
            rows.forEach(function(row){ row.splice(ci, 1); });
            render();
        });
        $(document).on('click', '.sc-del-row', function(){
            rows.splice(+$(this).data('ri'), 1);
            if (!rows.length) rows.push(new Array(columns.length).fill(''));
            render();
        });
        $('#sc-add-col').on('click', function(){
            columns.push('');
            rows.forEach(function(row){ row.push(''); });
            render();
        });
        $('#sc-add-row').on('click', function(){
            rows.push(new Array(columns.length).fill(''));
            render();
        });

        render();
    });
    </script>
    <?php
}

add_action( 'save_post_podval_size_chart', function( $post_id ) {
    if ( ! isset( $_POST['podval_sc_nonce'] ) || ! wp_verify_nonce( $_POST['podval_sc_nonce'], 'podval_sc_save' ) ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;

    update_post_meta( $post_id, '_sc_image_id', absint( $_POST['sc_image_id'] ?? 0 ) );
    // Sanitize JSON arrays
    $cols_raw = $_POST['sc_columns'] ?? '[]';
    $rows_raw = $_POST['sc_rows'] ?? '[]';
    $cols = json_decode( wp_unslash( $cols_raw ), true );
    $rows = json_decode( wp_unslash( $rows_raw ), true );
    if ( ! is_array( $cols ) ) $cols = [];
    if ( ! is_array( $rows ) ) $rows = [];
    // Sanitize every cell
    $cols = array_map( 'sanitize_text_field', $cols );
    $rows = array_map( function( $row ) {
        return is_array( $row ) ? array_map( 'sanitize_text_field', $row ) : [];
    }, $rows );
    update_post_meta( $post_id, '_sc_columns', wp_json_encode( $cols, JSON_UNESCAPED_UNICODE ) );
    update_post_meta( $post_id, '_sc_rows', wp_json_encode( $rows, JSON_UNESCAPED_UNICODE ) );
    update_post_meta( $post_id, '_sc_disclaimer', sanitize_textarea_field( $_POST['sc_disclaimer'] ?? '' ) );
} );


/* =============================================
   РАЗГРУЗКА АДМИНКИ WooCommerce
   ============================================= */

/**
 * Точечное отключение фоновых REST-поллеров в WooCommerce Admin.
 *
 * Диагностика лога за 23.04.2026 показала, что каждая загрузка страницы
 * админки генерировала ~7 REST-запросов к WC Analytics/Admin:
 *   - /wp-json/wc-analytics/products/reviews            → фича activity-panels
 *   - /wp-json/wc-analytics/products/count-low-in-stock → фича activity-panels
 *   - /wp-json/wc-analytics/orders                      → фича activity-panels
 *   - /wp-json/wc-analytics/admin/notes                 → фича remote-inbox-notifications
 *   - /wp-json/wc-admin/options (ces_tracks_queue)      → фича customer-effort-score-tracks
 *   - /wp-json/wc-admin/onboarding/tasks                → фича onboarding-tasks
 * При сессии редактирования 10 товаров 23.04 это дало всплеск 978 запросов
 * за 8 минут и упёрло CPU в 300% LVE-лимит → каскад OOM.
 *
 * ВАЖНО: меню "WooCommerce → Analytics" и сам дашборд аналитики остаются
 * полностью рабочими — фичи analytics / analytics-dashboard НЕ отключаются.
 * Убираются только фоновые поллеры Activity Panel и Inbox, плюс маркетинговый
 * блоат (Marketing Hub, баннеры приложения/этикеток, подсказки шлюзов).
 *
 * НЕ затрагивает: заказы (HPOS), товары, аналитику как таковую, категории,
 * атрибуты, настройки WC, платёжные шлюзы, доставку, купоны.
 */
add_filter( 'woocommerce_admin_features', function( $features ) {
    if ( ! is_array( $features ) ) {
        return $features;
    }
    $disabled = array(
        // === Источники тяжёлых REST-поллеров на каждой странице админки ===
        'activity-panels',                      // Панель активности (reviews/stock/orders)
        'remote-inbox-notifications',           // /wc-analytics/admin/notes
        'onboarding-tasks',                     // /wc-admin/onboarding/tasks
        'customer-effort-score-tracks',         // /wc-admin/options?options=ces_tracks_queue

        // === Мусор, который не делает запросов, но засоряет UI ===
        'marketing',                            // Меню Marketing Hub
        'mobile-app-banner',                    // Баннер "Get the WooCommerce App"
        'onboarding',                           // Мастер первоначальной настройки (сайт давно настроен)
        'remote-free-extensions',               // Подсказки бесплатных расширений
        'shipping-label-banner',                // Реклама печати этикеток (в РБ не применимо)
        'store-alerts',                         // Всплывающие предупреждения WooCommerce.com
        'payment-gateway-suggestions',          // Подсказки подключить Stripe/PayPal
    );
    return array_values( array_diff( $features, $disabled ) );
}, 999 );

/**
 * Увеличиваем интервал WP Heartbeat API с 15 до 60 секунд.
 *
 * Heartbeat по умолчанию шлёт POST /wp-admin/admin-ajax.php каждые 15 сек
 * при открытой админке. За час работы это ~240 запросов на каждую вкладку.
 * 60 секунд — достаточно для автосохранений и блокировки одновременного
 * редактирования, но в 4 раза меньше фоновой нагрузки.
 *
 * Фильтр heartbeat_settings: https://developer.wordpress.org/reference/hooks/heartbeat_settings/
 */
add_filter( 'heartbeat_settings', function( $settings ) {
    $settings['interval'] = 60;
    return $settings;
} );

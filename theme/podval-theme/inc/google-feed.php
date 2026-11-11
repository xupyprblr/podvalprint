<?php
/**
 * Podval Print — Google Merchant Center XML Feed
 *
 * Генерирует XML-фид для Google Shopping в формате RSS 2.0 + Google namespace.
 *
 * Endpoint:  /feed/google.xml
 *
 * Особенности:
 *  - Только Беларусь (BYN), один фид
 *  - Каждая вариация = отдельный <item> с item_group_id (родительский SKU/ID)
 *  - Исключаются: категория uslugi, бейджи custom/yourphoto, скрытые/нет в наличии,
 *    товары без главного изображения
 *  - Маппинг кириллических атрибутов (Цвет/Размер/Материал/Бренд) → g:color/g:size/g:material/g:brand
 *  - Кэш — transient на 6 часов, инвалидация на save_post_product
 *  - nginx-friendly: rewrite rule + template_redirect (без .htaccess)
 *  - WAF-friendly: чистый /feed/google.xml, не /admin-ajax.php
 *  - НЕ вызывает wc_price() → атрибут data-price-byn не появится в XML
 *
 *
 */
defined( 'ABSPATH' ) || exit;

/* ══════════════════════════════════════════
   CONFIG
   ══════════════════════════════════════════ */
if ( ! defined( 'PODVAL_GFEED_SLUG' ) ) {
    define( 'PODVAL_GFEED_SLUG', 'google.xml' );      // /feed/google.xml
}
if ( ! defined( 'PODVAL_GFEED_CACHE_KEY' ) ) {
    define( 'PODVAL_GFEED_CACHE_KEY', 'podval_google_feed_v1' );
}
if ( ! defined( 'PODVAL_GFEED_CACHE_TTL' ) ) {
    define( 'PODVAL_GFEED_CACHE_TTL', 6 * HOUR_IN_SECONDS );
}
if ( ! defined( 'PODVAL_GFEED_BATCH_SIZE' ) ) {
    define( 'PODVAL_GFEED_BATCH_SIZE', 100 );         // товаров за батч
}

/* ══════════════════════════════════════════
   1. REWRITE RULE: /feed/google.xml
   ══════════════════════════════════════════ */
add_action( 'init', function() {
    add_rewrite_rule(
        '^feed/' . preg_quote( PODVAL_GFEED_SLUG, '/' ) . '$',
        'index.php?podval_gfeed=1',
        'top'
    );
}, 1 );

add_filter( 'query_vars', function( $vars ) {
    $vars[] = 'podval_gfeed';
    return $vars;
} );

/* ══════════════════════════════════════════
   2. ROUTER: рендер фида при попадании на endpoint
   ══════════════════════════════════════════ */
add_action( 'template_redirect', function() {
    if ( (int) get_query_var( 'podval_gfeed' ) !== 1 ) {
        return;
    }

    // Не отдаём фид на дев-окружении — чтобы Google не подхватил его случайно
    if ( function_exists( 'podval_is_dev_host' ) && podval_is_dev_host() ) {
        status_header( 404 );
        exit;
    }

    // Параметр ?refresh=1 для админа — принудительный пересбор кэша
    $force = isset( $_GET['refresh'] ) && current_user_can( 'manage_options' );

    nocache_headers();
    header( 'Content-Type: application/xml; charset=UTF-8' );

    $xml = $force ? '' : (string) get_transient( PODVAL_GFEED_CACHE_KEY );
    if ( $xml === '' ) {
        $xml = podval_gfeed_build();
        set_transient( PODVAL_GFEED_CACHE_KEY, $xml, PODVAL_GFEED_CACHE_TTL );
    }

    echo $xml;
    exit;
}, 1 );

/* ══════════════════════════════════════════
   3. CACHE INVALIDATION
   ══════════════════════════════════════════ */
add_action( 'save_post_product',          'podval_gfeed_flush_cache' );
add_action( 'woocommerce_update_product', 'podval_gfeed_flush_cache' );
add_action( 'woocommerce_delete_product', 'podval_gfeed_flush_cache' );
add_action( 'trashed_post',               'podval_gfeed_flush_cache' );

function podval_gfeed_flush_cache() {
    delete_transient( PODVAL_GFEED_CACHE_KEY );
}

/* Подключаемся к глобальной инвалидации темы, если есть */
add_action( 'podval_flush_all_cache', 'podval_gfeed_flush_cache' );

/* ══════════════════════════════════════════
   4. BUILDER: генерация полного XML
   ══════════════════════════════════════════ */
function podval_gfeed_build() {

    if ( ! function_exists( 'wc_get_products' ) ) {
        return podval_gfeed_wrap_empty( 'WooCommerce not loaded' );
    }

    $shop_url   = home_url( '/' );
    $shop_name  = get_bloginfo( 'name' );
    $shop_desc  = get_bloginfo( 'description' );
    $generated  = gmdate( 'D, d M Y H:i:s' ) . ' GMT';

    $items_xml  = '';
    $count      = 0;
    $skipped    = 0;
    $page       = 1;

    do {
        $batch = wc_get_products( [
            'status'   => 'publish',
            'limit'    => PODVAL_GFEED_BATCH_SIZE,
            'page'     => $page,
            'orderby'  => 'ID',
            'order'    => 'ASC',
            'return'   => 'objects',
        ] );

        if ( empty( $batch ) ) break;

        foreach ( $batch as $product ) {
            $result = podval_gfeed_render_product( $product );
            if ( is_array( $result ) ) {
                $items_xml .= implode( '', $result['items'] );
                $count     += $result['count'];
                $skipped   += $result['skipped'];
            }
        }

        $page++;

        // Подстраховка от бесконечного цикла
        if ( $page > 500 ) break;

    } while ( count( $batch ) === PODVAL_GFEED_BATCH_SIZE );

    // Финальная сборка XML
    $header = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $header .= '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
    $header .= '  <channel>' . "\n";
    $header .= '    <title>' . podval_gfeed_xml_safe( $shop_name ) . '</title>' . "\n";
    $header .= '    <link>' . esc_url( $shop_url ) . '</link>' . "\n";
    $header .= '    <description>' . podval_gfeed_xml_safe( $shop_desc ) . '</description>' . "\n";
    $header .= '    <lastBuildDate>' . esc_html( $generated ) . '</lastBuildDate>' . "\n";
    $header .= '    <!-- items: ' . (int) $count . ', skipped: ' . (int) $skipped . ' -->' . "\n";

    $footer  = '  </channel>' . "\n" . '</rss>' . "\n";

    return $header . $items_xml . $footer;
}

function podval_gfeed_wrap_empty( $reason = '' ) {
    $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
    $xml .= '  <channel>' . "\n";
    $xml .= '    <title>' . esc_html( get_bloginfo( 'name' ) ) . '</title>' . "\n";
    $xml .= '    <link>' . esc_url( home_url( '/' ) ) . '</link>' . "\n";
    $xml .= '    <description>Empty feed: ' . esc_html( $reason ) . '</description>' . "\n";
    $xml .= '  </channel>' . "\n" . '</rss>' . "\n";
    return $xml;
}

/* ══════════════════════════════════════════
   5. PRODUCT RENDERER
   ══════════════════════════════════════════ */
/**
 * Рендерит товар (или его вариации) в XML-айтемы.
 *
 * @return array|false [ 'items' => [...], 'count' => N, 'skipped' => M ] или false
 */
function podval_gfeed_render_product( $product ) {

    // Пропускаем если нет объекта или skip-фильтр сработал
    if ( ! $product instanceof WC_Product ) return false;

    if ( podval_gfeed_should_skip_parent( $product ) ) {
        return [ 'items' => [], 'count' => 0, 'skipped' => 1 ];
    }

    // Вариативный товар → отдельный <item> на каждую публикуемую вариацию
    if ( $product->is_type( 'variable' ) ) {
        return podval_gfeed_render_variable( $product );
    }

    // Простой товар (или внешний/группа — пропускаем не-простые)
    if ( ! $product->is_type( 'simple' ) ) {
        return [ 'items' => [], 'count' => 0, 'skipped' => 1 ];
    }

    // Финальные проверки
    if ( podval_gfeed_should_skip_buyable( $product ) ) {
        return [ 'items' => [], 'count' => 0, 'skipped' => 1 ];
    }

    $xml = podval_gfeed_render_item( $product, $product );
    if ( $xml === '' ) {
        return [ 'items' => [], 'count' => 0, 'skipped' => 1 ];
    }
    return [ 'items' => [ $xml ], 'count' => 1, 'skipped' => 0 ];
}

function podval_gfeed_render_variable( $variable ) {

    $items   = [];
    $count   = 0;
    $skipped = 0;

    $children = $variable->get_children();
    foreach ( $children as $vid ) {
        $variation = wc_get_product( $vid );
        if ( ! $variation || ! $variation->exists() ) {
            $skipped++;
            continue;
        }
        if ( podval_gfeed_should_skip_buyable( $variation ) ) {
            $skipped++;
            continue;
        }
        $xml = podval_gfeed_render_item( $variation, $variable );
        if ( $xml === '' ) {
            $skipped++;
            continue;
        }
        $items[] = $xml;
        $count++;
    }

    return [ 'items' => $items, 'count' => $count, 'skipped' => $skipped ];
}

/* ══════════════════════════════════════════
   6. ФИЛЬТРЫ ИСКЛЮЧЕНИЙ
   ══════════════════════════════════════════ */

/**
 * Фильтры, применяемые на уровне РОДИТЕЛЬСКОГО товара
 * (если родитель в чёрном списке — все его вариации тоже).
 */
function podval_gfeed_should_skip_parent( WC_Product $p ) {

    $pid = $p->get_id();

    // 1. Категория uslugi
    if ( has_term( 'uslugi', 'product_cat', $pid ) ) return true;

    // 2. Бейдж: custom / yourphoto (товары "свой дизайн")
    $badge = get_post_meta( $pid, '_podval_badge', true );
    if ( in_array( $badge, [ 'custom', 'yourphoto' ], true ) ) return true;

    // 3. Скрытый из каталога целиком
    if ( $p->get_catalog_visibility() === 'hidden' ) return true;

    // 4. Не публикован
    if ( $p->get_status() !== 'publish' ) return true;

    // 5. Главное изображение должно быть
    if ( ! $p->get_image_id() ) return true;

    return false;
}

/**
 * Фильтры на уровне ПОКУПАЕМОЙ единицы (simple product или variation).
 * Главное изображение здесь НЕ проверяем для variation —
 * у вариации может не быть своего, тогда берётся родительское.
 */
function podval_gfeed_should_skip_buyable( WC_Product $p ) {

    // 1. Не в наличии
    if ( ! $p->is_in_stock() ) return true;

    // 2. Цена должна быть положительная
    $price = (float) $p->get_price();
    if ( $price <= 0 ) return true;

    // 3. Нет в покупке (purchasable)
    if ( ! $p->is_purchasable() ) return true;

    return false;
}

/* ══════════════════════════════════════════
   7. ITEM RENDERER (ядро)
   ══════════════════════════════════════════ */
/**
 * @param WC_Product $sellable   Покупаемая единица: simple-товар или variation
 * @param WC_Product $parent     Родитель: для simple == sellable; для variation — variable parent
 * @return string XML <item>...</item> либо '' если рендер невозможен
 */
function podval_gfeed_render_item( WC_Product $sellable, WC_Product $parent ) {

    $sellable_id = $sellable->get_id();
    $parent_id   = $parent->get_id();
    $is_variation = $sellable->is_type( 'variation' );

    /* ── Базовые поля ───────────────────────── */

    $title = $is_variation
        ? trim( $parent->get_name() . ' — ' . wc_get_formatted_variation( $sellable, true ) )
        : $parent->get_name();
    $title = wp_strip_all_tags( $title );
    $title = mb_substr( $title, 0, 150 );  // лимит Google = 150

    // Описание: variation->get_description() или parent description, или short
    $description = $is_variation ? $sellable->get_description() : '';
    if ( ! $description ) $description = $parent->get_description();
    if ( ! $description ) $description = $parent->get_short_description();
    $description = wp_strip_all_tags( $description );
    $description = preg_replace( '/\s+/u', ' ', $description );
    $description = trim( $description );
    if ( $description === '' ) {
        $description = $title;
    }
    $description = mb_substr( $description, 0, 5000 );  // лимит Google = 5000

    // ID товара: должен быть УНИКАЛЬНЫМ для каждого <item> в фиде.
    // SKU часто заполнен только у родителя — поэтому
    // get_sku() у вариации может вернуть пустую строку или повторяющееся
    // значение. Гарантируем уникальность через ID поста как ultimate fallback.
    $parent_sku_raw = $parent->get_sku();
    $parent_sku     = $parent_sku_raw !== ''
        ? preg_replace( '/[^A-Za-z0-9_\-]/', '_', $parent_sku_raw )
        : (string) $parent_id;

    if ( $is_variation ) {
        // Берём СВОЙ SKU вариации. WC_Product_Variation::get_sku() может
        // вернуть SKU родителя если у вариации пусто — поэтому читаем
        // напрямую из meta и сравниваем с родительским.
        $own_sku = (string) get_post_meta( $sellable_id, '_sku', true );
        if ( $own_sku !== '' && $own_sku !== $parent_sku_raw ) {
            // У вариации есть свой уникальный SKU
            $item_id = preg_replace( '/[^A-Za-z0-9_\-]/', '_', $own_sku );
        } else {
            // SKU нет → собираем из parent_sku + ID вариации
            $item_id = $parent_sku . '-' . $sellable_id;
        }
    } else {
        // Simple-товар
        $own_sku = (string) get_post_meta( $sellable_id, '_sku', true );
        $item_id = $own_sku !== ''
            ? preg_replace( '/[^A-Za-z0-9_\-]/', '_', $own_sku )
            : (string) $sellable_id;
    }
    $item_id = mb_substr( $item_id, 0, 50 );
    if ( $item_id === '' ) $item_id = (string) $sellable_id;

    // item_group_id — общий для всех вариаций одного товара
    $group_id = $is_variation ? $parent_sku : '';

    // Ссылка — всегда чистый permalink родителя, без ?attribute_*
    //
    // раньше для вариаций звался WC_Product_Variation::get_permalink(),
    // который добавляет ?attribute_<ключ>=<значение>. На этом сайте параметр
    // инертен: штатный wc-add-to-cart-variation.js не подключается (у темы свой
    // UI выбора в assets/js/product.js), шаблон variable.php переопределён и
    // $_REQUEST['attribute_*'] нигде не читается. Преселекта он не давал,
    // зато Google обходил каждую такую ссылку и складывал в отчёт
    // «Вариант страницы с тегом canonical» (~178 URL на 08.2026).
    //
    // Для Merchant Center это корректно: варианты одного товара могут делить
    // общий landing page, различаются они через g:id + g:item_group_id.
    $link = get_permalink( $parent_id );
    if ( ! $link ) return '';

    /* ── Изображение ────────────────────────── */

    $image_id = $sellable->get_image_id();
    if ( ! $image_id ) {
        $image_id = $parent->get_image_id();
    }
    if ( ! $image_id ) return '';

    // Используем podval-card-2x (1200x1600) — это >> 500x500 минимум Google.
    // Если размер не сгенерирован (старые товары до регистрации size, или
    // оригинал был меньше 1200x1600) — берём самый большой из доступных.
    $image_url = podval_gfeed_best_image_url( $image_id );
    if ( ! $image_url ) return '';

    // Дополнительные изображения (до 10) — та же логика
    $additional = [];
    $gallery = $parent->get_gallery_image_ids();
    if ( $gallery ) {
        foreach ( $gallery as $gid ) {
            if ( $gid == $image_id ) continue;
            $u = podval_gfeed_best_image_url( $gid );
            if ( $u ) $additional[] = $u;
            if ( count( $additional ) >= 10 ) break;
        }
    }

    /* ── Цена ───────────────────────────────── */

    $regular = (float) $sellable->get_regular_price();
    $current = (float) $sellable->get_price();

    // Google требует price = текущую (продажную); sale_price указывается отдельно
    $price_main = $current > 0 ? $current : $regular;
    if ( $price_main <= 0 ) return '';

    $price_str  = number_format( $price_main, 2, '.', '' ) . ' BYN';

    $sale_str = '';
    if ( $regular > 0 && $current > 0 && $current < $regular ) {
        $sale_str = number_format( $current, 2, '.', '' ) . ' BYN';
        // А в основной price идёт regular
        $price_str = number_format( $regular, 2, '.', '' ) . ' BYN';
    }

    /* ── Наличие ────────────────────────────── */

    if ( $sellable->is_in_stock() ) {
        $availability = $sellable->is_on_backorder() ? 'preorder' : 'in_stock';
    } else {
        $availability = 'out_of_stock';
    }

    /* ── Атрибуты Google: brand / color / size / material / gtin / mpn ── */

    $brand    = podval_gfeed_get_brand( $parent );
    $color    = podval_gfeed_attr_match( $sellable, $parent, [ 'color', 'colour', 'цвет', 'pa_color' ] );
    $size     = podval_gfeed_attr_match( $sellable, $parent, [ 'size', 'размер', 'pa_size' ] );
    $material = podval_gfeed_attr_match( $sellable, $parent, [ 'material', 'материал', 'fabric', 'ткань', 'pa_material' ] );

    // Общая категория Google: одежда (apparel)
    $google_category = 'Apparel & Accessories > Clothing';

    // gtin / mpn если есть в SKU вариации (некоторые магазины кладут в _gtin meta)
    $gtin = (string) get_post_meta( $sellable_id, '_gtin', true );
    if ( $gtin === '' && $is_variation ) {
        $gtin = (string) get_post_meta( $parent_id, '_gtin', true );
    }
    // MPN = наш item_id (он же уникальный артикул)
    $mpn = $item_id;

    // identifier_exists: если нет ни brand ни gtin ни mpn → ставим no
    $identifier_exists = ( $brand && ( $gtin || $mpn ) ) ? 'yes' : 'no';

    // age_group / gender — для одежды Google требует. Делаем разумные дефолты.
    $age_group = 'adult';
    $gender    = podval_gfeed_guess_gender( $parent );

    /* ── Категория магазина (product_type) ───── */

    $shop_categories = podval_gfeed_get_categories_path( $parent_id );

    /* ══════ СБОРКА XML ══════ */

    $x = '    <item>' . "\n";
    $x .= '      <g:id>' . podval_gfeed_xml_safe( $item_id ) . '</g:id>' . "\n";
    $x .= '      <title>' . podval_gfeed_xml_safe( $title ) . '</title>' . "\n";
    $x .= '      <description>' . podval_gfeed_xml_safe( $description ) . '</description>' . "\n";
    $x .= '      <link>' . esc_url( $link ) . '</link>' . "\n";
    $x .= '      <g:image_link>' . esc_url( $image_url ) . '</g:image_link>' . "\n";
    foreach ( $additional as $u ) {
        $x .= '      <g:additional_image_link>' . esc_url( $u ) . '</g:additional_image_link>' . "\n";
    }
    $x .= '      <g:availability>' . $availability . '</g:availability>' . "\n";
    $x .= '      <g:price>' . esc_html( $price_str ) . '</g:price>' . "\n";
    if ( $sale_str !== '' ) {
        $x .= '      <g:sale_price>' . esc_html( $sale_str ) . '</g:sale_price>' . "\n";
    }
    $x .= '      <g:condition>new</g:condition>' . "\n";
    $x .= '      <g:google_product_category>' . podval_gfeed_xml_safe( $google_category ) . '</g:google_product_category>' . "\n";

    if ( $shop_categories ) {
        $x .= '      <g:product_type>' . podval_gfeed_xml_safe( $shop_categories ) . '</g:product_type>' . "\n";
    }

    if ( $brand ) {
        $x .= '      <g:brand>' . podval_gfeed_xml_safe( $brand ) . '</g:brand>' . "\n";
    }
    if ( $gtin ) {
        $x .= '      <g:gtin>' . podval_gfeed_xml_safe( $gtin ) . '</g:gtin>' . "\n";
    }
    if ( $mpn ) {
        $x .= '      <g:mpn>' . podval_gfeed_xml_safe( $mpn ) . '</g:mpn>' . "\n";
    }
    $x .= '      <g:identifier_exists>' . $identifier_exists . '</g:identifier_exists>' . "\n";

    if ( $color ) {
        $x .= '      <g:color>' . podval_gfeed_xml_safe( $color ) . '</g:color>' . "\n";
    }
    if ( $size ) {
        $x .= '      <g:size>' . podval_gfeed_xml_safe( $size ) . '</g:size>' . "\n";
    }
    if ( $material ) {
        $x .= '      <g:material>' . podval_gfeed_xml_safe( $material ) . '</g:material>' . "\n";
    }

    $x .= '      <g:age_group>' . $age_group . '</g:age_group>' . "\n";
    if ( $gender ) {
        $x .= '      <g:gender>' . $gender . '</g:gender>' . "\n";
    }

    if ( $group_id ) {
        $x .= '      <g:item_group_id>' . podval_gfeed_xml_safe( $group_id ) . '</g:item_group_id>' . "\n";
    }

    // Стоимость доставки указывается в Merchant Center на уровне аккаунта,
    // здесь ничего не добавляем (иначе перетрём настройки доставки).

    $x .= '    </item>' . "\n";

    return $x;
}

/**
 * Аудит изображений — находит товары, у которых главное изображение
 * меньше 500×500 даже после применения логики podval_gfeed_best_image_url().
 *
 * Проверяет ТОЛЬКО товары, попадающие в фид (применяет те же фильтры
 * исключения: uslugi, custom/yourphoto, скрытые, без картинки и т.д.)
 *
 * @return array [ 'total' => int, 'bad' => [ ['id'=>, 'title'=>, 'w'=>, 'h'=>, 'url'=>], ... ] ]
 */
function podval_gfeed_audit_images() {
    if ( ! function_exists( 'wc_get_products' ) ) {
        return [ 'total' => 0, 'bad' => [] ];
    }

    $bad   = [];
    $total = 0;
    $page  = 1;

    do {
        $batch = wc_get_products( [
            'status' => 'publish',
            'limit'  => 100,
            'page'   => $page,
            'return' => 'objects',
        ] );
        if ( empty( $batch ) ) break;

        foreach ( $batch as $product ) {
            if ( ! $product instanceof WC_Product ) continue;
            if ( podval_gfeed_should_skip_parent( $product ) ) continue;

            // Для variable: проверяем родительское изображение (variation
            // обычно наследует, и мы возьмём parent при отсутствии).
            // Для simple: своё.
            $image_id = $product->get_image_id();
            if ( ! $image_id ) continue;

            $url = podval_gfeed_best_image_url( $image_id );
            if ( ! $url ) continue;

            $total++;

            // Получаем РЕАЛЬНЫЕ размеры файла, который попадёт в фид
            $meta = wp_get_attachment_metadata( $image_id );
            $w    = 0;
            $h    = 0;

            // Если URL содержит размерный суффикс ‑NxM. — извлекаем оттуда
            if ( preg_match( '/-(\d+)x(\d+)\.(jpg|jpeg|png|gif|webp)/i', $url, $m ) ) {
                $w = (int) $m[1];
                $h = (int) $m[2];
            } else {
                // Это оригинал — берём из metadata
                $w = isset( $meta['width'] )  ? (int) $meta['width']  : 0;
                $h = isset( $meta['height'] ) ? (int) $meta['height'] : 0;
            }

            if ( $w < 500 || $h < 500 ) {
                $bad[] = [
                    'id'    => $product->get_id(),
                    'title' => $product->get_name(),
                    'w'     => $w,
                    'h'     => $h,
                    'url'   => $url,
                ];
            }
        }

        $page++;
        if ( $page > 500 ) break;
    } while ( count( $batch ) === 100 );

    return [ 'total' => $total, 'bad' => $bad ];
}

/* ══════════════════════════════════════════
   8. ХЕЛПЕРЫ: атрибуты товара / категории
   ══════════════════════════════════════════ */

/**
 * Возвращает URL самого "правильного" размера изображения для Google Merchant.
 *
 * Стратегия:
 *  1. Пытаемся podval-card-2x (1200x1600) — наш кастомный размер для Shopping.
 *  2. Если не сгенерирован — ищем среди ВСЕХ зарегистрированных WP-размеров
 *     самый большой по площади, у которого минимальная сторона >= 500px.
 *  3. Если такого нет — fallback на full (оригинал).
 *
 * Это решает кейсы:
 *  - Старые товары загружены до регистрации podval-card-2x → берём другой большой размер
 *  - Старые товары с маленьким оригиналом (< 1200x1600) → WP не сгенерирует
 *    podval-card-2x, но возьмём оригинал (если он >= 500px)
 *  - Гарантированно >= 500x500 (минимум Google), если оригинал был достаточным
 *
 * @param int $attachment_id
 * @return string URL или '' если изображение не найдено
 */
function podval_gfeed_best_image_url( $attachment_id ) {

    $attachment_id = (int) $attachment_id;
    if ( ! $attachment_id ) return '';

    // 1. Приоритет: наш кастомный podval-card-2x (1200x1600)
    $src = wp_get_attachment_image_src( $attachment_id, 'podval-card-2x' );
    if ( $src && ! empty( $src[0] ) && (int) $src[1] >= 500 && (int) $src[2] >= 500 ) {
        // wp_get_attachment_image_src вернёт fallback даже если размер не сгенерирован,
        // поэтому проверяем что URL содержит размерный суффикс ИЛИ что оригинал
        // действительно достаточно большой (full при пустом размере тоже OK если он крупный)
        return $src[0];
    }

    // 2. Перебираем все размеры от больших к маленьким, ищем подходящий
    $candidates = [
        'podval-hero',     // 1920x1080
        'podval-card-2x',  // 1200x1600
        '1536x1536',      // WP default if exists
        '2048x2048',      // WP default if exists
        'large',          // 1024x1024
        'medium_large',   // 768
        'podval-card',     // 600x800
    ];

    foreach ( $candidates as $size ) {
        $s = wp_get_attachment_image_src( $attachment_id, $size );
        if ( $s && ! empty( $s[0] ) && (int) $s[1] >= 500 && (int) $s[2] >= 500 ) {
            return $s[0];
        }
    }

    // 3. Fallback: оригинал (если он >= 500x500)
    $full = wp_get_attachment_image_src( $attachment_id, 'full' );
    if ( $full && ! empty( $full[0] ) && (int) $full[1] >= 500 && (int) $full[2] >= 500 ) {
        return $full[0];
    }

    // 4. Совсем последний fallback — отдаём full даже если маленький
    // (Google всё равно увидит товар, но пометит warning'ом —
    // это лучше чем не показать товар вообще)
    if ( $full && ! empty( $full[0] ) ) {
        return $full[0];
    }

    return '';
}

/**
 * Бренд: сначала смотрим _podval_brand (свой meta-field темы),
 * затем term в таксономии 'brands' если есть.
 */
function podval_gfeed_get_brand( WC_Product $parent ) {
    $b = (string) get_post_meta( $parent->get_id(), '_podval_brand', true );
    if ( $b !== '' ) return $b;

    if ( taxonomy_exists( 'brands' ) ) {
        $terms = get_the_terms( $parent->get_id(), 'brands' );
        if ( $terms && ! is_wp_error( $terms ) ) {
            $first = reset( $terms );
            if ( $first instanceof WP_Term ) return $first->name;
        }
    }
    // Fallback на имя сайта — Google требует g:brand для одежды
    return (string) podval_brand( 'name_latin', get_bloginfo( 'name' ) );
}

/**
 * Ищет атрибут по списку алиасов, сначала в variation (если variation),
 * потом в parent. Возвращает ЗНАЧЕНИЕ (строку), а не slug.
 *
 * Атрибуты могут быть:
 *  - таксономические pa_color / pa_size (slug в БД, label через wc_attribute_label)
 *  - локальные (custom) — часто на кириллице: "Цвет", "Размер"
 *
 * Для variation атрибуты приходят как массив 'attribute_pa_color' => 'red'
 * или 'attribute_цвет' => 'красный' (URL-encoded в keys БД).
 */
function podval_gfeed_attr_match( WC_Product $sellable, WC_Product $parent, array $aliases ) {

    $aliases_lc = array_map( 'mb_strtolower', $aliases );

    /* === 1. Variation: смотрим назначенные значения === */
    if ( $sellable->is_type( 'variation' ) ) {
        $var_attrs = $sellable->get_variation_attributes();  // [ 'attribute_pa_color' => 'red' ]
        foreach ( $var_attrs as $key => $val ) {
            if ( $val === '' ) continue;
            // Чистим ключ
            $clean = urldecode( preg_replace( '/^attribute_/', '', $key ) );
            $clean_lc = mb_strtolower( $clean );
            $clean_no_pa = preg_replace( '/^pa_/', '', $clean_lc );

            if ( in_array( $clean_lc, $aliases_lc, true ) || in_array( $clean_no_pa, $aliases_lc, true ) ) {
                // Если это таксономическое — $val это slug, нужен label
                if ( strpos( $clean, 'pa_' ) === 0 ) {
                    $term = get_term_by( 'slug', $val, $clean );
                    if ( $term && ! is_wp_error( $term ) ) return $term->name;
                }
                return $val;
            }

            // Также проверяем лейбл атрибута
            $label = mb_strtolower( wc_attribute_label( $clean ) );
            if ( in_array( $label, $aliases_lc, true ) ) {
                if ( strpos( $clean, 'pa_' ) === 0 ) {
                    $term = get_term_by( 'slug', $val, $clean );
                    if ( $term && ! is_wp_error( $term ) ) return $term->name;
                }
                return $val;
            }
        }
    }

    /* === 2. Parent: смотрим атрибуты товара === */
    $attrs = $parent->get_attributes();  // [ slug => WC_Product_Attribute ]
    foreach ( $attrs as $taxonomy => $attr ) {

        if ( ! ( $attr instanceof WC_Product_Attribute ) ) continue;

        $name = $attr->get_name();   // 'pa_color' или 'Цвет'
        $name_lc = mb_strtolower( $name );
        $name_no_pa = preg_replace( '/^pa_/', '', $name_lc );

        $matched = false;
        if ( in_array( $name_lc, $aliases_lc, true ) || in_array( $name_no_pa, $aliases_lc, true ) ) {
            $matched = true;
        } else {
            $label = mb_strtolower( wc_attribute_label( $name ) );
            if ( in_array( $label, $aliases_lc, true ) ) $matched = true;
        }
        if ( ! $matched ) continue;

        // Получаем значения
        if ( $attr->is_taxonomy() ) {
            $terms = wc_get_product_terms( $parent->get_id(), $name, [ 'fields' => 'names' ] );
            if ( $terms ) return (string) reset( $terms );
        } else {
            $options = $attr->get_options();
            if ( $options ) return (string) reset( $options );
        }
    }

    return '';
}

/**
 * Грубое определение пола по категории/тегам товара.
 * Google ожидает: male / female / unisex.
 */
function podval_gfeed_guess_gender( WC_Product $parent ) {
    $pid = $parent->get_id();

    $male_terms   = [ 'men', 'mens', 'мужск', 'муж' ];
    $female_terms = [ 'women', 'womens', 'женск', 'жен' ];

    $cats = wp_get_post_terms( $pid, 'product_cat', [ 'fields' => 'all' ] );
    if ( ! is_wp_error( $cats ) && $cats ) {
        foreach ( $cats as $c ) {
            $haystack = mb_strtolower( $c->name . ' ' . $c->slug );
            foreach ( $male_terms as $t ) {
                if ( mb_strpos( $haystack, $t ) !== false ) return 'male';
            }
            foreach ( $female_terms as $t ) {
                if ( mb_strpos( $haystack, $t ) !== false ) return 'female';
            }
        }
    }
    return 'unisex';
}

/**
 * Путь категорий магазина (product_type) — берём самую глубокую цепочку.
 * Например: "Футболки > Oversize"
 */
function podval_gfeed_get_categories_path( $product_id ) {

    $cats = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'all' ] );
    if ( is_wp_error( $cats ) || ! $cats ) return '';

    // Игнорируем технический корень 'shop' и 'uncategorized'
    $cats = array_filter( $cats, function( $c ) {
        return ! in_array( $c->slug, [ 'shop', 'uncategorized' ], true );
    } );
    if ( ! $cats ) return '';

    // Берём первую "осмысленную" категорию (не корень) и поднимаемся к топу
    $term = reset( $cats );
    if ( ! $term instanceof WP_Term ) return '';

    $path = [ $term->name ];
    $parent_id = $term->parent;
    $depth = 0;
    while ( $parent_id && $depth < 5 ) {
        $parent = get_term( $parent_id, 'product_cat' );
        if ( ! $parent || is_wp_error( $parent ) ) break;
        if ( in_array( $parent->slug, [ 'shop' ], true ) ) break;
        array_unshift( $path, $parent->name );
        $parent_id = $parent->parent;
        $depth++;
    }
    return implode( ' > ', $path );
}

/* ══════════════════════════════════════════
   9. XML SAFE-ENCODER
   ══════════════════════════════════════════ */
function podval_gfeed_xml_safe( $str ) {
    $str = (string) $str;
    // Удаляем недопустимые в XML 1.0 символы
    $str = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $str );
    // Заменяем спецсимволы
    return htmlspecialchars( $str, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
}

/* ══════════════════════════════════════════
   10. АДМИНКА: страница "Google Feed" + кнопка флаша
   ══════════════════════════════════════════ */
add_action( 'admin_menu', function() {
    add_submenu_page(
        PODVAL_ADMIN_MENU, // было Инструменты
        'Google Merchant Feed',
        'Google Feed',
        'manage_options',
        'podval-gfeed',
        'podval_gfeed_admin_page',
        2
    );
} );

function podval_gfeed_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die();

    // Действия
    if ( isset( $_POST['podval_gfeed_action'] ) && check_admin_referer( 'podval_gfeed' ) ) {
        $action = sanitize_text_field( $_POST['podval_gfeed_action'] );
        if ( $action === 'flush' ) {
            podval_gfeed_flush_cache();
            echo '<div class="notice notice-success"><p>Кэш фида сброшен. Следующий запрос пересоберёт XML.</p></div>';
        } elseif ( $action === 'rebuild' ) {
            podval_gfeed_flush_cache();
            $t0 = microtime( true );
            $xml = podval_gfeed_build();
            set_transient( PODVAL_GFEED_CACHE_KEY, $xml, PODVAL_GFEED_CACHE_TTL );
            $dt = round( microtime( true ) - $t0, 2 );
            echo '<div class="notice notice-success"><p>Фид пересобран за ' . esc_html( $dt ) . ' сек. Размер: ' . esc_html( size_format( strlen( $xml ) ) ) . '</p></div>';
        } elseif ( $action === 'flush_rewrite' ) {
            flush_rewrite_rules( false );
            echo '<div class="notice notice-success"><p>Rewrite rules сброшены — endpoint теперь активен.</p></div>';
        } elseif ( $action === 'audit_images' ) {
            $audit = podval_gfeed_audit_images();
            $bad   = $audit['bad'];
            $total = $audit['total'];
            if ( empty( $bad ) ) {
                echo '<div class="notice notice-success"><p>✓ Проверено товаров: ' . (int) $total . '. Все главные изображения ≥ 500×500.</p></div>';
            } else {
                echo '<div class="notice notice-warning"><p><strong>Найдено товаров с маленьким главным изображением: ' . count( $bad ) . ' (из ' . (int) $total . ')</strong></p>';
                echo '<table class="widefat striped" style="margin-top:10px"><thead><tr><th>ID</th><th>Товар</th><th>Размер</th><th>Файл</th></tr></thead><tbody>';
                foreach ( $bad as $row ) {
                    $edit_link = get_edit_post_link( $row['id'] );
                    echo '<tr>';
                    echo '<td>' . (int) $row['id'] . '</td>';
                    echo '<td><a href="' . esc_url( $edit_link ) . '" target="_blank">' . esc_html( $row['title'] ) . '</a></td>';
                    echo '<td><strong style="color:#d63638">' . (int) $row['w'] . '×' . (int) $row['h'] . '</strong></td>';
                    echo '<td><a href="' . esc_url( $row['url'] ) . '" target="_blank">' . esc_html( basename( $row['url'] ) ) . '</a></td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
                echo '</div>';
            }
        }
    }

    $feed_url   = home_url( '/feed/' . PODVAL_GFEED_SLUG );
    $cached_xml = (string) get_transient( PODVAL_GFEED_CACHE_KEY );
    $cached_size = strlen( $cached_xml );

    $items_count = 0;
    if ( $cached_xml && preg_match( '/<!-- items: (\d+)/', $cached_xml, $m ) ) {
        $items_count = (int) $m[1];
    }
    ?>
    <div class="wrap">
        <h1>Google Merchant Feed</h1>

        <h2>Статус</h2>
        <table class="widefat striped" style="max-width:700px">
            <tr><th>URL фида</th><td><a href="<?php echo esc_url( $feed_url ); ?>" target="_blank"><?php echo esc_html( $feed_url ); ?></a></td></tr>
            <tr><th>В кэше</th><td><?php echo $cached_size ? esc_html( size_format( $cached_size ) ) : '<em>пусто (соберётся при первом запросе)</em>'; ?></td></tr>
            <tr><th>Товаров в фиде</th><td><?php echo (int) $items_count; ?></td></tr>
            <tr><th>TTL кэша</th><td><?php echo (int) ( PODVAL_GFEED_CACHE_TTL / HOUR_IN_SECONDS ); ?> часов</td></tr>
        </table>

        <h2 style="margin-top:30px">Действия</h2>
        <form method="post" style="margin-bottom:10px">
            <?php wp_nonce_field( 'podval_gfeed' ); ?>
            <input type="hidden" name="podval_gfeed_action" value="rebuild">
            <button class="button button-primary">🔄 Пересобрать фид сейчас</button>
            <span style="margin-left:10px;color:#666">Принудительный запуск генерации (медленно — может занять 10-30 сек)</span>
        </form>
        <form method="post" style="margin-bottom:10px">
            <?php wp_nonce_field( 'podval_gfeed' ); ?>
            <input type="hidden" name="podval_gfeed_action" value="flush">
            <button class="button">🗑 Очистить кэш</button>
            <span style="margin-left:10px;color:#666">Кэш будет пересобран при следующем запросе фида</span>
        </form>
        <form method="post" style="margin-bottom:10px">
            <?php wp_nonce_field( 'podval_gfeed' ); ?>
            <input type="hidden" name="podval_gfeed_action" value="flush_rewrite">
            <button class="button">⚙ Flush rewrite rules</button>
            <span style="margin-left:10px;color:#666">Нажми один раз после активации модуля, чтобы /feed/google.xml заработал</span>
        </form>

        <h2 style="margin-top:30px">Что попадает в фид</h2>
        <p><strong>Включается:</strong></p>
        <ul style="list-style:disc;padding-left:20px">
            <li>Опубликованные товары с главным изображением</li>
            <li>Простые товары + каждая вариация отдельно (с item_group_id)</li>
            <li>Только в наличии и с положительной ценой</li>
        </ul>
        <p><strong>Исключается:</strong></p>
        <ul style="list-style:disc;padding-left:20px">
            <li>Категория <code>uslugi</code> (услуги)</li>
            <li>Товары с бейджем <code>custom</code> или <code>yourphoto</code> (свой дизайн)</li>
            <li>Скрытые из каталога</li>
            <li>Без главного изображения</li>
            <li>Out of stock / без цены</li>
        </ul>

        <h2 style="margin-top:30px">Диагностика изображений</h2>
        <p>Google Merchant требует изображения <strong>не менее 500×500 px</strong>. Эта проверка пройдёт по всем товарам, попадающим в фид, и покажет те, у которых даже после фолбэков отдаётся маленькое изображение.</p>
        <form method="post" style="margin-bottom:10px">
            <?php wp_nonce_field( 'podval_gfeed' ); ?>
            <input type="hidden" name="podval_gfeed_action" value="audit_images">
            <button class="button">🖼 Проверить размеры изображений</button>
            <span style="margin-left:10px;color:#666">Может занять 10-30 сек на больших каталогах</span>
        </form>

        <h2 style="margin-top:30px">Подключение в Merchant Center</h2>
        <ol>
            <li>В Merchant Center → "Добавить источник данных о товарах"</li>
            <li>Выбрать <strong>«Из файла»</strong> (НЕ "Через API")</li>
            <li>Метод загрузки: <strong>«Запланированная выборка»</strong></li>
            <li>Имя файла: <code>google.xml</code></li>
            <li>URL: <code><?php echo esc_html( $feed_url ); ?></code></li>
            <li>Частота: 1 раз в день</li>
            <li>Без авторизации</li>
        </ol>
    </div>
    <?php
}

/* ══════════════════════════════════════════
   11. АКТИВАЦИЯ: автоматический flush rewrite
   ══════════════════════════════════════════ */
add_action( 'after_switch_theme', function() {
    flush_rewrite_rules( false );
} );

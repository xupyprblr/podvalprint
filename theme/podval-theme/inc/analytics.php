<?php
/**
 * Podval Print — Analytics Module прежней версии
 *
 * Централизованное управление аналитикой и рекламными пикселями.
 * Все ID настраиваются через Customizer → «Аналитика и трекинг».
 *
 * Интеграции:
 *  - Google Ads (gtag.js) — AW-*
 *  - Google Analytics 4 (gtag.js) — G-* (Enhanced Ecommerce)
 *  - Yandex.Metrika — счётчик + reachGoal + ecommerce dataLayer + userParams
 *  - Facebook Pixel — стандартные + ecommerce события
 *
 * События:
 *  - view_item          — просмотр карточки товара
 *  - add_to_cart         — добавление в корзину
 *  - begin_checkout      — переход к оформлению
 *  - purchase            — завершённая покупка
 *  - podval_form_submit   — заявка «Мерч под ключ»
 *  - telegram_click      — клик по кнопке Telegram (header / footer / product / mobile_menu)
 *  - print_upload        — успешная загрузка файла принта на page-artwork-upload.php
 *
 * Дедупликация purchase:
 *  - _podval_analytics_client_tracked — флаг для клиентского gtag/fbq/ym на thank-you page
 *  - _podval_analytics_server_tracked — флаг для Measurement Protocol отправок
 *  Флаги независимые: клиент и сервер не блокируют друг друга (GA4 сам дедуплицирует по transaction_id).
 *
 * Ecommerce dataLayer (Яндекс.Метрика):
 *  - detail, add, checkout, purchase
 */

defined( 'ABSPATH' ) || exit;


/* ═══════════════════════════════════════════
   1. CUSTOMIZER — секция «Аналитика и трекинг»
   ═══════════════════════════════════════════ */

add_action( 'customize_register', 'podval_analytics_customizer' );

function podval_analytics_customizer( $wp_customize ) {

    /* ---------- Секция ---------- */
    $wp_customize->add_section( 'podval_analytics', [
        'title'    => '📊 Аналитика и трекинг',
        'priority' => 25,
    ] );

    /* --- Google Ads ID (AW-XXXXXXXXX) --- */
    $wp_customize->add_setting( 'podval_google_ads_id', [
        'default'           => '',
        'sanitize_callback' => 'sanitize_text_field',
    ] );
    $wp_customize->add_control( 'podval_google_ads_id', [
        'label'       => 'Google Ads ID',
        'description' => 'Формат: AW-XXXXXXXXXXX',
        'section'     => 'podval_analytics',
        'type'        => 'text',
    ] );

    /* --- Google Ads Purchase Conversion Label (для точного event-send на thank-you) --- */
    $wp_customize->add_setting( 'podval_google_ads_purchase_label', [
        'default'           => '',
        'sanitize_callback' => 'sanitize_text_field',
    ] );
    $wp_customize->add_control( 'podval_google_ads_purchase_label', [
        'label'       => 'Google Ads — Purchase Conversion Label',
        'description' => 'Вторая часть из send_to: AW-XXXXXXXXXXX/YYYY. Вписывайте только YYYY, без AW-…',
        'section'     => 'podval_analytics',
        'type'        => 'text',
    ] );

    /* --- Google Analytics 4 ID (G-XXXXXXXXX) --- */
    $wp_customize->add_setting( 'podval_ga4_id', [
        'default'           => '',
        'sanitize_callback' => 'sanitize_text_field',
    ] );
    $wp_customize->add_control( 'podval_ga4_id', [
        'label'       => 'Google Analytics 4 ID',
        'description' => 'Формат: G-XXXXXXXXXX',
        'section'     => 'podval_analytics',
        'type'        => 'text',
    ] );

    /* --- GA4 Measurement Protocol API Secret (для серверного purchase) --- */
    $wp_customize->add_setting( 'podval_ga4_api_secret', [
        'default'           => '',
        'sanitize_callback' => 'sanitize_text_field',
    ] );
    $wp_customize->add_control( 'podval_ga4_api_secret', [
        'label'       => 'GA4 API Secret (Measurement Protocol)',
        'description' => 'GA4 → Admin → Data Streams → ваш поток → Measurement Protocol API secrets. Нужен для серверной отправки purchase при онлайн-оплате.',
        'section'     => 'podval_analytics',
        'type'        => 'text',
    ] );

    /* --- Yandex.Metrika ID --- */
    $wp_customize->add_setting( 'podval_ym_id', [
        'default'           => '',
        'sanitize_callback' => 'sanitize_text_field',
    ] );
    $wp_customize->add_control( 'podval_ym_id', [
        'label'       => 'Яндекс.Метрика ID',
        'description' => 'Только число, например: 12345678',
        'section'     => 'podval_analytics',
        'type'        => 'text',
    ] );

    /* --- Facebook Pixel ID --- */
    $wp_customize->add_setting( 'podval_fb_pixel_id', [
        'default'           => '',
        'sanitize_callback' => 'sanitize_text_field',
    ] );
    $wp_customize->add_control( 'podval_fb_pixel_id', [
        'label'       => 'Facebook Pixel ID',
        'description' => 'Только число, например: 1234567890',
        'section'     => 'podval_analytics',
        'type'        => 'text',
    ] );

    /* --- Глобальный выключатель --- */
    $wp_customize->add_setting( 'podval_analytics_enabled', [
        'default'           => true,
        'sanitize_callback' => 'wp_validate_boolean',
    ] );
    $wp_customize->add_control( 'podval_analytics_enabled', [
        'label'   => 'Включить аналитику',
        'section' => 'podval_analytics',
        'type'    => 'checkbox',
    ] );
}


/* ═══════════════════════════════════════════
   2. ВЫВОД СКРИПТОВ В <head>
   ═══════════════════════════════════════════ */

add_action( 'wp_head', 'podval_analytics_head', 1 );

function podval_analytics_head() {

    if ( ! get_theme_mod( 'podval_analytics_enabled', true ) ) {
        return;
    }

    if ( is_customize_preview() ) {
        return;
    }

    $google_ads_id = trim( get_theme_mod( 'podval_google_ads_id', '' ) );
    $ga4_id        = trim( get_theme_mod( 'podval_ga4_id', '' ) );
    $ym_id         = trim( get_theme_mod( 'podval_ym_id', '' ) );
    $fb_pixel_id   = trim( get_theme_mod( 'podval_fb_pixel_id', '' ) );

    // Определяем, нужен ли immediate loading (checkout/order страницы)
    $is_critical = function_exists( 'is_checkout' ) && ( is_checkout() || is_order_received_page() );

    if ( $is_critical ) {
        // На checkout грузим немедленно — нужны для отслеживания конверсий
        podval_analytics_render_scripts( $google_ads_id, $ga4_id, $ym_id, $fb_pixel_id );
    } else {
        // На остальных страницах — отложенная загрузка (через 3 сек или по взаимодействию)
        podval_analytics_render_deferred( $google_ads_id, $ga4_id, $ym_id, $fb_pixel_id );
    }
}

/**
 * Немедленная загрузка аналитики (checkout, purchase)
 */
function podval_analytics_render_scripts( $google_ads_id, $ga4_id, $ym_id, $fb_pixel_id ) {
    $gtag_ids = array_filter( [ $google_ads_id, $ga4_id ] );

    if ( ! empty( $gtag_ids ) ) {
        $primary_id = reset( $gtag_ids );
        ?>
<!-- analytics: Google gtag.js -->
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr( $primary_id ); ?>"></script>
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('js', new Date());
<?php
        foreach ( $gtag_ids as $gid ) {
            echo "gtag('config', '" . esc_js( $gid ) . "');\n";
        }
?>
</script>
<?php
    }

    if ( $ym_id ) {
        ?>
<!-- analytics: Yandex.Metrika -->
<script>
(function(m,e,t,r,i,k,a){m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
m[i].l=1*new Date();
for(var j=0;j<document.scripts.length;j++){if(document.scripts[j].src===r)return;}
k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)})
(window,document,"script","https://mc.yandex.ru/metrika/tag.js","ym");
ym(<?php echo esc_js( $ym_id ); ?>, "init", {
    clickmap:true,
    trackLinks:true,
    accurateTrackBounce:true,
    webvisor:true,
    ecommerce:"dataLayer"
});
</script>
<noscript><div><img src="https://mc.yandex.ru/watch/<?php echo esc_attr( $ym_id ); ?>" style="position:absolute;left:-9999px" alt=""></div></noscript>
<?php
    }

    if ( $fb_pixel_id ) {
        ?>
<!-- analytics: Facebook Pixel -->
<script>
!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
document,'script','https://connect.facebook.net/en_US/fbevents.js');
fbq('init', '<?php echo esc_js( $fb_pixel_id ); ?>');
fbq('track', 'PageView');
</script>
<noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=<?php echo esc_attr( $fb_pixel_id ); ?>&ev=PageView&noscript=1"></noscript>
<?php
    }
}

/**
 * Отложенная загрузка аналитики — после взаимодействия или через 3 сек
 * Стубы gtag/ym/fbq определяются сразу (события копятся в очередь),
 * сами скрипты подгружаются позже — не влияют на LCP/FCP
 */
function podval_analytics_render_deferred( $google_ads_id, $ga4_id, $ym_id, $fb_pixel_id ) {
    $gtag_ids = array_filter( [ $google_ads_id, $ga4_id ] );
    ?>
<!-- analytics: Deferred loading (after interaction or 7s) -->
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
<?php if ( $ym_id ) : ?>
window.ym=window.ym||function(){(window.ym.a=window.ym.a||[]).push(arguments)};
window.ym.l=1*new Date();
<?php endif; ?>
<?php if ( $fb_pixel_id ) : ?>
!function(f){if(f.fbq)return;var n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};
if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!1;n.version='2.0';n.queue=[]}(window);
fbq('init','<?php echo esc_js( $fb_pixel_id ); ?>');
fbq('track','PageView');
<?php endif; ?>
(function(){
var loaded=false;
function loadAnalytics(){
  if(loaded)return;loaded=true;
  <?php if ( ! empty( $gtag_ids ) ) :
      $primary_id = reset( $gtag_ids ); ?>
  var gs=document.createElement('script');gs.async=true;
  gs.src='https://www.googletagmanager.com/gtag/js?id=<?php echo esc_js( $primary_id ); ?>';
  document.head.appendChild(gs);
  gtag('js',new Date());
  <?php foreach ( $gtag_ids as $gid ) : ?>
  gtag('config','<?php echo esc_js( $gid ); ?>');
  <?php endforeach; ?>
  <?php endif; ?>

  <?php if ( $ym_id ) : ?>
  var ys=document.createElement('script');ys.async=true;
  ys.src='https://mc.yandex.ru/metrika/tag.js';
  document.head.appendChild(ys);
  ym(<?php echo esc_js( $ym_id ); ?>,"init",{clickmap:true,trackLinks:true,accurateTrackBounce:true,webvisor:true,ecommerce:"dataLayer"});
  <?php endif; ?>

  <?php if ( $fb_pixel_id ) : ?>
  var fs=document.createElement('script');fs.async=true;
  fs.src='https://connect.facebook.net/en_US/fbevents.js';
  document.head.appendChild(fs);
  <?php endif; ?>
}
var t=setTimeout(loadAnalytics,7000);
['scroll','click','touchstart','keydown'].forEach(function(e){
  document.addEventListener(e,function(){clearTimeout(t);loadAnalytics()},{once:true,passive:true});
});
})();
</script>
<?php
    if ( $ym_id ) : ?>
<noscript><div><img src="https://mc.yandex.ru/watch/<?php echo esc_attr( $ym_id ); ?>" style="position:absolute;left:-9999px" alt=""></div></noscript>
<?php endif;
    if ( $fb_pixel_id ) : ?>
<noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=<?php echo esc_attr( $fb_pixel_id ); ?>&ev=PageView&noscript=1"></noscript>
<?php endif;
}


/* ═══════════════════════════════════════════
   3. HELPER — получить данные товара для событий
   ═══════════════════════════════════════════ */

function podval_get_product_analytics_data( $product ) {
    if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
        return null;
    }

    $cats   = get_the_terms( $product->get_id(), 'product_cat' );
    $cat    = ( $cats && ! is_wp_error( $cats ) ) ? $cats[0]->name : '';
    $parent = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : $product;

    return [
        'item_id'       => (string) $parent->get_id(),
        'item_name'     => $parent->get_name(),
        'item_category' => $cat,
        'price'         => (float) $product->get_price(),
        'currency'      => get_woocommerce_currency(),
    ];
}


/* ═══════════════════════════════════════════
   4. VIEW_ITEM — просмотр карточки товара
   ═══════════════════════════════════════════ */

add_action( 'wp_footer', 'podval_analytics_view_item' );

function podval_analytics_view_item() {
    if ( ! get_theme_mod( 'podval_analytics_enabled', true ) ) return;
    if ( ! is_product() ) return;

    global $product;
    if ( ! $product ) return;

    $data = podval_get_product_analytics_data( $product );
    if ( ! $data ) return;

    $ym_id = trim( get_theme_mod( 'podval_ym_id', '' ) );
    ?>
<!-- analytics: view_item -->
<script>
(function(){
    var item = <?php echo wp_json_encode( $data ); ?>;

    /* GA4 */
    if (typeof gtag === 'function') {
        gtag('event', 'view_item', {
            currency: item.currency,
            value: item.price,
            items: [{
                item_id: item.item_id,
                item_name: item.item_name,
                item_category: item.item_category,
                price: item.price,
                quantity: 1
            }]
        });
    }

    /* Facebook Pixel */
    if (typeof fbq === 'function') {
        fbq('track', 'ViewContent', {
            content_ids: [item.item_id],
            content_name: item.item_name,
            content_type: 'product',
            content_category: item.item_category,
            value: item.price,
            currency: item.currency
        });
    }

    /* Яндекс.Метрика */
<?php if ( $ym_id ) : ?>
    if (typeof ym === 'function') {
        ym(<?php echo esc_js( $ym_id ); ?>, 'reachGoal', 'view_item', {
            product_id: item.item_id,
            product_name: item.item_name,
            price: item.price
        });
    }

    /* Яндекс.Метрика — Ecommerce: detail */
    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push({
        ecommerce: {
            detail: {
                products: [{
                    id: item.item_id,
                    name: item.item_name,
                    category: item.item_category,
                    price: item.price
                }]
            }
        }
    });
<?php endif; ?>
})();
</script>
<?php
}


/* ═══════════════════════════════════════════
   5. ADD_TO_CART — JS-хук (вызывается из main.js)
   ═══════════════════════════════════════════ */

add_action( 'wp_footer', 'podval_analytics_add_to_cart_data' );

function podval_analytics_add_to_cart_data() {
    if ( ! get_theme_mod( 'podval_analytics_enabled', true ) ) return;

    // Works on product pages AND on artwork-upload page
    $product_obj = null;

    if ( is_product() ) {
        global $product;
        $product_obj = $product;
    } elseif ( is_page() ) {
        // Artwork upload page — get product_id from GET params
        $pid = absint( $_GET['product_id'] ?? 0 );
        if ( $pid ) {
            $product_obj = wc_get_product( $pid );
        }
    }

    if ( ! $product_obj ) return;

    $data  = podval_get_product_analytics_data( $product_obj );
    $ym_id = trim( get_theme_mod( 'podval_ym_id', '' ) );

    if ( ! $data ) return;
    ?>
<!-- analytics: add_to_cart data -->
<script>
window.podvalAnalytics = window.podvalAnalytics || {};
window.podvalAnalytics.product = <?php echo wp_json_encode( $data ); ?>;
window.podvalAnalytics.ymId = <?php echo $ym_id ? esc_js( $ym_id ) : '0'; ?>;

/**
 * Вызывать из main.js после успешного addToCart:
 * if (window.podvalAnalytics && window.podvalAnalytics.fireAddToCart) {
 *     window.podvalAnalytics.fireAddToCart();
 * }
 */
window.podvalAnalytics.fireAddToCart = function() {
    var item = window.podvalAnalytics.product;
    if (!item) return;

    /* GA4 */
    if (typeof gtag === 'function') {
        gtag('event', 'add_to_cart', {
            currency: item.currency,
            value: item.price,
            items: [{
                item_id: item.item_id,
                item_name: item.item_name,
                item_category: item.item_category,
                price: item.price,
                quantity: 1
            }]
        });
    }

    /* Facebook Pixel */
    if (typeof fbq === 'function') {
        fbq('track', 'AddToCart', {
            content_ids: [item.item_id],
            content_name: item.item_name,
            content_type: 'product',
            value: item.price,
            currency: item.currency
        });
    }

    /* Яндекс.Метрика */
    if (window.podvalAnalytics.ymId && typeof ym === 'function') {
        ym(window.podvalAnalytics.ymId, 'reachGoal', 'add_to_cart', {
            product_id: item.item_id,
            product_name: item.item_name,
            price: item.price
        });

        /* Ecommerce: add */
        window.dataLayer = window.dataLayer || [];
        window.dataLayer.push({
            ecommerce: {
                add: {
                    products: [{
                        id: item.item_id,
                        name: item.item_name,
                        category: item.item_category,
                        price: item.price,
                        quantity: 1
                    }]
                }
            }
        });
    }
};
</script>
<?php
}


/* ═══════════════════════════════════════════
   6. BEGIN_CHECKOUT — переход на страницу оформления
   ═══════════════════════════════════════════ */

add_action( 'wp_footer', 'podval_analytics_begin_checkout' );

function podval_analytics_begin_checkout() {
    if ( ! get_theme_mod( 'podval_analytics_enabled', true ) ) return;

    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;

    // Double-check: exclude thank you / order-received page
    if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) return;
    if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) return;
    if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) return;

    if ( ! WC()->cart || WC()->cart->is_empty() ) return;

    $ym_id      = trim( get_theme_mod( 'podval_ym_id', '' ) );
    $cart_items = WC()->cart->get_cart();
    $items_data = [];
    $total      = (float) WC()->cart->get_total( 'edit' );
    $currency   = get_woocommerce_currency();

    foreach ( $cart_items as $cart_item ) {
        $prod = $cart_item['data'];
        if ( ! $prod || ! $prod->exists() ) continue;

        $parent   = $prod->get_parent_id() ? wc_get_product( $prod->get_parent_id() ) : $prod;
        $cats     = get_the_terms( $parent->get_id(), 'product_cat' );
        $cat_name = ( $cats && ! is_wp_error( $cats ) ) ? $cats[0]->name : '';

        $items_data[] = [
            'item_id'       => (string) $parent->get_id(),
            'item_name'     => $parent->get_name(),
            'item_category' => $cat_name,
            'price'         => (float) $prod->get_price(),
            'quantity'      => (int) $cart_item['quantity'],
        ];
    }
    ?>
<!-- analytics: begin_checkout -->
<script>
(function(){
    var items = <?php echo wp_json_encode( $items_data ); ?>;
    var total = <?php echo floatval( $total ); ?>;
    var currency = '<?php echo esc_js( $currency ); ?>';

    /* GA4 */
    if (typeof gtag === 'function') {
        gtag('event', 'begin_checkout', {
            currency: currency,
            value: total,
            items: items
        });
    }

    /* Facebook Pixel */
    if (typeof fbq === 'function') {
        fbq('track', 'InitiateCheckout', {
            content_ids: items.map(function(i){ return i.item_id; }),
            content_type: 'product',
            num_items: items.reduce(function(s,i){ return s + i.quantity; }, 0),
            value: total,
            currency: currency
        });
    }

    /* Яндекс.Метрика */
<?php if ( $ym_id ) : ?>
    if (typeof ym === 'function') {
        ym(<?php echo esc_js( $ym_id ); ?>, 'reachGoal', 'begin_checkout', {
            order_total: total,
            items_count: items.reduce(function(s,i){ return s + i.quantity; }, 0)
        });
    }
<?php endif; ?>
})();
</script>
<?php
}


/* ═══════════════════════════════════════════
   7. PURCHASE — заказ оформлен (thank you page)
   ═══════════════════════════════════════════ */

add_action( 'wp_footer', 'podval_analytics_purchase' );

function podval_analytics_purchase() {
    if ( ! get_theme_mod( 'podval_analytics_enabled', true ) ) return;

    // Check: must be the order-received page (two methods for reliability)
    $is_thankyou = false;
    if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
        $is_thankyou = true;
    }
    if ( ! $is_thankyou && function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) {
        $is_thankyou = true;
    }
    if ( ! $is_thankyou ) return;

    // заказ только при верном ?key= — чужой заказ по ID не отдаём в dataLayer
    // и не помечаем как отслеженный.
    $order = function_exists( 'podval_get_thankyou_order' ) ? podval_get_thankyou_order() : false;
    if ( ! $order ) return;
    $order_id = $order->get_id();

    // заявка на услугу — не покупка. Выходим ДО записи флага
    // _podval_analytics_client_tracked: иначе нулевая «покупка» ушла бы в Метрику
    // и GA4, а флаг заблокировал бы настоящий purchase, если заявку потом
    // переоформят в оплаченный заказ.
    if ( $order->get_meta( '_order_type' ) === 'service' ) return;

    // Предотвращаем дублирование при обновлении страницы (клиентский флаг — независимый от серверного!)
    if ( $order->get_meta( '_podval_analytics_client_tracked' ) === 'yes' ) {
        error_log( '[analytics] purchase client: SKIP (already tracked) order ' . $order_id );
        return;
    }
    $order->update_meta_data( '_podval_analytics_client_tracked', 'yes' );
    $order->save();
    error_log( '[analytics] purchase client: FIRE order ' . $order_id . ' total ' . $order->get_total() );

    $total     = (float) $order->get_total();
    $currency  = $order->get_currency();
    $order_num = $order->get_order_number();
    $ym_id     = trim( get_theme_mod( 'podval_ym_id', '' ) );
    $ads_id    = trim( get_theme_mod( 'podval_google_ads_id', '' ) );
    $ads_label = trim( get_theme_mod( 'podval_google_ads_purchase_label', '' ) );

    $items_data = [];
    foreach ( $order->get_items() as $item ) {
        $prod = $item->get_product();
        if ( ! $prod ) continue;

        $parent   = $prod->get_parent_id() ? wc_get_product( $prod->get_parent_id() ) : $prod;
        $cats     = get_the_terms( $parent->get_id(), 'product_cat' );
        $cat_name = ( $cats && ! is_wp_error( $cats ) ) ? $cats[0]->name : '';

        $items_data[] = [
            'item_id'       => (string) $parent->get_id(),
            'item_name'     => $parent->get_name(),
            'item_category' => $cat_name,
            'price'         => (float) $prod->get_price(),
            'quantity'      => (int) $item->get_quantity(),
        ];
    }
    ?>
<!-- analytics: purchase -->
<script>
(function(){
    var items = <?php echo wp_json_encode( $items_data ); ?>;
    var total = <?php echo floatval( $total ); ?>;
    var currency = '<?php echo esc_js( $currency ); ?>';
    var orderId = '<?php echo esc_js( $order_num ); ?>';

    /* GA4 + Google Ads (общий enhanced ecommerce event) */
    if (typeof gtag === 'function') {
        gtag('event', 'purchase', {
            transaction_id: orderId,
            value: total,
            currency: currency,
            items: items
        });
    }

<?php if ( $ads_id && $ads_label ) : ?>
    /* Google Ads — отдельное conversion-событие (для точного учёта в Ads) */
    if (typeof gtag === 'function') {
        gtag('event', 'conversion', {
            send_to: '<?php echo esc_js( $ads_id . '/' . $ads_label ); ?>',
            value: total,
            currency: currency,
            transaction_id: orderId
        });
    }
<?php endif; ?>

    /* Facebook Pixel */
    if (typeof fbq === 'function') {
        fbq('track', 'Purchase', {
            content_ids: items.map(function(i){ return i.item_id; }),
            content_type: 'product',
            num_items: items.reduce(function(s,i){ return s + i.quantity; }, 0),
            value: total,
            currency: currency
        });
    }

    /* Яндекс.Метрика */
<?php if ( $ym_id ) : ?>
    if (typeof ym === 'function') {
        ym(<?php echo esc_js( $ym_id ); ?>, 'reachGoal', 'purchase', {
            order_id: orderId,
            order_total: total
        });
    }

    /* Яндекс.Метрика — Ecommerce dataLayer */
    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push({
        ecommerce: {
            purchase: {
                actionField: { id: orderId, revenue: total, currency: currency },
                products: items.map(function(i){
                    return {
                        id: i.item_id,
                        name: i.item_name,
                        category: i.item_category,
                        price: i.price,
                        quantity: i.quantity
                    };
                })
            }
        }
    });
<?php endif; ?>
})();
</script>
<?php
}


/* ═══════════════════════════════════════════
   8. USERPARAMS — обогащение Вебвизора
   Передаём параметры посетителя в Метрику:
   тип клиента, кол-во заказов, общая сумма
   ═══════════════════════════════════════════ */

add_action( 'wp_footer', 'podval_analytics_user_params' );

function podval_analytics_user_params() {
    if ( ! get_theme_mod( 'podval_analytics_enabled', true ) ) return;

    $ym_id = trim( get_theme_mod( 'podval_ym_id', '' ) );
    if ( ! $ym_id ) return;

    $params = [ 'customer_type' => 'guest' ];

    if ( is_user_logged_in() ) {
        $user_id      = get_current_user_id();
        $order_count  = wc_get_customer_order_count( $user_id );
        $total_spent  = (float) wc_get_customer_total_spent( $user_id );

        $params['customer_type']   = $order_count > 0 ? 'returning' : 'new';
        $params['orders_count']    = $order_count;
        $params['total_spent']     = $total_spent;
    }
    ?>
<!-- analytics: userParams -->
<script>
(function(){
    if (typeof ym === 'function') {
        ym(<?php echo esc_js( $ym_id ); ?>, 'userParams', <?php echo wp_json_encode( $params ); ?>);
    }
})();
</script>
<?php
}


/* ═══════════════════════════════════════════
   8.1 TELEGRAM_CLICK — цель при клике по ссылке t.me
   Делегированный listener на все ссылки a[href*="t.me/"]
   Определяет source по ближайшему контейнеру:
   header / mobile_menu / footer / product_howto / other
   ═══════════════════════════════════════════ */

add_action( 'wp_footer', 'podval_analytics_telegram_clicks' );

function podval_analytics_telegram_clicks() {
    if ( ! get_theme_mod( 'podval_analytics_enabled', true ) ) return;

    $ym_id       = trim( get_theme_mod( 'podval_ym_id', '' ) );
    $fb_pixel_id = trim( get_theme_mod( 'podval_fb_pixel_id', '' ) );
    ?>
<!-- analytics: telegram_click -->
<script>
(function(){
    function resolveSource(el) {
        // Явный data-атрибут имеет приоритет
        var explicit = el.getAttribute('data-tg-source');
        if (explicit) return explicit;

        if (el.closest('.mnav, .mobile-nav, #mobile-menu, .mobile-menu')) return 'mobile_menu';
        if (el.closest('header, .site-header, #masthead, .header')) return 'header';
        if (el.closest('.sp-howto, .sp-howto__tg-btn')) return 'product_howto';
        if (el.closest('footer, .site-footer, #colophon, .footer')) return 'footer';
        if (el.closest('.single-product, .product, .woocommerce')) return 'product_page';
        return 'other';
    }

    document.addEventListener('click', function(e) {
        var link = e.target.closest('a[href*="t.me/"], a[href*="telegram.me/"]');
        if (!link) return;

        var source   = resolveSource(link);
        var linkUrl  = link.href;
        var linkText = (link.textContent || '').trim().substring(0, 80) || link.getAttribute('aria-label') || '';

        /* GA4 */
        if (typeof gtag === 'function') {
            gtag('event', 'telegram_click', {
                source: source,
                link_url: linkUrl,
                link_text: linkText,
                page_location: window.location.href
            });
        }

        /* Facebook Pixel */
<?php if ( $fb_pixel_id ) : ?>
        if (typeof fbq === 'function') {
            fbq('trackCustom', 'TelegramClick', {
                source: source,
                link_url: linkUrl
            });
        }
<?php endif; ?>

        /* Яндекс.Метрика */
<?php if ( $ym_id ) : ?>
        if (typeof ym === 'function') {
            ym(<?php echo esc_js( $ym_id ); ?>, 'reachGoal', 'telegram_click', {
                source: source,
                link_url: linkUrl
            });
        }
<?php endif; ?>
    }, true); // capture-фаза: ловим до того как браузер уйдёт по ссылке
})();
</script>
<?php
}


/* ═══════════════════════════════════════════
   9. PODVAL_FORM_SUBMIT — цель при отправке
   формы «Мерч под ключ» (услуги)
   ═══════════════════════════════════════════ */

add_action( 'wp_footer', 'podval_analytics_service_form_goal' );

function podval_analytics_service_form_goal() {
    if ( ! get_theme_mod( 'podval_analytics_enabled', true ) ) return;

    // Карточки товаров-услуг и лендинги услуг
    $svc_name = '';
    if ( function_exists( 'podval_sl_is_current' ) && podval_sl_is_current() ) {
        $svc_name = podval_sl_h1( get_queried_object_id() );
    } elseif ( is_singular( 'product' ) ) {
        global $product;
        if ( ! is_object( $product ) ) {
            $product = wc_get_product( get_queried_object_id() );
        }
        // Проверяем что товар в категории "uslugi" (Мерч под ключ)
        if ( $product && has_term( 'uslugi', 'product_cat', $product->get_id() ) ) {
            $svc_name = $product->get_name();
        }
    }
    if ( $svc_name === '' ) return;

    $ym_id       = trim( get_theme_mod( 'podval_ym_id', '' ) );
    $fb_pixel_id = trim( get_theme_mod( 'podval_fb_pixel_id', '' ) );
    ?>
<!-- analytics: service form goal -->
<script>
(function(){
    var form = document.querySelector('.podval-svc-form');
    if (!form) return;

    form.addEventListener('submit', function() {
      // цель — только за отправку валидной формы. Проверяем после того,
      // как service.js отработал валидацию (он регистрируется позже этого скрипта).
      setTimeout(function() {
        if (form.querySelector('.is-invalid')) return;
        /* GA4 */
        if (typeof gtag === 'function') {
            gtag('event', 'generate_lead', {
                event_category: 'service_form',
                event_label: '<?php echo esc_js( $svc_name ); ?>'
            });
        }

        /* Facebook Pixel */
<?php if ( $fb_pixel_id ) : ?>
        if (typeof fbq === 'function') {
            fbq('track', 'Lead', {
                content_name: '<?php echo esc_js( $svc_name ); ?>',
                content_category: 'service'
            });
        }
<?php endif; ?>

        /* Яндекс.Метрика */
<?php if ( $ym_id ) : ?>
        if (typeof ym === 'function') {
            ym(<?php echo esc_js( $ym_id ); ?>, 'reachGoal', 'podval_form_submit', {
                service: '<?php echo esc_js( $svc_name ); ?>'
            });
        }
<?php endif; ?>
      }, 0);
    });
})();
</script>
<?php
}


/* ═══════════════════════════════════════════
   10. ECOMMERCE — dataLayer для begin_checkout
   Данные о товарах в корзине при начале оформления
   ═══════════════════════════════════════════ */

add_action( 'wp_footer', 'podval_analytics_checkout_ecommerce', 25 );

function podval_analytics_checkout_ecommerce() {
    if ( ! get_theme_mod( 'podval_analytics_enabled', true ) ) return;

    $ym_id = trim( get_theme_mod( 'podval_ym_id', '' ) );
    if ( ! $ym_id ) return;

    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) return;
    if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) return;
    if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) return;
    if ( ! WC()->cart || WC()->cart->is_empty() ) return;

    $cart_items = WC()->cart->get_cart();
    $items_data = [];

    foreach ( $cart_items as $cart_item ) {
        $prod = $cart_item['data'];
        if ( ! $prod || ! $prod->exists() ) continue;

        $parent   = $prod->get_parent_id() ? wc_get_product( $prod->get_parent_id() ) : $prod;
        $cats     = get_the_terms( $parent->get_id(), 'product_cat' );
        $cat_name = ( $cats && ! is_wp_error( $cats ) ) ? $cats[0]->name : '';

        $items_data[] = [
            'id'       => (string) $parent->get_id(),
            'name'     => $parent->get_name(),
            'category' => $cat_name,
            'price'    => (float) $prod->get_price(),
            'quantity' => (int) $cart_item['quantity'],
        ];
    }
    ?>
<!-- analytics: Ecommerce checkout -->
<script>
(function(){
    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push({
        ecommerce: {
            checkout: {
                actionField: { step: 1 },
                products: <?php echo wp_json_encode( $items_data ); ?>
            }
        }
    });
})();
</script>
<?php
}

/* ═══════════════════════════════════════════
   9. SERVER-SIDE PURCHASE TRACKING
   Серверная отправка purchase-события в GA4 и Метрику
   через Measurement Protocol. Работает независимо от того,
   вернулся ли клиент на thank-you-page.

   Логика:
   - Наличка / самовывоз / «после согласования» → purchase на создании заказа
   - Alfabank (онлайн-оплата) → purchase при pending → processing
   - Дедупликация через меты _podval_analytics_client_tracked / _podval_analytics_server_tracked (независимо)
   ═══════════════════════════════════════════ */

/**
 * Сохраняем GA4 Client ID и Метрика UID в мету заказа.
 * Вызывается при создании заказа, когда cookies ещё доступны.
 */
add_action( 'woocommerce_checkout_create_order', 'podval_analytics_save_client_ids', 20, 2 );

function podval_analytics_save_client_ids( $order, $data ) {

    // GA4 Client ID из _ga cookie (формат: GA1.2.XXXXXXXXX.YYYYYYYYYY)
    if ( ! empty( $_COOKIE['_ga'] ) ) {
        $ga_cookie = sanitize_text_field( wp_unslash( $_COOKIE['_ga'] ) );
        // Извлекаем CID из формата GA1.2.CID.TIMESTAMP
        if ( preg_match( '/^GA\d\.\d\.(.+)$/', $ga_cookie, $m ) ) {
            $order->update_meta_data( '_podval_ga_client_id', $m[1] );
        }
    }

    // Yandex.Metrika UID (серверный формат для MP)
    if ( ! empty( $_COOKIE['_ym_uid'] ) ) {
        $order->update_meta_data(
            '_podval_ym_uid',
            sanitize_text_field( wp_unslash( $_COOKIE['_ym_uid'] ) )
        );
    }

    // Session ID для Метрики (помогает матчить с визитом)
    if ( ! empty( $_COOKIE['_ym_d'] ) ) {
        $order->update_meta_data(
            '_podval_ym_d',
            sanitize_text_field( wp_unslash( $_COOKIE['_ym_d'] ) )
        );
    }

    // User Agent и IP — для MP запросов (серверу нужны эти данные для атрибуции)
    if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
        $order->update_meta_data(
            '_podval_user_agent',
            substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 )
        );
    }
}

/**
 * Триггер 1: создание заказа (для оффлайн-оплат)
 * Priority 100 — после Telegram-плагина (99), чтобы не задерживать уведомление.
 */
add_action( 'woocommerce_checkout_order_processed', 'podval_analytics_server_purchase_on_create', 100, 1 );

function podval_analytics_server_purchase_on_create( $order_id ) {

    $order = wc_get_order( $order_id );
    if ( ! $order ) return;

    $payment_method = $order->get_payment_method();

    // Для онлайн-оплат (alfabank) ждём реального подтверждения — не отправляем здесь
    $online_methods = [ 'alfabank', 'wc_alfabank', 'alfabankby' ];
    if ( in_array( $payment_method, $online_methods, true ) ) {
        return;
    }

    podval_analytics_send_server_purchase( $order );
}

/**
 * Триггер 2: изменение статуса (для онлайн-оплат)
 * Срабатывает при pending → processing (оплата подтверждена).
 */
add_action( 'woocommerce_order_status_changed', 'podval_analytics_server_purchase_on_paid', 100, 4 );

function podval_analytics_server_purchase_on_paid( $order_id, $from_status, $to_status, $order ) {

    if ( ! $order ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
    }

    // Нас интересует именно переход в «оплачено»: pending → processing / completed
    $paid_statuses = [ 'processing', 'completed' ];
    if ( ! in_array( $to_status, $paid_statuses, true ) ) {
        return;
    }
    if ( $from_status === $to_status ) {
        return;
    }

    podval_analytics_send_server_purchase( $order );
}

/**
 * Основная функция отправки purchase-события на серверы аналитики.
 * Имеет встроенную дедупликацию через мету _podval_analytics_server_tracked
 * (независимо от клиентской меты _podval_analytics_client_tracked).
 */
function podval_analytics_send_server_purchase( $order ) {

    if ( ! get_theme_mod( 'podval_analytics_enabled', true ) ) return;
    if ( ! $order instanceof WC_Order ) return;

    // Дедупликация (серверный флаг — независимый от клиентского)
    if ( $order->get_meta( '_podval_analytics_server_tracked' ) === 'yes' ) {
        error_log( '[analytics] purchase server: SKIP (already tracked) order ' . $order->get_id() );
        return;
    }

    $order->update_meta_data( '_podval_analytics_server_tracked', 'yes' );
    $order->save();
    error_log( '[analytics] purchase server: FIRE order ' . $order->get_id() . ' total ' . $order->get_total() . ' payment ' . $order->get_payment_method() );

    // Собираем items
    $items_data = [];
    foreach ( $order->get_items() as $item ) {
        $prod = $item->get_product();
        if ( ! $prod ) continue;

        $parent   = $prod->get_parent_id() ? wc_get_product( $prod->get_parent_id() ) : $prod;
        $cats     = get_the_terms( $parent->get_id(), 'product_cat' );
        $cat_name = ( $cats && ! is_wp_error( $cats ) ) ? $cats[0]->name : '';

        $items_data[] = [
            'item_id'       => (string) $parent->get_id(),
            'item_name'     => $parent->get_name(),
            'item_category' => $cat_name,
            'price'         => (float) $prod->get_price(),
            'quantity'      => (int) $item->get_quantity(),
        ];
    }

    $payload = [
        'order_id'   => (string) $order->get_order_number(),
        'total'      => (float) $order->get_total(),
        'currency'   => $order->get_currency(),
        'items'      => $items_data,
        'ga_cid'     => $order->get_meta( '_podval_ga_client_id' ),
        'ym_uid'     => $order->get_meta( '_podval_ym_uid' ),
        'user_agent' => $order->get_meta( '_podval_user_agent' ),
        'ip'         => $order->get_customer_ip_address(),
    ];

    // Отправляем в оба сервиса параллельно (неблокирующе)
    podval_analytics_mp_send_ga4( $payload );
    podval_analytics_mp_send_yandex( $payload );
}

/**
 * GA4 Measurement Protocol
 * Docs: https://developers.google.com/analytics/devguides/collection/protocol/ga4
 */
function podval_analytics_mp_send_ga4( $payload ) {

    $ga4_id     = trim( get_theme_mod( 'podval_ga4_id', '' ) );
    $api_secret = trim( get_theme_mod( 'podval_ga4_api_secret', '' ) );

    if ( ! $ga4_id || ! $api_secret ) return;
    if ( empty( $payload['ga_cid'] ) ) {
        // Без client_id MP отклонит запрос; генерируем временный как fallback
        $payload['ga_cid'] = wp_generate_uuid4();
    }

    $items = [];
    foreach ( $payload['items'] as $i ) {
        $items[] = [
            'item_id'       => $i['item_id'],
            'item_name'     => $i['item_name'],
            'item_category' => $i['item_category'],
            'price'         => $i['price'],
            'quantity'      => $i['quantity'],
        ];
    }

    $body = [
        'client_id' => $payload['ga_cid'],
        'events'    => [ [
            'name'   => 'purchase',
            'params' => [
                'transaction_id' => $payload['order_id'],
                'value'          => $payload['total'],
                'currency'       => $payload['currency'],
                'items'          => $items,
            ],
        ] ],
    ];

    $url = add_query_arg( [
        'measurement_id' => $ga4_id,
        'api_secret'     => $api_secret,
    ], 'https://www.google-analytics.com/mp/collect' );

    $response = wp_remote_post( $url, [
        'timeout'   => 5,
        'blocking'  => false, // не блокируем оформление заказа
        'headers'   => [ 'Content-Type' => 'application/json' ],
        'body'      => wp_json_encode( $body ),
        'sslverify' => true,
    ] );

    if ( is_wp_error( $response ) ) {
        error_log( 'analytics GA4 MP error: ' . $response->get_error_message() );
    }
}

/**
 * Яндекс.Метрика — server-side событие через счётчик
 * Использует endpoint https://mc.yandex.ru/watch/{counter_id}
 * с параметрами ut=noindex, browser-info.
 *
 * Реализация: отправляем "хит" с пользовательскими параметрами визита
 * и ecommerce goal. Это официальный способ серверного трекинга,
 * описанный в документации Метрики для оффлайн-конверсий.
 */
function podval_analytics_mp_send_yandex( $payload ) {

    $ym_id = trim( get_theme_mod( 'podval_ym_id', '' ) );
    if ( ! $ym_id ) return;

    // Яндекс.Метрика требует связку с визитом через ClientID (_ym_uid) и sessionID (_ym_d)
    // Если cookie потеряны — отправка не даст атрибуции, пропускаем
    if ( empty( $payload['ym_uid'] ) ) {
        error_log( 'analytics YM: no _ym_uid for order ' . $payload['order_id'] );
        return;
    }

    // Формируем ecommerce payload для Метрики
    $ym_products = [];
    foreach ( $payload['items'] as $i ) {
        $ym_products[] = [
            'id'       => $i['item_id'],
            'name'     => $i['item_name'],
            'category' => $i['item_category'],
            'price'    => $i['price'],
            'quantity' => $i['quantity'],
        ];
    }

    $ecommerce = [
        'purchase' => [
            'actionField' => [
                'id'       => $payload['order_id'],
                'revenue'  => $payload['total'],
                'currency' => $payload['currency'],
            ],
            'products'    => $ym_products,
        ],
    ];

    // params для Метрики: goal + ecommerce через params visit
    $params = [
        'ym:s:params' => [
            'server_purchase' => [
                'order_id'    => $payload['order_id'],
                'order_total' => $payload['total'],
            ],
        ],
        'ecommerce' => [ $ecommerce ],
    ];

    // Отправляем хит на Метрику
    $url = sprintf( 'https://mc.yandex.ru/watch/%s/1', $ym_id );

    $body_params = [
        'browser-info' => sprintf( 'u:%s:t:server-purchase:pv:0', $payload['ym_uid'] ),
        'site-info'    => wp_json_encode( $params ),
        'ut'           => 'noindex',
    ];

    $response = wp_remote_post( $url, [
        'timeout'   => 5,
        'blocking'  => false,
        'headers'   => [
            'User-Agent' => $payload['user_agent'] ?: 'Mozilla/5.0 (compatible; PodvalServerAnalytics/1.0)',
        ],
        'body'      => $body_params,
        'sslverify' => true,
    ] );

    if ( is_wp_error( $response ) ) {
        error_log( 'analytics YM MP error: ' . $response->get_error_message() );
    }
}


/* ═══════════════════════════════════════════
   12. PRINT_UPLOAD — цель при успешной загрузке
   принта/файла на странице «Загрузите ваш дизайн»
   (page-artwork-upload.php).

   Слушает CustomEvent 'podval:print_uploaded', который
   диспатчится из assets/js/artwork-upload.js после
   успешного ответа сервера на podval_upload_print.
   ═══════════════════════════════════════════ */

add_action( 'wp_footer', 'podval_analytics_print_upload_goal' );

function podval_analytics_print_upload_goal() {
    if ( ! get_theme_mod( 'podval_analytics_enabled', true ) ) return;

    // Только на шаблоне загрузки дизайна
    if ( ! is_page_template( 'page-artwork-upload.php' ) ) return;

    $ym_id       = trim( get_theme_mod( 'podval_ym_id', '' ) );
    $fb_pixel_id = trim( get_theme_mod( 'podval_fb_pixel_id', '' ) );
    ?>
<!-- analytics: print_upload goal -->
<script>
(function(){
    document.addEventListener('podval:print_uploaded', function(e) {
        var d = (e && e.detail) ? e.detail : {};
        var payload = {
            slot:         d.slot || 'unknown',
            file_ext:     d.file_ext || '',
            file_size_kb: d.file_size_kb || 0
        };

        /* GA4 / Google Ads */
        if (typeof gtag === 'function') {
            gtag('event', 'print_upload', payload);
        }

        /* Facebook Pixel */
<?php if ( $fb_pixel_id ) : ?>
        if (typeof fbq === 'function') {
            fbq('trackCustom', 'PrintUpload', payload);
        }
<?php endif; ?>

        /* Яндекс.Метрика */
<?php if ( $ym_id ) : ?>
        if (typeof ym === 'function') {
            ym(<?php echo esc_js( $ym_id ); ?>, 'reachGoal', 'print_upload', payload);
        }
<?php endif; ?>
    });
})();
</script>
<?php
}

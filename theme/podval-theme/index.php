<?php
/**
 * Главная страница — index.php
 * Структура: Hero → Свой Дизайн → Новинки → Коллекция (теги) → Услуги → Ряды коллекций
 */
get_header();

// ═══════════════════════════════════════════
// DATA: подготовка данных для всех секций
// ═══════════════════════════════════════════

// Услуги — исключаем из товарных запросов
$uslugi_cat = get_term_by( 'slug', 'uslugi', 'product_cat' );
$uslugi_exclude_ids = [];
if ( $uslugi_cat ) {
    $uslugi_exclude_ids[] = $uslugi_cat->term_id;
    $ch = get_term_children( $uslugi_cat->term_id, 'product_cat' );
    if ( ! is_wp_error( $ch ) ) $uslugi_exclude_ids = array_merge( $uslugi_exclude_ids, $ch );
}

// М12 (База) — исключаем из подборок на главной (только на странице «Мерч под ключ»)
$base_cat_home = get_term_by( 'slug', 'base', 'product_cat' );
$base_exclude_ids = [];
if ( $base_cat_home ) {
    $base_exclude_ids[] = $base_cat_home->term_id;
    $ch_base = get_term_children( $base_cat_home->term_id, 'product_cat' );
    if ( ! is_wp_error( $ch_base ) ) $base_exclude_ids = array_merge( $base_exclude_ids, $ch_base );
}

// Хелпер: простой запрос товаров
if ( ! function_exists('podval_get_products') ) {
    function podval_get_products( $args = [] ) {
        $defaults = [
            'post_type' => 'product', 'post_status' => 'publish',
            'posts_per_page' => 8, 'orderby' => 'date', 'order' => 'DESC',
        ];
        $q = new WP_Query( array_merge( $defaults, $args ) );
        $products = [];
        while ( $q->have_posts() ) { $q->the_post(); $p = wc_get_product( get_the_ID() ); if ($p) $products[] = $p; }
        wp_reset_postdata();
        return $products;
    }
}

/**
 * Кэшированный запрос товаров — прежней версии
 * Кэширует только массив ID (не объекты — они тяжелые/несериализуемые).
 * Сами WC_Product достаются через wc_get_product() — это быстро благодаря object cache.
 *
 * @param array $args  WP_Query args
 * @param int   $ttl   TTL в секундах (по умолчанию 1 час)
 * @param string $key_prefix Префикс ключа кэша
 * @return WC_Product[]
 */
if ( ! function_exists('podval_get_products_cached') ) {
    function podval_get_products_cached( $args = [], $ttl = HOUR_IN_SECONDS, $key_prefix = 'podval_q_' ) {
        // Рандомные подборки тоже кэшируем — но порядок будет одинаковый в течение TTL.
        // Это OK: рандом для разнообразия между сессиями, не на каждый рендер.
        $cache_key = $key_prefix . md5( wp_json_encode( $args ) );
        $ids = get_transient( $cache_key );

        if ( false === $ids || ! is_array( $ids ) ) {
            $defaults = [
                'post_type' => 'product', 'post_status' => 'publish',
                'posts_per_page' => 8, 'orderby' => 'date', 'order' => 'DESC',
                'fields' => 'ids', // ВАЖНО: не вытягиваем post объекты — только ID
                'no_found_rows' => true, // отключаем COUNT(*) — экономия запроса
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ];
            $q = new WP_Query( array_merge( $defaults, $args, [ 'fields' => 'ids' ] ) );
            $ids = $q->posts ?: [];
            set_transient( $cache_key, $ids, $ttl );
        }

        // Сами объекты получаем через wc_get_product (object cache)
        $products = [];
        foreach ( $ids as $id ) {
            $p = wc_get_product( $id );
            if ( $p && $p->get_status() === 'publish' ) $products[] = $p;
        }
        return $products;
    }
}

// Хелпер: рендер карточки товара (компактная, для product-card template part)
if ( ! function_exists('podval_render_card') ) {
    function podval_render_card( $product ) {
        $GLOBALS['product'] = $product;
        $GLOBALS['post'] = get_post( $product->get_id() );
        setup_postdata( $GLOBALS['post'] );
        get_template_part( 'template-parts/content/product', 'card' );
        wp_reset_postdata();
    }
}

// ── Слайды hero (ключи из Customizer: podval_slide_{i}_*) ──
$slides_count = absint( get_theme_mod( 'podval_slides_count', 3 ) );
$slides_count = max( 1, min( 5, $slides_count ) );
$slides = [];
for ( $i = 1; $i <= $slides_count; $i++ ) {
    $title1 = get_theme_mod( "podval_slide_{$i}_title1", '' );
    if ( ! $title1 ) continue;
    $slides[] = [
        'bg'     => get_theme_mod( "podval_slide_{$i}_image", '' ),
        'label'  => get_theme_mod( "podval_slide_{$i}_label", '' ),
        'title1' => $title1,
        'title2' => get_theme_mod( "podval_slide_{$i}_title2", '' ),
        'desc'   => get_theme_mod( "podval_slide_{$i}_desc", '' ),
        'btn1_t' => get_theme_mod( "podval_slide_{$i}_btn1", '' ),
        'btn1_u' => get_theme_mod( "podval_slide_{$i}_btn1url", '#' ),
        'btn2_t' => get_theme_mod( "podval_slide_{$i}_btn2", '' ),
        'btn2_u' => get_theme_mod( "podval_slide_{$i}_btn2url", '#' ),
        'bg_pos_d' => trim( (string) get_theme_mod( "podval_slide_{$i}_bg_pos_d", '' ) ),
        'bg_pos_m' => trim( (string) get_theme_mod( "podval_slide_{$i}_bg_pos_m", '' ) ),
    ];
}
if ( empty( $slides ) ) {
    // Слайды не заданы: один слайд из inc/copy-data.php (home_hero) либо название бренда.
    $slides[] = array_merge( [
        'bg' => '', 'label' => '', 'title1' => podval_brand( 'logo_word_1' ), 'title2' => podval_brand( 'logo_word_2' ),
        'desc' => (string) podval_brand( 'tagline' ), 'btn1_t' => 'Каталог',
        'btn1_u' => podval_get_catalog_url(), 'btn2_t' => '', 'btn2_u' => '',
        'bg_pos_d' => '', 'bg_pos_m' => '',
    ], (array) podval_copy( 'home_hero' ) );
}

// ═══════════════════════════════════════════
// ANCHORS: подготовка списка якорей для мобильного sticky-меню
// Проверки идут через те же transient-кэшированные запросы, что и сами секции,
// поэтому повторный вызов — это HIT в transient (без SQL).
// ═══════════════════════════════════════════
$home_anchors = [];

// 1. Свой Дизайн
$_anchor_cd = podval_get_products_cached([
    'posts_per_page' => 12,
    'tax_query' => [[ 'taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => 'custom-design' ]],
    'orderby' => 'menu_order', 'order' => 'ASC',
], 6 * HOUR_IN_SECONDS);
if ( ! empty( $_anchor_cd ) ) {
    $home_anchors[] = [ 'id' => 'anchor-custom', 'label' => 'Свой дизайн' ];
}

// 2. Магазин — повторяем те же args, что в секции «Магазин»
$_anchor_shop_excl = array_merge(
    $uslugi_exclude_ids ?: [],
    $base_exclude_ids ?: [],
    [ get_term_by('slug', 'custom-design', 'product_cat') ? get_term_by('slug', 'custom-design', 'product_cat')->term_id : 0 ]
);
$_anchor_shop_excl = array_filter( $_anchor_shop_excl );
$_anchor_shop_args = [ 'posts_per_page' => 12, 'orderby' => 'rand' ];
if ( ! empty( $_anchor_shop_excl ) ) {
    $_anchor_shop_args['tax_query'] = [[ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $_anchor_shop_excl, 'operator' => 'NOT IN' ]];
}
if ( ! empty( podval_get_products_cached( $_anchor_shop_args, HOUR_IN_SECONDS ) ) ) {
    $home_anchors[] = [ 'id' => 'anchor-shop', 'label' => 'Магазин' ];
}

// 3. Коллекции — у блока коллекций свой transient (podval_home_collections_v1)
$_anchor_col = get_transient( 'podval_home_collections_v1' );
if ( ! is_array( $_anchor_col ) || ! empty( $_anchor_col['tags'] ) ) {
    // Если кэш пуст (первая загрузка) — показываем якорь по умолчанию;
    // секция сама решит выводиться или нет, но скорее всего теги есть.
    $home_anchors[] = [ 'id' => 'anchor-collections', 'label' => 'Коллекции' ];
}

// 4. Услуги
$_anchor_svc = function_exists( 'podval_sl_home_cards' ) ? podval_sl_home_cards() : [];
if ( empty( $_anchor_svc ) ) $_anchor_svc = podval_get_products_cached([
    'posts_per_page' => 4,
    'tax_query' => [[ 'taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => 'uslugi', 'include_children' => true ]],
    'orderby' => 'menu_order', 'order' => 'ASC',
], 6 * HOUR_IN_SECONDS);
if ( ! empty( $_anchor_svc ) ) {
    $home_anchors[] = [ 'id' => 'anchor-services', 'label' => 'Услуги' ];
}

if ( is_front_page() || is_home() ) :
?>

<!-- ========================================
     1. HERO SLIDER (self-contained)
     ======================================== -->
<?php if ( ! empty( $slides ) ) : ?>
<section class="hero" id="hero-slider">
    <?php foreach ( $slides as $idx => $slide ) :
        // Собираем inline-стили слайда: фон + опциональные фокус-переменные
        $slide_styles = [];
        if ( ! empty( $slide['bg'] ) ) {
            $slide_styles[] = '--slide-bg:url(' . esc_url( $slide['bg'] ) . ')';
        }
        if ( ! empty( $slide['bg_pos_d'] ) ) {
            $slide_styles[] = '--hero-bg-pos:' . esc_attr( $slide['bg_pos_d'] );
        }
        if ( ! empty( $slide['bg_pos_m'] ) ) {
            $slide_styles[] = '--hero-bg-pos-m:' . esc_attr( $slide['bg_pos_m'] );
        }
        $slide_style_attr = $slide_styles ? implode( ';', $slide_styles ) : '';
    ?>
    <div class="hero__slide <?php echo $idx === 0 ? 'is-active' : ''; ?>"
         style="<?php echo $slide_style_attr; ?>">
        <?php
        // LCP optimization: для ПЕРВОГО слайда рендерим реальный <img>
        // с fetchpriority="high" + srcset. Lighthouse находит LCP-элемент по тэгу <img>
        // и раньше, чем по background-image (который ждёт парсинга CSS).
        // Остальные слайды остаются на background-image — они вообще не нужны до swipe.
        if ( $idx === 0 && $slide['bg'] ) :
            // Получаем attachment_id один раз, кэшируем в transient на сутки
            $cache_key = 'podval_hero_first_slide_v1_' . md5( $slide['bg'] );
            $att_id    = get_transient( $cache_key );
            if ( false === $att_id ) {
                $att_id = attachment_url_to_postid( preg_replace( '/\.webp$/', '', $slide['bg'] ) );
                if ( ! $att_id ) $att_id = attachment_url_to_postid( $slide['bg'] );
                set_transient( $cache_key, (int) $att_id, DAY_IN_SECONDS );
            }
            if ( $att_id ) {
                echo wp_get_attachment_image( $att_id, 'podval-hero', false, array(
                    'class'         => 'hero__bg',
                    'alt'           => '',
                    'fetchpriority' => 'high',
                    'decoding'      => 'sync',
                    'loading'       => 'eager',
                    'sizes'         => '100vw',
                ) );
            } else {
                // Fallback если ID не нашли (например слайд не из медиа-библиотеки)
                ?>
                <img class="hero__bg" src="<?php echo esc_url( $slide['bg'] ); ?>"
                     alt="" fetchpriority="high" decoding="sync" loading="eager">
                <?php
            }
        else :
            // Все слайды кроме первого — фон через CSS.
            //
            // background-image намеренно НЕ пишется инлайном: браузер грузит
            // фоны у всех совпавших элементов, даже при opacity:0, и второй
            // слайд отъедал 95 КБ параллельно с LCP-картинкой. Правило
            // `.hero__slide.is-active .hero__bg{background-image:var(--slide-bg)}`
            // в старом critical.css включал фон только когда слайд стал активным.
        ?>
        <div class="hero__bg"></div>
        <?php endif; ?>
        <div class="hero__overlay"></div>
        <div class="hero__content">
            <div class="hero__inner container">
                <?php if ( $slide['label'] ) : ?>
                <div class="hero__label"><?php echo esc_html( $slide['label'] ); ?></div>
                <?php endif; ?>
                <?php
                /* H1 только на ПЕРВОМ слайде.
                   Все слайды присутствуют в DOM одновременно (скрыты через opacity),
                   поэтому раньше страница отдавала несколько <h1> — Google видел их все.
                   Остальные слайды рендерим как <div> с тем же классом: визуально
                   идентично, семантически корректно. */
                $hero_tag = ( $idx === 0 ) ? 'h1' : 'div';
                ?>
                <<?php echo $hero_tag; ?> class="hero__title">
                    <?php echo esc_html( $slide['title1'] ); ?><br>
                    <em><?php echo esc_html( $slide['title2'] ); ?></em>
                </<?php echo $hero_tag; ?>>
                <?php if ( $slide['desc'] ) : ?>
                <p class="hero__desc"><?php echo esc_html( $slide['desc'] ); ?></p>
                <?php endif; ?>
                <div class="hero__actions">
                    <?php if ( $slide['btn1_t'] ) : ?>
                    <a href="<?php echo esc_url( $slide['btn1_u'] ?: '#' ); ?>" class="btn btn--primary">
                        <?php echo esc_html( $slide['btn1_t'] ); ?>
                        <svg viewBox="0 0 14 14"><path d="M1 7h12M8 2l5 5-5 5"/></svg>
                    </a>
                    <?php endif; ?>
                    <?php if ( $slide['btn2_t'] ) : ?>
                    <a href="<?php echo esc_url( $slide['btn2_u'] ?: '#' ); ?>" class="btn btn--outline">
                        <?php echo esc_html( $slide['btn2_t'] ); ?>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if ( count( $slides ) > 1 ) : ?>
    <div class="hero__dots">
        <?php foreach ( $slides as $idx => $s ) : ?>
        <button class="hero__dot <?php echo $idx === 0 ? 'is-active' : ''; ?>"
                data-index="<?php echo $idx; ?>" aria-label="Слайд <?php echo $idx + 1; ?>"></button>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <script>
    (function(){
        var slides=document.querySelectorAll('#hero-slider .hero__slide');
        var dots=document.querySelectorAll('#hero-slider .hero__dot');
        if(slides.length<2)return;
        var current=0, total=slides.length, timer;

        /* Человек попросил меньше движения — слайды листаются только точками.
           Проверка объявлена до auto(), иначе клик по точке всё равно
           заводил бы интервал. */
        var calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        function go(n){
            slides[current].classList.remove('is-active');
            if(dots[current])dots[current].classList.remove('is-active');
            current=(n+total)%total;
            slides[current].classList.add('is-active');
            if(dots[current])dots[current].classList.add('is-active');
        }
        function auto(){
            if(calm)return;
            clearInterval(timer);
            timer=setInterval(function(){go(current+1)},6000);
        }
        dots.forEach(function(d){
            d.addEventListener('click',function(){
                go(parseInt(this.getAttribute('data-index')));
                auto();
            });
        });

        /* Автопрокрутка ждёт первого действия человека.
           Причина метрическая и вполне физическая: пока страницу не тронули,
           браузер продолжает переписывать LCP при каждой новой большой
           отрисовке. Слайд, всплывающий на шестой секунде, становился новым
           LCP-кандидатом и уводил метрику с ~3 c к 8.9 c — притом что человек
           к этому моменту давно всё увидел. */
        if (calm) return;

        var started = false;
        function start(){
            if(started)return;
            started = true;
            auto();
        }
        ['pointerdown','keydown','wheel','touchstart','scroll'].forEach(function(ev){
            window.addEventListener(ev, start, { once:true, passive:true });
        });
    })();
    </script>
</section>
<?php endif; ?>

</section>
<?php podval_render_decor(1); ?>


<!-- ========================================
     STICKY MOBILE ANCHORS — быстрая навигация по разделам главной (только мобайл)
     ======================================== -->
<?php if ( count( $home_anchors ) > 1 ) : ?>
<nav class="home-anchors" id="home-anchors" aria-label="Разделы главной">
    <div class="home-anchors__inner">
        <?php foreach ( $home_anchors as $a ) : ?>
        <a href="#<?php echo esc_attr( $a['id'] ); ?>"
           class="home-anchors__item"
           data-anchor="<?php echo esc_attr( $a['id'] ); ?>">
            <?php echo esc_html( $a['label'] ); ?>
        </a>
        <?php endforeach; ?>
    </div>
</nav>
<?php endif; ?>


<!-- ========================================
     2. СВОЙ ДИЗАЙН — единая сетка 4×2 = 8 cells (2 hero + до 6 templates), plug в стиле see-all-card
     ========================================
     порядок — menu_order (WC → Товары → Сортировка, drag-n-drop).
     первые N фиксированы, остальные ячейки — случайные на каждый
     показ (см. ниже). Первые 2 ячейки — hero.
     ======================================== -->
<?php
/*
 * первые N карточек (по умолчанию 3) — фиксированные, в порядке
 * WC → Товары → Сортировка (menu_order). Остальные ячейки сетки (до 8)
 * заполняются случайными товарами раздела на КАЖДОМ показе.
 *
 * Рандом — на клиенте: главная отдаётся из кэша страницы (WP-Optimize),
 * и серверный 'orderby' => 'rand' замораживался бы на весь срок кэша.
 * Сервер рендерит фиксированные + пул остальных (лишние — hidden),
 * инлайн-скрипт сразу после сетки, до первой отрисовки, перемешивает
 * пул, показывает нужное число и удаляет остальное — без сдвига вёрстки.
 * Без JS видны первые 8 по menu_order, как раньше.
 *
 * Настройки: Внешний вид → Настроить → Podval Print → «Свой дизайн на главной».
 */
$cd_cells   = 8;
$cd_fixed_n = max( 0, min( $cd_cells, (int) get_theme_mod( 'podval_cd_fixed', 3 ) ) );
$cd_shuffle = (bool) get_theme_mod( 'podval_cd_shuffle', true );

$cd_all = podval_get_products_cached([
    'posts_per_page' => $cd_shuffle ? 30 : $cd_cells,
    'tax_query' => [[ 'taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => 'custom-design' ]],
    'orderby' => 'menu_order', 'order' => 'ASC',
], 6 * HOUR_IN_SECONDS);

$cd_fixed = array_slice( $cd_all, 0, $cd_fixed_n );
$cd_pool  = array_slice( $cd_all, $cd_fixed_n );
$cd_slots = max( 0, min( $cd_cells - count( $cd_fixed ), count( $cd_pool ) ) ); // случайных ячеек
$cd_shown = count( $cd_fixed ) + $cd_slots;
$cd_rand  = $cd_shuffle && count( $cd_pool ) > $cd_slots;

// Первые 2 ячейки — hero (крупные на мобиле), дальше обычные.
$cd_templates_count = max( 0, $cd_shown - 2 );

// Plug-плитка — когда 2-й ряд не полон (шаблонов < 6). На мобиле скрыта CSS.
$cd_show_plug = ( $cd_templates_count > 0 && $cd_templates_count < 6 );

$cd_term = get_term_by( 'slug', 'custom-design', 'product_cat' );
$cd_term_url = ( $cd_term && ! is_wp_error( $cd_term ) ) ? get_term_link( $cd_term ) : '';

// Для совместимости со старым кодом якорей выше
$cd_products = $cd_all;
?>
<?php if ( $cd_shown > 0 ) : ?>
<section class="section cd-section" id="anchor-custom">
    <div class="container">
        <div class="section-head">
            <h2 class="section-head__title">Свой Дизайн</h2>
            <?php if ( $cd_term_url ) : ?>
            <a href="<?php echo esc_url( $cd_term_url ); ?>" class="section-head__link">
                Все варианты <?php echo podval_icon( 'arrow' ); ?>
            </a>
            <?php endif; ?>
        </div>

        <div class="cd-grid<?php echo $cd_show_plug ? ' cd-grid--with-plug' : ''; ?>" id="cd-grid" data-cd-slots="<?php echo (int) $cd_slots; ?>">
            <?php $cd_i = 0; foreach ( $cd_fixed as $p ) : ?>
            <div class="cd-grid__card<?php echo $cd_i < 2 ? ' cd-grid__card--hero' : ''; ?>">
                <?php podval_render_card( $p ); ?>
            </div>
            <?php $cd_i++; endforeach; ?>

            <?php foreach ( $cd_pool as $k => $p ) :
                if ( ! $cd_rand && $k >= $cd_slots ) break; ?>
            <div class="cd-grid__card<?php echo $cd_i < 2 ? ' cd-grid__card--hero' : ''; ?>"<?php echo $cd_rand ? ' data-cd-rand' : ''; ?><?php echo ( $k >= $cd_slots ) ? ' hidden' : ''; ?>>
                <?php podval_render_card( $p ); ?>
            </div>
            <?php $cd_i++; endforeach; ?>

            <?php if ( $cd_show_plug && $cd_term_url ) : ?>
            <div class="cd-grid__card cd-grid__card--plug">
                <a href="<?php echo esc_url( $cd_term_url ); ?>" class="see-all-card">
                    <span class="see-all-card__icon">
                        <svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                    </span>
                    <span class="see-all-card__label">Все шаблоны</span>
                </a>
            </div>
            <?php endif; ?>
        </div>
        <?php if ( $cd_rand ) : ?>
        <script>
        (function () {
            var g = document.getElementById('cd-grid');
            if (!g) return;
            var n = parseInt(g.getAttribute('data-cd-slots'), 10) || 0;
            var pool = Array.prototype.slice.call(g.querySelectorAll('[data-cd-rand]'));
            for (var i = pool.length - 1; i > 0; i--) {
                var j = Math.floor(Math.random() * (i + 1));
                var t = pool[i]; pool[i] = pool[j]; pool[j] = t;
            }
            var plug = g.querySelector('.cd-grid__card--plug');
            pool.forEach(function (c, k) {
                if (k < n) { c.hidden = false; g.insertBefore(c, plug); }
                else { c.parentNode.removeChild(c); }
            });
            // hero — по позиции, а не по товару.
            var cards = g.querySelectorAll('.cd-grid__card:not(.cd-grid__card--plug)');
            for (var m = 0; m < cards.length; m++) {
                cards[m].classList.toggle('cd-grid__card--hero', m < 2);
            }
        })();
        </script>
        <?php endif; ?>

        <?php if ( $cd_term_url ) : ?>
        <div class="cd-cta-wrap">
            <a href="<?php echo esc_url( $cd_term_url ); ?>" class="btn btn--primary cd-cta">
                Все шаблоны <?php echo podval_icon( 'arrow' ); ?>
            </a>
        </div>
        <?php endif; ?>

    </div>
</section>
<?php endif; ?>

<!-- Декор: слот 2 -->
<?php podval_render_decor(2); ?>
<?php if ( ! get_theme_mod('podval_decor_2_enabled', false) ) : ?>
<div class="home-decor home-decor--stripes"></div>
<?php endif; ?>


<!-- ========================================
     3. МАГАЗИН — сетка 4×3 (рандом, без Свой Дизайн и Услуги)
     ======================================== -->
<?php
$shop_exclude_cats = array_merge(
    $uslugi_exclude_ids ?: [],
    $base_exclude_ids ?: [],
    [ get_term_by('slug', 'custom-design', 'product_cat') ? get_term_by('slug', 'custom-design', 'product_cat')->term_id : 0 ]
);
$shop_exclude_cats = array_filter( $shop_exclude_cats );
$shop_args = [
    'posts_per_page' => 12,
    'orderby' => 'rand',
];
if ( ! empty( $shop_exclude_cats ) ) {
    $shop_args['tax_query'] = [[ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $shop_exclude_cats, 'operator' => 'NOT IN' ]];
}
$shop_products = podval_get_products_cached( $shop_args, HOUR_IN_SECONDS ); // rand → 1h TTL
?>
<?php if ( ! empty( $shop_products ) ) : ?>
<section class="section section--alt lazy-section" id="anchor-shop">
    <div class="container">
        <div class="section-head">
            <h2 class="section-head__title">Магазин</h2>
            <a href="<?php echo esc_url( podval_get_catalog_url() ); ?>" class="section-head__link">
                Все товары <?php echo podval_icon( 'arrow' ); ?>
            </a>
        </div>
        <div class="catalog-grid catalog-grid--home">
            <?php foreach ( $shop_products as $p ) podval_render_card( $p ); ?>
        </div>
    </div>
</section>
<?php endif; ?>
<?php podval_render_decor(3); ?>


<!-- ========================================
     4. КОЛЛЕКЦИИ — тег-грид (все теги из всех коллекций)
     ======================================== -->
<?php
// тяжёлый блок (get_terms + get_posts(-1) + wp_get_object_terms)
// кэшируем в один transient. Раньше при каждом не-cached рендере главной
// перебирали ВСЕ товары всех коллекций (1000+) для построения списка тегов.
$collections_cache_key = 'podval_home_collections_v1';
$collections_cached = get_transient( $collections_cache_key );

if ( false === $collections_cached || ! is_array( $collections_cached ) ) {
    // Берём дочерние категории «Коллекции» (term_id=43)
    $collections_parent = get_term_by( 'slug', '%d1%81ollections', 'product_cat' );
    if ( ! $collections_parent ) $collections_parent = get_term( 43, 'product_cat' );

    $collection_cats = [];
    if ( $collections_parent && ! is_wp_error( $collections_parent ) ) {
        $collection_cats = get_terms([
            'taxonomy' => 'product_cat',
            'parent'   => $collections_parent->term_id,
            'hide_empty' => true,
            'orderby' => 'count', 'order' => 'DESC',
        ]);
        if ( is_wp_error( $collection_cats ) ) $collection_cats = [];
    }

    // Собираем ВСЕ теги из ВСЕХ коллекций
    $all_collection_tag_ids = [];
    $all_collection_tags = [];
    if ( ! empty( $collection_cats ) ) {
        $all_col_cat_ids = wp_list_pluck( $collection_cats, 'term_id' );
        $tag_tax_query = [
            'relation' => 'AND',
            [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $all_col_cat_ids ],
        ];
        if ( ! empty( $base_exclude_ids ) ) {
            $tag_tax_query[] = [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $base_exclude_ids, 'operator' => 'NOT IN' ];
        }
        $all_col_product_ids = get_posts([
            'post_type' => 'product', 'posts_per_page' => -1, 'fields' => 'ids',
            'tax_query' => $tag_tax_query,
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);
        if ( ! empty( $all_col_product_ids ) ) {
            $raw_tags = wp_get_object_terms( $all_col_product_ids, 'product_tag', [ 'orderby' => 'count', 'order' => 'DESC' ] );
            if ( ! is_wp_error( $raw_tags ) ) {
                $col_names = array_map( function($c) { return mb_strtolower($c->name); }, $collection_cats );
                foreach ( $raw_tags as $t ) {
                    if ( in_array( mb_strtolower( $t->name ), $col_names, true ) ) continue;
                    if ( isset( $all_collection_tag_ids[ $t->term_id ] ) ) continue;
                    $all_collection_tag_ids[ $t->term_id ] = true;
                    $all_collection_tags[] = $t;
                }
            }
        }
    }

    $collections_link = $collections_parent && ! is_wp_error( $collections_parent ) ? get_term_link( $collections_parent ) : '';

    $collections_cached = [
        'cats'  => $collection_cats,
        'tags'  => $all_collection_tags,
        'link'  => $collections_link,
    ];
    // 12 часов TTL — теги/категории редко меняются
    set_transient( $collections_cache_key, $collections_cached, 12 * HOUR_IN_SECONDS );
}

$collection_cats     = $collections_cached['cats'];
$all_collection_tags = $collections_cached['tags'];
$collections_link    = $collections_cached['link'];

// Случайный порядок тегов в каждом рендере (это просто массив, дешево)
shuffle( $all_collection_tags );
$all_collection_tags = array_slice( $all_collection_tags, 0, 10 );
?>
<?php if ( ! empty( $all_collection_tags ) ) : ?>
<section class="section home-collection lazy-section" id="anchor-collections">
    <div class="container">
        <div class="section-head">
            <h2 class="section-head__title">Коллекции</h2>
            <?php if ( $collections_link && ! is_wp_error( $collections_link ) ) : ?>
            <a href="<?php echo esc_url( $collections_link ); ?>" class="section-head__link">
                Смотреть все <?php echo podval_icon( 'arrow' ); ?>
            </a>
            <?php endif; ?>
        </div>

        <div class="tag-grid">
            <?php foreach ( $all_collection_tags as $tag ) :
                $tag_data = podval_get_tag_image_data( $tag );
                $tag_url  = get_term_link( $tag );
                if ( is_wp_error( $tag_url ) ) continue;
                if ( ! $tag_data ) continue;
            ?>
            <a href="<?php echo esc_url( $tag_url ); ?>" class="tag-card">
                <?php if ( ! empty( $tag_data['id'] ) ) :
                    // используем 'podval-tag' (480×360, кроп 4:3) — точно под слот.
                    // Раньше был 'medium' — но WP отдавал в srcset ВСЕ доступные размеры
                    // (включая large 1024 и оригинал 683×1024), браузер на мобиле с DPR=2
                    // выбирал large вместо medium. Контейнер ~190px → нужен ~380px физических.
                    echo wp_get_attachment_image( $tag_data['id'], 'podval-tag', false, array(
                        'class'    => 'tag-card__bg',
                        'alt'      => '',
                        'loading'  => 'lazy',
                        'decoding' => 'async',
                        'sizes'    => '(max-width: 480px) 50vw, (max-width: 900px) 33vw, 20vw',
                    ) );
                else :
                    // Fallback: ID не нашли (URL из старого Customizer) — обычный <img>
                ?>
                <img src="<?php echo esc_url( $tag_data['url'] ); ?>"
                     class="tag-card__bg" alt=""
                     loading="lazy" decoding="async"
                     width="480" height="360">
                <?php endif; ?>
                <div class="tag-card__overlay"></div>
                <span class="tag-card__name"><?php echo esc_html( $tag->name ); ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>
<?php podval_render_decor(4); ?>


<!-- ========================================
     5. УСЛУГИ
     ======================================== -->
<?php
// источник — лендинги с отметкой «Плитка на главной» (до 4).
// Пока их нет — прежние 4 товара-услуги. Разметка карточки та же.
$svc_landing_cards = function_exists( 'podval_sl_home_cards' ) ? podval_sl_home_cards() : [];
$svc_products_arr  = empty( $svc_landing_cards ) ? podval_get_products_cached([
    'posts_per_page' => 4,
    'tax_query' => [[ 'taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => 'uslugi', 'include_children' => true ]],
    'orderby' => 'menu_order', 'order' => 'ASC',
], 6 * HOUR_IN_SECONDS) : [];
$uslugi_term = get_term_by( 'slug', 'uslugi', 'product_cat' );
?>
<?php if ( ! empty( $svc_landing_cards ) || ! empty( $svc_products_arr ) ) : ?>
<section class="section section--alt lazy-section" id="anchor-services">
    <div class="container">
        <div class="section-head">
            <h2 class="section-head__title">Наше производство</h2>
            <?php if ( $uslugi_term ) : ?>
            <a href="<?php echo esc_url( get_term_link( $uslugi_term ) ); ?>" class="section-head__link">
                Все услуги <?php echo podval_icon( 'arrow' ); ?>
            </a>
            <?php endif; ?>
        </div>
        <div class="podval-svc__cards">
            <?php foreach ( $svc_landing_cards as $svc_row ) {
                get_template_part( 'template-parts/service/landing-card', null, [ 'row' => $svc_row, 'heading' => 'h3' ] );
            } ?>
            <?php foreach ( $svc_products_arr as $svc_product ) :
                $svc_id = $svc_product->get_id();
                $svc_thumb = get_the_post_thumbnail_url( $svc_id, 'large' );
                $svc_name  = $svc_product->get_name();
                $svc_excerpt = $svc_product->get_short_description() ?: wp_trim_words( $svc_product->get_description(), 20 );
            ?>
            <a href="<?php echo esc_url( get_permalink( $svc_id ) ); ?>" class="podval-svc__service-card">
                <?php if ( $svc_thumb ) : ?>
                <div class="podval-svc__service-img" style="background-image:url(<?php echo esc_url( $svc_thumb ); ?>)"></div>
                <?php else : ?>
                <div class="podval-svc__service-img podval-svc__service-img--placeholder"><span><?php echo esc_html( mb_substr( $svc_name, 0, 1 ) ); ?></span></div>
                <?php endif; ?>
                <div class="podval-svc__service-info">
                    <h3 class="podval-svc__service-name"><?php echo esc_html( $svc_name ); ?></h3>
                    <?php if ( $svc_excerpt ) : ?>
                    <p class="podval-svc__service-desc"><?php echo esc_html( wp_strip_all_tags( $svc_excerpt ) ); ?></p>
                    <?php endif; ?>
                    <span class="podval-svc__service-link">Подробнее →</span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Декор: слот 5 -->
<?php podval_render_decor(5); ?>
<?php if ( ! get_theme_mod('podval_decor_5_enabled', false) ) : ?>
<div class="home-decor home-decor--stripes"></div>
<?php endif; ?>


<!-- ========================================
     6. РЯДЫ КОЛЛЕКЦИЙ — горизонтальный скролл
     ======================================== -->
<?php if ( ! empty( $collection_cats ) ) : ?>
<?php foreach ( $collection_cats as $col_cat ) :
    $col_tax_query = [
        'relation' => 'AND',
        [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $col_cat->term_id ],
    ];
    if ( ! empty( $base_exclude_ids ) ) {
        $col_tax_query[] = [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $base_exclude_ids, 'operator' => 'NOT IN' ];
    }
    $col_products = podval_get_products_cached([
        'posts_per_page' => 20,
        'orderby'        => 'rand',
        'tax_query'      => $col_tax_query,
    ], HOUR_IN_SECONDS); // rand → 1h TTL
    if ( empty( $col_products ) ) continue;
?>
<section class="section home-row home-row--collection lazy-section lazy-section--tall">
    <div class="container">
        <div class="section-head section-head--center">
            <h2 class="section-head__title"><?php echo esc_html( $col_cat->name ); ?></h2>
            <a href="<?php echo esc_url( get_term_link( $col_cat ) ); ?>" class="section-head__link">
                Смотреть все <?php echo podval_icon( 'arrow' ); ?>
            </a>
        </div>
    </div>
    <div class="home-row__wrap">
        <button class="home-row__arrow home-row__arrow--left is-hidden" aria-label="Прокрутить влево">
            <svg viewBox="0 0 24 24"><polyline points="15 6 9 12 15 18"/></svg>
        </button>
        <div class="home-row__scroll">
            <div class="home-row__track">
                <?php foreach ( $col_products as $p ) : ?>
                <div class="home-row__card">
                    <?php podval_render_card( $p ); ?>
                </div>
                <?php endforeach; ?>
                <div class="home-row__card home-row__card--see-all">
                    <a href="<?php echo esc_url( get_term_link( $col_cat ) ); ?>" class="see-all-card">
                        <span class="see-all-card__icon">
                            <svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                        </span>
                        <span class="see-all-card__label">Смотреть все</span>
                    </a>
                </div>
            </div>
        </div>
        <button class="home-row__arrow home-row__arrow--right" aria-label="Прокрутить вправо">
            <svg viewBox="0 0 24 24"><polyline points="9 6 15 12 9 18"/></svg>
        </button>
    </div>
</section>
<?php endforeach; ?>
<?php endif; ?>
<?php podval_render_decor(6); ?>


<!-- ========================================
     ПРОМО БАННЕР
     ======================================== -->
<?php
$promo_title   = get_theme_mod( 'promo_banner_title', '' );
$promo_sub     = get_theme_mod( 'promo_banner_sub', '' );
$promo_btn_txt = get_theme_mod( 'promo_banner_btn', 'Узнать подробнее' );
$promo_btn_url = get_theme_mod( 'promo_banner_url', podval_get_catalog_url() );
if ( '' !== trim( (string) $promo_title ) ) :
?>
<div class="promo-banner lazy-section lazy-section--short">
    <div>
        <div class="promo-banner__title"><?php echo nl2br( esc_html( $promo_title ) ); ?></div>
        <div class="promo-banner__sub"><?php echo esc_html( $promo_sub ); ?></div>
    </div>
    <a href="<?php echo esc_url( $promo_btn_url ); ?>" class="btn btn--dark">
        <?php echo esc_html( $promo_btn_txt ); ?> →
    </a>
</div>
<?php endif; // promo banner ?>

<?php else : ?>
<!-- FALLBACK: обычный wp loop -->
<div class="container section">
    <?php if ( have_posts() ) : ?>
        <div class="section-head"><h1 class="section-head__title"><?php the_archive_title(); ?></h1></div>
        <div class="catalog-grid">
            <?php while ( have_posts() ) : the_post();
                $product = wc_get_product( get_the_ID() );
                if ( $product ) get_template_part( 'template-parts/content/product', 'card' );
            endwhile; ?>
        </div>
        <?php the_posts_pagination(); ?>
    <?php else : ?>
        <p>Записей не найдено.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php get_footer(); ?>

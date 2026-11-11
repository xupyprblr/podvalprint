  </main><!-- /#main-content -->
</div><!-- /.site-wrapper -->

<!-- ===== НИЖНЯЯ БЕГУЩАЯ СТРОКА (теги) ===== -->
<?php
  $strip_source = get_theme_mod( 'podval_strip_source', 'tags' ); // 'tags' | 'custom'

  $strip_cache_key = 'podval_footer_strip_' . $strip_source;
  $strip_items = get_transient( $strip_cache_key );

  if ( $strip_items === false ) {
  $strip_items  = [];

  if ( $strip_source === 'custom' ) {
    // Ручной список из Customizer: "Текст|slug_тэга, Текст2|slug2, ..."
    $raw = get_theme_mod( 'podval_strip_custom', '' );
    foreach ( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) as $entry ) {
      $parts = array_map( 'trim', explode( '|', $entry ) );
      $label = $parts[0] ?? '';
      $slug  = $parts[1] ?? sanitize_title( $label );
      if ( $label ) {
        // Пробуем найти тэг товара или product_cat
        $term = get_term_by( 'slug', $slug, 'product_tag' )
             ?: get_term_by( 'slug', $slug, 'product_cat' )
             ?: get_term_by( 'slug', $slug, 'product_brand' );
        $strip_items[] = [
          'label' => strtoupper( $label ),
          'url'   => $term ? get_term_link( $term ) : add_query_arg( 'product_tag', $slug, podval_get_catalog_url() ),
        ];
      }
    }
  } else {
    // Автоматически — тэги товаров WooCommerce
    $tags = get_terms( [
      'taxonomy'   => 'product_tag',
      'hide_empty' => true,
      'number'     => 20,
      'orderby'    => 'count',
      'order'      => 'DESC',
    ] );
    if ( ! is_wp_error( $tags ) && ! empty( $tags ) ) {
      foreach ( $tags as $tag ) {
        $strip_items[] = [
          'label' => strtoupper( $tag->name ),
          'url'   => get_term_link( $tag ),
        ];
      }
    } else {
      // Дефолт если тэгов нет
      $fallback = (array) podval_copy( 'ticker', array() );
      foreach ( $fallback as $name ) {
        $strip_items[] = [ 'label' => $name, 'url' => '#' ];
      }
    }
  }

  set_transient( $strip_cache_key, $strip_items, 6 * HOUR_IN_SECONDS );
  } // end !$strip_items cached

  // Дублируем достаточно раз чтобы гарантированно заполнить экран
  // Минимум 30 элементов в одной половине
  $min_items = 30;
  $strip_fill = $strip_items;
  while ( count( $strip_fill ) < $min_items && ! empty( $strip_items ) ) {
      $strip_fill = array_merge( $strip_fill, $strip_items );
  }
?>
<div class="brand-strip">
  <div class="brand-strip__track">
    <div class="brand-strip__half">
      <?php foreach ( $strip_fill as $item ) : ?>
      <a href="<?php echo esc_url( $item['url'] ); ?>" class="brand-strip__item">
        <?php echo esc_html( $item['label'] ); ?>
        <span class="brand-strip__sep">•</span>
      </a>
      <?php endforeach; ?>
    </div>
    <div class="brand-strip__half" aria-hidden="true">
      <?php foreach ( $strip_fill as $item ) : ?>
      <a href="<?php echo esc_url( $item['url'] ); ?>" class="brand-strip__item" tabindex="-1">
        <?php echo esc_html( $item['label'] ); ?>
        <span class="brand-strip__sep">•</span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ===== FOOTER ===== -->
<?php
/* Читаем все данные из Customizer */
// Кол.1 — Юридическая
$footer_logo_text  = get_bloginfo('name');
$footer_legal_info = get_theme_mod( 'podval_footer_legal_info', implode( "\n", array_filter( array( podval_brand( 'legal_name' ), podval_brand( 'unp' ) ? 'УНП ' . podval_brand( 'unp' ) : '', podval_brand( 'legal_address' ), podval_brand( 'trade_register' ) ) ) ) );

// Кол.2 — Режим работы
$footer_hours_title = get_theme_mod( 'podval_footer_hours_title', 'Режим работы' );
$footer_hours       = get_theme_mod( 'podval_footer_hours',       "Пн–Пт: 11:00–21:00\nСб: 12:00–18:00\nВс: выходной" );
$footer_hours_note  = get_theme_mod( 'podval_footer_hours_note',  '' );

// Кол.3 — Информация (до 6 ссылок)
$footer_info_title = get_theme_mod( 'podval_footer_info_title', 'Информация' );
$footer_info_links = [];
for ( $i = 1; $i <= 6; $i++ ) {
    $lbl = get_theme_mod( "podval_footer_link{$i}_label", '' );
    $url = get_theme_mod( "podval_footer_link{$i}_url",   '' );
    if ( $lbl ) {
        // Делаем ссылки портабельными: если URL содержит текущий домен — извлекаем путь
        if ( $url ) {
            $site_host = wp_parse_url( home_url(), PHP_URL_HOST );
            $link_host = wp_parse_url( $url, PHP_URL_HOST );
            if ( $link_host && $link_host === $site_host ) {
                $path = wp_parse_url( $url, PHP_URL_PATH ) ?: '/';
                $url  = home_url( $path );
            }
        }
        $footer_info_links[] = [ 'label' => $lbl, 'url' => $url ?: '#' ];
    }
}
// Дефолтные ссылки если пусто
if ( empty( $footer_info_links ) ) {
    $footer_info_links = [
        [ 'label' => 'О нас',                      'url' => podval_url( 'about' ) ],
        [ 'label' => 'Доставка и оплата',           'url' => podval_url( 'delivery' ) ],
        [ 'label' => 'Публичная оферта',            'url' => podval_url( 'oferta' ) ],
        [ 'label' => 'Политика конфиденциальности', 'url' => podval_url( 'privacy' ) ],
        [ 'label' => 'Контакты',                    'url' => podval_url( 'about', 'contacts' ) ],
    ];
}

// Кол.4 — Контакты
$footer_contacts_title = get_theme_mod( 'podval_footer_contacts_title', 'Контакты' );
$footer_phone     = get_theme_mod( 'podval_footer_phone',    '' );
$footer_email     = get_theme_mod( 'podval_footer_email',    '' );
$footer_tg        = get_theme_mod( 'podval_footer_tg',       '' );
$footer_ig        = get_theme_mod( 'podval_footer_ig',       '' );
$footer_yt        = get_theme_mod( 'podval_footer_yt',       '' );
$footer_tt        = get_theme_mod( 'podval_footer_tt',       '' );
$footer_maps      = get_theme_mod( 'podval_footer_maps',     '' );
$footer_address   = get_theme_mod( 'podval_footer_address',  '' );

// Подвал
$footer_bottom_left  = get_theme_mod( 'podval_footer_bottom_left',  trim( podval_brand( 'legal_name' ) . ( podval_brand( 'unp' ) ? ' — УНП ' . podval_brand( 'unp' ) : '' ) ) );
$footer_bottom_right = get_theme_mod( 'podval_footer_bottom_right', '' ); // если пусто — авто год + название
?>

<footer class="site-footer" id="colophon">
    <div class="container">
        <?php
        // колонка «Печать на одежде» — до 6 опубликованных лендингов.
        // Нет лендингов — колонки нет, сетка остаётся 4-колоночной.
        $footer_sl_links = function_exists( 'podval_sl_footer_links' ) ? podval_sl_footer_links( 6 ) : array();
        ?>
        <div class="site-footer__grid<?php echo $footer_sl_links ? ' site-footer__grid--svc' : ''; ?>">

            <!-- КОЛ 1: Юридическая информация -->
            <div class="site-footer__col site-footer__col--brand">
                <a href="<?php echo esc_url( home_url('/') ); ?>" class="site-footer__logo-link" aria-label="<?php echo esc_attr( get_bloginfo('name') ); ?> — на главную">
                    <?php if ( has_custom_logo() ) : the_custom_logo();
                    else : echo '<span class="site-footer__logo-text">' . esc_html( $footer_logo_text ) . '</span>';
                    endif; ?>
                </a>
                <div class="site-footer__legal-block">
                    <?php
                    $lines = explode( "\n", $footer_legal_info );
                    foreach ( $lines as $line ) {
                        if ( trim($line) ) echo '<p>' . esc_html( trim($line) ) . '</p>';
                    }
                    ?>
                </div>
            </div>

            <!-- КОЛ 2: Режим работы (на мобиле — аккордеон) -->
            <div class="site-footer__col site-footer__col--collapsible">
                <h2 class="site-footer__col-title js-footer-toggle"><?php echo esc_html( $footer_hours_title ); ?></h2>
                <div class="site-footer__col-body">
                    <div class="site-footer__hours">
                        <?php
                        $hlines = explode( "\n", $footer_hours );
                        foreach ( $hlines as $hl ) {
                            if ( trim($hl) ) echo '<div class="site-footer__hours-row">' . esc_html( trim($hl) ) . '</div>';
                        }
                        ?>
                    </div>
                    <?php if ( $footer_hours_note ) : ?>
                    <p class="site-footer__hours-note"><?php echo esc_html( $footer_hours_note ); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- КОЛ 3: Информация + ссылки (на мобиле — аккордеон) -->
            <div class="site-footer__col site-footer__col--collapsible">
                <h2 class="site-footer__col-title js-footer-toggle"><?php echo esc_html( $footer_info_title ); ?></h2>
                <div class="site-footer__col-body">
                    <ul class="site-footer__links">
                        <?php foreach ( $footer_info_links as $link ) : ?>
                        <li><a href="<?php echo esc_url( $link['url'] ); ?>"><?php echo esc_html( $link['label'] ); ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>

            <?php if ( $footer_sl_links ) : ?>
            <!-- КОЛ 3б: Печать на одежде — лендинги услуг (на мобиле — аккордеон) -->
            <div class="site-footer__col site-footer__col--collapsible site-footer__col--svc">
                <h2 class="site-footer__col-title js-footer-toggle">Печать на одежде</h2>
                <div class="site-footer__col-body">
                    <ul class="site-footer__links">
                        <?php foreach ( $footer_sl_links as $sl_link ) : ?>
                        <li><a href="<?php echo esc_url( $sl_link['url'] ); ?>"><?php echo esc_html( $sl_link['short_title'] !== '' ? $sl_link['short_title'] : $sl_link['title'] ); ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <?php endif; ?>

            <!-- КОЛ 4: Контакты (всегда раскрыта) -->
            <div class="site-footer__col site-footer__col--contacts">
                <h2 class="site-footer__col-title"><?php echo esc_html( $footer_contacts_title ); ?></h2>

                <?php if ( $footer_phone ) :
                    $tel = preg_replace('/[^\d+]/', '', $footer_phone); ?>
                <a href="tel:<?php echo esc_attr($tel); ?>" class="site-footer__contact-phone">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 1.27h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L7.91 8.91a16 16 0 0 0 6 6l.91-.91a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 21.73 16.92z"/></svg>
                    <?php echo esc_html( $footer_phone ); ?>
                </a>
                <?php endif; ?>

                <?php if ( $footer_email ) : ?>
                <a href="mailto:<?php echo esc_attr($footer_email); ?>" class="site-footer__contact-email">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    <?php echo esc_html( $footer_email ); ?>
                </a>
                <?php endif; ?>

                <?php if ( $footer_address ) : ?>
                <p class="site-footer__address"><?php echo esc_html( $footer_address ); ?></p>
                <?php endif; ?>

                <!-- Иконки соцсетей -->
                <div class="site-footer__socials">

                    <?php if ( $footer_tg ) : ?>
                    <a href="<?php echo esc_url($footer_tg); ?>" class="footer-social-btn footer-social-btn--tg" aria-label="Telegram" target="_blank" rel="noopener">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.894 8.221-1.97 9.28c-.145.658-.537.818-1.084.508l-3-2.21-1.447 1.394c-.16.16-.295.295-.605.295l.213-3.053 5.56-5.023c.242-.213-.054-.333-.373-.12L7.17 13.6l-2.95-.924c-.64-.204-.657-.64.136-.954l11.57-4.461c.537-.194 1.006.131.968.96z"/></svg>
                    </a>
                    <?php endif; ?>

                    <?php if ( $footer_ig ) : ?>
                    <a href="<?php echo esc_url($footer_ig); ?>" class="footer-social-btn footer-social-btn--ig" aria-label="Instagram" target="_blank" rel="noopener">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg>
                    </a>
                    <?php endif; ?>

                    <?php if ( $footer_yt ) : ?>
                    <a href="<?php echo esc_url($footer_yt); ?>" class="footer-social-btn footer-social-btn--yt" aria-label="YouTube" target="_blank" rel="noopener">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>
                    </a>
                    <?php endif; ?>

                    <?php if ( $footer_tt ) : ?>
                    <a href="<?php echo esc_url($footer_tt); ?>" class="footer-social-btn footer-social-btn--tt" aria-label="TikTok" target="_blank" rel="noopener">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-2.88 2.5 2.89 2.89 0 0 1-2.89-2.89 2.89 2.89 0 0 1 2.89-2.89c.28 0 .54.04.79.1V9.01a6.33 6.33 0 0 0-.79-.05 6.34 6.34 0 0 0-6.34 6.34 6.34 6.34 0 0 0 6.34 6.34 6.34 6.34 0 0 0 6.33-6.34V8.69a8.18 8.18 0 0 0 4.78 1.52V6.76a4.85 4.85 0 0 1-1.01-.07z"/></svg>
                    </a>
                    <?php endif; ?>

                    <?php if ( $footer_maps ) : ?>
                    <a href="<?php echo esc_url($footer_maps); ?>" class="footer-social-btn footer-social-btn--maps" aria-label="Google Maps" target="_blank" rel="noopener">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    </a>
                    <?php endif; ?>

                </div><!-- /.site-footer__socials -->

            </div>

        </div><!-- /.site-footer__grid -->

        <!-- Подвал -->
        <div class="site-footer__bottom">
            <div class="site-footer__bottom-left">
                <?php echo wp_kses_post( $footer_bottom_left ); ?>
            </div>
            <div class="site-footer__bottom-right">
                <?php
                if ( $footer_bottom_right ) {
                    echo wp_kses_post( $footer_bottom_right );
                } else {
                    echo '&copy; ' . esc_html( date('Y') ) . ' ' . esc_html( get_bloginfo('name') );
                }
                ?>
            </div>
        </div>

        <!-- Платёжные системы -->
        <div class="site-footer__payments">
            <img src="<?php echo esc_url( get_template_directory_uri() . '/assets/img/payment-logos.png' ); ?>"
                 alt="Visa, Mastercard, Белкарт, Apple Pay, Samsung Pay, Alfa-Bank"
                 width="1468" height="60"
                 loading="lazy"
                 decoding="async">
        </div>

    </div><!-- /.container -->
</footer>

<!-- ===== MOBILE BOTTOM NAV ===== -->
<nav class="mobile-bottom-nav" aria-label="Мобильная навигация">
    <div class="mobile-bottom-nav__inner">
        <a href="<?php echo esc_url( home_url('/') ); ?>" class="mobile-bottom-nav__item <?php echo is_front_page() ? 'is-active' : ''; ?>">
            <?php echo podval_icon('home'); ?><span>Главная</span>
        </a>
        <a href="<?php echo esc_url( podval_get_catalog_url() ); ?>"
           class="mobile-bottom-nav__item <?php echo ( function_exists('is_shop') && is_shop() || function_exists('is_product_category') && is_product_category() ) ? 'is-active' : ''; ?>">
            <?php echo podval_icon('grid'); ?><span>Каталог</span>
        </a>
        <a href="<?php echo esc_url( get_search_link() ); ?>" class="mobile-bottom-nav__item">
            <?php echo podval_icon('search'); ?><span>Поиск</span>
        </a>
        <?php if ( function_exists('WC') ) : ?>
        <a href="<?php echo esc_url( function_exists('wc_get_cart_url') ? wc_get_cart_url() : home_url('/cart/') ); ?>"
           class="mobile-bottom-nav__item <?php echo function_exists('is_cart') && is_cart() ? 'is-active' : ''; ?>">
            <?php echo podval_icon('cart'); ?><span>Корзина</span>
        </a>
        <?php endif; ?>
    </div>
</nav>

<!-- Cookie Consent -->
<div class="podval-cookie" id="podval-cookie">
  <div class="podval-cookie__inner">
    <div class="podval-cookie__text">
      Мы используем файлы cookie для улучшения работы сайта и анализа трафика. Продолжая использовать сайт, вы соглашаетесь с <a href="<?php echo esc_url( podval_url( 'privacy' ) ); ?>">политикой конфиденциальности</a>.
    </div>
    <div class="podval-cookie__btns">
      <button class="podval-cookie__accept" id="podval-cookie-accept">Принять</button>
      <button class="podval-cookie__decline" id="podval-cookie-decline">Отклонить</button>
    </div>
  </div>
</div>

<script>
/* Home-row arrow scroll (left + right). init через rAF чтобы избежать forced reflow */
(function(){
    var wraps = document.querySelectorAll('.home-row__wrap');
    if (!wraps.length) return;

    function setupWrap(wrap) {
        var scroll = wrap.querySelector('.home-row__scroll');
        var arrowR = wrap.querySelector('.home-row__arrow--right');
        var arrowL = wrap.querySelector('.home-row__arrow--left');
        if (!scroll) return;

        // Batch reads first, writes after — избегаем layout thrashing
        function checkArrows() {
            var sl = scroll.scrollLeft;
            var cw = scroll.clientWidth;
            var sw = scroll.scrollWidth;
            var atStart = sl <= 4;
            var atEnd = sl + cw >= sw - 4;
            if (arrowL) arrowL.classList.toggle('is-hidden', atStart);
            if (arrowR) arrowR.classList.toggle('is-hidden', atEnd);
        }

        if (arrowR) arrowR.addEventListener('click', function(){
            scroll.scrollBy({ left: scroll.clientWidth * 0.75, behavior: 'smooth' });
        });
        if (arrowL) arrowL.addEventListener('click', function(){
            scroll.scrollBy({ left: -scroll.clientWidth * 0.75, behavior: 'smooth' });
        });
        scroll.addEventListener('scroll', checkArrows, { passive: true });
        window.addEventListener('load', checkArrows);
        return checkArrows;
    }

    // Собираем все init-функции, выполняем в одном rAF чтобы браузер сгруппировал layout reads
    var initFns = [];
    wraps.forEach(function(wrap){
        var fn = setupWrap(wrap);
        if (fn) initFns.push(fn);
    });
    if (initFns.length) {
        requestAnimationFrame(function(){
            initFns.forEach(function(fn){ fn(); });
        });
    }
})();

/* Дропдаун-фильтры каталога */
document.querySelectorAll('.catalog-filter').forEach(function(f){
    var btn=f.querySelector('.catalog-filter__btn');
    if(!btn)return;
    btn.addEventListener('click',function(e){
        e.stopPropagation();
        var wasOpen=f.classList.contains('is-open');
        document.querySelectorAll('.catalog-filter.is-open').forEach(function(o){o.classList.remove('is-open');});
        if(!wasOpen)f.classList.add('is-open');
    });
});
document.addEventListener('click',function(){
    document.querySelectorAll('.catalog-filter.is-open').forEach(function(o){o.classList.remove('is-open');});
});

/* Collections Hub v3: filter on the fly + title update + featured toggle + promo toggle + few-count + dedup */
(function(){
    var pills = document.querySelectorAll('.chub-pill[data-filter]');
    var grid = document.getElementById('chub-grid');
    var featured = document.getElementById('chub-featured');
    var promo = document.getElementById('chub-promo');
    var titleEl = document.getElementById('chub-title');
    if (!pills.length || !grid) return;
    var cards = grid.querySelectorAll('.chub-card[data-cat]');

    /* Initial state: hide few-count cards AND featured duplicates */
    cards.forEach(function(card) {
        if (card.getAttribute('data-few') === '1') card.classList.add('is-few-hidden');
        if (card.getAttribute('data-featured') === '1') card.classList.add('is-feat-hidden');
    });

    pills.forEach(function(pill) {
        pill.addEventListener('click', function() {
            var filter = pill.getAttribute('data-filter');
            var label = pill.getAttribute('data-label') || pill.textContent;
            pills.forEach(function(p) { p.classList.remove('is-active'); });
            pill.classList.add('is-active');

            if (titleEl) titleEl.textContent = label;

            var showFeatured = (filter === 'all') && featured;
            if (featured) {
                featured.style.display = showFeatured ? '' : 'none';
            }
            /* Промо-блок — только в режиме "Все" */
            if (promo) {
                promo.style.display = (filter === 'all') ? '' : 'none';
            }

            cards.forEach(function(card) {
                var catMatch = (filter === 'all' || card.getAttribute('data-cat') === filter);
                var isFew = card.getAttribute('data-few') === '1';
                var isFeat = card.getAttribute('data-featured') === '1';

                /* Category filter */
                if (catMatch) {
                    card.classList.remove('is-hidden');
                } else {
                    card.classList.add('is-hidden');
                }

                /* Few-count: hide in "all", show in specific category */
                if (isFew) {
                    if (filter === 'all') {
                        card.classList.add('is-few-hidden');
                    } else {
                        card.classList.remove('is-few-hidden');
                    }
                }

                /* Featured duplicates: hide when featured row visible, show when it's hidden */
                if (isFeat) {
                    if (showFeatured) {
                        card.classList.add('is-feat-hidden');
                    } else {
                        card.classList.remove('is-feat-hidden');
                    }
                }
            });
        });
    });
})();
(function(){
    var slides=document.querySelectorAll('.hero__slide');
    var btn1=document.querySelector('.js-hero-btn1');
    var btn2=document.querySelector('.js-hero-btn2');
    if(!slides.length||!btn1)return;
    function upd(s){
        var b1t=s.getAttribute('data-btn1-text')||'';
        var b1u=s.getAttribute('data-btn1-url')||'#';
        var b2t=s.getAttribute('data-btn2-text')||'';
        var b2u=s.getAttribute('data-btn2-url')||'#';
        var sp1=btn1.querySelector('.js-hero-btn1-text');
        if(sp1)sp1.textContent=b1t;
        btn1.href=b1u;
        btn1.style.display=b1t?'':'none';
        if(btn2){
            var sp2=btn2.querySelector('.js-hero-btn2-text');
            if(sp2)sp2.textContent=b2t;
            btn2.href=b2u;
            btn2.style.display=b2t?'':'none';
        }
    }
    var obs=new MutationObserver(function(){
        slides.forEach(function(s){if(s.classList.contains('is-active'))upd(s);});
    });
    slides.forEach(function(s){obs.observe(s,{attributes:true,attributeFilter:['class']});});
})();
/* Мобильное меню: 2-уровневый аккордеон (Коллекции → Аниме → теги) */
document.querySelectorAll('.js-mnav-toggle-sub').forEach(function(btn){
    btn.addEventListener('click', function(e){
        e.preventDefault();
        e.stopPropagation();
        var tags = btn.nextElementSibling;
        if(!tags || !tags.classList.contains('mnav__sub-tags')) return;
        var isOpen = tags.classList.contains('is-open');
        // Закрыть все остальные
        document.querySelectorAll('.mnav__sub-tags.is-open').forEach(function(t){ t.classList.remove('is-open'); });
        document.querySelectorAll('.mnav__sub-item--toggle.is-open').forEach(function(t){ t.classList.remove('is-open'); });
        if(!isOpen){
            tags.classList.add('is-open');
            btn.classList.add('is-open');
        }
    });
});
</script>
<?php wp_footer(); ?>
</body>
</html>

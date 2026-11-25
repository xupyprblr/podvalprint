<?php
/**
 * Подвал сайта — компонент Footer дизайн-системы (design/system/components/Footer).
 *
 * Колонки ссылок — podval_pp_footer_columns() (области меню «Футер — магазин» и
 * «Футер — информация» или страницы сайта), реквизиты — из inc/brand.php.
 *
 * @package Podval Print
 */

defined( 'ABSPATH' ) || exit;

$podval_footer = (array) podval_copy( 'footer', array() );
$podval_hours  = (array) podval_brand( 'hours', array() );
$podval_addr   = (string) get_theme_mod( 'podval_footer_address', podval_brand( 'address' ) );
$podval_legal  = array_filter( array(
	(string) podval_brand( 'legal_name' ),
	podval_brand( 'unp' ) ? sprintf( __( 'УНП %s', 'podval-print' ), podval_brand( 'unp' ) ) : '',
	(string) podval_brand( 'trade_register' ),
) );
?>
</main><!-- /#main-content -->
</div><!-- /.site-wrapper -->

<div class="pp-site-footer">
	<footer class="pp-footer">
		<div class="pp-footer__grid">
			<div class="pp-footer__brand">
				<a class="pp-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php echo esc_attr( sprintf( __( '%s — на главную', 'podval-print' ), podval_brand( 'name', get_bloginfo( 'name' ) ) ) ); ?>">
					<?php echo podval_pp_logo(); // phpcs:ignore WordPress.Security.EscapeOutput -- SVG из файла темы ?>
				</a>
				<?php if ( ! empty( $podval_footer['about'] ) ) : ?>
					<p class="pp-small"><?php echo esc_html( $podval_footer['about'] ); ?></p>
				<?php endif; ?>
				<?php if ( '' !== $podval_addr || $podval_hours ) : ?>
					<p class="pp-small" style="color:var(--ink)">
						<?php echo esc_html( $podval_addr ); ?>
						<?php foreach ( $podval_hours as $podval_line ) : ?>
							<br><?php echo esc_html( is_array( $podval_line ) ? implode( ' ', $podval_line ) : (string) $podval_line ); ?>
						<?php endforeach; ?>
					</p>
				<?php endif; ?>
			</div>
			<?php foreach ( podval_pp_footer_columns() as $podval_col ) : ?>
				<div>
					<h2 class="pp-footer__title"><?php echo esc_html( $podval_col['title'] ); ?></h2>
					<ul>
						<?php foreach ( $podval_col['items'] as $podval_link ) : ?>
							<li><a href="<?php echo esc_url( $podval_link['url'] ); ?>"><?php echo esc_html( $podval_link['label'] ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endforeach; ?>
		</div>
		<div class="pp-footer__legal">
			<span><?php echo esc_html( implode( ' · ', $podval_legal ) ); ?></span>
			<span>&copy; <?php echo esc_html( wp_date( 'Y' ) . ' ' . podval_brand( 'name', get_bloginfo( 'name' ) ) ); ?></span>
		</div>
	</footer>
</div>

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

<?php
/**
 * Podval Print — Services category page (uslugi)
 */
defined( 'ABSPATH' ) || exit;
get_header();

$current_cat = get_queried_object();

// хаб показывает опубликованные лендинги услуг. Пока их нет — прежние
// товары-услуги (и подкатегории), чтобы страница не опустела до публикации.
$sl_rows = function_exists( 'podval_sl_landings' ) ? podval_sl_landings() : array();

$service_products = new WP_Query( array(
    'no_found_rows'  => true,
    'post_type'      => 'product',
    'posts_per_page' => 10,
    'tax_query'      => array( array(
        'taxonomy' => 'product_cat',
        'field'    => 'slug',
        'terms'    => 'uslugi',
        'include_children' => true,
    ) ),
    'orderby' => 'menu_order',
    'order'   => 'ASC',
) );

$sub_cats = get_terms( array(
    'taxonomy' => 'product_cat',
    'parent'   => $current_cat ? $current_cat->term_id : 0,
    'hide_empty' => false,
) );

// Hero из Customizer
$hero_badge  = get_theme_mod( 'podval_svc_hero_badge', 'Услуги' );
$hero_title  = get_theme_mod( 'podval_svc_hero_title', single_term_title( '', false ) );
$hero_accent = get_theme_mod( 'podval_svc_hero_accent', '' );
$hero_desc   = get_theme_mod( 'podval_svc_hero_desc', wp_strip_all_tags( (string) term_description() ) );
$hero_image  = get_theme_mod( 'podval_svc_hero_image', '' );
$hero_btn1   = get_theme_mod( 'podval_svc_hero_btn1', 'Смотреть услуги' );
$hero_btn2   = get_theme_mod( 'podval_svc_hero_btn2', 'Связаться с нами' );

$tg = get_theme_mod( 'podval_footer_tg', '' );
?>

<?php /* микроразметка Service с provider-строкой снята — Service теперь
         у каждого лендинга (JSON-LD с provider → LocalBusiness #business). */ ?>
<article class="podval-services">

  <!-- ===== HERO ===== -->
  <section class="podval-svc__hero <?php echo $hero_image ? 'podval-svc__hero--has-bg' : ''; ?>" <?php if ( $hero_image ) echo 'style="background-image:url(' . esc_url( $hero_image ) . ')"'; ?>>
    <div class="podval-svc__hero-overlay"></div>
    <div class="podval-svc__hero-inner">
      <?php if ( $hero_badge ) : ?>
      <div class="podval-svc__hero-badge"><?php echo esc_html( $hero_badge ); ?></div>
      <?php endif; ?>
      <h1 class="podval-svc__hero-title">
        <?php echo esc_html( $hero_title ); ?><br>
        <span class="podval-svc__hero-accent"><?php echo esc_html( $hero_accent ); ?></span>
      </h1>
      <p class="podval-svc__hero-desc"><?php echo esc_html( $hero_desc ); ?></p>
      <div class="podval-svc__hero-actions">
        <?php if ( $hero_btn1 ) : ?><a href="#services" class="podval-svc__btn-primary"><?php echo esc_html( $hero_btn1 ); ?></a><?php endif; ?>
        <?php if ( $hero_btn2 ) : ?><a href="<?php echo esc_url( podval_url( 'about', 'contacts' ) ); ?>" class="podval-svc__btn-outline"><?php echo esc_html( $hero_btn2 ); ?></a><?php endif; ?>
      </div>
    </div>
  </section>

  <!-- ===== ПРЕИМУЩЕСТВА с иконками ===== -->
  <section class="podval-svc__section">
    <div class="podval-svc__container">
      <h2 class="podval-svc__section-title">Почему работают с нами</h2>
      <p class="podval-svc__section-desc">Полный цикл производства — от идеи до готового продукта. Никаких подрядчиков, всё делаем сами.</p>

      <div class="podval-svc__advantages">

        <div class="podval-svc__adv-card">
          <div class="podval-svc__adv-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
          </div>
          <h3>Собственное производство</h3>
          <p>Все процессы in-house на нашем оборудовании. Печать, нанесение, упаковка — под одной крышей в Минске.</p>
        </div>

        <div class="podval-svc__adv-card">
          <div class="podval-svc__adv-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
          </div>
          <h3>Полный контроль качества</h3>
          <p>Каждое изделие проходит проверку. Контролируем цветопередачу, стойкость принта, качество ткани и швов.</p>
        </div>

        <div class="podval-svc__adv-card">
          <div class="podval-svc__adv-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
          </div>
          <h3>Любой тираж</h3>
          <p>От 1 штуки для себя до партии в 1000+ единиц. Гибкие условия, без минимального заказа на печать.</p>
        </div>

        <div class="podval-svc__adv-card">
          <div class="podval-svc__adv-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          </div>
          <h3>Быстрые сроки</h3>
          <p>Единичные заказы — 1–3 дня. Партии — от 5 рабочих дней. Срочные заказы обсуждаем индивидуально.</p>
        </div>

        <div class="podval-svc__adv-card">
          <div class="podval-svc__adv-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 19l7-7 3 3-7 7-3-3z"/><path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"/><path d="M2 2l7.586 7.586"/><circle cx="11" cy="11" r="2"/></svg>
          </div>
          <h3>Помощь с дизайном</h3>
          <p>Нет макета? Поможем подготовить файл. Доработаем, адаптируем, предложим варианты размещения.</p>
        </div>

        <div class="podval-svc__adv-card">
          <div class="podval-svc__adv-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
          </div>
          <h3>Доставка по Беларуси</h3>
          <p>Самовывоз из Минска, Евро-Почта и Яндекс Доставка. Оптовые заказы доставляем бесплатно.</p>
        </div>

      </div>
    </div>
  </section>

  <!-- ===== УСЛУГИ ===== -->
  <section class="podval-svc__section podval-svc__section--dark" id="services">
    <div class="podval-svc__container">
      <h2 class="podval-svc__section-title">Наши услуги</h2>
      <p class="podval-svc__section-desc">Выберите направление — расскажем подробнее и рассчитаем стоимость.</p>

      <div class="podval-svc__cards">
        <?php if ( $sl_rows ) :
          foreach ( $sl_rows as $sl_row ) {
              get_template_part( 'template-parts/service/landing-card', null, array( 'row' => $sl_row, 'heading' => 'h3' ) );
          }
        elseif ( $service_products->have_posts() ) :
          while ( $service_products->have_posts() ) : $service_products->the_post();
            $product   = wc_get_product( get_the_ID() );
            if ( ! $product ) continue;
            $thumb_url = get_the_post_thumbnail_url( get_the_ID(), 'large' );
            $name      = $product->get_name();
            $excerpt   = $product->get_short_description() ?: wp_trim_words( $product->get_description(), 25 );
        ?>
        <a href="<?php echo esc_url( get_permalink() ); ?>" class="podval-svc__service-card">
          <?php if ( $thumb_url ) : ?>
          <div class="podval-svc__service-img" style="background-image:url(<?php echo esc_url( $thumb_url ); ?>)"></div>
          <?php else : ?>
          <div class="podval-svc__service-img podval-svc__service-img--placeholder"><span><?php echo esc_html( mb_substr( $name, 0, 1 ) ); ?></span></div>
          <?php endif; ?>
          <div class="podval-svc__service-info">
            <h3 class="podval-svc__service-name"><?php echo esc_html( $name ); ?></h3>
            <?php if ( $excerpt ) : ?>
            <p class="podval-svc__service-desc"><?php echo esc_html( wp_strip_all_tags( $excerpt ) ); ?></p>
            <?php endif; ?>
            <span class="podval-svc__service-link">Подробнее →</span>
          </div>
        </a>
        <?php endwhile; wp_reset_postdata();
        elseif ( ! empty( $sub_cats ) && ! is_wp_error( $sub_cats ) ) :
          foreach ( $sub_cats as $sub ) :
            $thumb_id  = get_term_meta( $sub->term_id, 'thumbnail_id', true );
            $thumb_url = $thumb_id ? wp_get_attachment_url( $thumb_id ) : '';
        ?>
        <a href="<?php echo esc_url( get_term_link( $sub ) ); ?>" class="podval-svc__service-card">
          <div class="podval-svc__service-img <?php echo $thumb_url ? '' : 'podval-svc__service-img--placeholder'; ?>" <?php if ($thumb_url) echo 'style="background-image:url('.esc_url($thumb_url).')"'; ?>>
            <?php if ( ! $thumb_url ) : ?><span><?php echo esc_html( mb_substr( $sub->name, 0, 1 ) ); ?></span><?php endif; ?>
          </div>
          <div class="podval-svc__service-info">
            <h3 class="podval-svc__service-name"><?php echo esc_html( $sub->name ); ?></h3>
            <?php if ( $sub->description ) : ?>
            <p class="podval-svc__service-desc"><?php echo esc_html( wp_strip_all_tags( $sub->description ) ); ?></p>
            <?php endif; ?>
            <span class="podval-svc__service-link">Подробнее →</span>
          </div>
        </a>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </section>

  <!-- ===== КАК ЭТО РАБОТАЕТ (5 шагов) ===== -->
  <section class="podval-svc__section">
    <div class="podval-svc__container">
      <h2 class="podval-svc__section-title">Как это работает</h2>
      <div class="podval-svc__process">
        <div class="podval-svc__process-step">
          <div class="podval-svc__process-num">1</div>
          <h3>Заявка</h3>
          <p>Опишите задачу, прикрепите макет или референс</p>
        </div>
        <div class="podval-svc__process-arrow">→</div>
        <div class="podval-svc__process-step">
          <div class="podval-svc__process-num">2</div>
          <h3>Обсуждение</h3>
          <p>Свяжемся, уточним тираж, сроки и стоимость</p>
        </div>
        <div class="podval-svc__process-arrow">→</div>
        <div class="podval-svc__process-step">
          <div class="podval-svc__process-num">3</div>
          <h3>Тестовые образцы</h3>
          <p>Отправим тестовую партию для утверждения перед основным тиражом</p>
        </div>
        <div class="podval-svc__process-arrow">→</div>
        <div class="podval-svc__process-step">
          <div class="podval-svc__process-num">4</div>
          <h3>Производство</h3>
          <p>Напечатаем и подготовим полный заказ</p>
        </div>
        <div class="podval-svc__process-arrow">→</div>
        <div class="podval-svc__process-step">
          <div class="podval-svc__process-num">5</div>
          <h3>Получение</h3>
          <p>Самовывоз или доставка по Беларуси</p>
        </div>
      </div>
    </div>
  </section>

  <!-- ===== CTA ===== -->
  <section class="podval-svc__cta">
    <div class="podval-svc__container">
      <h2 class="podval-svc__cta-title">Есть идея? Давайте обсудим!</h2>
      <p class="podval-svc__cta-desc">Напишите нам — рассчитаем стоимость и сроки бесплатно.</p>
      <div class="podval-svc__cta-actions">
        <?php if ( $tg ) : ?>
        <a href="<?php echo esc_url( $tg ); ?>" target="_blank" rel="noopener" class="podval-svc__btn-primary">Написать в Telegram</a>
        <?php endif; ?>
        <a href="<?php echo esc_url( podval_url( 'about', 'contacts' ) ); ?>" class="podval-svc__btn-outline">Все контакты</a>
      </div>
    </div>
  </section>

</article>

<?php get_footer(); ?>

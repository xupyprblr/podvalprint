<?php
/**
 * Лендинг категории «Свой Дизайн»
 */
defined( 'ABSPATH' ) || exit;
get_header();

// Customizer data
$hero_bg_id = get_theme_mod( 'podval_cd_hero_bg', '' );
$hero_bg    = $hero_bg_id ? wp_get_attachment_image_url( $hero_bg_id, 'full' ) : '';
$hero_badge = get_theme_mod( 'podval_cd_hero_badge', '' );
$hero_title = get_theme_mod( 'podval_cd_hero_title', single_term_title( '', false ) );
$hero_desc  = get_theme_mod( 'podval_cd_hero_desc', wp_strip_all_tags( (string) term_description() ) );

// Товары
$products = new WP_Query( array(
    'post_type'      => 'product',
    'posts_per_page' => 20,
    'tax_query'      => array( array(
        'taxonomy' => 'product_cat',
        'field'    => 'slug',
        'terms'    => 'custom-design',
    ) ),
    'orderby' => 'menu_order date',
    'order'   => 'ASC',
) );
?>

<div class="cd-landing">

  <!-- 1. НАВИГАЦИЯ ПО ТОВАРАМ -->
  <nav class="cd-nav">
    <div class="cd-nav__inner">
      <?php if ( $products->have_posts() ) : while ( $products->have_posts() ) : $products->the_post();
        $prod = wc_get_product( get_the_ID() ); if ( ! $prod ) continue; ?>
      <a href="#product-<?php echo esc_attr( get_the_ID() ); ?>" class="cd-nav__btn"><?php echo esc_html( $prod->get_name() ); ?></a>
      <?php endwhile; wp_reset_postdata(); endif; ?>
    </div>
  </nav>

  <!-- 2. HERO (Customizer) -->
  <section class="cd-hero <?php echo $hero_bg ? 'cd-hero--has-bg' : ''; ?>">
    <?php if ( $hero_bg ) : ?>
    <img class="cd-hero__bg" src="<?php echo esc_url( $hero_bg ); ?>"
         alt="<?php echo esc_attr( $hero_title ); ?>"
         width="1200" height="500"
         fetchpriority="high" decoding="async">
    <?php endif; ?>
    <div class="cd-hero__overlay"></div>
    <div class="cd-hero__inner">
      <div class="cd-hero__content">
        <?php if ( $hero_badge ) : ?><span class="cd-hero__badge"><?php echo esc_html( $hero_badge ); ?></span><?php endif; ?>
        <h1 class="cd-hero__title"><?php echo esc_html( $hero_title ); ?></h1>
        <?php if ( $hero_desc ) : ?><p class="cd-hero__desc"><?php echo esc_html( $hero_desc ); ?></p><?php endif; ?>
        <a href="#cd-products" class="cd-hero__cta">Выбрать товар <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg></a>
      </div>
    </div>
    <div class="cd-hero__accent"></div>
  </section>

  <!-- 3. ТОВАРЫ -->
  <section class="cd-section cd-section--dark" id="cd-products">
    <div class="cd-container">
      <h2 class="cd-section-title"><?php echo esc_html( get_theme_mod( 'podval_cd_products_title', 'Выберите изделие' ) ); ?></h2>
      <p class="cd-section-subtitle">Наносим принт на хлопок, полиэстер, смесовые ткани и нейлон. Светлые и тёмные изделия — одинаково ярко.</p>
      <?php if ( $products->have_posts() ) : ?>
      <div class="catalog-grid">
        <?php while ( $products->have_posts() ) : $products->the_post();
          global $product; $product = wc_get_product( get_the_ID() );
          if ( $product ) : ?>
          <div id="product-<?php echo esc_attr( get_the_ID() ); ?>"><?php get_template_part( 'template-parts/content/product', 'card' ); ?></div>
        <?php endif; endwhile; wp_reset_postdata(); ?>
      </div>
      <?php endif; ?>
    </div>
  </section>

  <?php
  // Шаги, преимущества, FAQ и SEO-текст раздела — из inc/copy-data.php (custom_hub).
  podval_copy_render( (array) podval_copy( 'custom_hub' ), 'cd-seo' );
  ?>

</div>

<?php get_footer(); ?>

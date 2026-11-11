<?php
/**
 * 404 — Страница не найдена
 */
get_header();
?>

<main class="error-page">
  <div class="error-page__container">

    <div class="error-page__glitch" data-text="404">404</div>

    <h1 class="error-page__title">Страница не найдена</h1>
    <p class="error-page__desc">
      Похоже, эта страница была удалена, перемещена или никогда не существовала.
    </p>

    <div class="error-page__actions">
      <a href="<?php echo esc_url( home_url('/') ); ?>" class="error-page__btn error-page__btn--primary">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="18" height="18">
          <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>
        </svg>
        На главную
      </a>
      <?php if ( function_exists('wc_get_page_id') ) : ?>
      <a href="<?php echo esc_url( podval_get_catalog_url() ); ?>" class="error-page__btn error-page__btn--secondary">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="18" height="18">
          <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>
        </svg>
        Каталог
      </a>
      <?php endif; ?>
    </div>

    <?php
    // Показываем популярные товары если WooCommerce активен
    if ( function_exists('wc_get_products') ) :
      $popular = wc_get_products([
        'limit'   => 4,
        'orderby' => 'popularity',
        'order'   => 'DESC',
        'status'  => 'publish',
        'stock_status' => 'instock',
      ]);
      if ( ! empty( $popular ) ) :
    ?>
    <div class="error-page__products">
      <h2 class="error-page__products-title">Может, заинтересует?</h2>
      <div class="error-page__products-grid">
        <?php foreach ( $popular as $product ) :
          $thumb = wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' );
          $price = $product->get_price_html();
        ?>
        <a href="<?php echo esc_url( $product->get_permalink() ); ?>" class="error-page__product-card">
          <?php if ( $thumb ) : ?>
          <div class="error-page__product-img">
            <img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( $product->get_name() ); ?>" loading="lazy">
          </div>
          <?php endif; ?>
          <div class="error-page__product-info">
            <span class="error-page__product-name"><?php echo esc_html( $product->get_name() ); ?></span>
            <?php if ( $price ) : ?>
            <span class="error-page__product-price"><?php echo $price; ?></span>
            <?php endif; ?>
          </div>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; endif; ?>

  </div>
</main>

<?php get_footer(); ?>

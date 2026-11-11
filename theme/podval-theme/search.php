<?php
/**
 * Search results
 *
 * Раньше поиск падал в index.php-fallback: H1 «Архивы», сетка без контейнера,
 * без счётчика и без поля повторного поиска. Здесь используем разметку каталога
 * (.catalog-page / .catalog-toolbar / .catalog-grid), чтобы выглядело как архив.
 * Запрос ограничен товарами и постранично (см. podval_search_query в functions.php).
 */
defined( 'ABSPATH' ) || exit;

get_header();

$search_q = get_search_query();
$found    = (int) $GLOBALS['wp_query']->found_posts;
?>
<div class="catalog-page catalog-page--search">

  <div class="catalog-toolbar">
    <div class="catalog-toolbar__left">
      <h1 class="catalog-title">Поиск</h1>
      <p class="catalog-search__meta">
        <?php if ( $search_q !== '' ) : ?>
          По запросу «<strong><?php echo esc_html( $search_q ); ?></strong>» —
          <?php echo esc_html( sprintf( _n( '%d товар', '%d товаров', $found, 'podval-print' ), $found ) ); ?>
        <?php else : ?>
          Введите название принта, аниме, игры или персонажа
        <?php endif; ?>
      </p>
    </div>
    <form role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>" class="catalog-search__form">
      <input type="search" name="s" value="<?php echo esc_attr( $search_q ); ?>"
             placeholder="Поиск товаров…" autocomplete="off" aria-label="Поиск товаров">
      <input type="hidden" name="post_type" value="product">
      <button type="submit" class="btn btn--primary">Найти</button>
    </form>
  </div>

  <?php if ( have_posts() ) : ?>
    <div class="catalog-grid">
      <?php
      while ( have_posts() ) : the_post();
        $product = wc_get_product( get_the_ID() );
        if ( $product && $product->is_visible() ) {
          get_template_part( 'template-parts/content/product', 'card' );
        }
      endwhile;
      ?>
    </div>
    <div class="catalog-pagination">
      <?php
      the_posts_pagination( [
        'mid_size'  => 1,
        'prev_text' => '←',
        'next_text' => '→',
      ] );
      ?>
    </div>
  <?php else : ?>
    <div class="catalog-empty catalog-search__empty">
      <p>По запросу «<?php echo esc_html( $search_q ); ?>» ничего не нашлось.</p>
      <p class="catalog-search__hint">Попробуйте название на другом языке (например, «Берсерк» / «Berserk») или загляните в коллекции.</p>
      <div class="catalog-search__links">
        <a href="<?php echo esc_url( podval_get_catalog_url() ); ?>" class="btn btn--primary">Коллекции</a>
        <a href="<?php echo esc_url( podval_get_catalog_url() ); ?>" class="btn btn--outline">Весь каталог</a>
      </div>
    </div>
  <?php endif; ?>

</div>

<?php get_footer(); ?>

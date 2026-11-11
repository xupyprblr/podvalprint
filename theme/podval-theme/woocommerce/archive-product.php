<?php
/**
 * WooCommerce Archive — catalog page
 * Баннер вставляется после N-й карточки (настраивается в Customizer → Баннеры в каталоге)
 */
defined( 'ABSPATH' ) || exit;

// Определяем текущий контекст: категория, тег или общий каталог
$current_cat      = is_product_category() ? get_queried_object() : null;
$current_tag      = is_product_tag() ? get_queried_object() : null;

if ( $current_cat && ! is_wp_error( $current_cat ) ) {
    $current_cat_slug = $current_cat->slug;
    $banner_slug      = $current_cat->slug;
} elseif ( $current_tag && ! is_wp_error( $current_tag ) ) {
    $current_cat_slug = null; // не категория
    $banner_slug      = 'tag__' . $current_tag->slug;
} else {
    $current_cat_slug = 'shop';
    $banner_slug      = 'shop';
}

if ( $current_cat_slug === 'custom-design' ) {
    include get_stylesheet_directory() . '/woocommerce/taxonomy-product_cat-custom-design.php';
    return;
}

get_header();

// Принудительно показываем все товары (без пагинации)
global $wp_query;

// Если мы на корневой «Коллекции» — рандомный порядок
$is_collections_root = false;
$col_parent_check = get_term( 43, 'product_cat' );
if ( ! $col_parent_check || is_wp_error( $col_parent_check ) ) {
    $col_parent_check = get_term_by( 'slug', 'collections', 'product_cat' );
}
if ( $current_cat && $col_parent_check && $current_cat->term_id == $col_parent_check->term_id ) {
    $is_collections_root = true;
    $wp_query->set( 'posts_per_page', 999 );
    $wp_query->set( 'orderby', 'rand' );
    $wp_query->query( $wp_query->query_vars );
} elseif ( $current_tag && ! is_wp_error( $current_tag ) ) {
    // Страница тега: перемешиваем товары, если их больше 4
    $wp_query->set( 'posts_per_page', 999 );
    if ( (int) $current_tag->count > 4 ) {
        $wp_query->set( 'orderby', 'rand' );
    }
    $wp_query->query( $wp_query->query_vars );
} elseif ( $wp_query->get('posts_per_page') != 999 ) {
    $wp_query->set( 'posts_per_page', 999 );
    $wp_query->query( $wp_query->query_vars );
}

// ── Бегущая строка тегов (только корневая страница Коллекций) ──
$marquee_tags = [];
if ( $is_collections_root ) {
    $mq_prod_ids = get_posts([ 'post_type' => 'product', 'posts_per_page' => -1, 'fields' => 'ids',
        'tax_query' => [[ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $col_parent_check->term_id, 'include_children' => true ]] ]);
    if ( ! empty( $mq_prod_ids ) ) {
        $mq_raw = wp_get_object_terms( $mq_prod_ids, 'product_tag', [ 'orderby' => 'count', 'order' => 'DESC' ] );
        if ( ! is_wp_error( $mq_raw ) ) {
            $seen = [];
            foreach ( $mq_raw as $mt ) {
                if ( isset( $seen[ $mt->term_id ] ) ) continue;
                $seen[ $mt->term_id ] = true;
                $mt_img  = podval_get_tag_image_url( $mt );
                if ( $mt_img ) {
                    $marquee_tags[] = [ 'img' => $mt_img, 'name' => $mt->name, 'url' => get_term_link( $mt ) ];
                }
            }
        }
    }
}

// ══════════════════════════════════════════════════════════════
// COLLECTIONS HUB v3 — featured row + flat grid, фильтрация на лету
// ══════════════════════════════════════════════════════════════
if ( $is_collections_root ) :

  // Дочерние категории Коллекций (Аниме, Фильмы, Гик, Музыка)
  $hub_cats_raw = get_terms([
      'taxonomy' => 'product_cat',
      'parent'   => $col_parent_check->term_id,
      'hide_empty' => true,
      'orderby' => 'count', 'order' => 'DESC',
  ]);
  if ( is_wp_error( $hub_cats_raw ) ) $hub_cats_raw = [];
  $hub_cats = [];
  foreach ( $hub_cats_raw as $hc ) {
      $hub_cats[] = [ 'term_id' => $hc->term_id, 'slug' => $hc->slug, 'name' => $hc->name ];
  }

  // Собираем ВСЕ теги
  $all_tags = [];
  foreach ( $hub_cats as $hc ) {
      $hc_product_ids = get_posts([
          'post_type' => 'product', 'posts_per_page' => -1, 'fields' => 'ids',
          'tax_query' => [[ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $hc['term_id'] ]],
      ]);
      if ( empty( $hc_product_ids ) ) continue;
      $raw = wp_get_object_terms( $hc_product_ids, 'product_tag', [ 'orderby' => 'count', 'order' => 'DESC' ] );
      if ( is_wp_error( $raw ) ) continue;
      $seen_t = [];
      foreach ( $raw as $rt ) {
          if ( isset( $seen_t[ $rt->term_id ] ) ) continue;
          if ( mb_strtolower( $rt->name ) === mb_strtolower( $hc['name'] ) ) continue;
          $seen_t[ $rt->term_id ] = true;
          $rt_img = podval_get_tag_image_url( $rt );
          if ( ! $rt_img ) continue;
          $rt_url = get_term_link( $rt );
          if ( is_wp_error( $rt_url ) ) continue;
          $all_tags[] = [
              'name'     => $rt->name,
              'url'      => $rt_url,
              'img'      => $rt_img,
              'count'    => $rt->count,
              'cat_slug' => $hc['slug'],
              'cat_name' => $hc['name'],
          ];
      }
  }

  usort( $all_tags, function($a, $b) { return $b['count'] - $a['count']; } );

  // Дедупликация
  $deduped = []; $deduped_keys = [];
  foreach ( $all_tags as $at ) {
      $key = mb_strtolower( $at['name'] );
      if ( isset( $deduped_keys[ $key ] ) ) continue;
      $deduped_keys[ $key ] = true;
      $deduped[] = $at;
  }
  $all_tags = $deduped;

  // Hero: Customizer → fallback рандомный из топ-6 (count > 1)
  $hero_img   = podval_cat_banner( 'img',   $banner_slug );
  $hero_title = podval_cat_banner( 'title', $banner_slug );
  $hero_sub   = podval_cat_banner( 'sub',   $banner_slug );
  $hero_btn   = podval_cat_banner( 'btn',   $banner_slug );
  $hero_url   = podval_cat_banner( 'url',   $banner_slug );
  $has_hero   = ( $hero_img || $hero_title );

  if ( ! $has_hero && ! empty( $all_tags ) ) {
      $hero_pool = array_filter( array_slice( $all_tags, 0, 10 ), function($t) { return $t['count'] > 1; } );
      if ( empty( $hero_pool ) ) $hero_pool = array_slice( $all_tags, 0, 3 );
      $hero_pool = array_values( $hero_pool );
      $hero_pick = $hero_pool[ array_rand( $hero_pool ) ];
      $hero_img   = $hero_pick['img'];
      $hero_title = $hero_pick['name'];
      $hero_sub   = $hero_pick['count'] . ' шт. в коллекции';
      $hero_url   = $hero_pick['url'];
      $hero_btn   = 'Смотреть коллекцию';
      $has_hero   = true;
  }

  // «Топ» бейдж — рандомные 4 из топ-10 (count > 1)
  $top_ids = [];
  $top_eligible = [];
  foreach ( $all_tags as $idx => $t ) {
      if ( $t['count'] > 1 && count( $top_eligible ) < 10 ) $top_eligible[] = $idx;
  }
  if ( count( $top_eligible ) > 4 ) {
      shuffle( $top_eligible );
      $top_ids = array_flip( array_slice( $top_eligible, 0, 4 ) );
  }

  // Featured row: рандомные 6 из тегов с count > 1
  $featured_eligible = array_filter( $all_tags, function($t) { return $t['count'] > 1; } );
  $featured_eligible = array_values( $featured_eligible );
  $featured_pool = array_slice( $featured_eligible, 0, min( 8, count( $featured_eligible ) ) );
  shuffle( $featured_pool );
  $featured = array_slice( $featured_pool, 0, min( 6, count( $featured_pool ) ) );

  // Collect featured tag names to exclude from main grid
  $featured_names = [];
  foreach ( $featured as $ft ) {
      $featured_names[ mb_strtolower( $ft['name'] ) ] = true;
  }
?>

<div class="collections-hub">
  <div class="chub-container">

    <!-- Хлебные крошки -->
    <div class="sp-breadcrumbs" style="padding: 24px 0 0;">
      <a href="<?php echo esc_url( home_url( '/' ) ); ?>">Главная</a>
      <span class="sp-breadcrumbs__sep">—</span>
      <span><?php echo esc_html( $current_cat->name ); ?></span>
    </div>

    <!-- ═══ HERO ═══ -->
    <?php if ( $has_hero ) : ?>
    <section class="chub-hero">
      <div class="chub-hero__bg" style="background-image: url(<?php echo esc_url( $hero_img ); ?>)"></div>
      <div class="chub-hero__overlay"></div>
      <div class="chub-hero__body">
        <div class="chub-hero__badge">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
          <span>Новое поступление</span>
        </div>
        <h1 class="chub-hero__title"><?php echo esc_html( $hero_title ); ?></h1>
        <?php if ( $hero_sub ) : ?>
        <p class="chub-hero__sub"><?php echo esc_html( $hero_sub ); ?></p>
        <?php endif; ?>
        <?php if ( $hero_url && $hero_btn ) : ?>
        <a href="<?php echo esc_url( $hero_url ); ?>" class="chub-hero__btn">
          <?php echo esc_html( $hero_btn ); ?>
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
        </a>
        <?php endif; ?>
      </div>
    </section>
    <?php endif; ?>

    <!-- ═══ TOOLBAR ═══ -->
    <div class="chub-toolbar">
      <div class="chub-toolbar__left">
        <h2 class="chub-toolbar__title" id="chub-title">Все коллекции</h2>
        <div class="chub-toolbar__line"></div>
      </div>
      <nav class="chub-pills">
        <button class="chub-pill is-active" data-filter="all" data-label="Все коллекции">Все</button>
        <?php foreach ( $hub_cats as $hc ) : ?>
        <button class="chub-pill" data-filter="<?php echo esc_attr( $hc['slug'] ); ?>" data-label="<?php echo esc_attr( $hc['name'] ); ?>"><?php echo esc_html( $hc['name'] ); ?></button>
        <?php endforeach; ?>
      </nav>
    </div>

    <!-- ═══ FEATURED ROW ═══ -->
    <?php if ( count( $featured ) >= 5 ) : ?>
    <div class="chub-featured" id="chub-featured">
      <!-- LEFT: 2 tall cards -->
      <a href="<?php echo esc_url( $featured[0]['url'] ); ?>" class="chub-fcard chub-fcard--tall" data-cat="<?php echo esc_attr( $featured[0]['cat_slug'] ); ?>">
        <img src="<?php echo esc_url( $featured[0]['img'] ); ?>" alt="<?php echo esc_attr( $featured[0]['name'] ); ?>" loading="lazy">
        <div class="chub-fcard__over"></div>
        <div class="chub-fcard__body">
          <span class="chub-fcard__cat"><?php echo esc_html( $featured[0]['cat_name'] ); ?></span>
          <span class="chub-fcard__name"><?php echo esc_html( $featured[0]['name'] ); ?></span>
          <span class="chub-fcard__count"><?php echo esc_html( $featured[0]['count'] ); ?> шт.</span>
        </div>
      </a>
      <a href="<?php echo esc_url( $featured[1]['url'] ); ?>" class="chub-fcard chub-fcard--tall" data-cat="<?php echo esc_attr( $featured[1]['cat_slug'] ); ?>">
        <img src="<?php echo esc_url( $featured[1]['img'] ); ?>" alt="<?php echo esc_attr( $featured[1]['name'] ); ?>" loading="lazy">
        <div class="chub-fcard__over"></div>
        <div class="chub-fcard__body">
          <span class="chub-fcard__cat"><?php echo esc_html( $featured[1]['cat_name'] ); ?></span>
          <span class="chub-fcard__name"><?php echo esc_html( $featured[1]['name'] ); ?></span>
          <span class="chub-fcard__count"><?php echo esc_html( $featured[1]['count'] ); ?> шт.</span>
        </div>
      </a>
      <!-- RIGHT: 1 wide on top + 3 small below -->
      <div class="chub-featured__right">
        <a href="<?php echo esc_url( $featured[2]['url'] ); ?>" class="chub-fcard chub-fcard--wide" data-cat="<?php echo esc_attr( $featured[2]['cat_slug'] ); ?>">
          <img src="<?php echo esc_url( $featured[2]['img'] ); ?>" alt="<?php echo esc_attr( $featured[2]['name'] ); ?>" loading="lazy">
          <div class="chub-fcard__over"></div>
          <div class="chub-fcard__body">
            <span class="chub-fcard__cat"><?php echo esc_html( $featured[2]['cat_name'] ); ?></span>
            <span class="chub-fcard__name"><?php echo esc_html( $featured[2]['name'] ); ?></span>
            <span class="chub-fcard__count"><?php echo esc_html( $featured[2]['count'] ); ?> шт.</span>
          </div>
        </a>
        <div class="chub-featured__row">
          <?php for ( $fi = 3; $fi < count( $featured ); $fi++ ) : $ft = $featured[ $fi ]; ?>
          <a href="<?php echo esc_url( $ft['url'] ); ?>" class="chub-fcard chub-fcard--small" data-cat="<?php echo esc_attr( $ft['cat_slug'] ); ?>">
            <img src="<?php echo esc_url( $ft['img'] ); ?>" alt="<?php echo esc_attr( $ft['name'] ); ?>" loading="lazy">
            <div class="chub-fcard__over"></div>
            <div class="chub-fcard__body">
              <span class="chub-fcard__cat"><?php echo esc_html( $ft['cat_name'] ); ?></span>
              <span class="chub-fcard__name"><?php echo esc_html( $ft['name'] ); ?></span>
              <span class="chub-fcard__count"><?php echo esc_html( $ft['count'] ); ?> шт.</span>
            </div>
          </a>
          <?php endfor; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- ═══ PROMO ROW ═══ (видна только при фильтре "Все", скрыта если нет товаров с бейджем 'promo') -->
    <?php
    $promo_products = podval_get_products_by_badge( 'promo', 12, false );
    if ( ! empty( $promo_products ) ) : ?>
    <section class="chub-promo home-row" id="chub-promo" aria-label="Промо">
      <div class="chub-promo__head">
        <h2 class="chub-promo__title">В фокусе</h2>
        <span class="chub-promo__dot"></span>
        <div class="chub-promo__line"></div>
      </div>
      <div class="home-row__wrap">
        <button class="home-row__arrow home-row__arrow--left is-hidden" aria-label="Прокрутить влево">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 6 9 12 15 18"/></svg>
        </button>
        <div class="home-row__scroll">
          <div class="home-row__track">
            <?php foreach ( $promo_products as $pp ) : ?>
            <div class="home-row__card">
              <?php podval_render_card( $pp ); ?>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <button class="home-row__arrow home-row__arrow--right" aria-label="Прокрутить вправо">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 6 15 12 9 18"/></svg>
        </button>
      </div>
    </section>
    <?php endif; ?>

    <!-- ═══ MAIN GRID ═══ -->
    <div class="chub-grid" id="chub-grid">
      <?php foreach ( $all_tags as $ti => $tag ) :
        $is_in_featured = isset( $featured_names[ mb_strtolower( $tag['name'] ) ] );
      ?>
      <a href="<?php echo esc_url( $tag['url'] ); ?>"
         class="chub-card<?php echo $tag['count'] <= 1 ? ' chub-card--few' : ''; ?>"
         data-cat="<?php echo esc_attr( $tag['cat_slug'] ); ?>"
         data-few="<?php echo $tag['count'] <= 1 ? '1' : '0'; ?>"
         data-featured="<?php echo $is_in_featured ? '1' : '0'; ?>">
        <div class="chub-card__visual">
          <img src="<?php echo esc_url( $tag['img'] ); ?>" alt="<?php echo esc_attr( $tag['name'] ); ?>" class="chub-card__img" loading="lazy">
          <div class="chub-card__overlay"></div>
          <?php if ( isset( $top_ids[ $ti ] ) ) : ?>
          <span class="chub-card__badge">
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
            Топ
          </span>
          <?php endif; ?>
          <div class="chub-card__hover-arrow">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17L17 7M17 7H7M17 7v10"/></svg>
          </div>
        </div>
        <div class="chub-card__info">
          <span class="chub-card__cat"><?php echo esc_html( $tag['cat_name'] ); ?></span>
          <span class="chub-card__name"><?php echo esc_html( $tag['name'] ); ?></span>
          <div class="chub-card__meta">
            <span class="chub-card__count"><?php echo esc_html( $tag['count'] ); ?> шт.</span>
            <span class="chub-card__go">Перейти <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg></span>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>

  </div><!-- .chub-container -->
</div><!-- .collections-hub -->

<?php
  get_footer();
  return;
endif;
// ══════════════════════════════════════════════════════════════
// СТАНДАРТНЫЙ КАТАЛОГ (категории, теги, общий магазин)
// ══════════════════════════════════════════════════════════════

// ── Данные баннера ──
$banner_img   = podval_cat_banner( 'img',   $banner_slug );
$banner_title = podval_cat_banner( 'title', $banner_slug );
$banner_sub   = podval_cat_banner( 'sub',   $banner_slug );
$banner_btn   = podval_cat_banner( 'btn',   $banner_slug );
$banner_url   = podval_cat_banner( 'url',   $banner_slug );
$banner_after = (int) podval_cat_banner( 'after', $banner_slug );
if ( $banner_after < 1 ) $banner_after = 4;
$has_banner   = ( $banner_img || $banner_title );

?>

<div class="catalog-page">

  <!-- Хлебные крошки -->
  <div class="sp-breadcrumbs" style="padding: 24px 48px 0;">
    <a href="<?php echo esc_url( home_url( '/' ) ); ?>">Главная</a>
    <span class="sp-breadcrumbs__sep">—</span>
    <?php if ( $current_cat ) :
      // Строим цепочку: родитель → текущая категория
      $ancestors = get_ancestors( $current_cat->term_id, 'product_cat' );
      $ancestors = array_reverse( $ancestors );
      foreach ( $ancestors as $anc_id ) :
        $anc = get_term( $anc_id, 'product_cat' );
        if ( $anc && ! is_wp_error( $anc ) ) : ?>
    <a href="<?php echo esc_url( get_term_link( $anc ) ); ?>"><?php echo esc_html( $anc->name ); ?></a>
    <span class="sp-breadcrumbs__sep">—</span>
      <?php endif; endforeach; ?>
    <span><?php echo esc_html( $current_cat->name ); ?></span>
    <?php elseif ( $current_tag ) : ?>
    <a href="<?php echo esc_url( podval_get_catalog_url() ); ?>">Магазин</a>
    <span class="sp-breadcrumbs__sep">—</span>
    <span><?php echo esc_html( $current_tag->name ); ?></span>
    <?php else : ?>
    <span>Магазин</span>
    <?php endif; ?>
  </div>

  <!-- Фильтры: Тип + Коллекции (дропдауны) -->
  <?php
  // «Тип» = дочерние Магазина (odezhda)
  $type_parent = get_term_by( 'slug', 'odezhda', 'product_cat' );
  if ( ! $type_parent ) $type_parent = get_term_by( 'slug', 'shop', 'product_cat' );
  if ( ! $type_parent ) $type_parent = get_term_by( 'slug', 'magazin', 'product_cat' );
  if ( ! $type_parent ) $type_parent = get_term_by( 'slug', 'одежда', 'product_cat' );
  $type_items = $type_parent ? get_terms([ 'taxonomy' => 'product_cat', 'parent' => $type_parent->term_id, 'hide_empty' => true, 'orderby' => 'name' ]) : [];
  if ( is_wp_error( $type_items ) ) $type_items = [];

  // «Коллекции» = дочерние Коллекций + теги коллекций
  $col_parent = get_term( 43, 'product_cat' ); // Коллекции
  $col_items = $col_parent && ! is_wp_error( $col_parent ) ? get_terms([ 'taxonomy' => 'product_cat', 'parent' => $col_parent->term_id, 'hide_empty' => true, 'orderby' => 'name' ]) : [];
  if ( is_wp_error( $col_items ) ) $col_items = [];

  // Определяем активные значения
  $active_type = '';
  $active_col  = '';
  if ( $current_cat ) {
      $ancs = get_ancestors( $current_cat->term_id, 'product_cat' );
      // Если текущая или её предок = Магазин → тип
      if ( $type_parent && ( $current_cat->term_id == $type_parent->term_id || in_array( $type_parent->term_id, $ancs ) ) ) {
          $active_type = ( $current_cat->term_id == $type_parent->term_id ) ? 'Все' : $current_cat->name;
      }
      // Если текущая или её предок = Коллекции → коллекция
      if ( $col_parent && ( $current_cat->term_id == $col_parent->term_id || in_array( $col_parent->term_id, $ancs ) ) ) {
          // Если мы на самой родительской «Коллекции» — показываем «Все»
          $active_col = ( $current_cat->term_id == $col_parent->term_id ) ? 'Все' : $current_cat->name;
      }
  }
  if ( $current_tag && $col_parent ) {
      // Определяем коллекцию по товарам тега
      $tag_prods = get_posts([ 'post_type' => 'product', 'posts_per_page' => 1, 'fields' => 'ids',
          'tax_query' => [[ 'taxonomy' => 'product_tag', 'field' => 'term_id', 'terms' => $current_tag->term_id ]] ]);
      if ( $tag_prods ) {
          $tag_cats = wp_get_post_terms( $tag_prods[0], 'product_cat', ['fields' => 'all'] );
          foreach ( $tag_cats as $tc ) {
              if ( $tc->parent == $col_parent->term_id ) { $active_col = $tc->name; break; }
          }
      }
  }
  ?>
  <nav class="catalog-filters">
    <!-- Тип -->
    <?php if ( ! empty( $type_items ) ) : ?>
    <div class="catalog-filter" data-filter="type">
      <button class="catalog-filter__btn <?php echo $active_type ? 'is-active' : ''; ?>">
        <span class="catalog-filter__label">Тип</span>
        <?php if ( $active_type ) : ?><span class="catalog-filter__value"><?php echo esc_html( $active_type ); ?></span><?php endif; ?>
        <svg class="catalog-filter__arrow" viewBox="0 0 10 6" fill="none"><path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
      </button>
      <div class="catalog-filter__drop">
        <?php if ( $type_parent ) : ?>
        <a href="<?php echo esc_url( get_term_link( $type_parent ) ); ?>" class="catalog-filter__option">Все</a>
        <?php endif; ?>
        <?php foreach ( $type_items as $ti ) : ?>
        <a href="<?php echo esc_url( get_term_link( $ti ) ); ?>"
           class="catalog-filter__option <?php echo $active_type === $ti->name ? 'is-active' : ''; ?>">
          <?php echo esc_html( $ti->name ); ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Коллекции -->
    <?php if ( ! empty( $col_items ) ) : ?>
    <div class="catalog-filter" data-filter="collection">
      <button class="catalog-filter__btn <?php echo $active_col ? 'is-active' : ''; ?>">
        <span class="catalog-filter__label">Коллекции</span>
        <?php if ( $active_col ) : ?><span class="catalog-filter__value"><?php echo esc_html( $active_col ); ?></span><?php endif; ?>
        <svg class="catalog-filter__arrow" viewBox="0 0 10 6" fill="none"><path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
      </button>
      <div class="catalog-filter__drop">
        <?php if ( $col_parent ) : ?>
        <a href="<?php echo esc_url( get_term_link( $col_parent ) ); ?>" class="catalog-filter__option">Все</a>
        <?php endif; ?>
        <?php foreach ( $col_items as $ci ) : ?>
        <a href="<?php echo esc_url( get_term_link( $ci ) ); ?>"
           class="catalog-filter__option <?php echo $active_col === $ci->name ? 'is-active' : ''; ?>">
          <?php echo esc_html( $ci->name ); ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Тэг (теги) — фильтруются по текущей коллекции -->
    <?php
    // Определяем текущую коллекцию для фильтрации тегов
    $title_tags = [];
    $active_title = $current_tag ? $current_tag->name : '';

    // Если мы в коллекции (Аниме, Фильмы) — показываем только теги этой коллекции
    $tag_source_cat = null;
    if ( $current_cat && $col_parent ) {
        $cat_ancs = get_ancestors( $current_cat->term_id, 'product_cat' );
        if ( $current_cat->parent == $col_parent->term_id || in_array( $col_parent->term_id, $cat_ancs ) ) {
            // Текущая категория — дочерняя Коллекций
            $tag_source_cat = ( $current_cat->parent == $col_parent->term_id ) ? $current_cat : null;
            foreach ( $cat_ancs as $a ) {
                $at = get_term( $a, 'product_cat' );
                if ( $at && $at->parent == $col_parent->term_id ) { $tag_source_cat = $at; break; }
            }
            if ( ! $tag_source_cat ) $tag_source_cat = $current_cat;
        }
    }
    // Если на странице тега — определяем коллекцию по товарам тега
    if ( $current_tag ) {
        // Берём любой товар с этим тегом и смотрим его коллекцию
        $tag_products = get_posts([ 'post_type' => 'product', 'posts_per_page' => 1, 'fields' => 'ids',
            'tax_query' => [[ 'taxonomy' => 'product_tag', 'field' => 'term_id', 'terms' => $current_tag->term_id ]] ]);
        if ( $tag_products && $col_parent ) {
            $pcats = wp_get_post_terms( $tag_products[0], 'product_cat', ['fields' => 'all'] );
            foreach ( $pcats as $pc ) {
                if ( $pc->parent == $col_parent->term_id ) { $tag_source_cat = $pc; break; }
            }
        }
    }

    if ( $tag_source_cat ) {
        // Теги из товаров конкретной коллекции
        $col_product_ids = get_posts([ 'post_type' => 'product', 'posts_per_page' => -1, 'fields' => 'ids',
            'tax_query' => [[ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $tag_source_cat->term_id ]] ]);
        if ( $col_product_ids ) {
            $title_tags = wp_get_object_terms( $col_product_ids, 'product_tag', [ 'orderby' => 'count', 'order' => 'DESC' ] );
            if ( is_wp_error( $title_tags ) ) $title_tags = [];
            // Убираем тег совпадающий с именем коллекции
            $title_tags = array_filter( $title_tags, function( $t ) use ( $tag_source_cat ) {
                return mb_strtolower( $t->name ) !== mb_strtolower( $tag_source_cat->name );
            } );
            $title_tags = array_values( $title_tags );
        }
    } else {
        // Все теги с товарами
        $title_tags = get_terms([ 'taxonomy' => 'product_tag', 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC', 'number' => 50 ]);
        if ( is_wp_error( $title_tags ) ) $title_tags = [];
        // Исключаем теги, имя которых совпадает с именем любой категории товаров
        if ( ! empty( $title_tags ) ) {
            $all_cat_names = get_terms([ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'names' ]);
            if ( ! is_wp_error( $all_cat_names ) && ! empty( $all_cat_names ) ) {
                $cat_names_lower = array_map( 'mb_strtolower', $all_cat_names );
                $title_tags = array_filter( $title_tags, function( $t ) use ( $cat_names_lower ) {
                    return ! in_array( mb_strtolower( $t->name ), $cat_names_lower, true );
                } );
                $title_tags = array_values( $title_tags );
            }
        }
        // Исключаем теги, которые принадлежат только товарам из «Свой дизайн» (custom-design) и её подкатегорий
        $cd_cat = get_term_by( 'slug', 'custom-design', 'product_cat' );
        if ( $cd_cat && ! is_wp_error( $cd_cat ) && ! empty( $title_tags ) ) {
            $cd_product_ids = get_posts([ 'post_type' => 'product', 'posts_per_page' => -1, 'fields' => 'ids',
                'tax_query' => [[ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cd_cat->term_id, 'include_children' => true ]] ]);
            if ( ! empty( $cd_product_ids ) ) {
                $cd_tag_ids = wp_get_object_terms( $cd_product_ids, 'product_tag', [ 'fields' => 'ids' ] );
                if ( ! is_wp_error( $cd_tag_ids ) && ! empty( $cd_tag_ids ) ) {
                    // Теги, которые есть у НЕ-custom-design товаров — оставляем
                    $non_cd_product_ids = get_posts([ 'post_type' => 'product', 'posts_per_page' => -1, 'fields' => 'ids',
                        'tax_query' => [[ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cd_cat->term_id, 'include_children' => true, 'operator' => 'NOT IN' ]] ]);
                    $safe_tag_ids = [];
                    if ( ! empty( $non_cd_product_ids ) ) {
                        $safe_tags = wp_get_object_terms( $non_cd_product_ids, 'product_tag', [ 'fields' => 'ids' ] );
                        if ( ! is_wp_error( $safe_tags ) ) $safe_tag_ids = $safe_tags;
                    }
                    $only_cd_tag_ids = array_diff( $cd_tag_ids, $safe_tag_ids );
                    if ( ! empty( $only_cd_tag_ids ) ) {
                        $title_tags = array_filter( $title_tags, function( $t ) use ( $only_cd_tag_ids ) {
                            return ! in_array( $t->term_id, $only_cd_tag_ids, true );
                        } );
                        $title_tags = array_values( $title_tags );
                    }
                }
            }
        }
    }
    ?>
    <?php if ( ! empty( $title_tags ) ) : ?>
    <div class="catalog-filter" data-filter="title">
      <button class="catalog-filter__btn <?php echo $active_title ? 'is-active' : ''; ?>">
        <span class="catalog-filter__label">Тэг</span>
        <?php if ( $active_title ) : ?><span class="catalog-filter__value"><?php echo esc_html( $active_title ); ?></span><?php endif; ?>
        <svg class="catalog-filter__arrow" viewBox="0 0 10 6" fill="none"><path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
      </button>
      <div class="catalog-filter__drop">
        <?php if ( $tag_source_cat ) : ?>
        <a href="<?php echo esc_url( get_term_link( $tag_source_cat ) ); ?>" class="catalog-filter__option">Все</a>
        <?php endif; ?>
        <?php foreach ( $title_tags as $tt ) : ?>
        <a href="<?php echo esc_url( get_term_link( $tt ) ); ?>"
           class="catalog-filter__option <?php echo $active_title === $tt->name ? 'is-active' : ''; ?>">
          <?php echo esc_html( $tt->name ); ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </nav>

  <div class="catalog-toolbar">
    <div class="catalog-toolbar__left">
      <?php if ( $current_cat ) : ?>
        <h1 class="catalog-title"><?php echo esc_html( $current_cat->name ); ?></h1>
      <?php elseif ( $current_tag ) : ?>
        <h1 class="catalog-title"><?php echo esc_html( $current_tag->name ); ?></h1>
      <?php endif; ?>
    </div>
  </div>

  <?php if ( ! empty( $marquee_tags ) ) : ?>
  <div class="catalog-marquee">
    <div class="catalog-marquee__track">
      <div class="catalog-marquee__set">
        <?php foreach ( $marquee_tags as $mi ) : ?>
        <a href="<?php echo esc_url( $mi['url'] ); ?>" class="catalog-marquee__item" title="<?php echo esc_attr( $mi['name'] ); ?>">
          <img src="<?php echo esc_url( $mi['img'] ); ?>" alt="<?php echo esc_attr( $mi['name'] ); ?>" loading="lazy">
        </a>
        <?php endforeach; ?>
      </div>
      <div class="catalog-marquee__set">
        <?php foreach ( $marquee_tags as $mi ) : ?>
        <a href="<?php echo esc_url( $mi['url'] ); ?>" class="catalog-marquee__item" title="<?php echo esc_attr( $mi['name'] ); ?>">
          <img src="<?php echo esc_url( $mi['img'] ); ?>" alt="<?php echo esc_attr( $mi['name'] ); ?>" loading="lazy">
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ( have_posts() ) : ?>
    <div class="catalog-grid">
      <?php
      $card_index = 0;
      $banner_rendered = false;

      while ( have_posts() ) : the_post();
        global $product;
        $product = wc_get_product( get_the_ID() );
        if ( ! $product ) continue;

        $card_index++;
        get_template_part( 'template-parts/content/product', 'card' );

        // Вставляем баннер после N-й карточки
        if ( $has_banner && ! $banner_rendered && $card_index === $banner_after ) :
          $banner_rendered = true;
      ?>
      </div><!-- /catalog-grid (split) -->

      <div class="catalog-banner" style="--bg: url(<?php echo esc_url( $banner_img ); ?>)">
        <?php if ( $banner_url ) : ?>
          <a href="<?php echo esc_url( $banner_url ); ?>" class="catalog-banner__link" aria-hidden="true"></a>
        <?php endif; ?>
        <div class="catalog-banner__body">
          <?php if ( $banner_title ) : ?>
            <div class="catalog-banner__title"><?php echo esc_html( $banner_title ); ?></div>
          <?php endif; ?>
          <?php if ( $banner_sub ) : ?>
            <div class="catalog-banner__sub"><?php echo esc_html( $banner_sub ); ?></div>
          <?php endif; ?>
          <?php if ( $banner_btn && $banner_url ) : ?>
            <a href="<?php echo esc_url( $banner_url ); ?>" class="btn btn--primary"><?php echo esc_html( $banner_btn ); ?><svg viewBox="0 0 14 14"><path d="M1 7h12M8 2l5 5-5 5"/></svg></a>
          <?php endif; ?>
        </div>
      </div>

      <div class="catalog-grid">
      <?php
        endif;
      endwhile;
      ?>
    </div>
  <?php else : ?>
    <p style="text-align:center;padding:60px 20px;color:#888;">Товары не найдены.</p>
  <?php endif; ?>

</div>

<?php get_footer(); ?>

<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php
/* ── Critical CSS inline — мгновенный first paint без блокировки рендера ── */
$critical_css_file = get_template_directory() . '/assets/css/critical.css';
if ( file_exists( $critical_css_file ) ) {
    $critical_css = file_get_contents( $critical_css_file );
    // Inline CSS не имеет base URL — заменяем относительные пути на абсолютные
    $critical_css = str_replace( './assets/', get_template_directory_uri() . '/assets/', $critical_css );
    echo '<style id="critical-css">' . $critical_css . '</style>' . "\n";
}
/* бейдж для пункта меню «Бандлы». Inline (а не в style-core.css),
   чтобы остальные модули остались нетронутыми. ~200 байт. */
?>
<style id="nav-tag-css">.nav-tag{display:inline-flex;align-items:center;height:14px;padding:0 5px;margin-left:6px;background:#ff6a00;color:#0a0a0a;font-family:"Unbounded",Arial,sans-serif;font-size:8px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;border-radius:2px;vertical-align:middle;line-height:1;flex-shrink:0;white-space:nowrap}.nav-tag--new{background:#ff6a00}</style>
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<div class="site-wrapper">

  <!-- ===== TOP BAR ===== -->
  <?php
    $ticker_hide   = get_theme_mod( 'podval_ticker_hide', false );
    $ticker_hide   = ! empty( $ticker_hide );
    $ticker_text   = get_theme_mod( 'podval_ticker_text', '' );
    $ticker_url    = get_theme_mod( 'podval_ticker_url', '' );
    $ticker_repeat = (bool) get_theme_mod( 'podval_ticker_repeat', false );

    // Делаем ссылку портабельной — извлекаем путь если домен совпадает
    if ( $ticker_url ) {
        $site_host = wp_parse_url( home_url(), PHP_URL_HOST );
        $link_host = wp_parse_url( $ticker_url, PHP_URL_HOST );
        if ( $link_host && $link_host === $site_host ) {
            $path = wp_parse_url( $ticker_url, PHP_URL_PATH ) ?: '/';
            $query = wp_parse_url( $ticker_url, PHP_URL_QUERY );
            $ticker_url = home_url( $path . ( $query ? '?' . $query : '' ) );
        }
    }

    if ( $ticker_repeat ) {
        $clean = wp_strip_all_tags( html_entity_decode( $ticker_text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        // Разделяем по ✦, +, |, • или другим разделителям (Unicode-safe)
        $parts = preg_split( '/[\x{2726}\x{2022}\x{272A}✦✶•|+]+/u', $clean );
        if ( ! is_array( $parts ) ) $parts = array( $clean ); // fallback если regex failed
        $words = [];
        foreach ( $parts as $p ) {
            $w = strtoupper( trim( $p ) );
            if ( $w !== '' ) $words[] = esc_html( $w );
        }
        if ( empty( $words ) ) $words = [ strtoupper( trim( $clean ) ) ];
        // Дублируем слова до 20+ для заполнения экрана
        while ( count( $words ) < 20 ) {
            $words = array_merge( $words, $words );
        }
        $words = array_slice( $words, 0, 30 );
        $ticker_rendered = implode( ' <span class="site-topbar__sep">✦</span> ', $words );
    } else {
        $ticker_rendered = wp_kses_post( $ticker_text );
    }
  ?>
  <?php if ( ! $ticker_hide && ! empty( trim( wp_strip_all_tags( $ticker_text ) ) ) ) : ?>
  <div class="site-topbar<?php echo $ticker_repeat ? ' site-topbar--repeat' : ''; ?>">
    <?php if ( $ticker_url ) : ?>
    <a href="<?php echo esc_url( $ticker_url ); ?>" class="site-topbar__link">
    <?php endif; ?>
      <div class="site-topbar__track">
        <div class="site-topbar__inner"><?php echo $ticker_rendered; ?></div>
        <div class="site-topbar__inner" aria-hidden="true"><?php echo $ticker_rendered; ?></div>
      </div>
    <?php if ( $ticker_url ) : ?>
    </a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- ===== HEADER ===== -->
  <?php
  // Данные корзины — вычисляем один раз
  $wc_active   = class_exists( 'WooCommerce' );
  $cart_count  = $wc_active && WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
  $cart_url    = $wc_active && function_exists('wc_get_cart_url')  ? wc_get_cart_url()  : home_url('/cart/');
  $account_url = $wc_active && function_exists('wc_get_page_id')   ? get_permalink( wc_get_page_id('myaccount') ) : home_url('/my-account/');

  // Пункты навигации — кешируем, чтобы не делать ~20 SQL запросов на каждом pageview
  // bump v4 → v5 после добавления пункта «Бандлы» в навигацию.
  // bump v5 → v6 — мега-меню «Услуги» строится из лендингов (mega_svc_groups).
  // ВАЖНО: ключ должен совпадать с delete_transient() в functions.php → podval_flush_all_cache().
  $nav_cache_key = 'podval_nav_data_v6';
  $nav_cached = get_transient( $nav_cache_key );

  if ( $nav_cached !== false ) {
      $nav_items      = $nav_cached['nav_items'];
      $mega_col_data  = $nav_cached['mega_col_data'];
      $mega_col_cards = $nav_cached['mega_col_cards'];
      $mega_svc_data  = $nav_cached['mega_svc_data'];
      $mega_svc_groups = $nav_cached['mega_svc_groups'] ?? [];
      $mega_new_ids   = $nav_cached['mega_new_ids'] ?? [];
  } else {

  $nav_items = [];
  $exclude_slugs_raw = get_theme_mod( 'podval_nav_exclude_slugs', '' );
  $exclude_slugs = array_filter( array_map( 'trim', explode( ',', $exclude_slugs_raw ) ) );
  // Всегда скрываем служебные категории из навигации
  $exclude_slugs[] = 'kollaboracii';
  $exclude_slugs[] = 'anime';
  $exclude_slugs[] = 'base';

  $nav_cats = get_terms( array(
      'taxonomy'   => 'product_cat',
      'parent'     => 0,
      'hide_empty' => false,
      'orderby'    => 'menu_order',
      'order'      => 'ASC',
      'exclude'    => array( (int) get_option( 'default_product_cat' ) ),
  ) );
  if ( ! is_wp_error( $nav_cats ) && ! empty( $nav_cats ) ) {
      foreach ( $nav_cats as $cat ) {
          if ( in_array( $cat->slug, $exclude_slugs, true ) ) continue;

          $children = get_terms( array(
              'taxonomy'   => 'product_cat',
              'parent'     => $cat->term_id,
              'hide_empty' => false,
              'number'     => 1,
          ) );
          $has_children = ! is_wp_error( $children ) && ! empty( $children );

          // Определяем тип мега-меню
          $menu_type = 'default';
          if ( mb_strtolower( $cat->name ) === 'коллекции' || $cat->slug === '%d1%81ollections' ) {
              $menu_type = 'mega_collections';
          } elseif ( $cat->slug === 'uslugi' ) {
              $menu_type = 'mega_services';
          }

          // Прекешируем children для обычных меню
          $cached_children = [];
          if ( $menu_type === 'default' && $has_children ) {
              $ch = get_terms([ 'taxonomy' => 'product_cat', 'parent' => $cat->term_id, 'hide_empty' => false, 'orderby' => 'name' ]);
              if ( ! is_wp_error( $ch ) ) {
                  foreach ( $ch as $child ) {
                      $cached_children[] = [
                          'name' => $child->name,
                          'url'  => get_term_link( $child ),
                      ];
                  }
              }
          }

          $nav_items[] = [
              'label'        => $cat->name,
              'slug'         => $cat->slug,
              'term_id'      => $cat->term_id,
              'has_children' => $has_children,
              'type'         => $menu_type,
              'url'          => get_term_link( $cat ),
              'children'     => $cached_children,
          ];
      }
  }

  // ── Коллекции — в конец ──
  $collections_key = null;
  foreach ( $nav_items as $i => $ni ) {
      if ( $ni['type'] === 'mega_collections' ) {
          $collections_key = $i;
          break;
      }
  }
  if ( $collections_key !== null ) {
      $collections_item = $nav_items[ $collections_key ];
      array_splice( $nav_items, $collections_key, 1 );
      $nav_items[] = $collections_item;
  }

  // ── Бандлы — кастомный пункт в самый конец.
  // Не product_cat, а отдельная WP-страница с шаблоном page-bundles.php.
  // Условие отображения: страница существует и опубликована.
  $bundles_page = get_page_by_path( 'bundles' );
  $bundles_visible = function_exists( 'podval_bundles_are_public' ) && podval_bundles_are_public();
  if ( $bundles_visible && $bundles_page && $bundles_page->post_status === 'publish' ) {
      $nav_items[] = [
          'label'        => 'Бандлы',
          'slug'         => 'bundles',
          'term_id'      => 0,
          'has_children' => false,
          'type'         => 'default',
          'url'          => get_permalink( $bundles_page ),
          'children'     => [],
          'tag'          => 'NEW', // показываем бейдж справа от названия
      ];
  }

  // ── Данные для мега-меню Коллекций ──
  // Категории, которые в мегаменю отображаются свёрнуто (только название, без списка тегов).
  // По умолчанию это «Музыка» и «Фильмы» — там много мелких тегов с 1-2 товарами.
  // Можно переопределить через фильтр podval_mega_collapsed_categories.
  $collapsed_cat_names = apply_filters( 'podval_mega_collapsed_categories', [ 'музыка', 'фильмы' ] );

  $mega_col_data = [];
  $mega_col_cards = [];
  $col_parent = get_term( 43, 'product_cat' );
  if ( ! $col_parent || is_wp_error( $col_parent ) ) {
      $col_parent = get_term_by( 'slug', '%d1%81ollections', 'product_cat' );
  }
  if ( $col_parent && ! is_wp_error( $col_parent ) ) {
      $col_children = get_terms([ 'taxonomy' => 'product_cat', 'parent' => $col_parent->term_id, 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC' ]);
      if ( ! is_wp_error( $col_children ) ) {
          foreach ( $col_children as $cc ) {
              $cc_prod_ids = get_posts([ 'post_type' => 'product', 'posts_per_page' => -1, 'fields' => 'ids',
                  'tax_query' => [[ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cc->term_id ]] ]);
              $cc_tags = [];
              if ( ! empty( $cc_prod_ids ) ) {
                  $raw = wp_get_object_terms( $cc_prod_ids, 'product_tag', [ 'orderby' => 'count', 'order' => 'DESC' ] );
                  if ( ! is_wp_error( $raw ) ) {
                      foreach ( $raw as $t ) {
                          if ( mb_strtolower($t->name) !== mb_strtolower($cc->name) ) $cc_tags[] = $t;
                      }
                  }
              }
              $cc_collapsed = in_array( mb_strtolower( $cc->name ), $collapsed_cat_names, true );
              $mega_col_data[] = [
                  'term'      => $cc,
                  'term_url'  => get_term_link( $cc ),
                  'tags'      => $cc_tags,
                  'collapsed' => $cc_collapsed,
              ];
          }
      }
      // 5 случайных карточек-тегов для правой части: 1 большая + 4 маленьких (бенто)
      $all_mega_tags = [];
      foreach ( $mega_col_data as $mcd ) {
          foreach ( $mcd['tags'] as $t ) {
              $all_mega_tags[ $t->term_id ] = $t;
          }
      }
      $all_mega_tags = array_values( $all_mega_tags );
      shuffle( $all_mega_tags );
      $mega_col_cards = array_slice( $all_mega_tags, 0, 5 );
  }

  // ── Данные для мега-меню Услуг ──
  // основной источник — опубликованные лендинги, три колонки
  // (podval_sl_nav_groups). Пока ни одного лендинга нет — прежняя выборка
  // двух товаров-услуг, чтобы меню не опустело до публикации.
  $mega_svc_groups = function_exists( 'podval_sl_nav_groups' ) ? podval_sl_nav_groups() : [];
  $mega_svc_data = [];
  $uslugi_parent = empty( $mega_svc_groups ) ? get_term_by( 'slug', 'uslugi', 'product_cat' ) : false;
  if ( $uslugi_parent ) {
      $svc_q = new WP_Query([
          'post_type' => 'product', 'posts_per_page' => 2,
          'tax_query' => [[ 'taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => 'uslugi', 'include_children' => true ]],
          'orderby' => 'menu_order', 'order' => 'ASC',
      ]);
      if ( $svc_q->have_posts() ) {
          while ( $svc_q->have_posts() ) {
              $svc_q->the_post();
              $mega_svc_data[] = [
                  'name'  => get_the_title(),
                  'url'   => get_permalink(),
                  'thumb' => get_the_post_thumbnail_url( get_the_ID(), 'medium' ),
              ];
          }
          wp_reset_postdata();
      }
  }

  // ── Новинки для мегаменю (бейдж 'new', 4 случайных) ──
  $mega_new_ids = [];
  $mega_new_products_raw = podval_get_products_by_badge( 'new', 4, true );
  foreach ( $mega_new_products_raw as $np ) {
      $mega_new_ids[] = $np->get_id();
  }

  // Сохраняем в transient на 6 часов
  set_transient( $nav_cache_key, [
      'nav_items'      => $nav_items,
      'mega_col_data'  => $mega_col_data,
      'mega_col_cards' => $mega_col_cards,
      'mega_svc_data'  => $mega_svc_data,
      'mega_svc_groups' => $mega_svc_groups,
      'mega_new_ids'   => $mega_new_ids,
  ], 6 * HOUR_IN_SECONDS );

  } // end !$nav_cached

  ?>

  <header class="site-header" id="site-header">
    <div class="header-inner">

      <!-- БУРГЕР — только на мобиле (слева через DOM) -->
      <button class="menu-toggle js-menu-toggle" aria-label="Меню" aria-expanded="false">
        <span></span><span></span><span></span>
      </button>

      <!-- ① ЛОГОТИП — левый край (десктоп) / центр (мобиле через CSS) -->
      <div class="site-logo">
        <a href="<?php echo esc_url( home_url('/') ); ?>" rel="home" aria-label="<?php echo esc_attr( get_bloginfo('name') ); ?> — на главную">
          <?php if ( has_custom_logo() ) : the_custom_logo();
          else : echo '<span class="site-logo__text">' . esc_html( get_bloginfo('name') ) . '</span>';
          endif; ?>
        </a>
      </div>

      <!-- ② НАВИГАЦИЯ — центр -->
      <nav class="primary-nav" aria-label="Главное меню">
        <ul class="nav-list">

          <?php foreach ( $nav_items as $item ) :
            $url       = $item['url'] ?? '#';
            $children  = $item['children'] ?? [];
            $menu_type = $item['type'] ?? 'default';

            $has_drop = ( $menu_type === 'mega_collections' && ! empty( $mega_col_data ) )
                     || ( $menu_type === 'mega_services' && ( ! empty( $mega_svc_groups ) || ! empty( $mega_svc_data ) ) )
                     || ( $menu_type === 'default' && ! empty( $children ) );
          ?>

          <li class="nav-item<?php echo $has_drop ? ' has-dropdown' : ''; ?><?php echo $menu_type !== 'default' ? ' has-mega' : ''; ?>">
            <a href="<?php echo esc_url( $url ); ?>" class="nav-link">
              <?php echo esc_html( $item['label'] ); ?>
              <?php if ( ! empty( $item['tag'] ) ) : ?>
              <span class="nav-tag nav-tag--new"><?php echo esc_html( $item['tag'] ); ?></span>
              <?php endif; ?>
              <?php if ( $has_drop ) : ?>
              <svg width="20" height="12" class="nav-arrow" viewBox="0 0 10 6" fill="none">
                <path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
              </svg>
              <?php endif; ?>
            </a>

            <?php if ( $menu_type === 'mega_collections' && ! empty( $mega_col_data ) ) : ?>
            <!-- МЕГА-МЕНЮ: Коллекции -->
            <div class="mega-dropdown mega-dropdown--collections">
              <div class="mega-dropdown__inner">
                <div class="mega-dropdown__left">
                  <?php
                  // Сначала рендерим раскрытые категории (АНИМЕ, ГИК, ИГРЫ)
                  foreach ( $mega_col_data as $mcd ) :
                    if ( ! empty( $mcd['collapsed'] ) ) continue;
                    $cc = $mcd['term'];
                    $cc_url = $mcd['term_url'] ?? get_term_link( $cc );
                  ?>
                  <div class="mega-col-group">
                    <a href="<?php echo esc_url( $cc_url ); ?>" class="mega-col-group__title"><?php echo esc_html( $cc->name ); ?></a>
                    <div class="mega-col-group__tags">
                      <?php foreach ( $mcd["tags"] as $tag ) :
                        $tag_url = get_term_link( $tag );
                        if ( is_wp_error( $tag_url ) ) continue;
                      ?>
                      <a href="<?php echo esc_url( $tag_url ); ?>" class="mega-tag-link"><?php echo esc_html( $tag->name ); ?></a>
                      <?php endforeach; ?>
                    </div>
                  </div>
                  <?php endforeach; ?>

                  <?php
                  // Затем собираем свёрнутые (Музыка, Фильмы) в один стек справа от раскрытых
                  $collapsed_groups = array_filter( $mega_col_data, function( $mcd ) {
                      return ! empty( $mcd['collapsed'] );
                  } );
                  if ( ! empty( $collapsed_groups ) ) : ?>
                  <div class="mega-col-group mega-col-group--collapsed-stack">
                    <?php foreach ( $collapsed_groups as $mcd ) :
                      $cc = $mcd['term'];
                      $cc_url = $mcd['term_url'] ?? get_term_link( $cc );
                    ?>
                    <a href="<?php echo esc_url( $cc_url ); ?>" class="mega-col-group__title mega-col-group__title--collapsed"><?php echo esc_html( $cc->name ); ?></a>
                    <?php endforeach; ?>
                  </div>
                  <?php endif; ?>
                </div>
                <div class="mega-dropdown__right">
                  <div class="mega-dropdown__mosaic">
                  <?php if ( ! empty( $mega_col_cards ) ) : $mc_i = 0; foreach ( $mega_col_cards as $mc_tag ) :
                    $mc_url = get_term_link( $mc_tag );
                    if ( is_wp_error( $mc_url ) ) continue;
                    $mc_img  = podval_get_tag_image_url( $mc_tag );
                    $mc_class = 'mega-tag-card' . ( $mc_i === 0 ? ' mega-tag-card--featured' : '' );
                    $mc_i++;
                  ?>
                  <a href="<?php echo esc_url( $mc_url ); ?>" class="<?php echo esc_attr( $mc_class ); ?>">
                    <div class="mega-tag-card__bg" style="<?php echo $mc_img ? 'background-image:url('.esc_url($mc_img).')' : ''; ?>"></div>
                    <div class="mega-tag-card__overlay"></div>
                    <span class="mega-tag-card__name"><?php echo esc_html( $mc_tag->name ); ?></span>
                  </a>
                  <?php endforeach; endif; ?>
                  </div>

                  <?php
                  // ── Новинки ──
                  $mega_new_products = [];
                  if ( ! empty( $mega_new_ids ) ) {
                      foreach ( $mega_new_ids as $_np_id ) {
                          $_np = wc_get_product( $_np_id );
                          if ( $_np && $_np->is_visible() ) $mega_new_products[] = $_np;
                      }
                  }
                  if ( ! empty( $mega_new_products ) ) : ?>
                  <div class="mega-dropdown__news">
                    <div class="mega-news__head">
                      <span class="mega-news__title">Новинки</span>
                      <span class="mega-news__dot"></span>
                    </div>
                    <div class="mega-news__grid">
                      <?php foreach ( $mega_new_products as $np ) :
                        $np_id    = $np->get_id();
                        $np_url   = $np->get_permalink();
                        $np_name  = $np->get_name();
                        // Для вариативных товаров — минимальная цена, как в основной карточке
                        if ( $np->is_type('variable') ) {
                            $np_price = wc_price( $np->get_variation_price( 'min', true ) );
                        } else {
                            $np_price = $np->get_price_html();
                        }
                        $np_img   = get_the_post_thumbnail_url( $np_id, 'medium' );
                        if ( ! $np_img ) $np_img = wc_placeholder_img_src( 'medium' );
                      ?>
                      <a href="<?php echo esc_url( $np_url ); ?>" class="mega-news-card">
                        <div class="mega-news-card__img">
                          <img src="<?php echo esc_url( $np_img ); ?>" alt="<?php echo esc_attr( $np_name ); ?>" width="220" height="220" loading="lazy" decoding="async">
                        </div>
                        <div class="mega-news-card__body">
                          <div class="mega-news-card__name"><?php echo esc_html( $np_name ); ?></div>
                          <div class="mega-news-card__price"><?php echo wp_kses_post( $np_price ); ?></div>
                        </div>
                      </a>
                      <?php endforeach; ?>
                    </div>
                  </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>

            <?php elseif ( $menu_type === 'mega_services' && ! empty( $mega_svc_groups ) ) : ?>
            <!-- МЕГА-МЕНЮ: Услуги — колонки лендингов -->
            <div class="mega-dropdown mega-dropdown--services">
              <div class="mega-dropdown__inner mega-svc-cols">
                <?php foreach ( $mega_svc_groups as $grp ) : ?>
                <div class="mega-svc-col">
                  <div class="mega-svc-col__title"><?php echo esc_html( $grp['label'] ); ?></div>
                  <ul class="mega-svc-col__list">
                    <?php foreach ( $grp['items'] as $it ) : ?>
                    <li><a href="<?php echo esc_url( $it['url'] ); ?>" class="mega-svc-col__link"><?php echo esc_html( $it['title'] ); ?></a></li>
                    <?php endforeach; ?>
                  </ul>
                </div>
                <?php endforeach; ?>
                <div class="mega-svc-col mega-svc-col--all">
                  <a href="<?php echo esc_url( $url ); ?>" class="mega-svc-col__all">Все услуги →</a>
                </div>
              </div>
            </div>

            <?php elseif ( $menu_type === 'mega_services' && ! empty( $mega_svc_data ) ) : ?>
            <!-- МЕГА-МЕНЮ: Услуги (фолбэк до публикации лендингов) -->
            <div class="mega-dropdown mega-dropdown--services">
              <div class="mega-dropdown__inner">
                <?php foreach ( $mega_svc_data as $svc ) : ?>
                <a href="<?php echo esc_url( $svc['url'] ); ?>" class="mega-svc-card">
                  <?php if ( $svc['thumb'] ) : ?>
                  <div class="mega-svc-card__img" style="background-image:url(<?php echo esc_url( $svc['thumb'] ); ?>)"></div>
                  <?php endif; ?>
                  <span class="mega-svc-card__name"><?php echo esc_html( $svc['name'] ); ?></span>
                </a>
                <?php endforeach; ?>
              </div>
            </div>

            <?php elseif ( $has_drop && $menu_type === 'default' ) : ?>
            <div class="nav-dropdown">
              <?php foreach ( $children as $child ) : ?>
              <a href="<?php echo esc_url( $child['url'] ); ?>"
                 class="nav-dropdown-item">
                <?php echo esc_html( $child['name'] ); ?>
              </a>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </li>

          <?php endforeach; ?>

        </ul>
      </nav>

      <!-- ③ КОНТАКТЫ + ИКОНКИ — правый край -->
      <div class="header-actions">

        <?php
          $header_phone = get_theme_mod( 'podval_header_phone', podval_brand( 'phone' ) );
          $header_tg    = get_theme_mod( 'podval_header_tg',    '' );
          // Для tel: убираем всё кроме + и цифр
          $tel_clean = preg_replace( '/[^\d+]/', '', $header_phone );
        ?>

        <!-- Телефон -->
        <?php if ( $header_phone ) : ?>
        <a href="tel:<?php echo esc_attr( $tel_clean ); ?>" class="header-phone" aria-label="<?php echo esc_attr( sprintf( 'Позвонить: %s', $header_phone ) ); ?>">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 1.27h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L7.91 8.91a16 16 0 0 0 6 6l.91-.91a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 21.73 16.92z"/>
          </svg>
          <span><?php echo esc_html( $header_phone ); ?></span>
        </a>
        <?php endif; ?>

        <!-- Telegram -->
        <?php if ( $header_tg ) : ?>
        <a href="<?php echo esc_url( $header_tg ); ?>" class="header-action-btn header-tg-btn" aria-label="Telegram" target="_blank" rel="noopener">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
            <path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.894 8.221-1.97 9.28c-.145.658-.537.818-1.084.508l-3-2.21-1.447 1.394c-.16.16-.295.295-.605.295l.213-3.053 5.56-5.023c.242-.213-.054-.333-.373-.12L7.17 13.6l-2.95-.924c-.64-.204-.657-.64.136-.954l11.57-4.461c.537-.194 1.006.131.968.96z"/>
          </svg>
        </a>
        <?php endif; ?>

        <!-- Поиск -->
        <button class="header-action-btn js-search-toggle" aria-label="Поиск">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
          </svg>
        </button>

        <?php if ( $wc_active ) : ?>

        <?php if ( get_theme_mod( 'podval_currency_enabled', true ) ) : ?>
        <!-- Переключатель валюты -->
        <div class="currency-switch js-currency-switch" aria-label="Выбор валюты"></div>
        <?php endif; ?>

        <!-- О нас -->
        <a href="<?php echo esc_url( podval_url( 'about' ) ); ?>"
           class="header-action-btn header-action-btn--text" aria-label="О нас" title="О нас">О нас</a>

        <!-- Корзина -->
        <a href="<?php echo esc_url( $cart_url ); ?>" class="header-action-btn js-cart-icon" aria-label="Корзина">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M9 22c.55 0 1-.45 1-1s-.45-1-1-1-1 .45-1 1 .45 1 1 1zm11 0c.55 0 1-.45 1-1s-.45-1-1-1-1 .45-1 1 .45 1 1 1z"/>
            <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
          </svg>
          <span class="header-action-count js-cart-count<?php echo $cart_count > 0 ? '' : ' is-empty'; ?>"><?php echo esc_html( $cart_count ); ?></span>
        </a>

        <?php endif; ?>

      </div><!-- /header-actions -->

    </div><!-- /header-inner -->
  </header>

  <!-- ===== МОБИЛЬНОЕ МЕНЮ (drawer) ===== -->
  <?php
    // Данные для мобильного меню из Customizer (те же что в футере)
    $mob_phone   = get_theme_mod( 'podval_header_phone', get_theme_mod( 'podval_footer_phone', '' ) );
    $mob_tel     = preg_replace( '/[^\d+]/', '', $mob_phone );
    $mob_email   = get_theme_mod( 'podval_footer_email', '' );
    $mob_tg      = get_theme_mod( 'podval_header_tg',    get_theme_mod( 'podval_footer_tg', '' ) );
    $mob_ig      = get_theme_mod( 'podval_footer_ig',    '' );
    $mob_yt      = get_theme_mod( 'podval_footer_yt',    '' );
    $mob_tt      = get_theme_mod( 'podval_footer_tt',    '' );
    $mob_maps    = get_theme_mod( 'podval_footer_maps',  '' );
    $mob_order_q = get_theme_mod( 'podval_mob_orders_label',  'Вопросы о заказах' );
    $mob_podval_q = get_theme_mod( 'podval_mob_podval_label',   'Производство мерча' );
    // Ссылки «Информация» (из 3й колонки футера)
    $mob_info_links = [];
    for ( $i = 1; $i <= 6; $i++ ) {
      $lbl = get_theme_mod( "podval_footer_link{$i}_label", '' );
      $url = get_theme_mod( "podval_footer_link{$i}_url",   '' );
      if ( $lbl ) $mob_info_links[] = [ 'label' => $lbl, 'url' => $url ?: '#' ];
    }
    
  ?>
  <div class="mnav" id="mnav" aria-hidden="true" inert>
    <div class="mnav__overlay js-mnav-close"></div>
    <div class="mnav__panel">

      <!-- Шапка панели: лого + крестик -->
      <div class="mnav__header">
        <a href="<?php echo esc_url( home_url('/') ); ?>" class="mnav__logo">
          <?php if ( has_custom_logo() ) : the_custom_logo();
          else : echo '<span class="mnav__logo-text">' . esc_html( get_bloginfo('name') ) . '</span>';
          endif; ?>
        </a>
        <button class="mnav__close js-mnav-close" aria-label="Закрыть">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M18 6 6 18M6 6l12 12"/>
          </svg>
        </button>
      </div>

      <div class="mnav__body">

        <!-- Главное меню -->
        <nav class="mnav__nav">
          <?php foreach ( $nav_items as $item ) :
            $url       = $item['url'] ?? '#';
            $menu_type = $item['type'] ?? 'default';
            $children  = $item['children'] ?? [];
            $has_ch = ( $menu_type === 'mega_collections' && ! empty( $mega_col_data ) )
                   || ( $menu_type === 'default' && ! empty( $children ) );
          ?>
            <?php if ( $menu_type === 'mega_collections' && ! empty( $mega_col_data ) ) : ?>
            <!-- Коллекции: 2-уровневый аккордеон -->
            <div class="mnav__row mnav__row--parent js-mnav-toggle">
              <span><?php echo esc_html( $item['label'] ); ?></span>
              <svg width="20" height="20" class="mnav__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M9 18l6-6-6-6"/>
              </svg>
            </div>
            <div class="mnav__sub">
              <a href="<?php echo esc_url($url); ?>" class="mnav__sub-item mnav__sub-all">
                Смотреть все
              </a>
              <?php foreach ( $mega_col_data as $mcd ) :
                $cc = $mcd['term'];
                $cc_url = $mcd['term_url'] ?? get_term_link( $cc );
              ?>
                <?php if ( ! empty( $mcd['collapsed'] ) ) : ?>
                <!-- Свёрнутая категория (Музыка/Фильмы): прямая ссылка без раскрытия тегов -->
                <a href="<?php echo esc_url( $cc_url ); ?>" class="mnav__sub-item">
                  <?php echo esc_html( $cc->name ); ?>
                </a>
                <?php else : ?>
                <div class="mnav__sub-item mnav__sub-item--toggle js-mnav-toggle-sub">
                  <span><?php echo esc_html( $cc->name ); ?></span>
                  <svg width="20" height="20" class="mnav__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path d="M9 18l6-6-6-6"/>
                  </svg>
                </div>
                <div class="mnav__sub-tags">
                  <a href="<?php echo esc_url( $cc_url ); ?>" class="mnav__sub-item mnav__sub-item--tag">Смотреть все</a>
                  <?php foreach ( $mcd["tags"] as $tag ) :
                    $tag_url = get_term_link( $tag );
                    if ( is_wp_error( $tag_url ) ) continue;
                  ?>
                  <a href="<?php echo esc_url( $tag_url ); ?>" class="mnav__sub-item mnav__sub-item--tag">
                    <?php echo esc_html( $tag->name ); ?>
                  </a>
                  <?php endforeach; ?>
                </div>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>

            <?php elseif ( $menu_type === 'mega_services' && ! empty( $mega_svc_groups ) ) : ?>
            <!-- Услуги: аккордеон по колонкам лендингов -->
            <div class="mnav__row mnav__row--parent js-mnav-toggle">
              <span><?php echo esc_html( $item['label'] ); ?></span>
              <svg width="20" height="20" class="mnav__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M9 18l6-6-6-6"/>
              </svg>
            </div>
            <div class="mnav__sub">
              <a href="<?php echo esc_url( $url ); ?>" class="mnav__sub-item mnav__sub-all">Все услуги</a>
              <?php foreach ( $mega_svc_groups as $grp ) : ?>
              <div class="mnav__sub-item mnav__sub-item--toggle js-mnav-toggle-sub">
                <span><?php echo esc_html( $grp['label'] ); ?></span>
                <svg width="20" height="20" class="mnav__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                  <path d="M9 18l6-6-6-6"/>
                </svg>
              </div>
              <div class="mnav__sub-tags">
                <?php foreach ( $grp['items'] as $it ) : ?>
                <a href="<?php echo esc_url( $it['url'] ); ?>" class="mnav__sub-item mnav__sub-item--tag"><?php echo esc_html( $it['short'] ); ?></a>
                <?php endforeach; ?>
              </div>
              <?php endforeach; ?>
            </div>

            <?php elseif ( $menu_type === 'mega_services' ) : ?>
            <!-- Услуги: прямая ссылка -->
            <a class="mnav__row" href="<?php echo esc_url($url); ?>">
              <span><?php echo esc_html( $item['label'] ); ?></span>
            </a>

            <?php elseif ( $has_ch ) : ?>
            <div class="mnav__row mnav__row--parent js-mnav-toggle">
              <span><?php echo esc_html( $item['label'] ); ?></span>
              <svg width="20" height="20" class="mnav__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M9 18l6-6-6-6"/>
              </svg>
            </div>
            <div class="mnav__sub">
              <a href="<?php echo esc_url($url); ?>" class="mnav__sub-item mnav__sub-all">
                Смотреть все
              </a>
              <?php foreach ( $children as $child ) : ?>
              <a href="<?php echo esc_url( $child['url'] ); ?>" class="mnav__sub-item">
                <?php echo esc_html( $child['name'] ); ?>
              </a>
              <?php endforeach; ?>
            </div>
            <?php else : ?>
            <a class="mnav__row" href="<?php echo esc_url($url); ?>">
              <span><?php echo esc_html( $item['label'] ); ?>
                <?php if ( ! empty( $item['tag'] ) ) : ?>
                <span class="nav-tag nav-tag--new"><?php echo esc_html( $item['tag'] ); ?></span>
                <?php endif; ?>
              </span>
            </a>
            <?php endif; ?>
          <?php endforeach; ?>
        </nav>

        <?php if ( get_theme_mod( 'podval_currency_enabled', true ) ) : ?>
        <!-- Переключатель валюты -->
        <div class="mnav__currency">
          <span class="mnav__currency-label">Валюта</span>
          <div class="currency-switch currency-switch--mob js-currency-switch-mob" aria-label="Выбор валюты"></div>
        </div>
        <?php endif; ?>

        <!-- Кнопки: поиск, избранное, корзина -->
        <div class="mnav__divider"></div>
        <div class="mnav__actions">
          <button class="mnav__action-btn js-search-toggle">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
            <span>Поиск</span>
          </button>
          <?php if ( $wc_active ) : ?>
          <a href="<?php echo esc_url( podval_url( 'about' ) ); ?>" class="mnav__action-btn">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <span>О нас</span>
          </a>
          <a href="<?php echo esc_url( $cart_url ); ?>" class="mnav__action-btn js-cart-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9 22c.55 0 1-.45 1-1s-.45-1-1-1-1 .45-1 1 .45 1 1 1zm11 0c.55 0 1-.45 1-1s-.45-1-1-1-1 .45-1 1 .45 1 1 1z"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
            <span>Корзина<?php if ( $cart_count > 0 ) echo ' (' . esc_html($cart_count) . ')'; ?></span>
          </a>
          <?php endif; ?>
        </div>

        <!-- Информация (аккордеон) -->
        <?php if ( ! empty( $mob_info_links ) ) : ?>
        <div class="mnav__divider"></div>
        <div class="mnav__row mnav__row--parent js-mnav-toggle">
          <span>Информация</span>
          <svg width="20" height="20" class="mnav__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M9 18l6-6-6-6"/>
          </svg>
        </div>
        <div class="mnav__sub">
          <?php foreach ( $mob_info_links as $link ) : ?>
          <a href="<?php echo esc_url( $link['url'] ); ?>" class="mnav__sub-item">
            <?php echo esc_html( $link['label'] ); ?>
          </a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Контакты -->
        <div class="mnav__divider"></div>
        <div class="mnav__contacts">
          <?php if ( $mob_phone ) : ?>
          <div class="mnav__contact-group">
            <div class="mnav__contact-label"><?php echo esc_html( $mob_order_q ); ?></div>
            <a href="tel:<?php echo esc_attr( $mob_tel ); ?>" class="mnav__contact-value">
              <?php echo esc_html( $mob_phone ); ?>
            </a>
          </div>
          <?php endif; ?>
          <?php if ( $mob_email ) : ?>
          <div class="mnav__contact-group">
            <div class="mnav__contact-label"><?php echo esc_html( $mob_podval_q ); ?></div>
            <a href="mailto:<?php echo esc_attr( $mob_email ); ?>" class="mnav__contact-value">
              <?php echo esc_html( $mob_email ); ?>
            </a>
          </div>
          <?php endif; ?>
        </div>

        <!-- Соцсети -->
        <div class="mnav__socials">
          <?php if ( $mob_tg ) : ?>
          <a href="<?php echo esc_url($mob_tg); ?>" class="mnav__social" aria-label="Telegram" target="_blank" rel="noopener">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.894 8.221-1.97 9.28c-.145.658-.537.818-1.084.508l-3-2.21-1.447 1.394c-.16.16-.295.295-.605.295l.213-3.053 5.56-5.023c.242-.213-.054-.333-.373-.12L7.17 13.6l-2.95-.924c-.64-.204-.657-.64.136-.954l11.57-4.461c.537-.194 1.006.131.968.96z"/></svg>
          </a>
          <?php endif; ?>
          <?php if ( $mob_ig ) : ?>
          <a href="<?php echo esc_url($mob_ig); ?>" class="mnav__social" aria-label="Instagram" target="_blank" rel="noopener">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg>
          </a>
          <?php endif; ?>
          <?php if ( $mob_yt ) : ?>
          <a href="<?php echo esc_url($mob_yt); ?>" class="mnav__social" aria-label="YouTube" target="_blank" rel="noopener">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>
          </a>
          <?php endif; ?>
          <?php if ( $mob_tt ) : ?>
          <a href="<?php echo esc_url($mob_tt); ?>" class="mnav__social" aria-label="TikTok" target="_blank" rel="noopener">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-2.88 2.5 2.89 2.89 0 0 1-2.89-2.89 2.89 2.89 0 0 1 2.89-2.89c.28 0 .54.04.79.1V9.01a6.33 6.33 0 0 0-.79-.05 6.34 6.34 0 0 0-6.34 6.34 6.34 6.34 0 0 0 6.34 6.34 6.34 6.34 0 0 0 6.33-6.34V8.69a8.18 8.18 0 0 0 4.78 1.52V6.76a4.85 4.85 0 0 1-1.01-.07z"/></svg>
          </a>
          <?php endif; ?>
          <?php if ( $mob_maps ) : ?>
          <a href="<?php echo esc_url($mob_maps); ?>" class="mnav__social" aria-label="Google Maps" target="_blank" rel="noopener">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
          </a>
          <?php endif; ?>
        </div>

      </div><!-- /mnav__body -->
    </div><!-- /mnav__panel -->
  </div><!-- /mnav -->


  <main id="main-content">

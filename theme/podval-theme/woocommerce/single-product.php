<?php
/**
 * Single Product Page
 */
defined( 'ABSPATH' ) || exit;
get_header();

if ( ! have_posts() ) { get_footer(); return; }
the_post();

if ( ! function_exists( 'wc_get_product' ) ) { get_footer(); return; }

global $product;
$product = wc_get_product( get_the_ID() );
if ( ! ( $product instanceof WC_Product ) ) { get_footer(); return; }

// Если товар из категории "Услуги" — используем кастомный шаблон
if ( has_term( 'uslugi', 'product_cat', get_the_ID() ) || has_term( 'dtf-print', 'product_cat', get_the_ID() ) || has_term( 'podval-production', 'product_cat', get_the_ID() ) ) {
    $svc_tpl = get_stylesheet_directory() . '/woocommerce/single-product-service.php';
    if ( file_exists( $svc_tpl ) ) {
        include $svc_tpl;
        return;
    }
}

$product_id = $product->get_id();
$name       = $product->get_name();
// Для вариативных — показываем минимальную цену до выбора
if ( $product->is_type( 'variable' ) ) {
    $min_price   = $product->get_variation_price( 'min', true );
    $max_price   = $product->get_variation_price( 'max', true );
    $price_html  = wc_price( $min_price );
    // Сохраняем данные вариаций для JS
    $variations_data = array();
    foreach ( $product->get_available_variations() as $var ) {
        $variations_data[ $var['variation_id'] ] = array(
            'price'         => $var['display_price'],
            'price_html'    => wc_price( $var['display_price'] ),
            'regular_price' => $var['display_regular_price'],
            'on_sale'       => $var['display_price'] < $var['display_regular_price'],
            'in_stock'      => $var['is_in_stock'],
            'image'         => ! empty( $var['image']['url'] ) ? $var['image']['url'] : '',
        );
    }
} else {
    $price_html      = $product->get_price_html();
    $variations_data = array();
}
$in_stock   = $product->is_in_stock();
$on_sale    = $product->is_on_sale();
$stock_qty  = $product->get_stock_quantity();
$desc       = $product->get_description();
$short_desc = $product->get_short_description();
$brand      = get_post_meta( $product_id, '_podval_brand', true );
$badge_key  = get_post_meta( $product_id, '_podval_badge', true );

// Detect if product is in "База" category (under base) or any child of it
// Only "База" products get "Оставить заявку"; other base subcategories will have normal cart
$is_base_garment = false;
$baza_term = get_term_by( 'slug', 'baza', 'product_cat' );
if ( ! $baza_term ) $baza_term = get_term_by( 'slug', 'база', 'product_cat' );
if ( ! $baza_term ) $baza_term = get_term_by( 'slug', 'base', 'product_cat' );
if ( $baza_term && ! is_wp_error( $baza_term ) ) {
    $prod_cats = wc_get_product_cat_ids( $product_id );
    foreach ( $prod_cats as $pc_id ) {
        if ( (int) $pc_id === (int) $baza_term->term_id ) {
            $is_base_garment = true;
            break;
        }
        $ancestors = get_ancestors( $pc_id, 'product_cat', 'taxonomy' );
        if ( in_array( (int) $baza_term->term_id, array_map( 'intval', $ancestors ), true ) ) {
            $is_base_garment = true;
            break;
        }
    }
}

// Дата создания безопасно
$date_obj = $product->get_date_created();
$is_new   = ( $date_obj instanceof WC_DateTime ) && ( $date_obj->getTimestamp() > strtotime( '-14 days' ) );

// Галерея
$thumb_id    = $product->get_image_id();
$gallery_ids = $product->get_gallery_image_ids();
$all_images  = $thumb_id ? array_merge( array( (int) $thumb_id ), (array) $gallery_ids ) : (array) $gallery_ids;

// Атрибуты вариативного товара — PHP 7.2 совместимо
$sizes      = array();
// Все группы атрибутов вариативного товара
$attr_groups = array(); // [['label'=>'Размер','key'=>'pa_size','options'=>[...]], ...]

if ( $product->is_type( 'variable' ) ) {
    try {
        // get_variation_attributes() возвращает ['attribute_pa_size' => ['s','m','l'], ...]
        $variation_attributes = $product->get_variation_attributes();

        foreach ( $variation_attributes as $raw_key => $values ) {
            if ( empty( $values ) ) continue;

            // Нормализуем ключ: 'attribute_pa_size' -> 'pa_size'
            $attr_key = $raw_key;

            // Название атрибута
            $label = '';
            if ( function_exists( 'wc_attribute_label' ) ) {
                $label = wc_attribute_label( $attr_key );
            }
            if ( ! $label ) {
                $label = ucfirst( str_replace( array( 'pa_', 'attribute_pa_', 'attribute_' ), ' ', $attr_key ) );
                $label = trim( $label );
            }

            // Taxonomy для получения имён термов
            $tax = '';
            if ( strpos( $attr_key, 'pa_' ) === 0 ) {
                $tax = $attr_key;
            } elseif ( strpos( $attr_key, 'attribute_pa_' ) === 0 ) {
                $tax = str_replace( 'attribute_', '', $attr_key );
            }

            $options    = array();
            $seen_slugs = array();

            foreach ( $values as $slug ) {
                if ( $slug === '' || in_array( $slug, $seen_slugs, true ) ) continue;
                $seen_slugs[] = $slug;

                // Получаем читаемое имя
                $display = $slug;
                if ( $tax && taxonomy_exists( $tax ) ) {
                    $term = get_term_by( 'slug', $slug, $tax );
                    if ( $term && ! is_wp_error( $term ) ) {
                        $display = $term->name;
                    }
                }

                // Находим variation_id для этого значения (берём первое подходящее)
                $var_id   = 0;
                $in_stock = true;
                foreach ( $product->get_available_variations() as $var ) {
                    foreach ( $var['attributes'] as $vk => $vv ) {
                        if ( ( $vk === $attr_key || $vk === 'attribute_' . $attr_key ) && $vv === $slug ) {
                            $var_id   = $var['variation_id'];
                            $in_stock = ! empty( $var['is_in_stock'] );
                            break 2;
                        }
                    }
                }

                $options[] = array(
                    'slug'         => $slug,      // оригинальный slug для сравнения с variation attrs
                    'label'        => $display,   // читаемое название для кнопки
                    'variation_id' => $var_id,
                    'in_stock'     => $in_stock,
                    'attr_key'     => $attr_key,
                );
            }

            if ( ! empty( $options ) ) {
                $attr_groups[] = array(
                    'label'   => $label,
                    'key'     => $attr_key,
                    'options' => $options,
                );
            }
        }
    } catch ( Exception $e ) {
        $attr_groups = array();
    }
}

// Обратная совместимость
$sizes      = ! empty( $attr_groups ) ? $attr_groups[0]['options'] : array();
$attr_label = ! empty( $attr_groups ) ? $attr_groups[0]['label']   : '';


// Категории для хлебных крошек
$cats     = array();
$raw_cats = get_the_terms( $product_id, 'product_cat' );
if ( $raw_cats && ! is_wp_error( $raw_cats ) ) {
    $cats = $raw_cats;
}
$shop_url = podval_get_catalog_url();

// Тег для хлебных крошек (первый по популярности, если не совпадает с категорией)
$breadcrumb_tag = null;
$raw_tags = get_the_terms( $product_id, 'product_tag' );
if ( $raw_tags && ! is_wp_error( $raw_tags ) ) {
    // Имя категории в крошках (lowercase) для сравнения
    $cat_name_lower = ! empty( $cats ) ? mb_strtolower( $cats[0]->name ) : '';
    foreach ( $raw_tags as $tag ) {
        if ( mb_strtolower( $tag->name ) !== $cat_name_lower ) {
            $breadcrumb_tag = $tag;
            break;
        }
    }
}
?>

<div class="sp-wrap">

  <!-- Хлебные крошки -->
  <div class="sp-breadcrumbs">
    <a href="<?php echo esc_url( home_url( '/' ) ); ?>">Главная</a>
    <span class="sp-breadcrumbs__sep">—</span>
    <a href="<?php echo esc_url( $shop_url ); ?>">Магазин</a>
    <?php if ( ! empty( $cats ) ) : ?>
    <span class="sp-breadcrumbs__sep">—</span>
    <a href="<?php echo esc_url( get_term_link( $cats[0] ) ); ?>"><?php echo esc_html( $cats[0]->name ); ?></a>
    <?php endif; ?>
    <?php if ( $breadcrumb_tag ) : ?>
    <span class="sp-breadcrumbs__sep">—</span>
    <a href="<?php echo esc_url( get_term_link( $breadcrumb_tag ) ); ?>"><?php echo esc_html( $breadcrumb_tag->name ); ?></a>
    <?php endif; ?>
    <span class="sp-breadcrumbs__sep">—</span>
    <span><?php echo esc_html( $name ); ?></span>
  </div>

  <!-- Основной layout -->
  <div class="sp-layout">

    <!-- ГАЛЕРЕЯ -->
    <div class="sp-gallery">

      <?php if ( count( $all_images ) > 1 ) : ?>
      <div class="sp-thumbs">
        <?php foreach ( $all_images as $i => $img_id ) :
          $small = wp_get_attachment_image_url( $img_id, 'thumbnail' );
          $large = wp_get_attachment_image_url( $img_id, 'full' );
          if ( ! $small ) continue;
        ?>
        <div class="sp-thumb <?php echo $i === 0 ? 'is-active' : ''; ?>"
             data-large="<?php echo esc_url( $large ); ?>">
          <img src="<?php echo esc_url( $small ); ?>" alt="" loading="lazy">
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div class="sp-main-img">
        <?php
        $first_img = ! empty( $all_images ) ? wp_get_attachment_image_url( $all_images[0], 'full' ) : '';
        if ( $first_img ) : ?>
        <img src="<?php echo esc_url( $first_img ); ?>"
             alt="<?php echo esc_attr( $name ); ?>"
             id="sp-main-photo" loading="eager" fetchpriority="high" decoding="async">
        <?php else : ?>
        <div class="sp-img-placeholder"><?php echo esc_html( mb_substr( $name, 0, 1 ) ); ?></div>
        <?php endif; ?>

        <div class="sp-badges">
          <?php if ( ! $in_stock ) : ?>
            <span class="badge-tag badge-sold-out">Sold Out</span>
          <?php elseif ( $badge_key === 'new' ) : ?>
            <span class="badge-tag badge-new">Новинка</span>
          <?php elseif ( $badge_key === 'limited' ) : ?>
            <span class="badge-tag badge-limited">Limited</span>
          <?php elseif ( $badge_key === 'preorder' ) : ?>
            <span class="badge-tag badge-preorder">Предзаказ</span>
          <?php elseif ( $badge_key === 'yourphoto' ) : ?>
            <span class="badge-tag badge-yourphoto">Ваше фото</span>
          <?php elseif ( $on_sale ) : ?>
            <span class="badge-tag badge-sale">Sale</span>
          <?php endif; ?>
        </div>
      </div>

    </div><!-- /.sp-gallery -->

    <!-- ДЕТАЛИ -->
    <div class="sp-details">

      <?php
      // Определяем "Свой Дизайн" в начале — используется для цены, кнопок, инструкции
      $is_custom_design = has_term( 'custom-design', 'product_cat', $product_id );

      // Товар с подключённым конструктором шаблонов. Карточка у него обычная:
      // галерея, цена, описание. Отличается только блок выбора и кнопка —
      // вместо «В корзину» человек уходит собирать макет.
      $tpl_group = function_exists( 'podval_tpl_product_group' ) ? podval_tpl_product_group( $product_id ) : '';
      $is_tpl    = (bool) $tpl_group;
      $tpl_garment = $is_tpl && function_exists( 'podval_tpl_garment_options' ) ? podval_tpl_garment_options( $product_id ) : array();

      // Artwork upload page URL (page with template "page-artwork-upload.php")
      $artwork_upload_url = '';
      if ( $is_custom_design ) {
          $aw_pages = get_pages( array( 'meta_key' => '_wp_page_template', 'meta_value' => 'page-artwork-upload.php', 'number' => 1 ) );
          if ( ! empty( $aw_pages ) ) {
              $artwork_upload_url = get_permalink( $aw_pages[0]->ID );
          }
      }
      ?>

      <?php if ( $brand ) : ?>
      <div class="sp-brand"><?php echo esc_html( $brand ); ?></div>
      <?php endif; ?>

      <h1 class="sp-title"><?php echo esc_html( $name ); ?></h1>
      <?php if ( ! $is_base_garment ) : ?>
      <div class="sp-price price" id="sp-price"><?php echo wp_kses_post( $price_html ); ?></div>
      <?php if ( $is_custom_design || $is_tpl ) : ?>
      <p class="sp-price-hint">включая нанесение с одной стороны</p>
      <?php endif; ?>
      <?php endif; ?>
      <?php if ( ! empty( $variations_data ) ) : ?>
      <script>
        window.podvalVariations = <?php echo wp_json_encode( $variations_data ); ?>;
        window.podvalVariationAttrs = <?php
          $attrs_map = array();
          foreach ( $product->get_available_variations() as $var ) {
              $attrs_map[ $var['variation_id'] ] = $var['attributes'];
          }
          echo wp_json_encode( $attrs_map );
        ?>;
      </script>
      <?php endif; ?>

      <!-- WC notices скрыты от пользователей -->

      <!-- ═══ ВЫБОР ИЗДЕЛИЯ ДЛЯ ТОВАРА С КОНСТРУКТОРОМ ═══
           Опции берутся из podval_tpl_garment_options() — того же источника,
           что и первый шаг конструктора. Через атрибуты WC было бы нельзя:
           значения там русские («Чёрная»), а конструктор ждёт ключи
           («black»), и любое переименование в админке рвало бы связь. -->
      <?php if ( $is_tpl && ! empty( $tpl_garment ) ) :
        // Таблица размеров определяется ниже для вариативных товаров, а этот
        // блок идёт раньше — читаем мету здесь же.
        $size_chart_type = get_post_meta( $product_id, '_podval_size_chart', true );

        /* Цена зависит от изделия: оверсайз и большие размеры идут с
           надбавкой. На карточке она обязана совпадать с той, что покажет
           конструктор, поэтому считается тем же podval_tpl_calc_addons(),
           а не отдельной формулой.

           Комбинаций немного (тип × размер), поэтому цены готовятся
           сервером заранее и в разметку уходит готовый wc_price(): так
           формат, валюта и атрибут data-price-byn для переключателя
           BYN/RUB получаются те же, что и везде на сайте. */
        $tpl_base_price = $product ? (float) $product->get_price() : 0.0;
        $tpl_price_map  = array();
        if ( function_exists( 'podval_tpl_calc_addons' ) ) {
          $types = array_keys( $tpl_garment['type']['options'] ?? array( 'classic' => 1 ) );
          $sizes = array_keys( $tpl_garment['size']['options'] ?? array( 'm' => 1 ) );
          foreach ( $types as $t_key ) {
            foreach ( $sizes as $s_key ) {
              $sum = $tpl_base_price;
              foreach ( podval_tpl_calc_addons( array(
                'garment' => array( 'type' => $t_key, 'size' => $s_key ),
                'addons'  => array(),
              ) ) as $a ) {
                $sum += (float) $a['amount'];
              }
              $tpl_price_map[ $t_key . '|' . $s_key ] = wc_price( $sum );
            }
          }
        }
      ?>
      <script>var podvalTplPrices = <?php echo wp_json_encode( $tpl_price_map, JSON_UNESCAPED_UNICODE ); ?>;</script>
      <div class="sp-tpl-pick" id="sp-tpl-pick">
        <?php foreach ( $tpl_garment as $g_key => $g_def ) :
          $g_default = $g_def['default'] ?? key( $g_def['options'] );
          $is_color  = ( 'color' === $g_key );
        ?>
        <div class="sp-section <?php echo $is_color ? 'sp-section--color' : 'sp-section--sizes'; ?>" data-garment="<?php echo esc_attr( $g_key ); ?>">
          <div class="sp-section__label">
            <span><?php echo esc_html( $g_def['label'] ); ?></span>
            <?php if ( 'size' === $g_key && $size_chart_type ) : ?>
              <a href="#" class="sp-size-chart-link js-size-chart-open" data-chart="<?php echo esc_attr( $size_chart_type ); ?>">Таблица размеров</a>
            <?php endif; ?>
          </div>
          <div class="sp-sizes <?php echo $is_color ? 'sp-swatches' : 'sp-sizes--compact'; ?>">
            <?php foreach ( $g_def['options'] as $o_key => $o_def ) :
              $hex = $o_def['hex'] ?? '';
            ?>
            <button type="button"
                    class="sp-size-btn <?php echo $is_color ? 'sp-swatch' : 'sp-size-btn--sq'; ?> <?php echo $o_key === $g_default ? 'is-active' : ''; ?>"
                    data-garment="<?php echo esc_attr( $g_key ); ?>"
                    data-val="<?php echo esc_attr( $o_key ); ?>"
                    <?php if ( $hex ) : ?>style="--swatch: <?php echo esc_attr( $hex ); ?>"<?php endif; ?>
                    title="<?php echo esc_attr( $o_def['label'] ); ?>">
              <?php if ( $hex ) : ?><span class="sp-swatch__dot"></span><?php else : ?><?php echo esc_html( $o_def['label'] ); ?><?php endif; ?>
            </button>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Атрибуты вариаций — все группы -->
      <?php if ( ! $is_tpl && ! empty( $attr_groups ) ) : ?>
        <?php
          // Дефолтные атрибуты товара — маппим на формат кнопок
          $default_attrs = $product->is_type('variable') ? $product->get_default_attributes() : array();
          $defaults_mapped = array();
          foreach ( $default_attrs as $da_key => $da_val ) {
              if ( ! $da_val ) continue;
              // Нормализуем ключ из get_default_attributes()
              $da_decoded = urldecode( $da_key );
              $da_no_pa   = preg_replace( '/^pa_/', '', $da_decoded );

              // Ищем соответствующую группу атрибутов
              foreach ( $attr_groups as $grp ) {
                  $grp_decoded = urldecode( $grp['key'] );
                  $grp_no_pa   = preg_replace( '/^pa_/', '', $grp_decoded );

                  // Для кастомных (не taxonomy) атрибутов WC хранит ключ дефолта
                  // как sanitize_title('Где печатаем?') = 'где-печатаем',
                  // а $grp['key'] = 'Где печатаем?' (оригинальное имя).
                  // Поэтому сравниваем и через sanitize_title тоже.
                  $grp_sanitized = sanitize_title( $grp_decoded );
                  $da_sanitized  = sanitize_title( $da_decoded );

                  $key_match = ( $grp['key'] === $da_key
                    || mb_strtolower( $grp_decoded ) === mb_strtolower( $da_decoded )
                    || mb_strtolower( $grp_no_pa ) === mb_strtolower( $da_no_pa )
                    || $grp_sanitized === $da_sanitized
                    || $grp_sanitized === $da_key
                    || sanitize_title( $grp['key'] ) === $da_key );

                  if ( ! $key_match ) continue;

                  // Нашли группу — ищем точный slug кнопки.
                  // $da_val может быть slug, label, или sanitized версия
                  $da_val_decoded   = urldecode( $da_val );
                  $da_val_lower     = mb_strtolower( $da_val_decoded );
                  $da_val_sanitized = sanitize_title( $da_val_decoded );
                  $matched_slug = '';

                  foreach ( $grp['options'] as $opt ) {
                      $opt_slug_lower  = mb_strtolower( urldecode( $opt['slug'] ) );
                      $opt_label_lower = mb_strtolower( $opt['label'] );
                      $opt_sanitized   = sanitize_title( urldecode( $opt['slug'] ) );

                      if ( $opt_slug_lower === $da_val_lower
                        || $opt_label_lower === $da_val_lower
                        || $opt_sanitized === $da_val_sanitized
                        || $opt_sanitized === $da_val_lower
                        || $opt_slug_lower === $da_val_sanitized
                      ) {
                          $matched_slug = $opt['slug'];
                          break;
                      }
                  }

                  // Если нашли — записываем slug; иначе пишем оригинал (на всякий случай)
                  $defaults_mapped[ $grp['key'] ] = $matched_slug ?: $da_val;
                  break;
              }
          }
        ?>
        <script>var podvalDefaultAttrs = <?php echo wp_json_encode( $defaults_mapped, JSON_UNESCAPED_UNICODE ); ?>;</script>
        <?php
          $size_chart_type = get_post_meta( $product_id, '_podval_size_chart', true );

          // ── Color map for swatch rendering ──
          $podval_color_map = array(
              'черный'     => '#1a1a1a', 'чёрный'     => '#1a1a1a',
              'белый'      => '#f0f0f0',
              'серый'      => '#808080',
              'красный'    => '#e53935',
              'синий'      => '#1e88e5',
              'зеленый'    => '#43a047', 'зелёный'    => '#43a047',
              'желтый'     => '#fdd835', 'жёлтый'     => '#fdd835',
              'оранжевый'  => '#ff6600',
              'розовый'    => '#ec407a',
              'фиолетовый' => '#7b1fa2',
              'бежевый'    => '#d4b896',
              'хаки'       => '#6b6b47',
              'бордовый'   => '#800020',
              'голубой'    => '#29b6f6',
              'коричневый' => '#6d4c41',
              'бирюзовый'  => '#00897b',
              'тёмно-синий' => '#1a237e', 'темно-синий' => '#1a237e',
              'молочный'   => '#faf3e0',
              'песочный'   => '#c2b280',
              'лавандовый' => '#b39ddb',
              'мятный'     => '#80cbc4',
              'графитовый' => '#424242',
          );

          // ── Pre-scan groups to identify types ──
          $print_group_idx  = null;
          $sleeve_group_idx = null;
          foreach ( $attr_groups as $_gi => $_grp ) {
              $_lbl = mb_strtolower( $_grp['label'] );
              if ( mb_strpos( $_lbl, 'печата' ) !== false || mb_strpos( $_lbl, 'где' ) !== false ) {
                  $print_group_idx = $_gi;
              }
              if ( mb_strpos( $_lbl, 'рукав' ) !== false ) {
                  $sleeve_group_idx = $_gi;
              }
          }
        ?>
        <?php foreach ( $attr_groups as $gi => $group ) :
            $lbl_lower = mb_strtolower( $group['label'] );
            $is_color  = ( mb_strpos( $lbl_lower, 'цвет' ) !== false || stripos( $group['key'], 'color' ) !== false );
            $is_print  = ( $gi === $print_group_idx );
            $is_sleeve = ( $gi === $sleeve_group_idx );
            $is_size   = ( mb_stripos( $group['label'], 'размер' ) !== false
                || stripos( $group['key'], 'size' ) !== false
                || stripos( $group['key'], 'razmer' ) !== false );

            // Skip sleeve — rendered inside the print group
            if ( $is_sleeve && $print_group_idx !== null ) continue;
        ?>

        <?php if ( $is_color ) : ?>
        <!-- ═══ COLOR SWATCHES ═══ -->
        <div class="sp-section sp-section--color" data-attr-group="<?php echo esc_attr( $group['key'] ); ?>">
          <div class="sp-section__label">
            <span><?php echo esc_html( $group['label'] ); ?></span>
            <span class="sp-color-name" id="sp-color-name"></span>
          </div>
          <div class="sp-sizes sp-swatches">
            <?php foreach ( $group['options'] as $s ) :
              $c_lower = mb_strtolower( $s['label'] );
              $hex     = isset( $podval_color_map[ $c_lower ] ) ? $podval_color_map[ $c_lower ] : '';
              $is_light = in_array( $c_lower, array( 'белый', 'молочный', 'бежевый', 'песочный', 'мятный', 'лавандовый' ), true );
            ?>
            <button class="sp-size-btn sp-swatch <?php echo ! $s['in_stock'] ? 'is-unavailable' : ''; ?> <?php echo $is_light ? 'sp-swatch--light' : ''; ?>"
                    data-variation-id="<?php echo esc_attr( $s['variation_id'] ); ?>"
                    data-attr-group="<?php echo esc_attr( $group['key'] ); ?>"
                    data-attr-key="<?php echo esc_attr( $s['attr_key'] ); ?>"
                    data-attr-val="<?php echo esc_attr( $s['slug'] ); ?>"
                    data-color-name="<?php echo esc_attr( $s['label'] ); ?>"
                    <?php if ( $hex ) : ?>style="--swatch: <?php echo esc_attr( $hex ); ?>"<?php endif; ?>
                    title="<?php echo esc_attr( $s['label'] ); ?>"
                    <?php echo ! $s['in_stock'] ? 'disabled' : ''; ?>>
              <?php if ( $hex ) : ?>
                <span class="sp-swatch__dot"></span>
              <?php else : ?>
                <?php echo esc_html( mb_substr( $s['label'], 0, 2 ) ); ?>
              <?php endif; ?>
            </button>
            <?php endforeach; ?>
          </div>
        </div>

        <?php elseif ( $is_print ) : ?>
        <!-- ═══ PRINT GROUP (segmented + toggle) ═══ -->
        <div class="sp-section sp-section--print-group">
          <div class="sp-section__label"><span>Печать</span></div>

          <!-- Segmented: Где печатаем? -->
          <div class="sp-segment" data-attr-group="<?php echo esc_attr( $group['key'] ); ?>">
            <?php foreach ( $group['options'] as $s ) : ?>
            <button class="sp-size-btn sp-segment__btn <?php echo ! $s['in_stock'] ? 'is-unavailable' : ''; ?>"
                    data-variation-id="<?php echo esc_attr( $s['variation_id'] ); ?>"
                    data-attr-group="<?php echo esc_attr( $group['key'] ); ?>"
                    data-attr-key="<?php echo esc_attr( $s['attr_key'] ); ?>"
                    data-attr-val="<?php echo esc_attr( $s['slug'] ); ?>"
                    <?php echo ! $s['in_stock'] ? 'disabled' : ''; ?>>
              <?php echo esc_html( $s['label'] ); ?>
            </button>
            <?php endforeach; ?>
          </div>

          <?php // Toggle: Печать на рукаве
          if ( $sleeve_group_idx !== null ) :
            $sg = $attr_groups[ $sleeve_group_idx ];
          ?>
          <div class="sp-toggle-row">
            <span class="sp-toggle-label"><?php echo esc_html( $sg['label'] ); ?></span>
            <div class="sp-toggle">
              <?php foreach ( $sg['options'] as $s ) : ?>
              <button class="sp-size-btn sp-toggle__btn <?php echo ! $s['in_stock'] ? 'is-unavailable' : ''; ?>"
                      data-variation-id="<?php echo esc_attr( $s['variation_id'] ); ?>"
                      data-attr-group="<?php echo esc_attr( $sg['key'] ); ?>"
                      data-attr-key="<?php echo esc_attr( $s['attr_key'] ); ?>"
                      data-attr-val="<?php echo esc_attr( $s['slug'] ); ?>"
                      <?php echo ! $s['in_stock'] ? 'disabled' : ''; ?>>
                <?php echo esc_html( $s['label'] ); ?>
              </button>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>
        </div>

        <?php elseif ( $is_size ) : ?>
        <!-- ═══ SIZES (compact) ═══ -->
        <div class="sp-section sp-section--sizes" data-attr-group="<?php echo esc_attr( $group['key'] ); ?>">
          <div class="sp-section__label">
            <span><?php echo esc_html( $group['label'] ); ?></span>
            <?php if ( $size_chart_type ) : ?>
              <a href="#" class="sp-size-chart-link js-size-chart-open" data-chart="<?php echo esc_attr( $size_chart_type ); ?>">Таблица размеров</a>
            <?php endif; ?>
          </div>
          <div class="sp-sizes sp-sizes--compact">
            <?php foreach ( $group['options'] as $s ) : ?>
            <button class="sp-size-btn sp-size-btn--sq <?php echo ! $s['in_stock'] ? 'is-unavailable' : ''; ?>"
                    data-variation-id="<?php echo esc_attr( $s['variation_id'] ); ?>"
                    data-attr-group="<?php echo esc_attr( $group['key'] ); ?>"
                    data-attr-key="<?php echo esc_attr( $s['attr_key'] ); ?>"
                    data-attr-val="<?php echo esc_attr( $s['slug'] ); ?>"
                    <?php echo ! $s['in_stock'] ? 'disabled' : ''; ?>>
              <?php echo esc_html( $s['label'] ); ?>
            </button>
            <?php endforeach; ?>
          </div>
        </div>

        <?php else : ?>
        <!-- ═══ DEFAULT ═══ -->
        <div class="sp-section" data-attr-group="<?php echo esc_attr($group['key']); ?>">
          <div class="sp-section__label">
            <span><?php echo esc_html( $group['label'] ); ?></span>
          </div>
          <div class="sp-sizes">
            <?php foreach ( $group['options'] as $s ) : ?>
            <button class="sp-size-btn <?php echo ! $s['in_stock'] ? 'is-unavailable' : ''; ?>"
                    data-variation-id="<?php echo esc_attr( $s['variation_id'] ); ?>"
                    data-attr-group="<?php echo esc_attr( $group['key'] ); ?>"
                    data-attr-key="<?php echo esc_attr( $s['attr_key'] ); ?>"
                    data-attr-val="<?php echo esc_attr( $s['slug'] ); ?>"
                    <?php echo ! $s['in_stock'] ? 'disabled' : ''; ?>>
              <?php echo esc_html( $s['label'] ); ?>
            </button>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <?php endforeach; ?>
      <?php endif; ?>

      <?php
      // "Свой Дизайн" — определено выше
      ?>

      <!-- В корзину / Оставить заявку -->
      <div class="sp-actions">
        <?php if ( $is_tpl ) : ?>
          <a href="<?php echo esc_url( podval_tpl_builder_url( $product_id ) ); ?>"
             class="sp-add-btn sp-add-btn--tpl" id="sp-tpl-go">
            Создать принт
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="18" height="18" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
          </a>
          <p class="sp-custom-hint">Дальше выберете основу, впишете надписи и загрузите фото</p>
        <?php elseif ( $is_base_garment ) :
          // Find podval-production product URL for the order form
          $podval_prod = get_posts( array( 'post_type' => 'product', 'posts_per_page' => 1, 'tax_query' => array( array( 'taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => 'podval-production' ) ) ) );
          $podval_url = $podval_prod ? get_permalink( $podval_prod[0]->ID ) . '#order-form' : '#';
        ?>
          <a href="<?php echo esc_url( $podval_url ); ?>" class="sp-add-btn">Оставить заявку</a>
        <?php elseif ( $in_stock ) : ?>
          <?php
            $add_btn_text     = $is_custom_design ? 'Продолжить — загрузить изображения' : 'В корзину';
            $select_btn_text  = $is_custom_design ? 'Выберите параметры' : 'Выберите размер';
          ?>
          <?php if ( $product->is_type( 'simple' ) ) : ?>
          <form class="podval-add-to-cart-form" data-product-id="<?php echo esc_attr( $product_id ); ?>"
            <?php if ( $is_custom_design && $artwork_upload_url ) echo 'data-artwork-url="' . esc_url( $artwork_upload_url ) . '"'; ?>>
            <?php wp_nonce_field( 'podval_nonce', '_podval_nonce' ); ?>
            <button type="submit" class="sp-add-btn"><?php echo esc_html( $add_btn_text ); ?></button>
          </form>
          <?php elseif ( $product->is_type( 'variable' ) ) : ?>
          <form class="podval-add-to-cart-form" data-product-id="<?php echo esc_attr( $product_id ); ?>"
            <?php if ( $is_custom_design && $artwork_upload_url ) echo 'data-artwork-url="' . esc_url( $artwork_upload_url ) . '"'; ?>>
            <?php wp_nonce_field( 'podval_nonce', '_podval_nonce' ); ?>
            <input type="hidden" name="variation_id" class="js-selected-variation" value="">
            <button type="submit" class="sp-add-btn" disabled><?php echo esc_html( $select_btn_text ); ?></button>
          </form>
          <?php else : ?>
          <a href="<?php echo esc_url( get_permalink() ); ?>" class="sp-add-btn">Подробнее</a>
          <?php endif; ?>
          <?php if ( $is_custom_design ) : ?>
          <?php
            // If artwork upload page exists, "Добавить принты" goes there; otherwise checkout
            $post_add_url = $artwork_upload_url ? $artwork_upload_url : wc_get_checkout_url();
          ?>
          <a href="<?php echo esc_url( $post_add_url ); ?>" class="sp-go-cart-btn" id="sp-go-cart" style="display:none;"
             <?php if ( $artwork_upload_url ) echo 'data-artwork-url="' . esc_url( $artwork_upload_url ) . '"'; ?>>
            Добавить принты и оформить заказ
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="18" height="18"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
          </a>
          <p class="sp-custom-hint" id="sp-custom-hint" style="display:none;">свяжемся с вами после оформления</p>
          <?php else : ?>
          <a href="<?php echo esc_url( wc_get_cart_url() ); ?>" class="sp-go-cart-btn" id="sp-go-cart" style="display:none;">
            Перейти в корзину
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="18" height="18"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
          </a>
          <?php endif; ?>
        <?php else : ?>
          <button class="sp-add-btn" disabled>Нет в наличии</button>
        <?php endif; ?>

        <?php
        // Size chart button in actions area — only for base products
        if ( $is_base_garment ) :
          $sc_val = get_post_meta( $product_id, '_podval_size_chart', true );
          if ( $sc_val ) : ?>
        <button type="button" class="sp-size-chart-btn js-size-chart-open">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="18" height="18"><path d="M3 3h18v18H3zM3 9h18M9 3v18M15 9v3"/></svg>
          Таблица размеров
        </button>
        <?php endif; endif; ?>
      </div>

      <?php if ( $stock_qty && $stock_qty <= 5 ) : ?>
      <p class="sp-stock-warning">Осталось: <?php echo (int) $stock_qty; ?> шт.</p>
      <?php endif; ?>

      <?php
      /* ── Кросс-сейл: товар входит в бандл ──────────────────
         Показываем максимум 2 набора, отсортированы по выгоде.
         Хелпер сам возвращает пустой массив, если раздел бандлов скрыт. */
      $sp_bundles = function_exists( 'podval_bundle_get_for_product' )
          ? podval_bundle_get_for_product( $product_id )
          : array();
      if ( ! empty( $sp_bundles ) ) :
        foreach ( array_slice( $sp_bundles, 0, 2 ) as $sp_b ) :
          $sp_save = (float) $sp_b['saving'];
      ?>
      <a href="<?php echo esc_url( $sp_b['url'] ); ?>" class="sp-bundle-hint">
        <svg class="sp-bundle-hint__ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="20" height="20" aria-hidden="true">
          <path d="M20 7H4v13h16V7zM4 7l2-4h12l2 4M12 3v4M9 12h6"/>
        </svg>
        <span class="sp-bundle-hint__txt">
          Есть в наборе «<?php echo esc_html( $sp_b['title'] ); ?>»
          <strong>— выгода <span data-price-byn="<?php echo esc_attr( $sp_save ); ?>"><?php echo esc_html( number_format( $sp_save, 2, ',', ' ' ) ); ?>&nbsp;Br</span></strong>
        </span>
        <svg class="sp-bundle-hint__arr" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16" aria-hidden="true">
          <path d="M5 12h14M12 5l7 7-7 7"/>
        </svg>
      </a>
      <?php endforeach; endif; ?>

      <!-- Sticky price bar (mobile only) -->
      <div class="sp-sticky-bar" id="sp-sticky-bar">
        <div class="sp-sticky-bar__price" id="sp-sticky-price"><?php echo wp_kses_post( $price_html ); ?></div>
        <?php if ( $is_tpl ) : ?>
          <!-- Ссылка, а не кнопка: у шаблонного товара нет добавления в
               корзину с карточки, sp-sticky-btn ведёт свою логику вариаций
               и здесь только мешал бы. -->
          <a href="<?php echo esc_url( podval_tpl_builder_url( $product_id ) ); ?>"
             class="sp-sticky-bar__btn" id="sp-tpl-go-sticky">Создать принт →</a>
        <?php else : ?>
        <button type="button" class="sp-sticky-bar__btn" id="sp-sticky-btn" data-label-continue="<?php echo esc_attr( $is_custom_design ? 'Продолжить →' : 'В корзину →' ); ?>" data-label-choose="Выбрать параметры →">
          <?php echo esc_html( $is_custom_design ? 'Продолжить →' : 'В корзину →' ); ?>
        </button>
        <?php endif; ?>
      </div>

      <?php if ( $is_tpl ) : ?>
      <script>
      /* Выбор изделия на карточке уезжает в ссылку конструктора: спрашивать
         цвет и размер второй раз внутри — лишний шаг. Обе кнопки («Создать
         принт» в блоке действий и в липкой панели) обновляются вместе. */
      (function () {
        var pick = document.getElementById('sp-tpl-pick');
        if (!pick) return;

        var links = [ document.getElementById('sp-tpl-go'), document.getElementById('sp-tpl-go-sticky') ]
          .filter(Boolean);
        if (!links.length) return;

        var base = links[0].getAttribute('href').split('?')[0];
        var state = {};

        pick.querySelectorAll('.sp-size-btn.is-active').forEach(function (b) {
          state[b.dataset.garment] = b.dataset.val;
        });

        var priceEl = document.getElementById('sp-price');
        var stickyPriceEl = document.getElementById('sp-sticky-price');
        var prices = window.podvalTplPrices || {};

        function syncPrice() {
          var html = prices[state.type + '|' + state.size];
          if (!html) return;
          if (priceEl) priceEl.innerHTML = html;
          if (stickyPriceEl) stickyPriceEl.innerHTML = html;
          // Ценники несут data-price-byn для переключателя BYN/RUB.
          // Подменив разметку, надо попросить его пересчитать заново —
          // иначе в рублях осталась бы старая сумма.
          if (window.PodvalCurrency && typeof window.PodvalCurrency.refresh === 'function') {
            window.PodvalCurrency.refresh();
          }
        }

        function sync() {
          // Выбранное изделие уходит во ФРАГМЕНТ, а не в query-строку:
          // WP-Optimize не кеширует URL с незнакомыми GET-параметрами, и
          // конструктор открывался мимо кеша (TTFB 1.5 с). Фрагмент на
          // сервер не отправляется, страница остаётся одной для всех,
          // а выбор разбирает tpl-builder.js из location.hash.
          var frag = [];
          Object.keys(state).forEach(function (k) {
            frag.push('g_' + encodeURIComponent(k) + '=' + encodeURIComponent(state[k]));
          });
          var href = base + '?builder=1' + (frag.length ? '#' + frag.join('&') : '');
          links.forEach(function (a) { a.setAttribute('href', href); });
          syncPrice();
        }

        pick.addEventListener('click', function (e) {
          var btn = e.target.closest('.sp-size-btn');
          if (!btn || !btn.dataset.garment) return;
          var group = btn.dataset.garment;
          pick.querySelectorAll('.sp-size-btn[data-garment="' + group + '"]').forEach(function (b) {
            b.classList.toggle('is-active', b === btn);
          });
          state[group] = btn.dataset.val;
          sync();
        });

        sync();
      })();
      </script>
      <?php endif; ?>

      <?php
      // Инструкция для товаров с конструктором. Шаги другие: человек не
      // грузит готовый файл, а собирает макет из основы, надписей и фото.
      if ( $is_tpl ) : ?>
      <div class="sp-howto">
        <h3 class="sp-howto__title">Как это работает</h3>
        <div class="sp-howto__steps">
          <div class="sp-howto__step">
            <span class="sp-howto__num">1</span>
            <div class="sp-howto__text">Выберите <strong>цвет, тип и размер</strong> изделия</div>
          </div>
          <div class="sp-howto__step">
            <span class="sp-howto__num">2</span>
            <div class="sp-howto__text">Нажмите <strong>«Создать принт»</strong> — откроется конструктор макета</div>
          </div>
          <div class="sp-howto__step">
            <span class="sp-howto__num">3</span>
            <div class="sp-howto__text">Выберите <strong>основу и раскладку</strong>, впишите надписи и загрузите фото</div>
          </div>
          <div class="sp-howto__step">
            <span class="sp-howto__num">4</span>
            <div class="sp-howto__text">Добавьте в корзину и оформите заказ. Фото можно прислать и позже, в Telegram</div>
          </div>
          <div class="sp-howto__step">
            <span class="sp-howto__num">5</span>
            <div class="sp-howto__text">Дизайнер соберёт <strong>чистовой макет</strong> и пришлёт на согласование до печати</div>
          </div>
        </div>
        <?php
        $howto_tg = get_theme_mod( 'podval_header_tg', get_theme_mod( 'podval_footer_tg', '' ) );
        if ( $howto_tg ) : ?>
        <div class="sp-howto__cta">
          <p class="sp-howto__cta-text">Не уверены, что выбрать? Поможем подобрать основу и&nbsp;кадры</p>
          <a href="<?php echo esc_url( $howto_tg ); ?>" target="_blank" rel="noopener noreferrer" class="sp-howto__tg-btn">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M11.944 0A12 12 0 1 0 24 12.056A12.014 12.014 0 0 0 11.944 0Zm5.655 8.418c-.197 2.062-.95 7.068-1.343 9.384-.167.98-.495 1.308-.812 1.34-.69.065-1.215-.456-1.882-.893-1.046-.685-1.636-1.112-2.652-1.78-1.174-.773-.413-1.198.256-1.89.175-.182 3.22-2.953 3.28-3.204.007-.031.014-.148-.055-.21s-.172-.04-.246-.024c-.106.024-1.793 1.14-5.06 3.345-.479.33-.913.49-1.302.482-.429-.009-1.252-.242-1.865-.44-.751-.245-1.349-.374-1.297-.79.027-.216.325-.437.893-.663 3.498-1.524 5.831-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635.1-.002.322.023.466.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472Z"/></svg>
            Написать в Telegram
          </a>
        </div>
        <?php endif; ?>
      </div>
      <?php
      // Инструкция для товаров из "Свой Дизайн"
      elseif ( $is_custom_design ) : ?>
      <div class="sp-howto">
        <h3 class="sp-howto__title">Как заказать печать</h3>
        <div class="sp-howto__steps">
          <div class="sp-howto__step">
            <span class="sp-howto__num">1</span>
            <div class="sp-howto__text">Выберите <strong>модель, размер, цвет</strong> и место для принта</div>
          </div>
          <div class="sp-howto__step">
            <span class="sp-howto__num">2</span>
            <div class="sp-howto__text">Нажмите <strong>«Продолжить»</strong> и загрузите изображения для печати</div>
          </div>
          <div class="sp-howto__step">
            <span class="sp-howto__num">3</span>
            <div class="sp-howto__text">Заполните данные в форме заказа и нажмите <strong>«Сделать заказ»</strong></div>
          </div>
          <div class="sp-howto__step">
            <span class="sp-howto__num">4</span>
            <div class="sp-howto__text">Мы <strong>свяжемся с вами</strong> для уточнения макета и расположения принта</div>
          </div>
          <div class="sp-howto__step">
            <span class="sp-howto__num">5</span>
            <div class="sp-howto__text"><strong>Оплатите</strong> заказ — онлайн при оформлении или при получении</div>
          </div>
        </div>
        <?php
        $howto_tg = get_theme_mod( 'podval_header_tg', get_theme_mod( 'podval_footer_tg', '' ) );
        if ( $howto_tg ) : ?>
        <div class="sp-howto__cta">
          <p class="sp-howto__cta-text">Остались вопросы? Поможем с выбором и&nbsp;подготовкой макета</p>
          <a href="<?php echo esc_url( $howto_tg ); ?>" target="_blank" rel="noopener noreferrer" class="sp-howto__tg-btn">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M11.944 0A12 12 0 1 0 24 12.056A12.014 12.014 0 0 0 11.944 0Zm5.655 8.418c-.197 2.062-.95 7.068-1.343 9.384-.167.98-.495 1.308-.812 1.34-.69.065-1.215-.456-1.882-.893-1.046-.685-1.636-1.112-2.652-1.78-1.174-.773-.413-1.198.256-1.89.175-.182 3.22-2.953 3.28-3.204.007-.031.014-.148-.055-.21s-.172-.04-.246-.024c-.106.024-1.793 1.14-5.06 3.345-.479.33-.913.49-1.302.482-.429-.009-1.252-.242-1.865-.44-.751-.245-1.349-.374-1.297-.79.027-.216.325-.437.893-.663 3.498-1.524 5.831-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635.1-.002.322.023.466.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472Z"/></svg>
            Написать в Telegram
          </a>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Аккордеон -->
      <div class="sp-accordion">

        <?php $acc_desc = $desc ? $desc : $short_desc; if ( $acc_desc ) : ?>
        <div class="sp-acc-item">
          <button class="sp-acc-trigger js-acc-trigger">
            <span>Описание</span>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M6 9l6 6 6-6"/></svg>
          </button>
          <div class="sp-acc-body">
            <div class="sp-acc-content"><?php echo wp_kses_post( $acc_desc ); ?></div>
          </div>
        </div>
        <?php endif; ?>

        <?php
        // Доставка, возврат, уход — из inc/copy-data.php (product_info); пустых блоков нет.
        foreach ( (array) podval_copy( 'product_info' ) as $acc ) :
          $acc_html = $is_custom_design && ! empty( $acc['html_custom'] ) ? $acc['html_custom'] : ( $acc['html'] ?? '' );
          if ( empty( $acc['title'] ) || '' === $acc_html ) { continue; }
        ?>
        <div class="sp-acc-item">
          <button class="sp-acc-trigger js-acc-trigger">
            <span><?php echo esc_html( $acc['title'] ); ?></span>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M6 9l6 6 6-6"/></svg>
          </button>
          <div class="sp-acc-body">
            <div class="sp-acc-content"><?php echo wp_kses_post( $acc_html ); ?></div>
          </div>
        </div>
        <?php endforeach; ?>

      </div><!-- /.sp-accordion -->

      <?php
      // ссылка на лендинг услуги по категории товара (podval_sl_catmap).
      $sp_landing = function_exists( 'podval_sl_landing_for_product' ) ? podval_sl_landing_for_product( (int) $product_id ) : null;
      if ( $sp_landing ) :
      ?>
      <p class="sp-sl-link">Хотите свой принт на такой же вещи? <a href="<?php echo esc_url( $sp_landing['url'] ); ?>"><?php echo esc_html( $sp_landing['title'] ); ?> →</a></p>
      <?php endif; ?>

    </div><!-- /.sp-details -->
  </div><!-- /.sp-layout -->

</div><!-- /.sp-wrap -->

<!-- Похожие товары -->
<?php
try {
    $related_ids = wc_get_related_products( $product_id, 8 );
    if ( ! empty( $related_ids ) ) {
        $related_products = array_filter( array_map( 'wc_get_product', $related_ids ) );
        if ( ! empty( $related_products ) ) {
?>
<section class="sp-related">
  <div class="sp-related__inner">
    <h2 class="sp-related__title">Похожие товары</h2>
    <div class="catalog-grid">
      <?php
      foreach ( $related_products as $rel_product ) {
          if ( ! ( $rel_product instanceof WC_Product ) ) continue;
          $rel_post = get_post( $rel_product->get_id() );
          if ( ! $rel_post ) continue;
          $GLOBALS['post']    = $rel_post;
          $GLOBALS['product'] = $rel_product;
          setup_postdata( $rel_post );
          get_template_part( 'template-parts/content/product', 'card' );
      }
      wp_reset_postdata();
      ?>
    </div>
  </div>
</section>
<?php
        }
    }
} catch ( Exception $e ) {
    // silence
}
?>

<?php
// ═══════════════════════════════════════════
// SEO-блок карточки товара со своим дизайном. Текст — в inc/copy-data.php
// (ключ product_seo: по слагу товара или 'default'). Нет текста — блока нет.
// ═══════════════════════════════════════════
if ( has_term( 'custom-design', 'product_cat', $product_id ) ) {
    $sp_seo_all = (array) podval_copy( 'product_seo' );
    $sp_seo     = $sp_seo_all[ $product->get_slug() ] ?? ( $sp_seo_all['default'] ?? array() );
    podval_copy_render( (array) $sp_seo, 'sp-seo' );
}
?>

<?php
// ═══════════════════════════════════════════
// ПОПАП — Таблица размеров (dynamic CPT + legacy fallback)
// ═══════════════════════════════════════════
$sc_type = get_post_meta( $product_id, '_podval_size_chart', true );
if ( $sc_type ) {
  // разметка переехала в template-parts/size-chart-popup.php
  get_template_part( 'template-parts/size-chart-popup', null, array( 'sc_type' => $sc_type ) );
}
?>

<?php get_footer(); ?>

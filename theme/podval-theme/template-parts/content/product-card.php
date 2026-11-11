<?php
/**
 * Product Card Partial
 * Used in: homepage, archive, search results
 *
 * @var WC_Product $product  (global, set by WooCommerce or our loop)
 */
defined( 'ABSPATH' ) || exit;

global $product;
if ( ! $product instanceof WC_Product ) {
    $product = wc_get_product( get_the_ID() );
}
if ( ! $product ) return;

$product_id   = $product->get_id();
$product_url  = get_permalink( $product_id );
$product_name = $product->get_name();
// Для вариативных — минимальная цена вместо диапазона
if ( $product->is_type('variable') ) {
    $min_price  = $product->get_variation_price('min', true);
    $price_html = wc_price($min_price);
} else {
    $price_html = $product->get_price_html();
}
$brand        = get_post_meta( $product_id, '_podval_brand', true );
$badge_key    = get_post_meta( $product_id, '_podval_badge', true );
$is_in_stock  = $product->is_in_stock();
$is_on_sale   = $product->is_on_sale();

// Если нет бренда — подставляем последнюю (самую глубокую) подкатегорию
if ( ! $brand ) {
    $pc_cats = get_the_terms( $product_id, 'product_cat' );
    if ( $pc_cats && ! is_wp_error( $pc_cats ) ) {
        $deepest = null;
        $deepest_depth = -1;
        foreach ( $pc_cats as $pc_cat ) {
            $anc = get_ancestors( $pc_cat->term_id, 'product_cat', 'taxonomy' );
            if ( count( $anc ) > $deepest_depth ) {
                $deepest_depth = count( $anc );
                $deepest = $pc_cat;
            }
        }
        if ( $deepest && $deepest_depth > 0 ) {
            $brand = $deepest->name;
        }
    }
}

// Проверяем — это услуга?
$is_service = has_term( 'uslugi', 'product_cat', $product_id )
           || has_term( 'dtf-print', 'product_cat', $product_id )
           || has_term( 'podval-production', 'product_cat', $product_id );

// Проверяем — «Свой Дизайн»?
$is_custom_design = has_term( 'custom-design', 'product_cat', $product_id );

// Товар с подключённым конструктором шаблонов.
// Быстрое добавление для него недопустимо: позиция без данных конструктора
// уезжает в заказ пустой — ни макета, ни размера, ни фото (прод, заказ #422).
// Вместо кнопки «В корзину» / «Добавить в заказ» показываем призыв собрать
// принт, а переход на карточку даёт ссылка самой карточки.
$is_tpl_card = function_exists( 'podval_tpl_product_group' )
    ? (bool) podval_tpl_product_group( $product_id )
    : false;

// Determine badge
$badge_map = [
    'new'       => [ 'class' => 'badge-new',        'label' => __( 'Новинка', 'podval-print' ) ],
    'limited'   => [ 'class' => 'badge-limited',   'label' => __( 'Limited', 'podval-print' ) ],
    'preorder'  => [ 'class' => 'badge-preorder',   'label' => __( 'Предзаказ', 'podval-print' ) ],
    'custom'    => [ 'class' => 'badge-custom',     'label' => __( 'Custom', 'podval-print' ) ],
    'yourphoto' => [ 'class' => 'badge-yourphoto',  'label' => __( 'Ваше фото', 'podval-print' ) ],
];
// 'promo' — технический бейдж (для рекламных подборок), не отображается клиенту
if ( $badge_key === 'promo' ) {
    $badge_key = '';
}
if ( $is_on_sale && empty( $badge_key ) ) {
    $badge_key = 'sale';
    $badge_map['sale'] = [ 'class' => 'badge-sale', 'label' => __( 'Акция', 'podval-print' ) ];
}

// Get variations for size grid (if variable product)
$sizes        = [];
$variation_id = 0;
if ( $product->is_type( 'variable' ) ) {
    $available = $product->get_available_variations();
    foreach ( $available as $var ) {
        foreach ( $var['attributes'] as $attr_name => $attr_value ) {
            if ( strpos( strtolower( $attr_name ), 'size' ) !== false ||
                 strpos( strtolower( $attr_name ), 'razmer' ) !== false ||
                 strpos( strtolower( $attr_name ), 'pa_size' ) !== false ) {
                $sizes[] = [
                    'label'        => $attr_value,
                    'variation_id' => $var['variation_id'],
                    'in_stock'     => $var['is_in_stock'],
                    'attr_key'     => $attr_name,
                ];
            }
        }
    }
}

// Thumbnail
// ВНИМАНИЕ: используем 'podval-card-sm' (320×427) как базу, плюс 'podval-card' (600×800) и
// 'podval-card-2x' (1200×1600) в srcset через add_image_size().
// Раньше тут был 'podval-card' (600×800) — но мобильный контейнер ~200px,
// браузер всё равно качал 600px-картинку (~95 KB вместо ~35 KB).
// Сейчас базовый src=320px, srcset выдаёт 320/600/1200 — браузер сам выберет.
$thumb_id  = $product->get_image_id();
$thumb_alt = get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) ?: $product_name;

// Для placeholder (товар без главной картинки) оставляем простой URL
$thumb_src_fallback = $thumb_id
    ? wp_get_attachment_image_url( $thumb_id, 'podval-card-sm' )
    : wc_placeholder_img_src( 'podval-card-sm' );

// Hover image (first gallery image)
$gallery_ids = $product->get_gallery_image_ids();
$hover_src   = ! empty( $gallery_ids )
    ? wp_get_attachment_image_url( $gallery_ids[0], 'podval-card' )
    : '';
?>

<article class="product-card" data-product-id="<?php echo esc_attr( $product_id ); ?>">

  <!-- === THUMBNAIL === -->
  <a href="<?php echo esc_url( $product_url ); ?>"
     class="product-card-thumb">

    <?php
    $carousel_frames = function_exists( 'podval_card_carousel_frames' )
        ? podval_card_carousel_frames( $product )
        : array();

    if ( $carousel_frames ) :
        // Карусель вариантов дизайна. Первый кадр — обычный <img> (участвует
        // в LCP), остальные ленивые. Автоплей включает JS только для видимых
        // карточек и только если пользователь не просил меньше движения.
        ?>
        <div class="pc-carousel" data-carousel data-count="<?php echo count( $carousel_frames ); ?>">
          <?php foreach ( $carousel_frames as $fi => $frame ) : ?>
            <img class="pc-carousel__frame<?php echo 0 === $fi ? ' is-active' : ''; ?>"
                 src="<?php echo esc_url( $frame['src'] ); ?>"
                 <?php if ( $frame['srcset'] ) : ?>srcset="<?php echo esc_attr( $frame['srcset'] ); ?>"<?php endif; ?>
                 sizes="(max-width: 480px) 50vw, (max-width: 900px) 50vw, (max-width: 1200px) 33vw, 25vw"
                 alt="<?php echo esc_attr( $frame['alt'] ); ?>"
                 loading="lazy"
                 decoding="async"
                 width="320" height="427">
          <?php endforeach; ?>
          <span class="pc-carousel__dots" aria-hidden="true">
            <?php foreach ( $carousel_frames as $fi => $frame ) : ?>
              <i<?php echo 0 === $fi ? ' class="is-active"' : ''; ?>></i>
            <?php endforeach; ?>
          </span>
        </div>
    <?php elseif ( $thumb_id ) :
        // wp_get_attachment_image() автоматически добавит srcset со всеми размерами
        // (podval-card-sm 320, podval-card 600, podval-card-2x 1200).
        // Базовый src — самый маленький (320), браузер сам поднимет до нужного.
        // sizes уточнены под реальные контейнеры:
        //   - mobile (≤480px viewport, 2 col grid):  ~190px → берёт 320
        //   - tablet (≤900px, 2 col grid):           ~380px → берёт 600
        //   - small desktop (≤1200px, 3 col grid):   ~360px → берёт 600
        //   - desktop (4 col grid + max 1440px):     ~360px → берёт 600
        //   - retina screens поднимут до 1200
        echo wp_get_attachment_image( $thumb_id, 'podval-card-sm', false, array(
            'alt'         => $thumb_alt,
            'loading'     => 'lazy',
            'decoding'    => 'async',
            'sizes'       => '(max-width: 480px) 50vw, (max-width: 900px) 50vw, (max-width: 1200px) 33vw, 25vw',
            'data-hover'  => $hover_src,
        ) );
    else : ?>
        <img src="<?php echo esc_url( $thumb_src_fallback ); ?>"
             alt="<?php echo esc_attr( $thumb_alt ); ?>"
             loading="lazy"
             decoding="async"
             width="320" height="427">
    <?php endif; ?>

    <!-- Badges -->
    <div class="product-badges" aria-hidden="true">
      <?php if ( isset( $badge_map[ $badge_key ] ) ) : ?>
        <span class="badge-tag <?php echo esc_attr( $badge_map[ $badge_key ]['class'] ); ?>">
          <?php echo esc_html( $badge_map[ $badge_key ]['label'] ); ?>
        </span>
      <?php endif; ?>
      <?php if ( ! $is_in_stock ) : ?>
        <span class="badge-tag badge-sold-out"><?php esc_html_e( 'Sold Out', 'podval-print' ); ?></span>
      <?php endif; ?>
    </div>


    <!-- Quick-add overlay -->
    <?php if ( $is_service ) : ?>
    <div class="product-quick-add product-quick-add--service">
      <span class="quick-add-service-label">Подробнее →</span>
    </div>
    <?php elseif ( $is_tpl_card ) :
      /* Конструктор. Здесь <span>, а не <button>: вложенный <a> внутри
         .product-card-thumb невозможен, а класс .quick-add-btn перехватил бы
         делегированный обработчик в main.js (e.preventDefault) и заблокировал
         переход по ссылке карточки. Отдельный класс .quick-add-tpl-label
         повторяет вид кнопки. Комментарий PHP, а не HTML: карточек на
         странице до сотни, в разметку это уезжать не должно. */
    ?>
    <div class="product-quick-add product-quick-add--tpl">
      <span class="quick-add-tpl-label">Создать принт →</span>
    </div>
    <?php elseif ( $is_in_stock ) : ?>
    <div class="product-quick-add" role="form" aria-label="<?php esc_attr_e( 'Быстрое добавление', 'podval-print' ); ?>">

      <?php if ( ! empty( $sizes ) ) : ?>
      <div class="size-grid" role="group" aria-label="<?php esc_attr_e( 'Выберите размер', 'podval-print' ); ?>">
        <?php foreach ( $sizes as $size ) : ?>
        <button class="size-btn <?php echo ! $size['in_stock'] ? 'out-of-stock' : ''; ?>"
                data-variation-id="<?php echo esc_attr( $size['variation_id'] ); ?>"
                data-attr-key="<?php echo esc_attr( $size['attr_key'] ); ?>"
                data-attr-val="<?php echo esc_attr( $size['label'] ); ?>"
                <?php echo ! $size['in_stock'] ? 'disabled aria-disabled="true"' : ''; ?>>
          <?php echo esc_html( $size['label'] ); ?>
        </button>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <button class="quick-add-btn"
              data-product-id="<?php echo esc_attr( $product_id ); ?>"
              data-product-type="<?php echo esc_attr( $product->get_type() ); ?>"
              <?php if ( $is_custom_design ) echo 'data-custom="1"'; ?>>
        <?php
        if ( $product->is_type( 'simple' ) ) {
            echo esc_html( $is_custom_design ? 'Добавить в заказ' : __( 'В корзину', 'podval-print' ) );
        } elseif ( $badge_key === 'preorder' ) {
            esc_html_e( 'Предзаказ', 'podval-print' );
        } else {
            echo esc_html( $is_custom_design ? 'Выбрать параметры' : __( 'Выбрать размер', 'podval-print' ) );
        }
        ?>
      </button>
    </div>
    <?php endif; ?>

  </a><!-- .product-card-thumb -->

  <!-- === INFO === -->
  <div class="product-card-info">
    <?php if ( $brand ) : ?>
    <div class="product-brand-label"><?php echo esc_html( $brand ); ?></div>
    <?php endif; ?>

    <a href="<?php echo esc_url( $product_url ); ?>"
       class="product-card-name woocommerce-loop-product__title">
      <?php echo esc_html( $product_name ); ?>
    </a>

    <?php if ( $is_service ) : ?>
    <div class="product-card-service-link">Подробнее →</div>
    <?php else : ?>
    <div class="product-card-price price">
      <?php echo wp_kses_post( $price_html ); ?>
    </div>
    <?php if ( $is_custom_design ) : ?>
    <div class="product-card-price-hint">вкл. нанесение с одной стороны</div>
    <?php endif; ?>
    <?php endif; ?>
  </div>

</article>

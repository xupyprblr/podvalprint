<?php
/**
 * Podval Print — Cart Page (полная страница с шапкой и футером)
 */
defined( 'ABSPATH' ) || exit;

// Действия корзины обрабатываются в functions.php через init hook

get_header();

// Инициализируем корзину WC
if ( function_exists('WC') && WC()->cart ) {
    WC()->cart->calculate_totals();
}

$cart_items = WC()->cart ? WC()->cart->get_cart() : array();
$is_empty   = empty( $cart_items );
?>

<div class="site-content">
<div class="podval-cart">

  <div class="podval-cart__header">
    <h1 class="podval-cart__title">Корзина</h1>
    <?php if ( ! $is_empty ) :
      $n = WC()->cart->get_cart_contents_count();
      if     ( $n % 10 == 1 && $n % 100 != 11 )                                    $suffix = '';
      elseif ( $n % 10 >= 2 && $n % 10 <= 4 && ( $n % 100 < 10 || $n % 100 >= 20 ) ) $suffix = 'а';
      else                                                                            $suffix = 'ов';
    ?>
      <span class="podval-cart__count"><?php echo $n; ?> товар<?php echo $suffix; ?></span>
    <?php endif; ?>
  </div>

<?php if ( $is_empty ) : ?>

  <div class="podval-cart__empty">
    <div class="podval-cart__empty-icon">
      <svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M8 8h4l8 32h24l6-20H18" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
        <circle cx="28" cy="48" r="3" fill="currentColor"/>
        <circle cx="42" cy="48" r="3" fill="currentColor"/>
      </svg>
    </div>
    <p class="podval-cart__empty-text">В корзине пусто</p>
    <a href="<?php echo esc_url( podval_get_catalog_url() ); ?>" class="podval-cart__empty-btn">
      Перейти в каталог
    </a>
  </div>

<?php else : ?>

  <div class="podval-cart__layout">

    <!-- ЛЕВАЯ КОЛОНКА: список товаров -->
    <div class="podval-cart__items">

      <?php foreach ( $cart_items as $cart_item_key => $cart_item ) :
        $product      = $cart_item['data'];
        $variation_id = $cart_item['variation_id'];
        $quantity     = $cart_item['quantity'];
        if ( ! $product || ! $product->exists() || $quantity == 0 ) continue;

        // BUNDLE — позиция типа podval_bundle (cart_item имеет _bundle_meta)
        $bm = ! empty( $cart_item['_bundle_meta'] ) && is_array( $cart_item['_bundle_meta'] )
            ? $cart_item['_bundle_meta']
            : null;

        if ( $bm ) {
            // Bundle thumbnail
            $thumb_id   = (int) ( $bm['thumbnail_id'] ?? 0 );
            $thumbnail  = $thumb_id
                ? wp_get_attachment_image( $thumb_id, 'woocommerce_thumbnail', false, array( 'alt' => esc_attr( $bm['bundle_name'] ) ) )
                : $product->get_image( 'woocommerce_thumbnail' );
            if ( ! $thumbnail && ! empty( $bm['shirts'][0]['image'] ) ) {
                $thumbnail = '<img src="' . esc_url( $bm['shirts'][0]['image'] ) . '" alt="' . esc_attr( $bm['bundle_name'] ) . '">';
            }
            $product_name = 'Бандл: ' . $bm['bundle_name'];
            $permalink    = ! empty( $bm['bundle_url'] ) ? $bm['bundle_url'] : '';
            $line_total   = wc_price( (float) $bm['total_price'] );
            $unit_price   = '';
            $variation_attrs = array();
        } else {
            $thumbnail    = $product->get_image( 'woocommerce_thumbnail' );

            // Собранный в конструкторе макет вместо фотографии товара.
            // В корзине может лежать несколько принтов на одном товаре —
            // по каталожному снимку они неразличимы, а по макету видно
            // сразу, что именно человек собрал.
            if ( ! empty( $cart_item['podval_tpl']['preview_id'] ) ) {
                $tpl_thumb = wp_get_attachment_image(
                    (int) $cart_item['podval_tpl']['preview_id'],
                    'woocommerce_thumbnail',
                    false,
                    array( 'alt' => esc_attr( $product->get_name() ), 'class' => 'cart-item__tpl-preview' )
                );
                if ( $tpl_thumb ) {
                    $thumbnail = $tpl_thumb;
                }
            }

            $product_name = $product->get_name();
            $permalink    = $product->get_permalink( $cart_item );
            $line_total   = wc_price( $product->get_price() * $quantity );
            $unit_price   = wc_price( $product->get_price() );

            // Атрибуты вариации
            $variation_attrs = array();
            if ( $variation_id && ! empty( $cart_item['variation'] ) ) {
                foreach ( $cart_item['variation'] as $attr_key => $attr_val ) {
                    if ( $attr_val === '' ) continue;
                    $tax = str_replace( 'attribute_', '', $attr_key );
                    if ( taxonomy_exists( $tax ) ) {
                        $term = get_term_by( 'slug', $attr_val, $tax );
                        if ( $term && ! is_wp_error( $term ) ) $attr_val = $term->name;
                    }
                    $variation_attrs[] = $attr_val;
                }
            }
        }

        // Кастом-дизайн позиция? (есть прикреплённый артворк / надписи / расположение)
        $aw = ! $bm && ! empty( $cart_item['podval_artwork'] ) ? $cart_item['podval_artwork'] : null;

        // Ссылка «Добавить ещё»: ведёт на страницу товара (без фиксации вариации),
        // чтобы клиент заново выбрал размер/цвет/фасон и принт → новая отдельная позиция.
        $aw_create_url = '';
        if ( $aw ) {
            $aw_create_url = $product->get_permalink();
        }
      ?>

      <div class="podval-cart__item<?php echo $bm ? ' podval-cart__item--bundle' : ''; ?>" data-key="<?php echo esc_attr( $cart_item_key ); ?>"<?php echo $bm ? ' data-bundle="1"' : ''; ?>>

        <a href="<?php echo esc_url( $permalink ); ?>" class="podval-cart__item-img">
          <?php echo $thumbnail; ?>
        </a>

        <div class="podval-cart__item-info">

          <div class="podval-cart__item-top">
            <a href="<?php echo esc_url( $permalink ); ?>" class="podval-cart__item-name">
              <?php echo esc_html( $product_name ); ?>
            </a>
            <button class="podval-cart__remove" data-key="<?php echo esc_attr( $cart_item_key ); ?>" aria-label="Удалить">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <polyline points="3 6 5 6 21 6"/>
                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
              </svg>
            </button>
          </div>

          <?php if ( $bm ) : /* BUNDLE composition + discount badges */ ?>
            <div class="podval-cart__bundle-meta">
              <?php if ( ! empty( $bm['discount_percent'] ) ) : ?>
                <span class="podval-cart__bundle-pct">скидка −<?php echo (int) $bm['discount_percent']; ?>%</span>
              <?php endif; ?>
              <?php if ( ! empty( $bm['saved'] ) && (float) $bm['saved'] > 0 ) : ?>
                <span class="podval-cart__bundle-save">
                  экономия&nbsp;<span data-price-byn="<?php echo (int) round( $bm['saved'] ); ?>"><?php echo number_format( $bm['saved'], 2, ',', '' ); ?>&nbsp;Br</span>
                </span>
              <?php endif; ?>
            </div>

            <?php if ( ! empty( $bm['shirts'] ) ) : ?>
            <details class="podval-cart__bundle-comp" open>
              <summary class="podval-cart__bundle-comp-sum"><?php echo (int) count( $bm['shirts'] ); ?> футболки в наборе</summary>
              <ol class="podval-cart__bundle-comp-list">
                <?php foreach ( $bm['shirts'] as $sh ) :
                  $parts = array();
                  if ( ! empty( $sh['color'] ) ) $parts[] = $sh['color'];
                  if ( ! empty( $sh['size'] ) )  $parts[] = $sh['size'];
                  if ( ! empty( $sh['type'] ) )  $parts[] = $sh['type'];
                ?>
                <li>
                  <span class="podval-cart__bundle-comp-name"><?php echo esc_html( $sh['name'] ); ?></span>
                  <?php if ( $parts ) : ?>
                    <span class="podval-cart__bundle-comp-attrs"><?php echo esc_html( implode( ' · ', $parts ) ); ?></span>
                  <?php endif; ?>
                </li>
                <?php endforeach; ?>
              </ol>
            </details>
            <?php endif; ?>

          <?php elseif ( ! empty( $variation_attrs ) ) : ?>
            <div class="podval-cart__item-attrs">
              <?php foreach ( $variation_attrs as $val ) : ?>
              <span class="podval-cart__attr-badge"><?php echo esc_html( $val ); ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php if ( ! $bm ) : // Artwork files — skipped for bundles
          if ( $aw ) :
            $file_count = ! empty( $aw['files'] ) ? count( $aw['files'] ) : 0;
          ?>
          <div class="podval-cart__artwork-badge-row">
            <?php if ( $file_count > 0 ) : ?>
              <span class="podval-cart__artwork-badge">
                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3" width="14" height="14"><path d="M2.5 2h7l3.5 3.5V14a1 1 0 01-1 1H2.5A1 1 0 011.5 14V3a1 1 0 011-1z"/><path d="M9.5 2v4h4"/><path d="M5 8.5h6M5 11h4"/></svg>
                <?php
                  $slot_labels = array( 'front' => 'перед', 'back' => 'спина', 'sleeve' => 'рукав' );
                  $slot_names  = array();
                  foreach ( $aw['files'] as $slot => $fid ) {
                      $slot_names[] = $slot_labels[ $slot ] ?? $slot;
                  }
                  $word = $file_count === 1 ? 'принт' : ( $file_count < 5 ? 'принта' : 'принтов' );
                  echo 'Прикреплено ' . $file_count . ' ' . $word . ' (' . implode( ', ', $slot_names ) . ')';
                ?>
              </span>
            <?php elseif ( ! empty( $aw['skipped'] ) ) : ?>
              <span class="podval-cart__artwork-badge podval-cart__artwork-badge--pending">
                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3" width="14" height="14"><circle cx="8" cy="8" r="7"/><path d="M8 4v4l3 2"/></svg>
                Файл будет отправлен позже
              </span>
            <?php endif; ?>
            <?php if ( ! empty( $aw['comment'] ) ) : ?>
              <span class="podval-cart__artwork-badge podval-cart__artwork-badge--comment" title="<?php echo esc_attr( $aw['comment'] ); ?>">
                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3" width="14" height="14"><path d="M14 10.7a1.3 1.3 0 01-1.3 1.3H4.6L2 14V3.3A1.3 1.3 0 013.3 2h9.4A1.3 1.3 0 0114 3.3z"/></svg>
                Комментарий к печати
              </span>
            <?php endif; ?>
            <?php
            // Split placement schematics from real extra files.
            $pc_extra = 0; $pc_place = 0;
            if ( ! empty( $aw['extra_files'] ) && is_array( $aw['extra_files'] ) ) {
                foreach ( $aw['extra_files'] as $pc_fid ) {
                    if ( get_post_meta( (int) $pc_fid, '_podval_placement', true ) === '1' ) { $pc_place++; }
                    else { $pc_extra++; }
                }
            }
            if ( $pc_place > 0 ) : ?>
              <span class="podval-cart__artwork-badge podval-cart__artwork-badge--placement">
                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3" width="14" height="14"><path d="M2 4.5V3a1 1 0 011-1h1.5M11.5 2H13a1 1 0 011 1v1.5M14 11.5V13a1 1 0 01-1 1h-1.5M4.5 14H3a1 1 0 01-1-1v-1.5"/><rect x="5" y="5" width="6" height="6" rx="1"/></svg>
                Расположение задано
              </span>
            <?php endif; ?>
            <?php if ( $pc_extra > 0 ) :
              $pc_w = $pc_extra === 1 ? 'доп. файл' : ( $pc_extra < 5 ? 'доп. файла' : 'доп. файлов' );
            ?>
              <span class="podval-cart__artwork-badge podval-cart__artwork-badge--extra">
                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3" width="14" height="14"><path d="M9 1H4a1 1 0 00-1 1v12a1 1 0 001 1h8a1 1 0 001-1V5L9 1z"/><path d="M9 1v4h4"/></svg>
                <?php echo $pc_extra . ' ' . $pc_w; ?>
              </span>
            <?php endif; ?>
          </div>
          <?php endif;
          endif; ?>

          <div class="podval-cart__item-bottom">
            <?php if ( $bm ) : ?>
              <div class="podval-cart__bundle-qty">
                <span class="podval-cart__qty-bundle-label">1 набор</span>
              </div>
            <?php else : ?>
              <div class="podval-cart__qty" data-key="<?php echo esc_attr( $cart_item_key ); ?>">
                <button class="podval-cart__qty-btn podval-cart__qty-minus" aria-label="—">
                  <svg viewBox="0 0 16 16" fill="none"><path d="M3 8h10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                </button>
                <span class="podval-cart__qty-num"><?php echo esc_html( $quantity ); ?></span>
                <button class="podval-cart__qty-btn podval-cart__qty-plus" aria-label="+">
                  <svg viewBox="0 0 16 16" fill="none"><path d="M8 3v10M3 8h10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                </button>
              </div>
              <?php if ( $aw && $aw_create_url ) : ?>
                <a href="<?php echo esc_url( $aw_create_url ); ?>" class="podval-cart__new-design"
                   title="Добавить ещё одну футболку — выбрать размер, цвет, фасон и принт заново">
                  <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" width="14" height="14" aria-hidden="true"><path d="M8 3.5v9M3.5 8h9" stroke-linecap="round"/></svg>
                  <span>Добавить ещё</span>
                </a>
              <?php endif; ?>
            <?php endif; ?>

            <div class="podval-cart__item-price">
              <span class="podval-cart__item-total"><?php echo $line_total; ?></span>
              <?php if ( $unit_price && ! $bm ) : ?>
                <span class="podval-cart__item-unit"><?php echo $unit_price; ?> / шт.</span>
              <?php endif; ?>
            </div>
          </div>

        </div>
      </div>

      <?php endforeach; ?>
    </div><!-- /.podval-cart__items -->

    <!-- ПРАВАЯ КОЛОНКА: итог -->
    <div class="podval-cart__sidebar">
      <div class="podval-cart__summary">

        <div class="podval-cart__summary-row podval-cart__summary-total">
          <span>Итого</span>
          <span class="podval-cart__summary-price">
            <?php echo WC()->cart->get_cart_total(); ?>
          </span>
        </div>

        <?php if ( wc_coupons_enabled() ) : ?>
        <div class="podval-cart__coupon">
          <input type="text" id="podval-coupon-code" class="podval-cart__coupon-input"
                 placeholder="Есть промокод или сертификат?">
          <button id="podval-coupon-apply" class="podval-cart__coupon-btn" aria-label="Применить">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M5 12h14M12 5l7 7-7 7"/>
            </svg>
          </button>
        </div>
        <?php endif; ?>

        <a href="<?php echo esc_url( wc_get_checkout_url() ); ?>" class="podval-cart__checkout-btn">
          Оформить заказ
        </a>

        <?php
        /* Второй принт — частый сценарий: собрали футболку одному, тут же
           хотят себе. Отправлять за этим в меню неудобно, поэтому ссылка
           стоит там, где человек уже находится.

           Показывается только если в корзине есть собранный макет: для
           обычных товаров кнопка была бы навязчивой. */
        $has_tpl = false;
        foreach ( WC()->cart->get_cart() as $ci ) {
            if ( ! empty( $ci['podval_tpl'] ) ) { $has_tpl = true; break; }
        }
        $cd_term = $has_tpl ? get_term_by( 'slug', 'custom-design', 'product_cat' ) : null;
        if ( $cd_term && ! is_wp_error( $cd_term ) ) :
            $cd_url = get_term_link( $cd_term );
            if ( ! is_wp_error( $cd_url ) ) : ?>
        <a href="<?php echo esc_url( $cd_url ); ?>" class="podval-cart__more-btn">
          Собрать ещё один принт
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="16" height="16" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
        </a>
        <?php endif; endif; ?>

        <button id="podval-cart-clear" class="podval-cart__clear-btn">
          Очистить корзину
        </button>

      </div>
    </div>

  </div><!-- /.podval-cart__layout -->

<?php endif; ?>

</div><!-- /.podval-cart -->
</div><!-- /.site-content -->

<?php get_footer(); ?>

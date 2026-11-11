<?php
/**
 * Podval Print — Cart Page
 * Кастомная корзина
 */
defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_cart' );

$cart_items = WC()->cart->get_cart();
$is_empty   = empty( $cart_items );
?>

<div class="podval-cart">

  <div class="podval-cart__header">
    <h1 class="podval-cart__title">Корзина</h1>
    <?php if ( ! $is_empty ) : ?>
      <span class="podval-cart__count">
        <?php echo WC()->cart->get_cart_contents_count(); ?> товар<?php
          $n = WC()->cart->get_cart_contents_count();
          if ( $n % 10 == 1 && $n % 100 != 11 ) echo '';
          elseif ( $n % 10 >= 2 && $n % 10 <= 4 && ( $n % 100 < 10 || $n % 100 >= 20 ) ) echo 'а';
          else echo 'ов';
        ?>
      </span>
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
        $product   = $cart_item['data'];
        $product_id = $cart_item['product_id'];
        $variation_id = $cart_item['variation_id'];
        $quantity   = $cart_item['quantity'];

        if ( ! $product || ! $product->exists() || $cart_item['quantity'] == 0 ) continue;

        $thumbnail   = $product->get_image( 'woocommerce_thumbnail' );
        $product_name = $product->get_name();
        $permalink   = $product->get_permalink( $cart_item );
        $line_price  = WC()->cart->get_product_price( $product );

        // Атрибуты вариации
        $variation_attrs = array();
        if ( $variation_id && ! empty( $cart_item['variation'] ) ) {
          foreach ( $cart_item['variation'] as $attr_key => $attr_val ) {
            if ( $attr_val === '' ) continue;
            $label = wc_attribute_label( str_replace( 'attribute_', '', $attr_key ) );
            // Если taxonomy — получаем имя термина
            $tax = str_replace( 'attribute_', '', $attr_key );
            if ( taxonomy_exists( $tax ) ) {
              $term = get_term_by( 'slug', $attr_val, $tax );
              if ( $term && ! is_wp_error( $term ) ) $attr_val = $term->name;
            }
            $variation_attrs[] = array( 'label' => $label, 'value' => $attr_val );
          }
        }

        $line_total  = wc_price( $product->get_price() * $quantity );
        $unit_price  = wc_price( $product->get_price() );
      ?>

      <div class="podval-cart__item" data-key="<?php echo esc_attr( $cart_item_key ); ?>">

        <!-- Фото -->
        <a href="<?php echo esc_url( $permalink ); ?>" class="podval-cart__item-img">
          <?php echo $thumbnail; ?>
        </a>

        <!-- Инфо -->
        <div class="podval-cart__item-info">

          <div class="podval-cart__item-top">
            <a href="<?php echo esc_url( $permalink ); ?>" class="podval-cart__item-name">
              <?php echo esc_html( $product_name ); ?>
            </a>
            <!-- Кнопка удалить -->
            <button class="podval-cart__remove"
                    data-key="<?php echo esc_attr( $cart_item_key ); ?>"
                    aria-label="Удалить товар">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
              </svg>
            </button>
          </div>

          <!-- Вариации (бейджи размеров) -->
          <?php if ( ! empty( $variation_attrs ) ) : ?>
          <div class="podval-cart__item-attrs">
            <?php foreach ( $variation_attrs as $attr ) : ?>
            <span class="podval-cart__attr-badge"><?php echo esc_html( $attr['value'] ); ?></span>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <?php // DEBUG: show cart item keys when ?debug_aw=1
          if ( isset( $_GET['debug_aw'] ) ) :
            $debug_t = get_transient( 'podval_aw_' . $cart_item_key );
            echo '<div style="background:#330;padding:8px;font-size:11px;border-radius:6px;margin-top:6px;">';
            echo '<b>key:</b> ' . esc_html( $cart_item_key ) . '<br>';
            echo '<b>has podval_artwork:</b> ' . ( ! empty( $cart_item['podval_artwork'] ) ? 'YES' : 'NO' ) . '<br>';
            echo '<b>transient found:</b> ' . ( $debug_t ? 'YES' : 'NO' ) . '<br>';
            echo '<b>keys in item:</b> ' . esc_html( implode( ', ', array_keys( $cart_item ) ) );
            echo '</div>';
          endif; ?>

          <?php // Artwork files — simplified display in cart
          $aw = ! empty( $cart_item['podval_artwork'] ) ? $cart_item['podval_artwork'] : null;
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
            // Split placement schematics (editor reference) from real extra files.
            $extra_count     = 0;
            $placement_count = 0;
            if ( ! empty( $aw['extra_files'] ) && is_array( $aw['extra_files'] ) ) {
                foreach ( $aw['extra_files'] as $efile_id ) {
                    if ( get_post_meta( (int) $efile_id, '_podval_placement', true ) === '1' ) {
                        $placement_count++;
                    } else {
                        $extra_count++;
                    }
                }
            }
            if ( $placement_count > 0 ) :
            ?>
              <span class="podval-cart__artwork-badge podval-cart__artwork-badge--placement">
                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3" width="14" height="14"><path d="M2 4.5V3a1 1 0 011-1h1.5M11.5 2H13a1 1 0 011 1v1.5M14 11.5V13a1 1 0 01-1 1h-1.5M4.5 14H3a1 1 0 01-1-1v-1.5"/><rect x="5" y="5" width="6" height="6" rx="1"/></svg>
                Расположение задано
              </span>
            <?php endif; ?>
            <?php
            if ( $extra_count > 0 ) :
              $ef_w = $extra_count === 1 ? 'доп. файл' : ( $extra_count < 5 ? 'доп. файла' : 'доп. файлов' );
            ?>
              <span class="podval-cart__artwork-badge podval-cart__artwork-badge--extra">
                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3" width="14" height="14"><path d="M9 1H4a1 1 0 00-1 1v12a1 1 0 001 1h8a1 1 0 001-1V5L9 1z"/><path d="M9 1v4h4"/></svg>
                <?php echo $extra_count . ' ' . $ef_w; ?>
              </span>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <!-- Нижняя строка: стюпер + цена -->
          <div class="podval-cart__item-bottom">

            <!-- Стюпер количества -->
            <div class="podval-cart__qty" data-key="<?php echo esc_attr( $cart_item_key ); ?>">
              <button class="podval-cart__qty-btn podval-cart__qty-minus" aria-label="Уменьшить">
                <svg viewBox="0 0 16 16" fill="none"><path d="M3 8h10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
              </button>
              <span class="podval-cart__qty-num"><?php echo esc_html( $quantity ); ?></span>
              <button class="podval-cart__qty-btn podval-cart__qty-plus" aria-label="Увеличить">
                <svg viewBox="0 0 16 16" fill="none"><path d="M8 3v10M3 8h10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
              </button>
            </div>

            <!-- Цена -->
            <div class="podval-cart__item-price">
              <span class="podval-cart__item-total"><?php echo $line_total; ?></span>
              <span class="podval-cart__item-unit"><?php echo $unit_price; ?> / шт.</span>
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
          <span class="podval-cart__summary-price" id="cart-total-price">
            <?php echo WC()->cart->get_cart_total(); ?>
          </span>
        </div>

        <?php if ( wc_coupons_enabled() ) : ?>
        <div class="podval-cart__coupon">
          <input type="text"
                 id="podval-coupon-code"
                 class="podval-cart__coupon-input"
                 placeholder="Есть промокод или сертификат?"
                 value="">
          <button id="podval-coupon-apply" class="podval-cart__coupon-btn" aria-label="Применить">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M5 12h14M12 5l7 7-7 7"/>
            </svg>
          </button>
        </div>
        <?php endif; ?>

        <a href="<?php echo esc_url( wc_get_checkout_url() ); ?>" class="podval-cart__checkout-btn">
          Перейти к оформлению
        </a>

        <button id="podval-cart-clear" class="podval-cart__clear-btn">
          Очистить корзину
        </button>

        <?php do_action( 'woocommerce_cart_totals_before_shipping' ); ?>
        <?php do_action( 'woocommerce_cart_totals_after_shipping' ); ?>

      </div>
    </div><!-- /.podval-cart__sidebar -->

  </div><!-- /.podval-cart__layout -->

  <div class="podval-cart__back">
    <a href="<?php echo esc_url( podval_get_catalog_url() ); ?>" class="podval-cart__back-btn">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="18" height="18"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
      Вернуться в магазин
    </a>
  </div>

<?php endif; ?>

</div><!-- /.podval-cart -->

<?php do_action( 'woocommerce_after_cart' ); ?>

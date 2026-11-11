<?php
/**
 * Podval Print — Thank You / Order Received page
 */
defined( 'ABSPATH' ) || exit;

// заказ — только при верном ?key= (раньше показывался любой заказ по ID).
$order    = function_exists( 'podval_get_thankyou_order' ) ? podval_get_thankyou_order() : false;
$order_id = $order ? $order->get_id() : 0;
?>

<div class="podval-thankyou">

  <?php if ( $order ) :
    $order_number   = $order->get_order_number();
    $order_date     = $order->get_date_created();
    $order_total    = $order->get_formatted_order_total();
    $payment_title  = $order->get_payment_method_title();
    $billing_name   = $order->get_billing_first_name();
    $messenger      = $order->get_meta( '_billing_messenger' );
    $phone          = $order->get_billing_phone();
    $shipping_label = $order->get_meta( '_podval_shipping_label' );
    $shipping_addr  = $order->get_shipping_address_1();
    if ( ! $shipping_addr ) {
        $shipping_addr = get_post_meta( $order_id, '_shipping_address_1', true );
    }
    $items          = $order->get_items();
    $is_service     = $order->get_meta( '_order_type' ) === 'service';
  ?>

  <?php if ( $is_service ) : ?>
  <!-- ===== ЗАЯВКА НА УСЛУГУ ===== -->
  <div class="podval-ty__header">
    <div class="podval-ty__icon">
      <svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
        <circle cx="32" cy="32" r="30" stroke="currentColor" stroke-width="2"/>
        <path d="M20 33l8 8 16-16" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    </div>
    <h1 class="podval-ty__title">Заявка принята!</h1>
    <p class="podval-ty__subtitle">Спасибо, <?php echo esc_html( $billing_name ?: 'друг' ); ?>! Мы получили вашу заявку и скоро свяжемся с вами для обсуждения деталей.</p>
    <div class="podval-ty__order-num">Заявка №<?php echo esc_html( $order_number ); ?></div>
  </div>

  <?php
  // заявка с лендинга — сводка расчёта (пересчитан сервером) и возврат на лендинг.
  $ty_landing_id = (int) $order->get_meta( '_podval_sl_landing' );
  $ty_landing_ok = $ty_landing_id && get_post_status( $ty_landing_id ) === 'publish';
  $ty_calc       = $order->get_meta( '_podval_sl_calc' );
  $ty_calc_text  = ( is_array( $ty_calc ) && function_exists( 'podval_price_calc_summary' ) )
      ? podval_price_calc_summary( $ty_calc, ( $ty_landing_id && function_exists( 'podval_sl_get' ) ) ? podval_sl_get( $ty_landing_id )['card']['lead_time'] : '' )
      : '';
  ?>
  <div class="podval-ty__details">
    <?php if ( $ty_calc_text ) : ?>
    <div class="podval-ty__info-block" style="margin-bottom:24px;">
      <h3 class="podval-ty__info-label">Предварительный расчёт</h3>
      <div class="podval-ty__info-value"><?php echo esc_html( $ty_calc_text ); ?></div>
      <div class="podval-ty__info-sub">Точную стоимость подтвердим после проверки макета.</div>
    </div>
    <?php endif; ?>
    <div class="podval-ty__info-grid" style="border-top:none;padding-top:0;">
      <?php if ( $messenger ) : ?>
      <div class="podval-ty__info-block">
        <h3 class="podval-ty__info-label">Мы напишем</h3>
        <div class="podval-ty__info-value"><?php echo esc_html( $messenger ); ?></div>
        <?php if ( $phone ) : ?>
        <div class="podval-ty__info-sub"><?php echo esc_html( $phone ); ?></div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <?php if ( $shipping_label && $shipping_label !== 'Обсудим позже' ) : ?>
      <div class="podval-ty__info-block">
        <h3 class="podval-ty__info-label">Доставка</h3>
        <div class="podval-ty__info-value"><?php echo esc_html( $shipping_label ); ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="podval-ty__actions">
    <?php if ( $ty_landing_ok && function_exists( 'podval_sl_h1' ) ) : ?>
    <a href="<?php echo esc_url( get_permalink( $ty_landing_id ) ); ?>" class="podval-ty__btn-primary">Вернуться к «<?php echo esc_html( podval_sl_h1( $ty_landing_id ) ); ?>»</a>
    <?php else : ?>
    <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="podval-ty__btn-primary">На главную</a>
    <?php endif; ?>
    <?php $ty_hub = get_term_link( 'uslugi', 'product_cat' ); ?>
    <a href="<?php echo esc_url( is_wp_error( $ty_hub ) ? home_url( '/' ) : $ty_hub ); ?>" class="podval-ty__btn-outline">Все услуги</a>
  </div>

  <?php else : ?>

  <!-- Шапка -->
  <div class="podval-ty__header">
    <div class="podval-ty__icon">
      <svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
        <circle cx="32" cy="32" r="30" stroke="currentColor" stroke-width="2"/>
        <path d="M20 33l8 8 16-16" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    </div>
    <h1 class="podval-ty__title">Заказ принят!</h1>
    <p class="podval-ty__subtitle">Спасибо, <?php echo esc_html( $billing_name ?: 'друг' ); ?>! Скоро свяжемся с вами для подтверждения.</p>
    <div class="podval-ty__order-num">Заказ №<?php echo esc_html( $order_number ); ?></div>
  </div>

  <!-- Детали заказа -->
  <div class="podval-ty__details">

    <!-- Товары -->
    <div class="podval-ty__section">
      <h2 class="podval-ty__section-title">Состав заказа</h2>
      <div class="podval-ty__items">
        <?php foreach ( $items as $item ) :
          $product  = $item->get_product();
          $qty      = $item->get_quantity();
          $subtotal = $order->get_formatted_line_subtotal( $item );
          $thumb    = $product ? $product->get_image( array(60, 60) ) : '';

          // Вариация
          $meta_display = array();
          $meta_data = $item->get_formatted_meta_data( '_', true );
          foreach ( $meta_data as $meta ) {
              $meta_display[] = wp_strip_all_tags( $meta->display_value );
          }

          // BUNDLE — иначе рендер
          $_bm = method_exists( $item, 'get_meta' ) ? $item->get_meta( '_bundle_meta' ) : null;
          $is_bundle = is_array( $_bm ) && ! empty( $_bm['bundle_name'] );
        ?>
        <div class="podval-ty__item<?php echo $is_bundle ? ' podval-ty__item--bundle' : ''; ?>">
          <?php if ( $thumb ) : ?>
          <div class="podval-ty__item-thumb"><?php echo $thumb; ?></div>
          <?php endif; ?>
          <div class="podval-ty__item-info">
            <div class="podval-ty__item-name"><?php echo esc_html( $item->get_name() ); ?></div>

            <?php if ( $is_bundle ) : ?>
              <?php echo podval_bundle_render_order_composition( $_bm, 'html' ); ?>
              <div class="podval-ty__item-qty">1 набор</div>
            <?php else : ?>
              <?php if ( ! empty( $meta_display ) ) : ?>
              <div class="podval-ty__item-meta"><?php echo esc_html( implode( ', ', $meta_display ) ); ?></div>
              <?php endif; ?>
              <div class="podval-ty__item-qty">× <?php echo esc_html( $qty ); ?></div>
              <?php
              // Artwork files badge (skipped for bundles)
              $aw_files   = $item->get_meta( '_artwork_files' );
              $aw_comment = $item->get_meta( '_artwork_comment' );
              $aw_skipped = $item->get_meta( '_artwork_skipped' );
              if ( $aw_files || $aw_skipped ) :
                $slot_labels = array( 'front' => 'перед', 'back' => 'спина', 'sleeve' => 'рукав' );
              ?>
              <div class="podval-cart__artwork-badge-row" style="margin-top:6px;">
                <?php if ( is_array( $aw_files ) && ! empty( $aw_files ) ) :
                  $slot_names = array();
                  foreach ( $aw_files as $slot => $fid ) { $slot_names[] = $slot_labels[ $slot ] ?? $slot; }
                  $fc = count( $aw_files );
                  $word = $fc === 1 ? 'принт' : ( $fc < 5 ? 'принта' : 'принтов' );
                ?>
                  <span class="podval-cart__artwork-badge">
                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3" width="14" height="14"><path d="M2.5 2h7l3.5 3.5V14a1 1 0 01-1 1H2.5A1 1 0 011.5 14V3a1 1 0 011-1z"/><path d="M9.5 2v4h4"/><path d="M5 8.5h6M5 11h4"/></svg>
                    <?php echo $fc . ' ' . $word . ' (' . implode( ', ', $slot_names ) . ')'; ?>
                  </span>
                <?php elseif ( $aw_skipped ) : ?>
                  <span class="podval-cart__artwork-badge podval-cart__artwork-badge--pending">Файл будет отправлен позже</span>
                <?php endif; ?>
                <?php if ( $aw_comment ) : ?>
                  <span class="podval-cart__artwork-badge podval-cart__artwork-badge--comment">Комментарий к печати</span>
                <?php endif; ?>
                <?php
              $aw_extra = $item->get_meta( '_artwork_extra_files' );
              $aw_extra_count = 0;
              $aw_placement_count = 0;
              if ( is_array( $aw_extra ) ) {
                  foreach ( $aw_extra as $aw_efid ) {
                      if ( get_post_meta( (int) $aw_efid, '_podval_placement', true ) === '1' ) {
                          $aw_placement_count++;
                      } else {
                          $aw_extra_count++;
                      }
                  }
              }
              if ( $aw_placement_count > 0 ) :
              ?>
                <span class="podval-cart__artwork-badge podval-cart__artwork-badge--placement">
                  <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3" width="14" height="14"><path d="M2 4.5V3a1 1 0 011-1h1.5M11.5 2H13a1 1 0 011 1v1.5M14 11.5V13a1 1 0 01-1 1h-1.5M4.5 14H3a1 1 0 01-1-1v-1.5"/><rect x="5" y="5" width="6" height="6" rx="1"/></svg>
                  Расположение задано
                </span>
              <?php endif; ?>
              <?php
              if ( $aw_extra_count > 0 ) :
                $aw_ef_w = $aw_extra_count === 1 ? 'доп. файл' : ( $aw_extra_count < 5 ? 'доп. файла' : 'доп. файлов' );
              ?>
                <span class="podval-cart__artwork-badge podval-cart__artwork-badge--extra">
                  <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3" width="14" height="14"><path d="M9 1H4a1 1 0 00-1 1v12a1 1 0 001 1h8a1 1 0 001-1V5L9 1z"/><path d="M9 1v4h4"/></svg>
                  <?php echo $aw_extra_count . ' ' . $aw_ef_w; ?>
                </span>
              <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php endif; // $is_bundle ?>
          </div>
          <div class="podval-ty__item-price"><?php echo $subtotal; ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Информация -->
    <div class="podval-ty__info-grid">

      <div class="podval-ty__info-block">
        <h3 class="podval-ty__info-label">Доставка</h3>
        <div class="podval-ty__info-value"><?php echo esc_html( $shipping_label ?: '—' ); ?></div>
        <?php if ( $shipping_addr ) : ?>
        <div class="podval-ty__info-sub"><?php echo esc_html( $shipping_addr ); ?></div>
        <?php endif; ?>
      </div>

      <div class="podval-ty__info-block">
        <h3 class="podval-ty__info-label">Оплата</h3>
        <div class="podval-ty__info-value"><?php echo esc_html( $payment_title ?: '—' ); ?></div>
      </div>

      <div class="podval-ty__info-block">
        <h3 class="podval-ty__info-label">Контакт</h3>
        <?php if ( $messenger ) : ?>
        <div class="podval-ty__info-value"><?php echo esc_html( $messenger ); ?></div>
        <?php endif; ?>
        <?php if ( $phone ) : ?>
        <div class="podval-ty__info-sub"><?php echo esc_html( $phone ); ?></div>
        <?php endif; ?>
      </div>

      <div class="podval-ty__info-block">
        <h3 class="podval-ty__info-label">Итого</h3>
        <div class="podval-ty__info-value podval-ty__info-total"><?php echo $order_total; ?></div>
      </div>

    </div>

  </div>

  <!-- Кнопка -->
  <div class="podval-ty__actions">
    <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="podval-ty__btn-primary">На главную</a>
    <a href="<?php echo esc_url( podval_get_catalog_url() ); ?>" class="podval-ty__btn-outline">Продолжить покупки</a>
  </div>

  <?php endif; // end is_service else (regular order) ?>

  <?php else : ?>

  <div class="podval-ty__header">
    <h1 class="podval-ty__title">Заказ не найден</h1>
    <p class="podval-ty__subtitle">Возможно, ссылка устарела или заказ был удалён.</p>
    <div class="podval-ty__actions" style="margin-top:32px;">
      <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="podval-ty__btn-primary">На главную</a>
    </div>
  </div>

  <?php endif; ?>

</div>

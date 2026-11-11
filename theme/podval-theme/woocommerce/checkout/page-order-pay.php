<?php
/**
 * Podval Print — Order Pay page (online payment)
 *
 * Handles both the payment form display AND processing the POST
 * when the user clicks "Оплатить". Without the POST handler below
 * the gateway's process_payment() would never fire because we
 * replaced the standard WC form-pay.php template.
 */
defined( 'ABSPATH' ) || exit;

global $wp;
$order_id = absint( $wp->query_vars['order-pay'] ?? 0 );
$order    = $order_id ? wc_get_order( $order_id ) : false;

/* ── Handle POST: process payment ────────────────────────── */
if ( $_SERVER['REQUEST_METHOD'] === 'POST'
     && ! empty( $_POST['woocommerce_pay'] )
     && $order
     && $order->needs_payment()
) {
    // Verify nonce
    if ( ! isset( $_POST['woocommerce-pay-nonce'] )
         || ! wp_verify_nonce( $_POST['woocommerce-pay-nonce'], 'woocommerce-pay' )
    ) {
        wc_add_notice( 'Ошибка безопасности. Попробуйте снова.', 'error' );
    } else {
        // Validate order key if present in URL
        $order_key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
        if ( $order_key && $order->get_order_key() !== $order_key ) {
            wc_add_notice( 'Неверный ключ заказа.', 'error' );
        } else {
            $payment_method = isset( $_POST['payment_method'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_method'] ) ) : '';
            $available_gws  = WC()->payment_gateways->get_available_payment_gateways();

            if ( ! isset( $available_gws[ $payment_method ] ) ) {
                wc_add_notice( 'Выбранный способ оплаты недоступен.', 'error' );
            } else {
                // Update order payment method (in case it changed)
                $order->set_payment_method( $available_gws[ $payment_method ] );
                $order->save();

                // Fire standard WC hooks so other plugins can validate
                do_action( 'woocommerce_before_pay_action', $order );

                // Process payment through the gateway
                $result = $available_gws[ $payment_method ]->process_payment( $order->get_id() );

                if ( isset( $result['result'] ) && $result['result'] === 'success' ) {
                    // Redirect to bank / success page
                    if ( ! empty( $result['redirect'] ) ) {
                        wp_redirect( $result['redirect'] );
                        exit;
                    }
                } else {
                    // Gateway returned failure or no result
                    if ( ! wc_notice_count( 'error' ) ) {
                        wc_add_notice( 'Ошибка при обработке платежа. Попробуйте снова.', 'error' );
                    }
                }
            }
        }
    }

    // If we got here, there were errors — re-fetch order state
    $order = wc_get_order( $order_id );
}

get_header();
?>

<div class="podval-pay">

<?php
// Display WC error/success notices (e.g. payment processing errors)
if ( function_exists( 'wc_print_notices' ) ) {
    wc_print_notices();
}
?>

<?php if ( $order && $order->needs_payment() ) :
  $order_number  = $order->get_order_number();
  $order_total   = $order->get_formatted_order_total();
  $billing_name  = $order->get_billing_first_name();
  $items         = $order->get_items();
  $payment_url   = $order->get_checkout_payment_url();

  // Get available payment gateways for this order
  $available_gateways = WC()->payment_gateways->get_available_payment_gateways();
  $payment_method     = $order->get_payment_method();
  $gateway            = isset( $available_gateways[ $payment_method ] ) ? $available_gateways[ $payment_method ] : null;
?>

  <div class="podval-pay__header">
    <div class="podval-pay__icon">
      <svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
        <rect x="6" y="16" width="52" height="32" rx="4" stroke="currentColor" stroke-width="2"/>
        <line x1="6" y1="26" x2="58" y2="26" stroke="currentColor" stroke-width="2"/>
        <rect x="12" y="34" width="16" height="4" rx="1" fill="currentColor" opacity="0.4"/>
      </svg>
    </div>
    <h1 class="podval-pay__title">Оплата заказа</h1>
    <p class="podval-pay__subtitle">Заказ №<?php echo esc_html( $order_number ); ?></p>
  </div>

  <div class="podval-pay__card">

    <!-- Товары -->
    <div class="podval-pay__items">
      <?php foreach ( $items as $item ) :
        $qty = $item->get_quantity();
      ?>
      <div class="podval-pay__item">
        <span class="podval-pay__item-name"><?php echo esc_html( $item->get_name() ); ?> × <?php echo esc_html( $qty ); ?></span>
        <span class="podval-pay__item-price"><?php echo wc_price( $item->get_total() ); ?></span>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="podval-pay__divider"></div>

    <div class="podval-pay__total">
      <span>К оплате</span>
      <span class="podval-pay__total-amount"><?php echo $order_total; ?></span>
    </div>

    <div class="podval-pay__divider"></div>

    <!-- Форма оплаты -->
    <form method="post" id="order_review" action="<?php echo esc_url( $payment_url ); ?>">
      <input type="hidden" name="woocommerce_pay" value="1" />
      <input type="hidden" name="payment_method" value="<?php echo esc_attr( $payment_method ); ?>" />
      <?php wp_nonce_field( 'woocommerce-pay', 'woocommerce-pay-nonce' ); ?>

      <button type="submit" class="podval-pay__btn" id="place_order">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="20" height="20">
          <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>
          <line x1="1" y1="10" x2="23" y2="10"/>
        </svg>
        Оплатить <?php echo wp_strip_all_tags( $order_total ); ?>
      </button>
    </form>

    <p class="podval-pay__secure">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="14" height="14">
        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
      </svg>
      Безопасная оплата через Альфа-Банк
    </p>

  </div>

  <a href="<?php echo esc_url( home_url('/') ); ?>" class="podval-pay__back">← Вернуться на сайт</a>

<?php elseif ( $order ) : ?>

  <div class="podval-pay__header">
    <div class="podval-pay__icon podval-pay__icon--done">
      <svg viewBox="0 0 64 64" fill="none">
        <circle cx="32" cy="32" r="30" stroke="currentColor" stroke-width="2"/>
        <path d="M20 33l8 8 16-16" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    </div>
    <h1 class="podval-pay__title">Заказ уже оплачен</h1>
    <p class="podval-pay__subtitle">Заказ №<?php echo esc_html( $order->get_order_number() ); ?> не требует оплаты.</p>
    <a href="<?php echo esc_url( home_url('/') ); ?>" class="podval-pay__btn" style="margin-top:24px;display:inline-flex;">На главную</a>
  </div>

<?php else : ?>

  <div class="podval-pay__header">
    <h1 class="podval-pay__title">Заказ не найден</h1>
    <p class="podval-pay__subtitle">Проверьте ссылку или обратитесь в поддержку.</p>
    <a href="<?php echo esc_url( home_url('/') ); ?>" class="podval-pay__btn" style="margin-top:24px;display:inline-flex;">На главную</a>
  </div>

<?php endif; ?>

</div>

<?php get_footer(); ?>

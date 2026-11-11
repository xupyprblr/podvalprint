<?php
/**
 * Customer Processing Order Email (в оформлении бренда)
 *
 * Override: woocommerce/emails/customer-processing-order.php
 * Place this file at: wp-content/themes/podval-theme/woocommerce/emails/customer-processing-order.php
 *
 * @package PodvalTheme
 * @version 1.1.0
 */

defined( 'ABSPATH' ) || exit;

$order_number   = $order->get_order_number();
$order_date     = wc_format_datetime( $order->get_date_created(), 'd.m.Y' );
$billing_name   = $order->get_billing_first_name();
$currency       = $order->get_currency();
$total          = $order->get_total();
$items          = $order->get_items();
$site_url       = home_url( '/' );
$telegram_url   = podval_brand( 'telegram' );
$instagram_url  = podval_brand( 'instagram' );
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Заказ <?php echo esc_html( $order_number ); ?> — <?php echo esc_html( podval_brand( 'name' ) ); ?></title>
<style>
  body, table, td, p, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
  body { margin: 0; padding: 0; width: 100% !important; background-color: #111111; }
  table { border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
  img { border: 0; outline: none; text-decoration: none; -ms-interpolation-mode: bicubic; }
</style>
</head>
<body style="margin: 0; padding: 0; background-color: #111111;">

<!-- Wrapper -->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #111111;">
<tr><td align="center" style="padding: 24px 16px;">

<!-- Container 600px -->
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width: 600px; width: 100%;">

<!-- ============ HEADER ============ -->
<tr>
<td align="center" style="background-color: #1a1a1a; padding: 32px 40px 24px;">
  <p style="margin: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 22px; font-weight: 700; letter-spacing: 0.05em; color: #ffffff;">
    <?php echo esc_html( podval_brand( 'logo_word_1' ) ); ?> <span style="color: #ff6a00;"><?php echo esc_html( podval_brand( 'logo_word_2' ) ); ?></span>
  </p>
  <table role="presentation" cellpadding="0" cellspacing="0" style="margin-top: 16px;">
    <tr><td style="width: 60px; height: 2px; background-color: #ff6a00; font-size: 0; line-height: 0;">&nbsp;</td></tr>
  </table>
</td>
</tr>

<!-- ============ BODY ============ -->
<tr>
<td style="background-color: #222222; padding: 32px 40px;">

  <!-- Greeting -->
  <p style="margin: 0 0 8px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 16px; font-weight: 600; color: #ffffff;">
    <?php if ( $billing_name ) : ?>
      <?php echo esc_html( $billing_name ); ?>, спасибо за заказ!
    <?php else : ?>
      Спасибо за заказ!
    <?php endif; ?>
  </p>

  <p style="margin: 0 0 24px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 14px; color: #999999;">
    Заказ <span style="color: #ff6a00; font-weight: 600;">#<?php echo esc_html( $order_number ); ?></span> от <?php echo esc_html( $order_date ); ?>
  </p>

  <!-- Message block -->
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 24px;">
    <tr>
      <td style="border-left: 3px solid #ff6a00; background-color: #2a2a2a; padding: 16px 20px;">
        <p style="margin: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 14px; color: #cccccc; line-height: 1.6;">
          Мы получили ваш заказ и уже начинаем работу. В ближайшее время мы свяжемся с вами через
          <strong style="color: #ffffff;">Telegram</strong> или по
          <strong style="color: #ffffff;">телефону</strong> для уточнения деталей.
        </p>
      </td>
    </tr>
  </table>

  <!-- Section label -->
  <p style="margin: 0 0 12px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 11px; font-weight: 700; color: #ff6a00; letter-spacing: 0.1em; text-transform: uppercase;">
    Ваш заказ
  </p>

  <!-- Order items -->
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
  <?php foreach ( $items as $item_id => $item ) :
    $product  = $item->get_product();
    $qty      = $item->get_quantity();
    $subtotal = $order->get_line_total( $item, true );

    // BUNDLE — отдельный путь
    $_bm = method_exists( $item, 'get_meta' ) ? $item->get_meta( '_bundle_meta' ) : null;
    $is_bundle = is_array( $_bm ) && ! empty( $_bm['bundle_name'] );

    // Get item meta (variations + custom attributes) via WC standard method.
    // Handles Cyrillic taxonomy labels correctly and auto-skips hidden meta (prefixed with _).
    $meta_parts = array();
    if ( ! $is_bundle ) {
      $formatted_meta = $item->get_formatted_meta_data();
      foreach ( $formatted_meta as $meta ) {
        $label = wp_strip_all_tags( $meta->display_key );
        $value = wp_strip_all_tags( $meta->display_value );
        if ( $label && $value ) {
          $meta_parts[] = $label . ': ' . $value;
        }
      }
      if ( $qty > 1 ) {
        $meta_parts[] = 'x' . $qty;
      }
    }
    $meta_string = implode( ' / ', $meta_parts );
  ?>
    <tr>
      <td style="padding: 12px 0; border-bottom: 1px solid #333333;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
          <tr>
            <td style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
              <p style="margin: 0 0 4px; font-size: 14px; color: #ffffff;"><?php echo esc_html( $item->get_name() ); ?></p>
              <?php if ( $is_bundle ) : ?>
                <?php echo podval_bundle_render_order_composition( $_bm, 'email' ); ?>
              <?php elseif ( $meta_string ) : ?>
                <p style="margin: 0; font-size: 12px; color: #777777;"><?php echo esc_html( $meta_string ); ?></p>
              <?php endif; ?>
            </td>
            <td align="right" style="vertical-align: middle; white-space: nowrap; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 14px; color: #ffffff;">
              <?php echo wp_kses_post( wc_price( $subtotal, array( 'currency' => $currency ) ) ); ?>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  <?php endforeach; ?>
  </table>

  <!-- Subtotals -->
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top: 8px;">
    <?php
    // Shipping
    $shipping_total = $order->get_shipping_total();
    if ( $shipping_total > 0 ) :
      $shipping_method = '';
      foreach ( $order->get_shipping_methods() as $sm ) {
        $shipping_method = $sm->get_name();
        break;
      }
    ?>
    <tr>
      <td style="padding: 8px 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 13px; color: #999999;">
        Доставка<?php echo $shipping_method ? ' (' . esc_html( $shipping_method ) . ')' : ''; ?>
      </td>
      <td align="right" style="padding: 8px 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 13px; color: #999999;">
        <?php echo wp_kses_post( wc_price( $shipping_total, array( 'currency' => $currency ) ) ); ?>
      </td>
    </tr>
    <?php endif; ?>

    <?php
    // Discount
    $discount = $order->get_discount_total();
    if ( $discount > 0 ) :
    ?>
    <tr>
      <td style="padding: 8px 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 13px; color: #999999;">
        Скидка
      </td>
      <td align="right" style="padding: 8px 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 13px; color: #4ade80;">
        -<?php echo wp_kses_post( wc_price( $discount, array( 'currency' => $currency ) ) ); ?>
      </td>
    </tr>
    <?php endif; ?>

    <!-- Payment method -->
    <tr>
      <td style="padding: 8px 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 13px; color: #999999;">
        Оплата
      </td>
      <td align="right" style="padding: 8px 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 13px; color: #999999;">
        <?php echo esc_html( $order->get_payment_method_title() ); ?>
      </td>
    </tr>
  </table>

  <!-- Total -->
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top: 1px solid #333333; margin-top: 8px;">
    <tr>
      <td style="padding: 16px 0 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 14px; font-weight: 600; color: #ffffff;">
        Итого
      </td>
      <td align="right" style="padding: 16px 0 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 18px; font-weight: 700; color: #ff6a00;">
        <?php echo wp_kses_post( wc_price( $total, array( 'currency' => $currency ) ) ); ?>
      </td>
    </tr>
  </table>

</td>
</tr>

<!-- ============ FOOTER ============ -->
<tr>
<td align="center" style="background-color: #1a1a1a; padding: 24px 40px;">

  <p style="margin: 0 0 12px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 12px; color: #666666;">
    Есть вопросы? Напишите нам
  </p>

  <p style="margin: 0 0 16px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 12px;">
    <?php if ( $telegram_url ) : ?>
    <a href="<?php echo esc_url( $telegram_url ); ?>" style="color: #ff6a00; text-decoration: none;">Telegram</a>
    <span style="color: #444444; padding: 0 8px;">&middot;</span>
    <?php endif; ?>
    <?php if ( $instagram_url ) : ?>
    <a href="<?php echo esc_url( $instagram_url ); ?>" style="color: #ff6a00; text-decoration: none;">Instagram</a>
    <span style="color: #444444; padding: 0 8px;">&middot;</span>
    <?php endif; ?>
    <a href="<?php echo esc_url( $site_url ); ?>" style="color: #999999; text-decoration: none;"><?php echo esc_html( wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ); ?></a>
  </p>

  <p style="margin: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 11px; color: #444444;">
    &copy; <?php echo date( 'Y' ); ?> <?php echo esc_html( podval_brand( 'name' ) . '. ' . podval_brand( 'city' ) ); ?>
  </p>

</td>
</tr>

</table>
<!-- /Container -->

</td></tr>
</table>
<!-- /Wrapper -->

</body>
</html>
<?php

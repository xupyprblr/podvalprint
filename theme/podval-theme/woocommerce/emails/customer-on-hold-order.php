<?php
/**
 * Customer on-hold order email
 *
 * Для заявок на услуги (_order_type = service) — отдельное
 * письмо «Заявка принята» без таблицы «оплаты» и нулевых сумм: у заявки нет
 * оплаты, и стандартный текст WC «ждём подтверждения оплаты» вводил в заблуждение.
 * Обычные заказы в статусе on-hold получают стандартный шаблон WooCommerce ниже
 * без изменений.
 *
 * Базовый шаблон: WooCommerce 10.6.2 (template version 10.4.0).
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates\Emails
 * @version 10.4.0
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;

defined( 'ABSPATH' ) || exit;

if ( $order instanceof WC_Order && 'service' === $order->get_meta( '_order_type' ) ) :

	$f            = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
	$order_number = $order->get_order_number();
	$order_date   = wc_format_datetime( $order->get_date_created(), 'd.m.Y' );
	$billing_name = $order->get_billing_first_name();
	$landing_id   = (int) $order->get_meta( '_podval_sl_landing' );
	$landing_h1   = ( $landing_id && function_exists( 'podval_sl_h1' ) ) ? podval_sl_h1( $landing_id ) : '';
	$calc         = $order->get_meta( '_podval_sl_calc' );
	$calc_text    = ( is_array( $calc ) && function_exists( 'podval_price_calc_summary' ) )
		? podval_price_calc_summary( $calc, ( $landing_id && function_exists( 'podval_sl_get' ) ) ? podval_sl_get( $landing_id )['card']['lead_time'] : '' )
		: '';
	$files        = (array) $order->get_meta( '_podval_print_file_ids' );
	$telegram_url = function_exists( 'podval_sl_tg_url' ) && podval_sl_tg_url() ? podval_sl_tg_url() : podval_brand( 'telegram' );
	$site_url     = home_url( '/' );
	?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Заявка <?php echo esc_html( $order_number ); ?> — <?php echo esc_html( podval_brand( 'name' ) ); ?></title>
<style>
  body, table, td, p, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
  body { margin: 0; padding: 0; width: 100% !important; background-color: #111111; }
  table { border-collapse: collapse; mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
</style>
</head>
<body style="margin: 0; padding: 0; background-color: #111111;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #111111;">
<tr><td align="center" style="padding: 24px 16px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width: 600px; width: 100%;">

<tr>
<td align="center" style="background-color: #1a1a1a; padding: 32px 40px 24px;">
  <p style="margin: 0; font-family: <?php echo $f; ?>; font-size: 22px; font-weight: 700; letter-spacing: 0.05em; color: #ffffff;"><?php echo esc_html( podval_brand( 'logo_word_1' ) ); ?> <span style="color: #ff6a00;"><?php echo esc_html( podval_brand( 'logo_word_2' ) ); ?></span></p>
  <table role="presentation" cellpadding="0" cellspacing="0" style="margin-top: 16px;"><tr><td style="width: 60px; height: 2px; background-color: #ff6a00; font-size: 0; line-height: 0;">&nbsp;</td></tr></table>
</td>
</tr>

<tr>
<td style="background-color: #222222; padding: 32px 40px;">

  <p style="margin: 0 0 8px; font-family: <?php echo $f; ?>; font-size: 16px; font-weight: 600; color: #ffffff;">
    <?php echo $billing_name ? esc_html( $billing_name ) . ', заявка принята!' : 'Заявка принята!'; ?>
  </p>
  <p style="margin: 0 0 24px; font-family: <?php echo $f; ?>; font-size: 14px; color: #999999;">
    Заявка <span style="color: #ff6a00; font-weight: 600;">#<?php echo esc_html( $order_number ); ?></span> от <?php echo esc_html( $order_date ); ?>
  </p>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 24px;">
    <tr>
      <td style="border-left: 3px solid #ff6a00; background-color: #2a2a2a; padding: 16px 20px;">
        <p style="margin: 0; font-family: <?php echo $f; ?>; font-size: 14px; color: #cccccc; line-height: 1.6;">
          Мы получили заявку и проверим макет. В рабочее время ответим в <strong style="color: #ffffff;">Telegram</strong> или по <strong style="color: #ffffff;">телефону</strong>: уточним детали и назовём точную стоимость. Оплачивать сейчас ничего не нужно.
        </p>
      </td>
    </tr>
  </table>

  <?php if ( $landing_h1 || $calc_text ) : ?>
  <p style="margin: 0 0 12px; font-family: <?php echo $f; ?>; font-size: 11px; font-weight: 700; color: #ff6a00; letter-spacing: 0.1em; text-transform: uppercase;">Ваша заявка</p>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 24px;">
    <?php if ( $landing_h1 ) : ?>
    <tr><td style="padding: 10px 0; border-bottom: 1px solid #333333; font-family: <?php echo $f; ?>; font-size: 14px; color: #ffffff; font-weight: 600;"><?php echo esc_html( $landing_h1 ); ?></td></tr>
    <?php endif; ?>
    <?php if ( $calc_text ) : ?>
    <tr><td style="padding: 10px 0; border-bottom: 1px solid #333333; font-family: <?php echo $f; ?>; font-size: 14px; color: #cccccc; line-height: 1.6;">Предварительный расчёт: <?php echo esc_html( $calc_text ); ?></td></tr>
    <?php endif; ?>
    <?php if ( $files ) : ?>
    <tr><td style="padding: 10px 0; border-bottom: 1px solid #333333; font-family: <?php echo $f; ?>; font-size: 14px; color: #cccccc;">Файлов прикреплено: <?php echo (int) count( array_filter( $files ) ); ?></td></tr>
    <?php endif; ?>
  </table>
  <p style="margin: 0 0 8px; font-family: <?php echo $f; ?>; font-size: 12px; color: #777777; line-height: 1.5;">Цена ориентировочная — окончательную подтвердим после проверки макета.</p>
  <?php endif; ?>

  <?php
  if ( ! empty( $additional_content ) ) {
      echo '<div style="margin-top: 16px; font-family: ' . esc_attr( $f ) . '; font-size: 13px; color: #999999; line-height: 1.6;">' . wp_kses_post( wpautop( wptexturize( $additional_content ) ) ) . '</div>';
  }
  ?>

</td>
</tr>

<tr>
<td align="center" style="background-color: #1a1a1a; padding: 24px 40px;">
  <p style="margin: 0 0 12px; font-family: <?php echo $f; ?>; font-size: 12px; color: #666666;">Хотите быстрее? Напишите нам</p>
  <p style="margin: 0 0 16px; font-family: <?php echo $f; ?>; font-size: 12px;">
    <?php if ( $telegram_url ) : ?>
    <a href="<?php echo esc_url( $telegram_url ); ?>" style="color: #ff6a00; text-decoration: none;">Telegram</a>
    <span style="color: #444444; padding: 0 8px;">&middot;</span>
    <?php endif; ?>
    <a href="<?php echo esc_url( $site_url ); ?>" style="color: #999999; text-decoration: none;"><?php echo esc_html( wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ); ?></a>
  </p>
  <p style="margin: 0; font-family: <?php echo $f; ?>; font-size: 11px; color: #444444;">&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> <?php echo esc_html( podval_brand( 'name' ) . '. ' . podval_brand( 'city' ) ); ?></p>
</td>
</tr>

</table>
</td></tr>
</table>
</body>
</html>
	<?php
	return;
endif;

/* ═══ Ниже — стандартный шаблон WooCommerce 10.6.2 без изменений ═══ */
$email_improvements_enabled = FeaturesUtil::feature_is_enabled( 'email_improvements' );

/*
 * @hooked WC_Emails::email_header() Output the email header
 */
do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php echo $email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>
<p>
<?php
if ( ! empty( $order->get_billing_first_name() ) ) {
	/* translators: %s: Customer first name */
	printf( esc_html__( 'Hi %s,', 'woocommerce' ), esc_html( $order->get_billing_first_name() ) );
} else {
	printf( esc_html__( 'Hi,', 'woocommerce' ) );
}
?>
</p>
<?php if ( $email_improvements_enabled ) : ?>
	<p><?php esc_html_e( 'We’ve received your order and it’s currently on hold until we can confirm your payment has been processed.', 'woocommerce' ); ?></p>
	<p><?php esc_html_e( 'Here’s a reminder of what you’ve ordered:', 'woocommerce' ); ?></p>
<?php else : ?>
	<p><?php esc_html_e( 'Thanks for your order. It’s on-hold until we confirm that payment has been received.', 'woocommerce' ); ?></p>
<?php endif; ?>
<?php echo $email_improvements_enabled ? '</div>' : ''; ?>

<?php

/*
 * @hooked WC_Emails::order_details() Shows the order details table.
 * @hooked WC_Structured_Data::generate_order_data() Generates structured data.
 * @hooked WC_Structured_Data::output_structured_data() Outputs structured data.
 *
 */
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

/*
 * @hooked WC_Emails::order_meta() Shows order meta data.
 */
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

/*
 * @hooked WC_Emails::customer_details() Shows customer details
 * @hooked WC_Emails::email_address() Shows email address
 */
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ( $additional_content ) {
	echo $email_improvements_enabled ? '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"><tr><td class="email-additional-content">' : '';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo $email_improvements_enabled ? '</td></tr></table>' : '';
}

/*
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action( 'woocommerce_email_footer', $email );

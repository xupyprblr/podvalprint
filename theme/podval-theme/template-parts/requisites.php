<?php
/**
 * Реквизиты продавца из конфига бренда. Пустые строки не выводятся.
 *
 * @package Podval Print
 */

defined( 'ABSPATH' ) || exit;

$legal_name = podval_brand( 'legal_name' );
if ( ! $legal_name ) {
	return;
}
$phone = get_theme_mod( 'podval_header_phone', podval_brand( 'phone' ) );
$email = get_theme_mod( 'podval_footer_email', podval_brand( 'email' ) );
$rows  = array(
	'УНП'               => podval_brand( 'unp' ),
	'Юридический адрес' => podval_brand( 'legal_address' ),
	'Почтовый адрес'    => podval_brand( 'postal_address' ),
	'Торговый реестр'   => podval_brand( 'trade_register' ),
	'Банк'              => podval_brand( 'bank_details' ),
);
?>
<div class="podval-legal__company">
  <h3>Реквизиты</h3>
  <p><?php echo esc_html( $legal_name ); ?>
  <?php foreach ( $rows as $label => $value ) : if ( ! $value ) { continue; } ?>
    <br><?php echo esc_html( $label ); ?>: <?php echo esc_html( $value ); ?>
  <?php endforeach; ?>
  <?php if ( $phone ) : ?><br>Тел: <a href="tel:<?php echo esc_attr( podval_brand_tel( $phone ) ); ?>"><?php echo esc_html( $phone ); ?></a><?php endif; ?>
  <?php if ( $email ) : ?><br>E-mail: <a href="mailto:<?php echo esc_attr( $email ); ?>"><?php echo esc_html( $email ); ?></a><?php endif; ?>
  </p>
</div>

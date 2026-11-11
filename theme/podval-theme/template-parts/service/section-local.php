<?php
/**
 * Лендинг · 14 ЛОКАЛЬНЫЙ БЛОК — адрес, часы, карты, самовывоз.
 * Данные из Customizer (футер): podval_footer_address, podval_footer_hours, podval_footer_maps.
 *
 * @var array $args post_id, sl, h1, calc, from
 */
defined( 'ABSPATH' ) || exit;

$local = $args['sl']['local'];
if ( empty( $local['show'] ) ) {
	return;
}
$address = trim( (string) get_theme_mod( 'podval_footer_address', '' ) );
$hours   = (string) get_theme_mod( 'podval_footer_hours', "Пн–Пт: 11:00–21:00\nСб: 12:00–18:00\nВс: выходной" );
$gmaps   = (string) get_theme_mod( 'podval_footer_maps', '' );
$phone   = (string) get_theme_mod( 'podval_header_phone', get_theme_mod( 'podval_footer_phone', '' ) );
$tg      = podval_sl_tg_url();
$ymaps   = $address !== '' ? 'https://yandex.by/maps/?text=' . rawurlencode( 'Минск, ' . $address ) : '';
$img     = podval_sl_img( (int) $local['image_id'], 'medium_large', array( 'class' => 'sl-local__img', 'alt' => 'Точка самовывоза ' . podval_brand( 'name' ) . ' — Минске' ) );

if ( $address === '' && trim( $hours ) === '' ) {
	return;
}
?>
<section class="sl-sec sl-sec--local" id="kontakty">
	<div class="sl-wrap">
		<div class="sl-local<?php echo $img ? ' sl-local--img' : ''; ?>">
			<div class="sl-local__info">
				<h2 class="sl-h2">Самовывоз в Минске</h2>
				<?php if ( $address !== '' ) : ?>
				<p class="sl-local__addr"><?php echo esc_html( $address ); ?></p>
				<?php endif; ?>
				<?php if ( trim( $hours ) !== '' ) : ?>
				<ul class="sl-local__hours">
					<?php foreach ( array_filter( array_map( 'trim', explode( "\n", $hours ) ) ) as $h ) : ?>
					<li><?php echo esc_html( $h ); ?></li>
					<?php endforeach; ?>
				</ul>
				<?php endif; ?>
				<p class="sl-local__text">Забрать заказ можно бесплатно в рабочее время. По Беларуси отправляем Яндекс Доставкой, Евро-Почтой и СДЭК.</p>
				<div class="sl-local__links">
					<?php if ( $gmaps ) : ?><a class="sl-btn sl-btn--ghost" href="<?php echo esc_url( $gmaps ); ?>" target="_blank" rel="noopener">Google Карты</a><?php endif; ?>
					<?php if ( $ymaps ) : ?><a class="sl-btn sl-btn--ghost" href="<?php echo esc_url( $ymaps ); ?>" target="_blank" rel="noopener">Яндекс Карты</a><?php endif; ?>
					<?php if ( $tg ) : ?><a class="sl-btn sl-btn--ghost" href="<?php echo esc_url( $tg ); ?>" target="_blank" rel="noopener">Telegram</a><?php endif; ?>
					<?php if ( $phone ) : ?><a class="sl-btn sl-btn--ghost" href="tel:<?php echo esc_attr( preg_replace( '/[^\d+]/', '', $phone ) ); ?>"><?php echo esc_html( $phone ); ?></a><?php endif; ?>
				</div>
			</div>
			<?php if ( $img ) : ?>
			<div class="sl-local__media">
				<?php if ( $gmaps ) : ?><a href="<?php echo esc_url( $gmaps ); ?>" target="_blank" rel="noopener"><?php echo $img; // phpcs:ignore ?></a><?php else : echo $img; endif; // phpcs:ignore ?>
			</div>
			<?php endif; ?>
		</div>
	</div>
</section>

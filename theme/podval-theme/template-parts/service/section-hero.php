<?php
/**
 * Лендинг · 01 HERO. LCP-картинка — единственная с fetchpriority="high".
 *
 * @var array $args post_id, sl, h1, calc, from
 */
defined( 'ABSPATH' ) || exit;

$sl   = $args['sl'];
$hero = $sl['hero'];
$from = $args['from'];

$facts = array();
$facts[] = $from
	? array( 'html' => 'от ' . podval_sl_byn( $from['value'] ), 'label' => $from['label'] )
	: array( 'html' => 'цена по запросу', 'label' => 'рассчитаем под задачу' );
foreach ( array_slice( $hero['facts'], 0, 2 ) as $f ) {
	if ( $f['value'] !== '' ) {
		$facts[] = array( 'html' => esc_html( $f['value'] ), 'label' => $f['label'] );
	}
}

$cta1_url = '';
if ( $hero['cta_primary']['product_id'] && $hero['cta_primary']['label'] !== '' && get_post_status( $hero['cta_primary']['product_id'] ) === 'publish' ) {
	$cta1_url = get_permalink( $hero['cta_primary']['product_id'] );
}
$cta2_anchor = sanitize_title( $hero['cta_secondary']['anchor'] ?: 'zayavka' );
$layout      = in_array( $hero['layout'], array( 'frame', 'bleed', 'full' ), true ) ? $hero['layout'] : 'frame';
$img         = podval_sl_img( (int) $hero['image_id'], 'large', array(
	'class'         => 'sl-hero__img',
	'fetchpriority' => 'high',
	// bleed тянется до правого края экрана, поэтому ширина считается от вьюпорта.
	'sizes'         => 'bleed' === $layout ? '(max-width: 900px) 100vw, 50vw' : '(max-width: 900px) 100vw, 620px',
	'alt'           => $args['h1'],
) );
?>
<section class="sl-hero<?php echo $img ? ' sl-hero--' . $layout : ' sl-hero--noimg'; ?>">
	<div class="sl-wrap sl-hero__grid">
		<div class="sl-hero__text">
			<?php if ( $hero['badge'] !== '' ) : ?>
			<div class="sl-hero__badge"><?php echo esc_html( $hero['badge'] ); ?></div>
			<?php endif; ?>
			<h1 class="sl-hero__title"><?php echo esc_html( $args['h1'] ); ?></h1>
			<?php if ( $hero['subtitle'] !== '' ) : ?>
			<p class="sl-hero__sub"><?php echo esc_html( $hero['subtitle'] ); ?></p>
			<?php endif; ?>

			<ul class="sl-hero__facts">
				<?php foreach ( $facts as $f ) : ?>
				<li class="sl-hero__fact">
					<strong><?php echo $f['html']; // phpcs:ignore WordPress.Security.EscapeOutput — собрано выше из esc_html / podval_sl_byn ?></strong>
					<span><?php echo esc_html( $f['label'] ); ?></span>
				</li>
				<?php endforeach; ?>
			</ul>

			<div class="sl-hero__cta">
				<?php if ( $cta1_url ) : ?>
				<a class="sl-btn sl-btn--primary" href="<?php echo esc_url( $cta1_url ); ?>"><?php echo esc_html( $hero['cta_primary']['label'] ); ?></a>
				<?php endif; ?>
				<?php if ( $hero['cta_secondary']['label'] !== '' ) : ?>
				<a class="sl-btn <?php echo $cta1_url ? 'sl-btn--ghost' : 'sl-btn--primary'; ?>" href="#<?php echo esc_attr( $cta2_anchor ); ?>"><?php echo esc_html( $hero['cta_secondary']['label'] ); ?></a>
				<?php endif; ?>
			</div>
		</div>
		<?php if ( $img ) : ?>
		<div class="sl-hero__media"><?php echo $img; // phpcs:ignore ?></div>
		<?php endif; ?>
	</div>
</section>

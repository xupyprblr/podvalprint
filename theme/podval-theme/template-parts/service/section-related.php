<?php
/**
 * Лендинг · 13 ПОХОЖИЕ УСЛУГИ.
 *
 * @var array $args post_id, sl, h1, calc, from
 */
defined( 'ABSPATH' ) || exit;

$rows = podval_sl_related( (int) $args['post_id'] );
if ( ! $rows ) {
	return;
}
?>
<section class="sl-sec sl-sec--related" id="pohozhie">
	<div class="sl-wrap">
		<header class="sl-head sl-head--row">
			<h2 class="sl-h2">Другие услуги</h2>
			<a class="sl-link" href="<?php echo esc_url( podval_sl_hub_url() ); ?>">Все услуги →</a>
		</header>
		<div class="podval-svc__cards sl-related">
			<?php foreach ( $rows as $row ) {
				get_template_part( 'template-parts/service/landing-card', null, array( 'row' => $row, 'heading' => 'h3' ) );
			} ?>
		</div>
	</div>
</section>

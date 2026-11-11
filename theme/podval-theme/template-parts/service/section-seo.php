<?php
/**
 * Лендинг · 11 SEO-ТЕКСТ — post_content страницы (его анализирует Yoast).
 *
 * @var array $args post_id, sl, h1, calc, from
 */
defined( 'ABSPATH' ) || exit;

$raw = (string) get_post_field( 'post_content', $args['post_id'] );
if ( trim( wp_strip_all_tags( strip_shortcodes( $raw ) ) ) === '' ) {
	return;
}
?>
<section class="sl-sec sl-sec--seo" id="o-usluge">
	<div class="sl-wrap sl-wrap--narrow">
		<div class="sl-prose">
			<?php echo apply_filters( 'the_content', $raw ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</div>
	</div>
</section>

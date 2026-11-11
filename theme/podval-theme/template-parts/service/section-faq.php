<?php
/**
 * Лендинг · 10 FAQ — <details>. JSON-LD FAQPage выводится в wp_head
 * (podval_sl_schema_output) из тех же данных, шорткоды раскрыты до обоих выводов.
 *
 * @var array $args post_id, sl, h1, calc, from
 */
defined( 'ABSPATH' ) || exit;

$faq = podval_sl_faq_items( $args['sl'] );
if ( ! $faq ) {
	return;
}
?>
<section class="sl-sec sl-sec--faq" id="faq">
	<div class="sl-wrap sl-wrap--narrow">
		<header class="sl-head">
			<h2 class="sl-h2">Частые вопросы</h2>
		</header>
		<div class="sl-faq">
			<?php foreach ( $faq as $i => $item ) : ?>
			<details class="sl-faq__item"<?php echo 0 === $i ? ' open' : ''; ?>>
				<summary class="sl-faq__q"><?php echo esc_html( $item['q'] ); ?></summary>
				<div class="sl-faq__a"><?php echo wp_kses_post( wpautop( $item['a'] ) ); ?></div>
			</details>
			<?php endforeach; ?>
		</div>
	</div>
</section>

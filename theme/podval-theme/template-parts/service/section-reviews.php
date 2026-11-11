<?php
/**
 * Лендинг · 09 ОТЗЫВЫ — тексты из Google Maps. Без рейтинга и без schema (решение Alex).
 *
 * @var array $args post_id, sl, h1, calc, from
 */
defined( 'ABSPATH' ) || exit;

$reviews = array_filter( $args['sl']['reviews'], function ( $r ) {
	return $r['text'] !== '';
} );
if ( ! $reviews ) {
	return;
}
?>
<section class="sl-sec sl-sec--reviews" id="otzyvy">
	<div class="sl-wrap">
		<header class="sl-head">
			<h2 class="sl-h2">Отзывы клиентов</h2>
		</header>
		<div class="sl-grid sl-grid--reviews">
			<?php foreach ( $reviews as $r ) : ?>
			<blockquote class="sl-review">
				<p class="sl-review__text"><?php echo esc_html( $r['text'] ); ?></p>
				<footer class="sl-review__foot">
					<cite class="sl-review__name"><?php echo esc_html( $r['name'] ); ?></cite>
					<?php if ( $r['date'] !== '' ) : ?>
					<time datetime="<?php echo esc_attr( $r['date'] ); ?>"><?php echo esc_html( date_i18n( 'j F Y', strtotime( $r['date'] ) ) ); ?></time>
					<?php endif; ?>
					<?php if ( $r['source_url'] !== '' ) : ?>
					<a href="<?php echo esc_url( $r['source_url'] ); ?>" target="_blank" rel="noopener nofollow">Google Maps</a>
					<?php endif; ?>
				</footer>
			</blockquote>
			<?php endforeach; ?>
		</div>
	</div>
</section>

<?php
/**
 * Лендинг · 06 КАК ЗАКАЗАТЬ + требования к макету.
 *
 * @var array $args post_id, sl, h1, calc, from
 */
defined( 'ABSPATH' ) || exit;

$steps = $args['sl']['steps'];
$reqs  = $args['sl']['artwork_reqs'];
if ( ! $steps && ! $reqs ) {
	return;
}
?>
<section class="sl-sec sl-sec--steps" id="kak-zakazat">
	<div class="sl-wrap">
		<header class="sl-head">
			<h2 class="sl-h2">Как заказать</h2>
		</header>
		<?php if ( $steps ) : ?>
		<ol class="sl-steps">
			<?php foreach ( $steps as $i => $st ) : ?>
			<li class="sl-step">
				<span class="sl-step__num"><?php echo esc_html( sprintf( '%02d', $i + 1 ) ); ?></span>
				<h3 class="sl-step__title"><?php echo esc_html( $st['title'] ); ?></h3>
				<?php if ( $st['text'] !== '' ) : ?><p class="sl-step__text"><?php echo esc_html( $st['text'] ); ?></p><?php endif; ?>
			</li>
			<?php endforeach; ?>
		</ol>
		<?php endif; ?>

		<?php if ( $reqs ) : ?>
		<div class="sl-reqs">
			<h3 class="sl-h3">Требования к макету</h3>
			<ul class="sl-reqs__list">
				<?php foreach ( $reqs as $r ) : ?><li><?php echo esc_html( $r ); ?></li><?php endforeach; ?>
			</ul>
			<p class="sl-reqs__free">Проверим файл бесплатно и скажем, если что-то не напечатается.</p>
		</div>
		<?php endif; ?>
	</div>
</section>

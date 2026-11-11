<?php
/**
 * Лендинг · 07 РАБОТЫ — сетка + лайтбокс на <dialog>.
 * Крупное фото JS создаёт новым <img> вне <picture>: EWWW его не обернёт,
 * и подмена src не упрётся в <source> (см. podvalSwapImage в main.js).
 *
 * @var array $args post_id, sl, h1, calc, from
 */
defined( 'ABSPATH' ) || exit;

$items = array();
foreach ( $args['sl']['gallery'] as $g ) {
	$id = (int) $g['image_id'];
	if ( ! $id || ! wp_attachment_is_image( $id ) ) {
		continue;
	}
	$full = wp_get_attachment_image_src( $id, 'large' );
	if ( ! $full ) {
		continue;
	}
	$items[] = array( 'id' => $id, 'alt' => $g['alt'] !== '' ? $g['alt'] : $args['h1'], 'caption' => $g['caption'], 'full' => $full );
}
if ( ! $items ) {
	return;
}
?>
<section class="sl-sec sl-sec--gallery" id="raboty">
	<div class="sl-wrap">
		<header class="sl-head">
			<h2 class="sl-h2">Наши работы</h2>
		</header>
		<div class="sl-gallery">
			<?php foreach ( $items as $i => $it ) : ?>
			<figure class="sl-gallery__item">
				<button type="button" class="sl-gallery__btn" data-index="<?php echo (int) $i; ?>" data-full="<?php echo esc_url( $it['full'][0] ); ?>" data-w="<?php echo (int) $it['full'][1]; ?>" data-h="<?php echo (int) $it['full'][2]; ?>" aria-label="<?php echo esc_attr( 'Открыть фото: ' . $it['alt'] ); ?>">
					<?php echo podval_sl_img( $it['id'], 'medium_large', array( 'class' => 'sl-gallery__img', 'alt' => $it['alt'], 'sizes' => '(max-width: 600px) 50vw, 320px' ) ); // phpcs:ignore ?>
				</button>
				<?php if ( $it['caption'] !== '' ) : ?>
				<figcaption class="sl-gallery__cap"><?php echo esc_html( $it['caption'] ); ?></figcaption>
				<?php endif; ?>
			</figure>
			<?php endforeach; ?>
		</div>
		<dialog class="sl-lightbox" id="sl-lightbox" aria-label="Фото работы">
			<div class="sl-lightbox__frame"></div>
			<p class="sl-lightbox__cap"></p>
			<button type="button" class="sl-lightbox__close" aria-label="Закрыть">×</button>
			<button type="button" class="sl-lightbox__nav sl-lightbox__nav--prev" aria-label="Предыдущее">‹</button>
			<button type="button" class="sl-lightbox__nav sl-lightbox__nav--next" aria-label="Следующее">›</button>
		</dialog>
	</div>
</section>

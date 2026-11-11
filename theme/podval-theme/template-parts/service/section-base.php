<?php
/**
 * Лендинг · 04 БАЗА — карточки бланков.
 *
 * @var array $args post_id, sl, h1, calc, from
 */
defined( 'ABSPATH' ) || exit;

$items = $args['sl']['base_items'];
if ( ! $items ) {
	return;
}
?>
<section class="sl-sec sl-sec--base" id="baza">
	<div class="sl-wrap">
		<header class="sl-head">
			<h2 class="sl-h2">На чём печатаем</h2>
		</header>
		<div class="sl-grid sl-grid--base">
			<?php foreach ( $items as $it ) :
				$price = $it['garment'] !== '' ? podval_price_garment( $it['garment'] ) : null;
				$img   = podval_sl_img( (int) $it['image_id'], 'medium_large', array( 'class' => 'sl-base__img', 'alt' => $it['title'], 'sizes' => '(max-width: 600px) 50vw, 300px' ) );
				// Вся карточка — ссылка на конструктор: человек из поиска приходит
				// ради одной вещи, и до сих пор перейти к заказу можно было только
				// кнопкой в hero.
				$url = podval_sl_base_url( $it, $args['sl'] );
				$tag = $url ? 'a' : 'div';
				?>
			<<?php echo $tag; ?> class="sl-base<?php echo $url ? ' sl-base--link' : ''; ?>"<?php echo $url ? ' href="' . esc_url( $url ) . '"' : ''; ?>>
				<div class="sl-base__media">
					<?php echo $img ? $img : '<span class="sl-base__ph" aria-hidden="true">' . esc_html( mb_substr( $it['title'], 0, 1 ) ) . '</span>'; // phpcs:ignore ?>
				</div>
				<div class="sl-base__body">
					<h3 class="sl-base__title"><?php echo esc_html( $it['title'] ); ?></h3>
					<?php if ( null !== $price ) : ?>
					<div class="sl-base__price"><?php echo podval_sl_byn( $price ); // phpcs:ignore ?> <small>с печатью</small></div>
					<?php endif; ?>
					<?php
					$spec = array_filter( array( $it['fabric'], $it['density'], $it['sizes'] ) );
					if ( $spec ) :
						?>
					<p class="sl-base__spec"><?php echo esc_html( implode( ' · ', $spec ) ); ?></p>
					<?php endif; ?>
					<?php if ( $it['colors'] ) : ?>
					<ul class="sl-swatches" aria-label="Цвета">
						<?php foreach ( $it['colors'] as $c ) : ?>
						<li style="background:<?php echo esc_attr( $c ); ?>"></li>
						<?php endforeach; ?>
					</ul>
					<?php endif; ?>
					<?php if ( $it['note'] !== '' ) : ?>
					<p class="sl-base__note"><?php echo esc_html( $it['note'] ); ?></p>
					<?php endif; ?>
					<?php if ( $url ) : ?>
					<span class="sl-base__cta">Заказать →</span>
					<?php endif; ?>
				</div>
			</<?php echo $tag; ?>>
			<?php endforeach; ?>
		</div>
	</div>
</section>

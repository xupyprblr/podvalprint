<?php
/**
 * Карточка лендинга — хаб /catalog/uslugi/, «Похожие услуги», главная.
 *
 * Разметка .podval-svc__service-card — та же, что у товаров-услуг на хабе и главной
 * (style-core.css), поэтому переключение источника не меняет вёрстку.
 *
 * @var array $args row (строка podval_sl_landings()), heading (h2|h3)
 */
defined( 'ABSPATH' ) || exit;

$row = $args['row'] ?? null;
if ( ! $row ) {
	return;
}
$tag   = in_array( $args['heading'] ?? 'h3', array( 'h2', 'h3' ), true ) ? $args['heading'] : 'h3';
$from  = podval_sl_from_info( (array) $row['garments'], ! empty( $row['own'] ) );
$title = $row['short_title'] !== '' && ! empty( $args['short'] ) ? $row['short_title'] : $row['title'];
$img   = podval_sl_img( (int) $row['image_id'], 'medium_large', array( 'class' => 'sl-card__img', 'alt' => $row['title'], 'sizes' => '(max-width: 768px) 100vw, 50vw' ) );
?>
<a href="<?php echo esc_url( $row['url'] ); ?>" class="podval-svc__service-card sl-card">
	<?php if ( $img ) : ?>
	<div class="podval-svc__service-img sl-card__media"><?php echo $img; // phpcs:ignore ?></div>
	<?php else : ?>
	<div class="podval-svc__service-img podval-svc__service-img--placeholder"><span><?php echo esc_html( mb_substr( $row['title'], 0, 1 ) ); ?></span></div>
	<?php endif; ?>
	<div class="podval-svc__service-info">
		<<?php echo $tag; ?> class="podval-svc__service-name"><?php echo esc_html( $title ); ?></<?php echo $tag; ?>>
		<?php if ( $from || $row['lead_time'] !== '' ) : ?>
		<p class="sl-card__meta">
			<?php if ( $from ) : ?><span class="sl-card__price">от <?php echo podval_sl_byn( $from['value'] ); // phpcs:ignore ?></span><?php endif; ?>
			<?php if ( $row['lead_time'] !== '' ) : ?><span class="sl-card__lead"><?php echo esc_html( $row['lead_time'] ); ?></span><?php endif; ?>
		</p>
		<?php endif; ?>
		<?php if ( $row['excerpt'] !== '' ) : ?>
		<p class="podval-svc__service-desc"><?php echo esc_html( $row['excerpt'] ); ?></p>
		<?php endif; ?>
		<span class="podval-svc__service-link">Подробнее →</span>
	</div>
</a>

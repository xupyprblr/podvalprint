<?php
/**
 * Лендинг · 05 ТЕХНОЛОГИИ.
 *
 * @var array $args post_id, sl, h1, calc, from
 */
defined( 'ABSPATH' ) || exit;

$tech = $args['sl']['tech'];
if ( ! $tech ) {
	return;
}
?>
<section class="sl-sec sl-sec--tech" id="tehnologii">
	<div class="sl-wrap">
		<header class="sl-head">
			<h2 class="sl-h2">Технологии нанесения</h2>
			<p class="sl-lead">Подберём способ под дизайн, тираж и срок.</p>
		</header>
		<div class="sl-grid sl-grid--tech">
			<?php foreach ( $tech as $t ) : ?>
			<div class="sl-tech">
				<h3 class="sl-tech__name"><?php echo esc_html( $t['name'] ); ?></h3>
				<?php if ( $t['best_for'] !== '' ) : ?>
				<p class="sl-tech__best"><?php echo esc_html( $t['best_for'] ); ?></p>
				<?php endif; ?>
				<?php if ( $t['pros'] || $t['cons'] ) : ?>
				<ul class="sl-tech__list">
					<?php foreach ( $t['pros'] as $p ) : ?><li class="is-pro"><?php echo esc_html( $p ); ?></li><?php endforeach; ?>
					<?php foreach ( $t['cons'] as $c ) : ?><li class="is-con"><?php echo esc_html( $c ); ?></li><?php endforeach; ?>
				</ul>
				<?php endif; ?>
				<dl class="sl-tech__meta">
					<?php if ( $t['durability'] !== '' ) : ?><div><dt>Стойкость</dt><dd><?php echo esc_html( $t['durability'] ); ?></dd></div><?php endif; ?>
					<?php if ( $t['max_size'] !== '' ) : ?><div><dt>Макс. размер</dt><dd><?php echo esc_html( $t['max_size'] ); ?></dd></div><?php endif; ?>
					<?php if ( $t['lead_time'] !== '' ) : ?><div><dt>Срок</dt><dd><?php echo esc_html( $t['lead_time'] ); ?></dd></div><?php endif; ?>
				</dl>
			</div>
			<?php endforeach; ?>
		</div>
	</div>
</section>

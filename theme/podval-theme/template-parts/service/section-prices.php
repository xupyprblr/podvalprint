<?php
/**
 * Лендинг · 03 ЦЕНЫ. Таблицы server-side: «Наши изделия», «Своя вещь»,
 * и на DTF-лендинге — «DTF-трансферы на плёнке».
 *
 * @var array $args post_id, sl, h1, calc, from
 */
defined( 'ABSPATH' ) || exit;

$sl    = $args['sl'];
$reg   = podval_prices();
$ours  = podval_sl_priced_garments( $sl['calc']['garments'] );
$pl    = $sl['calc']['placements'];
$sec   = in_array( 'second_side', $pl, true ) ? podval_price_placement( 'second_side' ) : null;
$slv   = in_array( 'sleeve', $pl, true ) ? podval_price_placement( 'sleeve' ) : null;
$own   = ! empty( $sl['calc']['own'] );
$roll  = ! empty( $sl['prices_roll'] );

if ( ! $ours && ! $own && ! $roll ) {
	return;
}

$cell = function ( $v ) {
	return null === $v ? '<span class="sl-table__na">договорная</span>' : podval_sl_byn( (float) $v );
};
$dims = array( 'A6' => '10×15 см', 'A5' => '15×21 см', 'A4' => '21×30 см', 'A3' => '30×42 см' );
?>
<section class="sl-sec sl-sec--prices" id="ceny">
	<div class="sl-wrap">
		<header class="sl-head">
			<h2 class="sl-h2">Цены</h2>
		</header>

		<?php if ( $ours ) : ?>
		<h3 class="sl-h3">Наши изделия с печатью</h3>
		<p class="sl-lead">Цена за штуку, одно нанесение любого размера до A3 уже включено.</p>
		<div class="sl-table-wrap">
			<table class="sl-table">
				<thead><tr>
					<th scope="col">Изделие</th>
					<th scope="col">С печатью</th>
					<?php if ( null !== $sec ) : ?><th scope="col">Вторая сторона</th><?php endif; ?>
					<?php if ( null !== $slv ) : ?><th scope="col">Рукав</th><?php endif; ?>
				</tr></thead>
				<tbody>
				<?php foreach ( $ours as $key => $price ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $reg['garments'][ $key ]['label'] ); ?></th>
						<td class="sl-table__main"><?php echo podval_sl_byn( $price ); // phpcs:ignore ?></td>
						<?php if ( null !== $sec ) : ?><td>+<?php echo podval_sl_byn( $sec ); // phpcs:ignore ?></td><?php endif; ?>
						<?php if ( null !== $slv ) : ?><td>+<?php echo podval_sl_byn( $slv ); // phpcs:ignore ?></td><?php endif; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<p class="sl-table-note">Цена за 1 шт. От 5 штук — скидка, её размер обсуждаем индивидуально.</p>
		<?php endif; ?>

		<?php if ( $own ) : ?>
		<h3 class="sl-h3">Печать на вашей вещи</h3>
		<p class="sl-lead">Печать и перенос одного принта, цена за штуку. Хлопок и смесовые ткани.</p>
		<div class="sl-table-wrap">
			<table class="sl-table sl-table--tiers">
				<thead><tr>
					<th scope="col">Размер</th>
					<?php foreach ( $reg['tiers'] as $t ) : ?><th scope="col"><?php echo esc_html( $t['label'] ); ?> шт</th><?php endforeach; ?>
				</tr></thead>
				<tbody>
				<?php foreach ( $reg['transfer'] as $size => $row ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $size ); ?> <small><?php echo esc_html( $dims[ $size ] ?? '' ); ?></small></th>
						<?php foreach ( $row as $v ) : ?><td><?php echo $cell( $v ); // phpcs:ignore ?></td><?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>

			</table>
		</div>
		<?php
		// Пояснения к тирам — строкой под таблицей, а не в tfoot: длинный текст в
		// ячейке растягивал колонку «1–4 шт» на пол-таблицы, а на мобиле
		// превращался в узкий столбик из одного слова.
		$tier_notes = array();
		foreach ( $reg['tiers'] as $t ) {
			if ( ! empty( $t['note'] ) ) {
				$tier_notes[] = $t['label'] . ' шт — ' . $t['note'];
			}
		}
		if ( $tier_notes ) :
			?>
		<ul class="sl-table-legend">
			<?php foreach ( $tier_notes as $note ) : ?>
			<li><?php echo esc_html( $note ); ?></li>
			<?php endforeach; ?>
		</ul>
		<?php endif; ?>
		<?php endif; ?>

		<?php if ( $roll ) : ?>
		<h3 class="sl-h3">DTF-трансферы на плёнке</h3>
		<p class="sl-lead">Печать для самостоятельного переноса, цена за погонный метр.</p>
		<div class="sl-table-wrap">
			<table class="sl-table">
				<thead><tr>
					<?php foreach ( $reg['roll']['labels'] as $lbl ) : ?><th scope="col"><?php echo esc_html( $lbl ); ?></th><?php endforeach; ?>
				</tr></thead>
				<tbody><tr>
					<?php foreach ( $reg['roll']['price'] as $v ) : ?><td><?php echo $cell( $v ); // phpcs:ignore ?></td><?php endforeach; ?>
				</tr></tbody>
			</table>
		</div>
		<?php endif; ?>

		<?php if ( $sl['prices_note'] !== '' ) : ?>
		<div class="sl-note"><?php echo wp_kses_post( wpautop( do_shortcode( $sl['prices_note'] ) ) ); ?></div>
		<?php endif; ?>
	</div>
</section>

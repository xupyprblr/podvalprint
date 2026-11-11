<?php
/**
 * Лендинг · 02 КАЛЬКУЛЯТОР.
 *
 * Разметка полностью серверная, с результатом для значений по умолчанию —
 * JS (service-landing.js) только оживляет контролы и пересчитывает. HTML
 * результата обязан совпадать с первым рендером JS, иначе цифры прыгнут.
 *
 * @var array $args post_id, sl, h1, calc, from
 */
defined( 'ABSPATH' ) || exit;

$cfg = $args['calc'];
if ( ! $cfg ) {
	return;
}
$sl   = $args['sl'];
$mode = $cfg['mode'];

$allowed = podval_sl_calc_allowed( $sl );
$calc    = podval_price_calc( podval_sl_calc_default_params( $cfg, $mode ), $allowed );
$lead    = $cfg['lead'];

$has_unit = $calc && null !== $calc['unit'];
$sizes    = $cfg['sizes'];
$modes    = array_keys( array_filter( $cfg['modes'] ) );
$labels   = array( 'ours' => 'Наша вещь', 'own' => 'Своя вещь', 'roll' => 'Плёнка (метраж)' );

// Цены на вариантах считаются для тиража по умолчанию; JS пересчитывает их
// при смене количества — первый рендер обязан совпасть с серверным HTML.
$qty0 = (int) ( $calc['qty'] ?? 1 );
?>
<section class="sl-sec sl-sec--calc" id="calc">
	<div class="sl-wrap">
		<header class="sl-head">
			<h2 class="sl-h2">Рассчитайте стоимость</h2>
		</header>

		<div class="sl-calc" data-calc="<?php echo esc_attr( wp_json_encode( $cfg, JSON_UNESCAPED_UNICODE ) ); ?>">
			<div class="sl-calc__controls">
				<?php if ( count( $modes ) > 1 ) : ?>
				<div class="sl-calc__modes sl-calc__modes--<?php echo count( $modes ); ?>" role="group" aria-label="Что считаем">
					<?php foreach ( $modes as $m ) : ?>
					<button type="button" class="sl-calc__mode<?php echo $m === $mode ? ' is-active' : ''; ?>" data-mode="<?php echo esc_attr( $m ); ?>" aria-pressed="<?php echo $m === $mode ? 'true' : 'false'; ?>"><?php echo esc_html( $labels[ $m ] ); ?></button>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>

				<?php if ( $cfg['modes']['ours'] ) : ?>
				<div class="sl-calc__panel" data-panel="ours"<?php echo 'ours' !== $mode ? ' hidden' : ''; ?>>
					<div class="sl-field">
						<span class="sl-field__label">Изделие</span>
						<div class="sl-seg sl-seg--wrap">
							<?php foreach ( $cfg['garments'] as $i => $g ) : ?>
							<label class="sl-seg__opt">
								<input type="radio" name="sl_garment" value="<?php echo esc_attr( $g['key'] ); ?>"<?php checked( 0, $i ); ?>>
								<span><?php echo esc_html( $g['label'] ); ?><?php echo podval_sl_opt_price( (float) $g['price'] ); // phpcs:ignore ?></span>
							</label>
							<?php endforeach; ?>
						</div>
					</div>
					<?php if ( null !== $cfg['second'] ) : ?>
					<label class="sl-check">
						<input type="checkbox" name="sl_second" value="1">
						<span>Печать на второй стороне <em>+<?php echo podval_sl_byn( $cfg['second'] ); // phpcs:ignore ?></em></span>
					</label>
					<?php endif; ?>
					<?php if ( null !== $cfg['sleeve'] ) : ?>
					<div class="sl-field">
						<span class="sl-field__label">Рукава <em>+<?php echo podval_sl_byn( $cfg['sleeve'] ); // phpcs:ignore ?> за рукав</em></span>
						<div class="sl-seg">
							<?php for ( $n = 0; $n <= $cfg['sleeveMax']; $n++ ) : ?>
							<label class="sl-seg__opt"><input type="radio" name="sl_sleeves_ours" value="<?php echo (int) $n; ?>"<?php checked( 0, $n ); ?>><span><?php echo (int) $n; ?><?php echo podval_sl_opt_price( $n * (float) $cfg['sleeve'], true ); // phpcs:ignore ?></span></label>
							<?php endfor; ?>
						</div>
					</div>
					<?php endif; ?>
					<p class="sl-calc__cap">Любой размер принта до A3 — уже в цене</p>
				</div>
				<?php endif; ?>

				<?php if ( $cfg['modes']['own'] ) : ?>
				<div class="sl-calc__panel" data-panel="own"<?php echo 'own' !== $mode ? ' hidden' : ''; ?>>
					<div class="sl-field">
						<span class="sl-field__label">Основной принт</span>
						<div class="sl-seg">
							<?php foreach ( $sizes as $sz => $dim ) : ?>
							<label class="sl-seg__opt" title="<?php echo esc_attr( $dim ); ?>"><input type="radio" name="sl_size1" value="<?php echo esc_attr( $sz ); ?>"<?php checked( 'A4', $sz ); ?>><span><?php echo esc_html( $sz ); ?><?php echo podval_sl_opt_price( podval_price_transfer( $sz, $qty0 ) ); // phpcs:ignore ?></span></label>
							<?php endforeach; ?>
						</div>
					</div>
					<div class="sl-field">
						<span class="sl-field__label">Второй принт</span>
						<div class="sl-seg">
							<label class="sl-seg__opt"><input type="radio" name="sl_size2" value="" checked><span>Нет<?php echo podval_sl_opt_price( 0.0 ); // phpcs:ignore ?></span></label>
							<?php foreach ( $sizes as $sz => $dim ) : ?>
							<label class="sl-seg__opt" title="<?php echo esc_attr( $dim ); ?>"><input type="radio" name="sl_size2" value="<?php echo esc_attr( $sz ); ?>"><span><?php echo esc_html( $sz ); ?><?php echo podval_sl_opt_price( podval_price_transfer( $sz, $qty0 ), true ); // phpcs:ignore ?></span></label>
							<?php endforeach; ?>
						</div>
					</div>
					<div class="sl-field">
						<span class="sl-field__label">Рукава <em>по цене A6</em></span>
						<div class="sl-seg">
							<?php
							$a6 = podval_price_transfer( 'A6', $qty0 );
							for ( $n = 0; $n <= 2; $n++ ) :
								?>
							<label class="sl-seg__opt"><input type="radio" name="sl_sleeves_own" value="<?php echo (int) $n; ?>"<?php checked( 0, $n ); ?>><span><?php echo (int) $n; ?><?php echo podval_sl_opt_price( null === $a6 ? null : $n * $a6, true ); // phpcs:ignore ?></span></label>
							<?php endfor; ?>
						</div>
					</div>
					<p class="sl-calc__cap">Печать и перенос на вашу вещь; хлопок и смесовые ткани</p>
				</div>
				<?php endif; ?>

				<?php if ( $cfg['roll'] ) : ?>
				<div class="sl-calc__panel" data-panel="roll"<?php echo 'roll' !== $mode ? ' hidden' : ''; ?>>
					<div class="sl-field">
						<span class="sl-field__label">Сколько принтов нужно</span>
						<div class="sl-counts">
							<?php foreach ( $sizes as $sz => $dim ) : ?>
							<div class="sl-count">
								<label class="sl-count__label" for="sl-count-<?php echo esc_attr( $sz ); ?>">
									<strong><?php echo esc_html( $sz ); ?></strong> <em><?php echo esc_html( $dim ); ?></em>
								</label>
								<div class="sl-qty sl-qty--sm">
									<button type="button" class="sl-qty__btn" data-count-step="-1" data-size="<?php echo esc_attr( $sz ); ?>" aria-label="<?php echo esc_attr( $sz . ': меньше' ); ?>">−</button>
									<input type="number" id="sl-count-<?php echo esc_attr( $sz ); ?>" name="sl_count_<?php echo esc_attr( $sz ); ?>" value="<?php echo (int) ( $calc['counts'][ $sz ] ?? 0 ); ?>" min="0" max="100000" step="1" inputmode="numeric">
									<button type="button" class="sl-qty__btn" data-count-step="1" data-size="<?php echo esc_attr( $sz ); ?>" aria-label="<?php echo esc_attr( $sz . ': больше' ); ?>">+</button>
								</div>
							</div>
							<?php endforeach; ?>
						</div>
					</div>
					<p class="sl-calc__cap">Плёнка <?php echo esc_html( podval_price_num( (float) $cfg['roll']['width'] ) ); ?> см шириной: раскладываем принты рядами и считаем погонные метры. Метраж ориентировочный — при плотной раскладке выходит меньше.</p>
				</div>
				<?php endif; ?>

				<div class="sl-field sl-field--qty"<?php echo 'roll' === $mode ? ' hidden' : ''; ?>>
					<label class="sl-field__label" for="sl-qty">Количество, шт</label>
					<div class="sl-qty">
						<button type="button" class="sl-qty__btn" data-step="-1" aria-label="Меньше">−</button>
						<input type="number" id="sl-qty" name="sl_qty" value="1" min="1" max="100000" step="1" inputmode="numeric">
						<button type="button" class="sl-qty__btn" data-step="1" aria-label="Больше">+</button>
					</div>
					<div class="sl-qty__chips">
						<?php foreach ( array( 1, 5, 10, 20, 50 ) as $chip ) : ?>
						<button type="button" class="sl-chip<?php echo 1 === $chip ? ' is-active' : ''; ?>" data-qty="<?php echo (int) $chip; ?>"><?php echo (int) $chip; ?></button>
						<?php endforeach; ?>
					</div>
				</div>
			</div>

			<div class="sl-calc__out">
				<div class="sl-calc__label">Стоимость</div>
				<div class="sl-calc__result" aria-live="polite">
					<?php $show_price = $has_unit && ( 'roll' !== $mode || ! empty( $calc['total'] ) ); ?>
					<div class="sl-calc__price"<?php echo $show_price ? '' : ' hidden'; ?>>
						<?php
						// В режиме плёнки цена за метр, а не за штуку, и «за N м» —
						// те же узлы переписывает JS при смене режима.
						$is_roll = 'roll' === $mode;
						$amount  = $is_roll ? podval_price_num( (float) ( $calc['billed'] ?? 0 ) ) : (string) (int) ( $calc['qty'] ?? 1 );
						?>
						<div class="sl-calc__unit"><span class="sl-calc__prefix"><?php echo ! empty( $calc['from'] ) ? 'до' : '≈'; ?></span> <span class="sl-calc__unit-val"><?php echo $has_unit ? podval_sl_byn( $calc['unit'] ) : ''; // phpcs:ignore ?></span><small class="sl-calc__unit-suffix"><?php echo $is_roll ? '/м' : '/шт'; ?></small></div>
						<div class="sl-calc__line"><span class="sl-calc__total"><?php echo $has_unit && ! empty( $calc['total'] ) ? podval_sl_byn( $calc['total'] ) : ''; // phpcs:ignore ?></span> за <span class="sl-calc__qty"><?php echo esc_html( $amount ); ?></span> <span class="sl-calc__qty-unit"><?php echo $is_roll ? 'м' : 'шт'; ?></span><?php if ( $lead !== '' ) : ?> · срок <?php echo esc_html( $lead ); ?><?php endif; ?></div>
					</div>
					<div class="sl-calc__meta"<?php echo ( 'roll' === $mode && ! empty( $calc['qty'] ) ) ? '' : ' hidden'; ?>>≈ <span class="sl-calc__metres"><?php echo esc_html( podval_price_num( (float) ( $calc['metres'] ?? 0 ) ) ); ?></span> м плёнки</div>
					<div class="sl-calc__na"<?php echo $show_price ? ' hidden' : ''; ?>><?php echo 'roll' === $mode ? 'Укажите, сколько принтов нужно' : 'Рассчитаем индивидуально'; ?></div>
					<p class="sl-calc__hint"><?php echo podval_sl_calc_hint_html( $calc ); // phpcs:ignore ?></p>
				</div>
				<?php if ( $sl['form']['enabled'] ) : ?>
				<button type="button" class="sl-btn sl-btn--primary sl-btn--block sl-calc__send">Отправить расчёт в заявку</button>
				<?php endif; ?>
				<?php if ( $cfg['product'] ) : ?>
				<?php // Из «Нашей вещи» логичнее уйти в конструктор: там тот же товар и оформление заказа. ?>
				<a class="sl-btn sl-btn--ghost sl-btn--block sl-calc__product" href="<?php echo esc_url( $cfg['product']['url'] ); ?>"<?php echo 'ours' === $mode ? '' : ' hidden'; ?>><?php echo esc_html( $cfg['product']['label'] ); ?> →</a>
				<?php endif; ?>
				<noscript><p class="sl-calc__note">Для расчёта включите JavaScript — цены в таблице ниже.</p></noscript>
				<?php if ( $sl['calc']['note'] !== '' ) : ?>
				<p class="sl-calc__note"><?php echo esc_html( $sl['calc']['note'] ); ?></p>
				<?php endif; ?>
			</div>
		</div>
	</div>
</section>

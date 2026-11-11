<?php
/**
 * Single template for CPT podval_bundle.
 * ─────────────────────────────────────────────────────────────────────
 * URL: /bundles/<slug>/
 *
 * Состав страницы:
 *   1. Breadcrumbs
 *   2. HERO — обложка/состав слева, инфо-карточка с ценой/CTA + anchor справа
 *   3. EXCLUSION WARNING — предупреждение если клиент исключил часть футболок
 *   4. SHIRTS CONFIGURATOR — для каждой футболки: цвет / размер / тип / toggle exclude
 *      (с placeholder для align высот карточек если у разных shirts разные атрибуты)
 *   5. TIMELINE «Как работает скидка» — реактивная шкала скидок
 *   6. BENEFITS — 4 преимущества бандла (хлопок / DTF / крой / производство)
 *   7. SIMILAR — похожие наборы по таксономии bundle_theme
 *   8. STICKY BOTTOM BAR (mobile) — цена + кнопка добавления
 *
 * Realtime recalc — assets/js/bundle-single.js, читает state из
 * window.PodvalBundleSingle (initial state из PHP).
 *
 * Add-to-cart — реальный AJAX в прежней версии (см. inc/cart-bundle.php).
 *
 * @package podval-print
 *
 * @version 2.5.5
 */

defined( 'ABSPATH' ) || exit;

get_header();

the_post();
$post_obj = get_post();

if ( ! $post_obj || $post_obj->post_type !== 'podval_bundle' ) {
	echo '<main class="bundles-empty" style="padding:80px 20px;text-align:center"><p>Бандл не найден.</p><p><a href="' . esc_url( podval_url( 'bundles' ) ) . '" class="btn btn--primary">К каталогу</a></p></main>';
	get_footer();
	return;
}

$bundle = podval_bundle_to_array( $post_obj );
if ( empty( $bundle ) || empty( $bundle['shirts'] ) ) {
	echo '<main class="bundles-empty" style="padding:80px 20px;text-align:center"><p>В этом бандле пока нет товаров.</p><p><a href="' . esc_url( podval_url( 'bundles' ) ) . '" class="btn btn--primary">К каталогу</a></p></main>';
	get_footer();
	return;
}

// Полные WC-данные для каждого slot (variations, attrs, defaults)
$shirts_full = podval_bundle_get_single_data( $post_obj->ID );

// Шкала скидок
$discount_scale = podval_bundle_get_discount_scale( $post_obj->ID );

// Союз атрибутов по всем shirts — для выравнивания карточек
$attr_union = podval_bundle_get_attr_union( $shirts_full );

// Базовые данные для PHP-render первого состояния
$total_shirts = count( $bundle['shirts'] );

// Стартовая сумма (все футболки активны, defaults)
$start_sum = 0.0;
foreach ( $shirts_full as $sf ) {
	$start_sum += (float) $sf['price'];
}
// через единую resolve-функцию (поддержка скидки в Br)
$disc_type  = podval_bundle_get_discount_type( $post_obj->ID );
$resolved   = podval_bundle_resolve_discount( $post_obj->ID, $start_sum, $total_shirts );
$discount   = (int) round( $resolved['percent'] );
$disc_label = podval_bundle_format_discount( $resolved );
$now_price  = (int) round( $resolved['total'] );
$full_price = (int) round( $start_sum );
$saved      = $full_price - $now_price;

// Похожие бандлы
$similar = podval_bundle_get_similar( $post_obj->ID, 4 );

// JS configurator data
$js_data = array(
	'bundleId'      => (int) $post_obj->ID,
	'bundleName'    => $bundle['name'],
	'bundleSlug'    => $post_obj->post_name,
	'shirts'        => $shirts_full,
	'discountScale' => $discount_scale,
	'discountType'  => $disc_type,
	'totalShirts'   => $total_shirts,
	'attrUnion'     => $attr_union,
	'ajax'          => array(
		'url'   => admin_url( 'admin-ajax.php' ),
		'nonce' => wp_create_nonce( 'podval_bundle_add_to_cart' ),
	),
	'cartUrl'       => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart/' ),
);

get_template_part( 'template-parts/bundles-svg-defs' );
?>

<main class="bundles-page bundle-single-page" data-bundle-page>

	<!-- 1. Breadcrumbs -->
	<div class="chub-container">
		<div class="crumbs">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>">Главная</a>
			<span class="crumb-sep">›</span>
			<a href="<?php echo esc_url( podval_url( 'bundles' ) ); ?>">Наборы футболок</a>
			<span class="crumb-sep">›</span>
			<?php echo esc_html( $bundle['name'] ); ?>
		</div>
	</div>

	<!-- 2. PRODUCT HERO -->
	<div class="chub-container">
		<section class="bundle-hero">

			<!-- LEFT: gallery -->
			<div class="bhero-gallery">
				<div class="bhero-stamp" aria-hidden="true" data-stamp>
					<span data-stamp-pct><?php echo wp_kses_post( $disc_label ); ?></span>
					<small data-stamp-count>за <?php echo (int) $total_shirts; ?> из <?php echo (int) $total_shirts; ?></small>
				</div>

				<?php if ( ! empty( $bundle['thumbnail'] ) ) : ?>
					<div class="bhero-cover">
						<img src="<?php echo esc_url( $bundle['thumbnail'] ); ?>" alt="<?php echo esc_attr( $bundle['thumbnail_alt'] ?: $bundle['name'] ); ?>" loading="eager" fetchpriority="high">
					</div>
				<?php else : ?>
					<div class="bhero-grid">
						<?php foreach ( $bundle['shirts'] as $i => $s ) :
							$has_img = ! empty( $s['image'] );
						?>
							<div class="bhero-tee<?php echo $has_img ? ' bhero-tee--img' : ''; ?>"
							     data-shirt="<?php echo esc_attr( $s['shirt'] ); ?>"
							     data-shirt-id="<?php echo (int) $s['product_id']; ?>">
								<?php if ( $has_img ) : ?>
									<img src="<?php echo esc_url( $s['image'] ); ?>" alt="<?php echo esc_attr( $s['name'] ); ?>" loading="lazy">
								<?php else : ?>
									<svg viewBox="0 0 200 240" class="tee-svg" aria-hidden="true"><use href="#tee"/></svg>
									<svg viewBox="0 0 80 100" class="tee-print" aria-hidden="true"><use href="#prn-<?php echo esc_attr( $s['print'] ); ?>"/></svg>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>

			<!-- RIGHT: info card -->
			<aside class="bundle-info">

				<?php if ( ! empty( $bundle['badges'] ) ) : ?>
					<div class="badge-row">
						<?php foreach ( $bundle['badges'] as $b ) : ?>
							<span class="badge badge--<?php echo esc_attr( $b['type'] ); ?>"><?php echo esc_html( $b['label'] ); ?></span>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $bundle['cat'] ) ) : ?>
					<div class="bundle-info__cat"><?php echo esc_html( $bundle['cat'] ); ?></div>
				<?php endif; ?>

				<h1 class="bundle-info__title"><?php echo esc_html( $bundle['name'] ); ?></h1>

				<div class="bundle-info__sub" data-info-sub>
					<?php echo esc_html( $bundle['subtitle'] ); ?>
				</div>

				<?php if ( ! empty( $bundle['description'] ) ) : ?>
					<div class="bundle-info__desc"><?php echo $bundle['description']; // Уже sanitize в to_array ?></div>
				<?php endif; ?>

				<div class="inline-note">
					<svg class="inline-note__icon" aria-hidden="true"><use href="#bnd-ic-info-mini"/></svg>
					<span>Некоторые цвета и&nbsp;фасоны доступны не&nbsp;для всех футболок</span>
				</div>

				<div class="price-block">
					<div class="price-block__left">
						<div class="price-block__caption">Скидка сейчас:</div>
						<div class="price-block__discount" data-discount-pct><?php echo wp_kses_post( $disc_label ); ?></div>
					</div>
					<div class="price-block__right">
						<div class="price-block__nowline">
							<span class="price-now" data-price-now data-price-byn="<?php echo (int) $now_price; ?>"><?php echo number_format( $now_price, 2, ',', '' ); ?>&nbsp;Br</span>
							<?php if ( $saved > 0 ) : ?>
								<s class="price-old" data-price-old data-price-byn="<?php echo (int) $full_price; ?>"><?php echo number_format( $full_price, 2, ',', '' ); ?>&nbsp;Br</s>
							<?php else : ?>
								<s class="price-old" data-price-old hidden></s>
							<?php endif; ?>
						</div>
						<div class="price-save" data-price-save<?php echo $saved > 0 ? '' : ' hidden'; ?>>
							<?php if ( $saved > 0 ) : ?>
								Экономия <span data-price-byn="<?php echo (int) $saved; ?>"><?php echo number_format( $saved, 2, ',', '' ); ?>&nbsp;Br</span>
							<?php endif; ?>
						</div>
					</div>
				</div>

				<div class="reassure">Чем больше футболок в&nbsp;наборе — тем выше скидка</div>

				<button type="button" class="btn btn--primary btn--lg btn--full" data-add-to-cart>
					<svg class="btn-ic" aria-hidden="true"><use href="#bnd-ic-cart"/></svg>
					<span class="btn-label">Добавить в корзину</span>
				</button>

				<button type="button" class="btn btn--ghost btn--full bundle-anchor-btn" data-scroll-to="#bundle-configurator">
					Выбрать параметры футболок
					<svg class="btn-ic btn-ic--right" aria-hidden="true"><use href="#bnd-ic-arrow-d"/></svg>
				</button>
			</aside>

		</section>
	</div>

	<!-- 3. EXCLUSION WARNING -->
	<div class="chub-container">
		<div class="warn" data-warn hidden>
			<svg class="warn__ic" aria-hidden="true"><use href="#bnd-ic-info-mini"/></svg>
			<span>
				<span data-warn-count>0</span> футболка исключена — скидка снижена с
				<s data-warn-best-pct>0%</s>
				до <strong data-warn-now-pct>0%</strong>
			</span>
		</div>
	</div>

	<!-- 4. SHIRTS CONFIGURATOR -->
	<div class="chub-container">
		<header class="sect-head" id="bundle-configurator">
			<h2 class="sect-head__title">Состав набора</h2>
			<span class="sect-head__hint">
				<?php echo (int) $total_shirts; ?> футболок · настройте каждую под себя
			</span>
		</header>

		<?php
		/* сетка была жёстко в 4 колонки. Наборов из 4 футболок
		   меньшинство, и при 2–3 позициях карточки прижимались влево,
		   оставляя пустые треки справа. Отдаём фактическое количество
		   и ограничиваем ширину сетки, чтобы она центрировалась. */
		$cfg_cols  = max( 1, min( 4, (int) $total_shirts ) );
		$cfg_style = '--cfg-cols:' . $cfg_cols . ';';
		if ( $cfg_cols < 4 ) {
			// ширина = N карточек по 280px + гэпы по 12px
			$cfg_style .= '--cfg-max-w:calc(' . $cfg_cols . ' * 280px + ' . ( $cfg_cols - 1 ) . ' * 12px);';
		}
		?>
		<div class="config-grid" data-count="<?php echo (int) $total_shirts; ?>" style="<?php echo esc_attr( $cfg_style ); ?>">
			<?php foreach ( $shirts_full as $idx => $sf ) :
				$num = $idx + 1;
				if ( ! empty( $sf['error'] ) ) :
					?>
					<article class="bi-card bi-card--error">
						<span class="bi-num"><?php echo $num; ?></span>
						<div class="bi-name" style="color:#b32d2e">⚠ Товар недоступен (id #<?php echo (int) $sf['product_id']; ?>)</div>
						<div class="bi-row" style="font-size:12px;color:#666"><?php echo esc_html( $sf['error'] ); ?></div>
					</article>
					<?php
					continue;
				endif;

				$has_img = ! empty( $sf['image'] );
			?>
				<article class="bi-card"
				         data-bundle-item
				         data-shirt-id="<?php echo (int) $sf['product_id']; ?>"
				         data-shirt-idx="<?php echo $idx; ?>">
					<span class="bi-num"><?php echo $num; ?></span>

					<div class="bi-shirt<?php echo $has_img ? ' bi-shirt--img' : ''; ?>"
					     data-shirt-preview>
						<?php if ( $has_img ) : ?>
							<img src="<?php echo esc_url( $sf['image'] ); ?>" alt="<?php echo esc_attr( $sf['name'] ); ?>" loading="lazy" data-shirt-img>
						<?php else : ?>
							<svg viewBox="0 0 200 240" class="tee-svg" aria-hidden="true"><use href="#tee"/></svg>
						<?php endif; ?>
						<div class="excluded-overlay" hidden>
							<div class="excluded-ribbon">Исключено</div>
						</div>
					</div>

					<div class="bi-name"><?php echo esc_html( $sf['name'] ); ?></div>

					<?php
					// Render attrs по union — для align высот карточек.
					// Если у shirt'а нет атрибута из union — пустой placeholder.
					foreach ( $attr_union as $role ) :
						if ( isset( $sf['attributes'][ $role ] ) ) :
							$cfg = $sf['attributes'][ $role ];
							$def = $sf['defaults'][ $role ] ?? '';
							?>
							<div class="bi-row bi-row--<?php echo esc_attr( $role ); ?>">
								<div class="bi-label">
									<?php echo esc_html( $cfg['label'] ); ?>
									<?php
									/* ссылка на таблицу размеров прямо в строке «Размер».
									   Попап рендерится один раз внизу шаблона и дедуплицируется
									   по значению меты: у парных футболок таблица обычно одна. */
									if ( $role === 'size' && ! empty( $sf['size_chart'] ) ) : ?>
									<a href="#" class="bi-size-chart js-size-chart-open"
									   data-sc-target="sc-popup-<?php echo esc_attr( $sf['size_chart'] ); ?>">Таблица размеров</a>
									<?php endif; ?>
								</div>
								<?php if ( $role === 'color' ) : ?>
									<div class="bi-colors" data-attr-group="color">
										<?php foreach ( $cfg['options'] as $opt ) :
											$has_hex   = ! empty( $opt['hex'] );
											$is_active = ( $opt['value'] === $def );
											$cls       = 'sw';
											if ( $is_active )  $cls .= ' is-active';
											if ( ! $has_hex )  $cls .= ' sw--text';
											$style     = $has_hex ? 'style="--c:' . esc_attr( $opt['hex'] ) . '"' : '';
											?>
											<span class="<?php echo esc_attr( $cls ); ?>"
											      <?php echo $style; ?>
											      data-value="<?php echo esc_attr( $opt['value'] ); ?>"
											      title="<?php echo esc_attr( $opt['label'] ); ?>">
												<i></i>
												<span class="sw-l"><?php echo esc_html( $opt['label'] ); ?></span>
											</span>
										<?php endforeach; ?>
									</div>
								<?php else : /* type / size — buttons */ ?>
									<div class="bi-<?php echo $role === 'type' ? 'types' : 'sizes'; ?>" data-attr-group="<?php echo esc_attr( $role ); ?>">
										<?php foreach ( $cfg['options'] as $opt ) : ?>
											<button type="button"
											        class="size-btn<?php echo ( $opt['value'] === $def ) ? ' is-active' : ''; ?>"
											        data-value="<?php echo esc_attr( $opt['value'] ); ?>">
												<?php echo esc_html( $opt['label'] ); ?>
											</button>
										<?php endforeach; ?>
									</div>
								<?php endif; ?>
							</div>
							<?php
						else :
							// Empty placeholder для align — занимает то же место.
							?>
							<div class="bi-row bi-row--<?php echo esc_attr( $role ); ?> bi-row--empty" aria-hidden="true"></div>
							<?php
						endif;
					endforeach;
					?>

					<?php /* Цена single-item (обновляется JS) */ ?>
					<div class="bi-price-row">
						<span class="bi-price-label">Цена за единицу:</span>
						<span class="bi-price-value" data-shirt-price data-price-byn="<?php echo (int) round( $sf['price'] ); ?>"><?php echo number_format( $sf['price'], 2, ',', '' ); ?>&nbsp;Br</span>
					</div>

					<label class="bi-toggle">
						<span class="bi-toggle__lbl">Исключить из набора</span>
						<input type="checkbox"
						       class="bi-toggle__input"
						       data-exclude-toggle
						       style="position:absolute;opacity:0;pointer-events:none;width:0;height:0;">
						<span class="switch"><i></i></span>
					</label>
				</article>
			<?php endforeach; ?>
		</div>
	</div>

	<!-- 5. TIMELINE «Как работает скидка» -->
	<?php
	// Сортируем от max шт. (полный набор) вниз к 1 шт.
	// scale[i] = % для (i+1) штук → шаги [total..1].
	// сумма шага считается по первым N футболкам набора, чтобы
	// для типа 'fixed' подпись «−N Br» была честной, а не от полной суммы.
	$steps = array();
	$prices_seq = array();
	foreach ( $shirts_full as $sf ) {
		$prices_seq[] = (float) ( $sf['price'] ?? 0 );
	}
	for ( $count = $total_shirts; $count >= 1; $count-- ) {
		$step_sum = array_sum( array_slice( $prices_seq, 0, $count ) );
		$step_res = podval_bundle_resolve_discount( $post_obj->ID, $step_sum, $count );
		$steps[] = array(
			'count' => $count,
			'pct'   => (int) round( $step_res['percent'] ),
			'label' => podval_bundle_format_discount( $step_res ),
		);
	}
	?>
	<div class="chub-container">
		<section class="timeline" data-timeline data-total="<?php echo (int) $total_shirts; ?>">
			<div class="timeline__intro">
				<h3 class="timeline__h">КАК РАБОТАЕТ СКИДКА</h3>
				<p class="timeline__sub">Больше футболок&nbsp;— больше выгода</p>
			</div>

			<div class="timeline__steps">
				<?php foreach ( $steps as $si => $step ) :
					$is_first    = ( $si === 0 );
					$is_current  = ( $step['count'] === $total_shirts );    // initial: все включены
					$is_achiev   = ( $step['count'] > $total_shirts );      // выше текущего — недостижимо, не возникает initial
					$is_locked   = ( $step['count'] < $total_shirts );      // ниже текущего — меньше выгоды

					$cls = 'tstep';
					if ( $is_current )     $cls .= ' tstep--current-full';   // initial = всё активно → full set
					elseif ( $is_achiev )  $cls .= ' tstep--achievable';
					else                   $cls .= ' tstep--locked';

					$cap = '';
					if ( $is_current )       $cap = '✓ лучшая скидка';
					elseif ( $step['count'] === $total_shirts ) $cap = 'полный набор';
					elseif ( $step['count'] === 1 ) $cap = 'без скидки';
					else                     $cap = 'меньше выгода';
					?>
					<?php if ( ! $is_first ) : ?>
						<span class="tline <?php echo ( $is_current || $is_achiev ) ? 'tline--on' : 'tline--off'; ?>" data-tline></span>
					<?php endif; ?>
					<div class="<?php echo esc_attr( $cls ); ?>"
					     data-tstep
					     data-step-count="<?php echo (int) $step['count']; ?>"
					     data-step-pct="<?php echo (int) $step['pct']; ?>">
						<span class="tstep__top"><?php echo (int) $step['count']; ?>/<?php echo (int) $total_shirts; ?> футболки</span>
						<span class="tstep__pill">скидка <?php echo wp_kses_post( ltrim( $step['label'], '−' ) ); ?></span>
						<span class="tstep__cap" data-tstep-cap><?php echo esc_html( $cap ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="timeline__result">
				<div class="tres__cap">текущий выбор:</div>
				<div class="tres__count"><span data-tres-count><?php echo (int) $total_shirts; ?></span> из <?php echo (int) $total_shirts; ?> футболок</div>
				<span class="tres__pill tres__pill--best" data-tres-pill><?php echo wp_kses_post( $disc_label ); ?></span>
				<div class="tres__save" data-tres-save>
					<?php if ( $saved > 0 ) : ?>
						Экономия: <span data-price-byn="<?php echo (int) $saved; ?>"><?php echo number_format( $saved, 2, ',', '' ); ?>&nbsp;Br</span>
					<?php endif; ?>
				</div>
			</div>
		</section>
	</div>

	<!-- 6. BENEFITS -->
	<div class="chub-container">
		<div class="benefits">
			<div class="benefit">
				<svg class="benefit__icon" aria-hidden="true"><use href="#bnd-ic-cotton"/></svg>
				<div class="benefit__txt">
					<div class="benefit__title">100% Хлопок</div>
					<div class="benefit__sub">Мягкая и&nbsp;дышащая ткань премиального качества</div>
				</div>
			</div>
			<div class="benefit">
				<svg class="benefit__icon" aria-hidden="true"><use href="#bnd-ic-printer"/></svg>
				<div class="benefit__txt">
					<div class="benefit__title">Стойкая DTF-печать</div>
					<div class="benefit__sub">Яркие принты, которые не&nbsp;трескаются и&nbsp;не&nbsp;выцветают</div>
				</div>
			</div>
			<div class="benefit">
				<svg class="benefit__icon" aria-hidden="true"><use href="#bnd-ic-shirt-sliders"/></svg>
				<div class="benefit__txt">
					<div class="benefit__title">Свой крой</div>
					<div class="benefit__sub">Выбери крой для&nbsp;каждой футболки — классический или&nbsp;оверсайз</div>
				</div>
			</div>
			<div class="benefit">
				<svg class="benefit__icon" aria-hidden="true"><use href="#bnd-ic-factory"/></svg>
				<div class="benefit__txt">
					<div class="benefit__title">Собственное производство</div>
					<div class="benefit__sub">Контроль качества на&nbsp;каждом этапе</div>
				</div>
			</div>
		</div>
	</div>

	<!-- 7. ПОХОЖИЕ НАБОРЫ -->
	<?php if ( count( $similar ) >= 2 ) : ?>
	<div class="chub-container">
		<header class="sect-head sect-head--row">
			<h2 class="sect-head__title">Похожие наборы</h2>
		</header>
		<div class="similar-rail">
			<div class="similar-track">
				<?php foreach ( $similar as $sp ) :
					$sb       = podval_bundle_to_array( $sp );
					if ( empty( $sb ) ) continue;

					$s_url    = get_permalink( $sp );
					$s_thumb  = $sb['thumbnail'] ?? '';
					$s_count  = isset( $sb['shirts'] ) ? count( $sb['shirts'] ) : 0;

					// Цена бандла — sum цен товаров (без вызова heavy calc).
					$s_full = podval_bundle_get_single_data( $sp->ID );
					$s_sum  = 0.0;
					foreach ( $s_full as $sfx ) {
						$s_sum += (float) ( $sfx['price'] ?? 0 );
					}
					$s_res   = podval_bundle_resolve_discount( $sp->ID, $s_sum, $s_count );
					$s_pct   = (int) round( $s_res['percent'] );
					$s_label = podval_bundle_format_discount( $s_res );
					$s_now   = (int) round( $s_res['total'] );
					$s_full_price = (int) round( $s_sum );
				?>
					<a href="<?php echo esc_url( $s_url ); ?>" class="bcard">
						<?php if ( ! empty( $sb['badges'] ) ) : ?>
							<div class="bcard__badge-row">
								<?php foreach ( $sb['badges'] as $b ) : ?>
									<span class="badge badge--<?php echo esc_attr( $b['type'] ); ?>"><?php echo esc_html( $b['label'] ); ?></span>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
						<?php if ( $s_res['amount'] > 0 ) : ?>
							<span class="bcard__pct"><?php echo wp_kses_post( $s_label ); ?></span>
						<?php endif; ?>
						<div class="bcard__img">
							<?php if ( $s_thumb ) : ?>
								<img src="<?php echo esc_url( $s_thumb ); ?>" alt="<?php echo esc_attr( $sb['name'] ); ?>" loading="lazy">
							<?php else : ?>
								<svg viewBox="0 0 200 240" class="tee-svg" aria-hidden="true"><use href="#tee"/></svg>
							<?php endif; ?>
						</div>
						<div class="bcard__title"><?php echo esc_html( $sb['name'] ); ?></div>
						<div class="bcard__meta"><?php echo (int) $s_count; ?> футболки&nbsp;· хлопок&nbsp;· DTF</div>
						<div class="bcard__price">
							<span class="bcard__now" data-price-byn="<?php echo (int) $s_now; ?>"><?php echo number_format( $s_now, 2, ',', '' ); ?>&nbsp;Br</span>
							<?php if ( $s_full_price > $s_now ) : ?>
								<s class="bcard__old" data-price-byn="<?php echo (int) $s_full_price; ?>"><?php echo number_format( $s_full_price, 2, ',', '' ); ?>&nbsp;Br</s>
							<?php endif; ?>
						</div>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
	</div>
	<?php endif; ?>

	<!-- 8. STICKY BOTTOM BAR (mobile) -->
	<div class="m-stickybar" data-sticky-bar aria-hidden="true">
		<div class="m-stickybar__price">
			<div class="m-stickybar__sub" data-sticky-sub>
				<?php echo (int) $total_shirts; ?> из <?php echo (int) $total_shirts; ?> футболок
			</div>
			<div class="m-stickybar__nowline">
				<span class="m-stickybar__now" data-sticky-now data-price-byn="<?php echo (int) $now_price; ?>"><?php echo number_format( $now_price, 2, ',', '' ); ?>&nbsp;Br</span>
				<?php if ( $saved > 0 ) : ?>
					<s class="m-stickybar__old" data-sticky-old data-price-byn="<?php echo (int) $full_price; ?>"><?php echo number_format( $full_price, 2, ',', '' ); ?>&nbsp;Br</s>
				<?php else : ?>
					<s class="m-stickybar__old" data-sticky-old hidden></s>
				<?php endif; ?>
			</div>
		</div>
		<button type="button" class="btn btn--primary btn--lg" data-add-to-cart>
			<svg class="btn-ic" aria-hidden="true"><use href="#bnd-ic-cart"/></svg>
			<span class="btn-label">В корзину</span>
		</button>
	</div>

	<!-- Toast container — для AJAX notification -->
	<div class="bundle-toast" data-toast hidden role="status" aria-live="polite"></div>

	<?php
	/* ── Попапы «Таблица размеров» ─────────────────────────
	   Один попап на уникальное значение _podval_size_chart. У парных
	   футболок таблица, как правило, одна на обе — рендерить её дважды
	   значило бы получить два элемента с одинаковым id. */
	$sc_rendered = array();
	foreach ( $shirts_full as $sf_sc ) {
		$sc_val = $sf_sc['size_chart'] ?? '';
		if ( ! $sc_val || in_array( $sc_val, $sc_rendered, true ) ) {
			continue;
		}
		$sc_rendered[] = $sc_val;
		get_template_part( 'template-parts/size-chart-popup', null, array(
			'sc_type' => $sc_val,
			'sc_id'   => 'sc-popup-' . $sc_val,
		) );
	}
	?>

</main>

<script>
window.PodvalBundleSingle = <?php echo wp_json_encode( $js_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?>;
</script>
<?php
get_footer();

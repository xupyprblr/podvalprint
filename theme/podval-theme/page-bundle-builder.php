<?php
/**
 * Template Name: Бандл — конструктор (Builder)
 *
 * Страница «Собери свой бандл» — пользователь сам выбирает 3–5 принтов
 * из каталога, настраивает каждый (тип/цвет/размер) и получает скидку.
 *
 * На корневом <main> ТРИ класса — наследуем стили из двух уже подключённых
 * CSS-модулей и добавляем свой:
 *   bundles-page         → .bcard, .benefits, .mini-tee, .tnail
 *   bundle-single-page   → .bi-num, .size-btn, .sw, .bi-select, .m-stickybar,
 *                          .btn--lg/full/ic, .crumbs, .sect-head, .carbtn
 *   bundle-builder-page  → новые компоненты builder'а
 *
 * URL: /bundles/builder/
 *
 * Стили: style-bundles.css + style-bundle-single.css + style-bundle-builder.css
 * SVG:   template-parts/bundles-svg-defs.php (inline)
 *
 * @package podval-print
 */
defined( 'ABSPATH' ) || exit;

/* ------------------------------------------------------------------
 * Гейт публичности — см. page-bundles.php.
 * ------------------------------------------------------------------ */
$bundles_public = function_exists( 'podval_bundles_are_public' ) && podval_bundles_are_public();
if ( ! $bundles_public && ! current_user_can( 'edit_posts' ) ) {
    global $wp_query;
    $wp_query->set_404();
    status_header( 404 );
    nocache_headers();
    get_template_part( '404' );
    return;
}

get_header();

/* ------------------------------------------------------------------
 * Контактные данные / хелперы
 * ------------------------------------------------------------------ */
$tg_url      = get_theme_mod( 'podval_header_tg', get_theme_mod( 'podval_footer_tg', podval_brand( 'telegram' ) ) );
$bundles_url = podval_url( 'bundles' );

/* ------------------------------------------------------------------
 * Конфигурация builder'а — потом будет читаться из настроек темы / CPT
 * ------------------------------------------------------------------ */
$base_price = 299; // BYN за одну футболку

$pricing = array(
	1 => array( 'discount' => 0,  'state' => 'past'    ),
	2 => array( 'discount' => 5,  'state' => 'past'    ),
	3 => array( 'discount' => 10, 'state' => 'current' ),
	4 => array( 'discount' => 15, 'state' => 'locked'  ),
	5 => array( 'discount' => 20, 'state' => 'locked'  ),
);

/* ------------------------------------------------------------------
 * Mock-состав ($slots) — стартовое состояние страницы:
 * 3 заполненных + 2 пустых. После того как сделаем CPT,
 * это будет приходить из session (выбор пользователя сохраняется).
 *
 * Поля слота:
 *   index   — 1..5 (номер на UI)
 *   filled  — true|false
 *   print   — A..H (если filled)
 *   shirt   — black|white|graphite|beige (визуальный фон футболки)
 *   type    — oversize|classic
 *   color   — black|white|graphite|beige
 *   size    — XS..XXL
 *   ref     — id принта из каталога ниже (чтобы подсветить is-selected)
 * ------------------------------------------------------------------ */
$slots = array(
	1 => array( 'filled'=>true,  'print'=>'A', 'shirt'=>'black',    'type'=>'oversize', 'color'=>'black',    'size'=>'M',  'ref'=>'prn-01' ),
	2 => array( 'filled'=>true,  'print'=>'F', 'shirt'=>'white',    'type'=>'classic',  'color'=>'white',    'size'=>'L',  'ref'=>'prn-07' ),
	3 => array( 'filled'=>true,  'print'=>'B', 'shirt'=>'graphite', 'type'=>'oversize', 'color'=>'graphite', 'size'=>'XL', 'ref'=>'prn-12' ),
	4 => array( 'filled'=>false ),
	5 => array( 'filled'=>false ),
);

/* ------------------------------------------------------------------
 * Mock-каталог принтов, сгруппированный по темам.
 * Каждый принт — ссылка на наш #prn-A..#prn-H символ.
 * id используется для подсветки is-selected.
 * ------------------------------------------------------------------ */
$catalog_themes = array(
	'anime' => array(
		'label'  => 'Anime Vibes',
		'prints' => array(
			array( 'id'=>'prn-01', 'name'=>'Print 01', 'shirt'=>'black', 'print'=>'A' ),
			array( 'id'=>'prn-02', 'name'=>'Print 02', 'shirt'=>'black', 'print'=>'B' ),
			array( 'id'=>'prn-03', 'name'=>'Print 03', 'shirt'=>'white', 'print'=>'C' ),
			array( 'id'=>'prn-04', 'name'=>'Print 04', 'shirt'=>'black', 'print'=>'D' ),
			array( 'id'=>'prn-05', 'name'=>'Print 05', 'shirt'=>'white', 'print'=>'E' ),
			array( 'id'=>'prn-06', 'name'=>'Print 06', 'shirt'=>'black', 'print'=>'F' ),
		),
	),
	'dark' => array(
		'label'  => 'Dark Saga',
		'prints' => array(
			array( 'id'=>'prn-07', 'name'=>'Print 07', 'shirt'=>'black', 'print'=>'F' ),
			array( 'id'=>'prn-08', 'name'=>'Print 08', 'shirt'=>'black', 'print'=>'H' ),
			array( 'id'=>'prn-09', 'name'=>'Print 09', 'shirt'=>'black', 'print'=>'B' ),
			array( 'id'=>'prn-10', 'name'=>'Print 10', 'shirt'=>'black', 'print'=>'D' ),
			array( 'id'=>'prn-11', 'name'=>'Print 11', 'shirt'=>'black', 'print'=>'E' ),
			array( 'id'=>'prn-12', 'name'=>'Print 12', 'shirt'=>'black', 'print'=>'A' ),
		),
	),
	'gaming' => array(
		'label'  => 'Gaming',
		'prints' => array(
			array( 'id'=>'prn-13', 'name'=>'Print 13', 'shirt'=>'black', 'print'=>'H' ),
			array( 'id'=>'prn-14', 'name'=>'Print 14', 'shirt'=>'white', 'print'=>'D' ),
			array( 'id'=>'prn-15', 'name'=>'Print 15', 'shirt'=>'black', 'print'=>'C' ),
			array( 'id'=>'prn-16', 'name'=>'Print 16', 'shirt'=>'white', 'print'=>'G' ),
			array( 'id'=>'prn-17', 'name'=>'Print 17', 'shirt'=>'black', 'print'=>'F' ),
			array( 'id'=>'prn-18', 'name'=>'Print 18', 'shirt'=>'white', 'print'=>'B' ),
		),
	),
	'movies' => array(
		'label'  => 'Movie Icons',
		'prints' => array(
			array( 'id'=>'prn-19', 'name'=>'Print 19', 'shirt'=>'black', 'print'=>'A' ),
			array( 'id'=>'prn-20', 'name'=>'Print 20', 'shirt'=>'white', 'print'=>'E' ),
			array( 'id'=>'prn-21', 'name'=>'Print 21', 'shirt'=>'black', 'print'=>'C' ),
			array( 'id'=>'prn-22', 'name'=>'Print 22', 'shirt'=>'black', 'print'=>'G' ),
			array( 'id'=>'prn-23', 'name'=>'Print 23', 'shirt'=>'white', 'print'=>'F' ),
			array( 'id'=>'prn-24', 'name'=>'Print 24', 'shirt'=>'black', 'print'=>'D' ),
		),
	),
);

/* ------------------------------------------------------------------
 * Mock «Готовые сочетания» (быстрый старт) — те же бандлы что
 * на родительской странице, чтобы пользователь мог быстро взять
 * собранный набор без конструктора.
 * ------------------------------------------------------------------ */
$presets = array(
	array(
		'slug'=>'minimalism', 'name'=>'Минимализм',     'cat'=>'Кураторское', 'cat_slug'=>'curated',
		'status'=>'', 'count'=>5, 'price'=>1196, 'old_price'=>1495, 'save'=>20,
		'shirts'=>array(array('black','A'),array('white','E'),array('black','G'),array('white','D')),
	),
	array(
		'slug'=>'dark-aesthetic', 'name'=>'Тёмная эстетика', 'cat'=>'Dark', 'cat_slug'=>'dark',
		'status'=>'hot', 'count'=>5, 'price'=>1196, 'old_price'=>1495, 'save'=>20,
		'shirts'=>array(array('black','F'),array('black','B'),array('black','D'),array('black','H')),
	),
	array(
		'slug'=>'light-balance', 'name'=>'Светлый баланс', 'cat'=>'Light', 'cat_slug'=>'light',
		'status'=>'', 'count'=>5, 'price'=>1196, 'old_price'=>1495, 'save'=>20,
		'shirts'=>array(array('white','C'),array('white','G'),array('white','A'),array('white','E')),
	),
	array(
		'slug'=>'gamer-set', 'name'=>'Геймерский сет', 'cat'=>'Gaming', 'cat_slug'=>'games',
		'status'=>'new', 'count'=>5, 'price'=>1196, 'old_price'=>1495, 'save'=>20,
		'shirts'=>array(array('black','H'),array('white','D'),array('black','C'),array('white','G')),
	),
);

/* Цветовая палитра */
$color_palette = array(
	'black'    => '#0a0a0a',
	'white'    => '#f5f4f0',
	'graphite' => '#444444',
	'beige'    => '#d6c8a8',
);
$color_labels = array(
	'black'    => 'Чёрный',
	'white'    => 'Белый',
	'graphite' => 'Графит',
	'beige'    => 'Бежевый',
);

/* ------------------------------------------------------------------
 * Расчёты на сервере
 * ------------------------------------------------------------------ */
$filled_slots = array_filter( $slots, function ( $s ) { return ! empty( $s['filled'] ); } );
$filled_count = count( $filled_slots );

$step = isset( $pricing[ $filled_count ] ) ? $pricing[ $filled_count ] : array( 'discount' => 0 );
$discount  = (int) $step['discount'];
$gross     = $base_price * $filled_count;
$savings   = (int) round( $gross * $discount / 100 );
$total     = $gross - $savings;

$next_count = $filled_count < 5 ? $filled_count + 1 : null;
$next_disc  = $next_count ? (int) $pricing[ $next_count ]['discount'] : 0;
$to_next    = $next_count ? ( $next_count - $filled_count ) : 0;

/* ------------------------------------------------------------------
 * Helper-функции
 * ------------------------------------------------------------------ */
if ( ! function_exists( 'podval_builder_render_mini_tee' ) ) {
	function podval_builder_render_mini_tee( $shirt, $print, $class ) {
		$shirt = preg_match( '/^(black|white|graphite|beige)$/', $shirt ) ? $shirt : 'black';
		$print = preg_match( '/^[A-H]$/', $print ) ? $print : 'A';
		?>
		<div class="mini-tee <?php echo esc_attr( $class ); ?>" data-shirt="<?php echo $shirt; ?>">
			<svg viewBox="0 0 200 240" class="tee-svg" aria-hidden="true"><use href="#tee"/></svg>
			<svg viewBox="0 0 80 100" class="tee-print" aria-hidden="true"><use href="#prn-<?php echo $print; ?>"/></svg>
		</div>
		<?php
	}
}

if ( ! function_exists( 'podval_builder_render_tnail' ) ) {
	function podval_builder_render_tnail( $shirt, $print ) {
		$shirt = preg_match( '/^(black|white|graphite|beige)$/', $shirt ) ? $shirt : 'black';
		$print = preg_match( '/^[A-H]$/', $print ) ? $print : 'A';
		?>
		<div class="tnail" data-shirt="<?php echo $shirt; ?>">
			<svg viewBox="0 0 200 240" class="tee-svg" aria-hidden="true"><use href="#tee"/></svg>
			<svg viewBox="0 0 80 100" class="tee-print" aria-hidden="true"><use href="#prn-<?php echo $print; ?>"/></svg>
		</div>
		<?php
	}
}

if ( ! function_exists( 'podval_builder_render_preset_card' ) ) {
	function podval_builder_render_preset_card( $b ) {
		$url = '#'; // TODO: home_url('/bundles/' . $b['slug'] . '/')
		$mini_classes = array( 'mini-tee--a', 'mini-tee--b', 'mini-tee--c', 'mini-tee--d' );
		?>
		<a href="<?php echo esc_url( $url ); ?>" class="bcard" data-cat="<?php echo esc_attr( $b['cat_slug'] ); ?>">
			<div class="bcard__visual">
				<?php if ( ! empty( $b['status'] ) ) : ?>
					<span class="bcard__status bcard__status--<?php echo esc_attr( $b['status'] ); ?>">
						<?php echo esc_html( strtoupper( $b['status'] ) ); ?>
					</span>
				<?php endif; ?>
				<?php if ( ! empty( $b['save'] ) ) : ?>
					<span class="bcard__save">−<?php echo (int) $b['save']; ?>%</span>
				<?php endif; ?>
				<div class="bcard-stage">
					<?php foreach ( $b['shirts'] as $i => $s ) : if ( ! isset( $mini_classes[ $i ] ) ) break; ?>
						<?php podval_builder_render_mini_tee( $s[0], $s[1], $mini_classes[ $i ] ); ?>
					<?php endforeach; ?>
				</div>
				<div class="bcard__strip">
					<?php foreach ( $b['shirts'] as $s ) : ?>
						<?php podval_builder_render_tnail( $s[0], $s[1] ); ?>
					<?php endforeach; ?>
				</div>
			</div>
			<div class="bcard__info">
				<div class="bcard__cat"><?php echo esc_html( $b['cat'] ); ?></div>
				<h3 class="bcard__name"><?php echo esc_html( $b['name'] ); ?></h3>
				<div class="bcard__spec"><?php echo (int) $b['count']; ?> футболок · хлопок · DTF</div>
				<div class="bcard__bot">
					<div class="bcard__price">
						<span class="bcard__price-now"><?php echo number_format( $b['price'], 0, '.', ' ' ); ?> BYN</span>
						<?php if ( ! empty( $b['old_price'] ) ) : ?>
							<span class="bcard__price-old"><?php echo number_format( $b['old_price'], 0, '.', ' ' ); ?> BYN</span>
						<?php endif; ?>
					</div>
					<div class="bcard__go">Смотреть →</div>
				</div>
			</div>
		</a>
		<?php
	}
}

/* Найдём set of выбранных принтов — для подсветки is-selected в каталоге */
$selected_print_ids = array();
foreach ( $filled_slots as $s ) {
	if ( ! empty( $s['ref'] ) ) $selected_print_ids[] = $s['ref'];
}

$type_labels = array(
	'oversize' => 'Оверсайз',
	'classic'  => 'Классическая',
);
?>

<?php get_template_part( 'template-parts/bundles-svg-defs' ); ?>

<main class="bundles-page bundle-single-page bundle-builder-page">

	<!-- 1. Breadcrumbs -->
	<div class="chub-container">
		<div class="crumbs">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>">Главная</a>
			<span class="crumb-sep">›</span>
			<a href="<?php echo esc_url( $bundles_url ); ?>">Бандлы</a>
			<span class="crumb-sep">›</span>
			Собери свой бандл
		</div>
	</div>

	<!-- 2. BUILDER HERO -->
	<div class="chub-container">
		<section class="builder-hero">
			<div class="builder-hero__body">
				<div class="builder-hero__label">→ Конструктор бандла</div>
				<h1 class="builder-hero__title">Собери<br>свой бандл</h1>
				<p class="builder-hero__sub">
					Выбери 3–5 футболок, настрой каждую отдельно и&nbsp;получи скидку за&nbsp;комплект.
				</p>
				<ul class="vprops">
					<li class="vprop">
						<svg class="vprop__ic" aria-hidden="true"><use href="#bnd-ic-shirt-sliders"/></svg>
						<div>
							<div class="vprop__t">Настрой каждый принт</div>
							<div class="vprop__s">и&nbsp;цвет отдельно</div>
						</div>
					</li>
					<li class="vprop">
						<svg class="vprop__ic" aria-hidden="true"><use href="#bnd-ic-chart-up"/></svg>
						<div>
							<div class="vprop__t">Скидка растёт</div>
							<div class="vprop__s">с&nbsp;каждой футболкой</div>
						</div>
					</li>
					<li class="vprop">
						<svg class="vprop__ic" aria-hidden="true"><use href="#bnd-ic-percent"/></svg>
						<div>
							<div class="vprop__t">Экономия до&nbsp;20%</div>
							<div class="vprop__s">при 5&nbsp;футболках</div>
						</div>
					</li>
				</ul>
			</div>
			<div class="builder-hero__shirts" aria-hidden="true">
				<div class="hshirt hshirt--1" data-shirt="black">
					<svg viewBox="0 0 200 240" class="tee-svg"><use href="#tee"/></svg>
					<svg viewBox="0 0 80 100" class="tee-print"><use href="#prn-A"/></svg>
				</div>
				<div class="hshirt hshirt--2" data-shirt="white">
					<svg viewBox="0 0 200 240" class="tee-svg"><use href="#tee"/></svg>
					<svg viewBox="0 0 80 100" class="tee-print"><use href="#prn-E"/></svg>
				</div>
				<div class="hshirt hshirt--3" data-shirt="black">
					<svg viewBox="0 0 200 240" class="tee-svg"><use href="#tee"/></svg>
					<svg viewBox="0 0 80 100" class="tee-print"><use href="#prn-B"/></svg>
				</div>
				<div class="hshirt hshirt--4" data-shirt="white">
					<svg viewBox="0 0 200 240" class="tee-svg"><use href="#tee"/></svg>
					<svg viewBox="0 0 80 100" class="tee-print"><use href="#prn-G"/></svg>
				</div>
				<div class="hshirt hshirt--5" data-shirt="black">
					<svg viewBox="0 0 200 240" class="tee-svg"><use href="#tee"/></svg>
					<svg viewBox="0 0 80 100" class="tee-print"><use href="#prn-D"/></svg>
				</div>
			</div>
		</section>
	</div>

	<!-- 3. MAIN BUILDER LAYOUT -->
	<div class="chub-container">
		<div class="builder-grid">

			<!-- LEFT — slots + catalog -->
			<div class="builder-left">

				<!-- ВАШ НАБОР -->
				<section class="slots-section">
					<header class="sect-head sect-head--row">
						<div class="sect-head__l">
							<h2 class="sect-head__title">Ваш набор</h2>
							<span class="sect-head__count" data-slots-count>(<?php echo $filled_count; ?> из 5)</span>
						</div>
						<div class="sect-head__hint sect-head__hint--accent" data-slots-hint<?php echo ( $to_next === 0 ) ? ' hidden' : ''; ?>>
							<?php
							if ( $to_next > 0 ) {
								printf(
									'Добавьте ещё %d %s и&nbsp;получите скидку %d%%',
									(int) $to_next,
									( $to_next === 1 ) ? 'футболку' : 'футболки',
									(int) $next_disc
								);
							}
							?>
						</div>
					</header>

					<div class="slots-grid" data-slots-grid>
						<?php foreach ( $slots as $idx => $slot ) : ?>
							<?php if ( ! empty( $slot['filled'] ) ) : ?>
								<article class="builder-slot" data-slot="<?php echo $idx; ?>" data-print-ref="<?php echo esc_attr( $slot['ref'] ?? '' ); ?>">
									<span class="bi-num"><?php echo $idx; ?></span>
									<button type="button" class="slot-x" aria-label="Удалить">
										<svg aria-hidden="true"><use href="#bnd-ic-x"/></svg>
									</button>
									<div class="slot-shirt" data-shirt="<?php echo esc_attr( $slot['shirt'] ); ?>">
										<svg viewBox="0 0 200 240" class="tee-svg" aria-hidden="true"><use href="#tee"/></svg>
										<svg viewBox="0 0 80 100" class="tee-print" aria-hidden="true"><use href="#prn-<?php echo esc_attr( $slot['print'] ); ?>"/></svg>
									</div>
									<button type="button" class="slot-pickbtn">Выбрать принт</button>
									<div class="bi-row">
										<div class="bi-types">
											<?php foreach ( $type_labels as $type_slug => $type_label ) : ?>
												<button type="button"
												        class="size-btn <?php echo ( $slot['type'] === $type_slug ) ? 'is-active' : ''; ?>"
												        data-type="<?php echo esc_attr( $type_slug ); ?>">
													<?php echo esc_html( $type_label ); ?>
												</button>
											<?php endforeach; ?>
										</div>
									</div>
									<div class="bi-label">Цвет</div>
									<div class="bi-colors">
										<?php foreach ( $color_palette as $color_slug => $hex ) : ?>
											<span class="sw <?php echo ( $slot['color'] === $color_slug ) ? 'is-active' : ''; ?>"
											      style="--c:<?php echo esc_attr( $hex ); ?>"
											      data-color="<?php echo esc_attr( $color_slug ); ?>"
											      title="<?php echo esc_attr( $color_labels[ $color_slug ] ); ?>">
												<i></i>
											</span>
										<?php endforeach; ?>
									</div>
									<div class="bi-label">Размер</div>
									<div class="bi-select"><span><?php echo esc_html( $slot['size'] ); ?></span><svg class="bi-select__chev" aria-hidden="true"><use href="#bnd-ic-chev-d"/></svg></div>
								</article>
							<?php else : ?>
								<article class="builder-slot builder-slot--empty" data-slot="<?php echo $idx; ?>" data-empty>
									<span class="bi-num bi-num--empty"><?php echo $idx; ?></span>
									<div class="slot-empty">
										<svg class="slot-empty__plus" aria-hidden="true"><use href="#bnd-ic-plus"/></svg>
										<div class="slot-empty__t">Добавить футболку</div>
									</div>
								</article>
							<?php endif; ?>
						<?php endforeach; ?>
					</div>

					<div class="slots-note">
						<svg class="slots-note__ic" aria-hidden="true"><use href="#bnd-ic-info-mini"/></svg>
						<span>Доступные цвета и&nbsp;типы могут отличаться для&nbsp;каждого принта</span>
					</div>
				</section>

				<!-- КАТАЛОГ ПРИНТОВ -->
				<section class="catalog-section">
					<header class="sect-head sect-head--row">
						<h2 class="sect-head__title">Каталог принтов</h2>
						<div class="search-input">
							<svg class="search-input__ic" aria-hidden="true"><use href="#bnd-ic-search"/></svg>
							<input type="text" placeholder="Поиск принта" data-catalog-search>
						</div>
					</header>

					<div class="catalog-pills" data-catalog-pills>
						<button type="button" class="chub-pill is-active" data-filter="all">Все принты</button>
						<button type="button" class="chub-pill chub-pill--drop" data-filter="theme">Тема <svg aria-hidden="true"><use href="#bnd-ic-chev-d"/></svg></button>
						<button type="button" class="chub-pill chub-pill--drop" data-filter="color">Цвет <svg aria-hidden="true"><use href="#bnd-ic-chev-d"/></svg></button>
						<button type="button" class="chub-pill chub-pill--drop" data-filter="type">Тип футболки <svg aria-hidden="true"><use href="#bnd-ic-chev-d"/></svg></button>
						<button type="button" class="chub-pill" data-filter="new">Новинки</button>
						<button type="button" class="chub-pill" data-filter="sale">Скидка</button>
					</div>

					<?php foreach ( $catalog_themes as $theme_slug => $theme ) : ?>
						<div class="theme" data-theme="<?php echo esc_attr( $theme_slug ); ?>">
							<div class="theme__head">
								<h3 class="theme__h"><?php echo esc_html( $theme['label'] ); ?></h3>
								<a href="#" class="theme__all">Смотреть все →</a>
							</div>
							<div class="prn-grid">
								<?php foreach ( $theme['prints'] as $p ) :
									$is_selected = in_array( $p['id'], $selected_print_ids, true );
								?>
									<div class="print-card <?php echo $is_selected ? 'is-selected' : ''; ?>"
									     data-shirt="<?php echo esc_attr( $p['shirt'] ); ?>"
									     data-print-id="<?php echo esc_attr( $p['id'] ); ?>"
									     data-print="<?php echo esc_attr( $p['print'] ); ?>">
										<?php if ( $is_selected ) : ?>
											<div class="print-card__check" aria-hidden="true">
												<svg><use href="#bnd-ic-check"/></svg>
											</div>
										<?php endif; ?>
										<div class="pc-stage">
											<svg viewBox="0 0 200 240" class="tee-svg" aria-hidden="true"><use href="#tee"/></svg>
											<svg viewBox="0 0 80 100" class="tee-print" aria-hidden="true"><use href="#prn-<?php echo esc_attr( $p['print'] ); ?>"/></svg>
										</div>
										<div class="pc-cap"><?php echo esc_html( $p['name'] ); ?></div>
									</div>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endforeach; ?>

					<button type="button" class="btn btn--ghost btn--lg btn--full catalog-more">
						Показать ещё принты
						<svg class="btn-ic" aria-hidden="true"><use href="#bnd-ic-arrow-d"/></svg>
					</button>
				</section>
			</div>

			<!-- RIGHT — sticky summary -->
			<aside class="builder-right">
				<div class="summary">
					<header class="summary__head">
						<h3 class="summary__title">Ваш бандл</h3>
						<div class="summary__sub" data-summary-sub><?php echo $filled_count; ?> из 5 футболок</div>
					</header>

					<!-- Discount progress 1→5 -->
					<div class="progress">
						<div class="progress__steps">
							<?php
							$steps_array = array_keys( $pricing );
							foreach ( $steps_array as $i => $step_num ) :
								$state = ( $step_num < $filled_count ) ? 'past'
									: ( ( $step_num === $filled_count ) ? 'current' : 'locked' );
								$pct   = (int) $pricing[ $step_num ]['discount'];
							?>
								<div class="pstep pstep--<?php echo $state; ?>" data-pstep="<?php echo $step_num; ?>">
									<span class="pstep__dot"><?php echo $step_num; ?></span>
									<?php if ( $state === 'current' && $pct > 0 ) : ?>
										<span class="pstep__pill">−<?php echo $pct; ?>%</span>
									<?php else : ?>
										<span class="pstep__pct"><?php echo $pct > 0 ? '−' . $pct . '%' : '0%'; ?></span>
									<?php endif; ?>
								</div>
								<?php if ( $i < count( $steps_array ) - 1 ) :
									$line_on = ( $step_num < $filled_count );
								?>
									<span class="pline pline--<?php echo $line_on ? 'on' : 'off'; ?>"></span>
								<?php endif; ?>
							<?php endforeach; ?>
						</div>
						<div class="progress__cap" data-progress-cap<?php echo ( $to_next === 0 ) ? ' hidden' : ''; ?>>
							<?php
							if ( $to_next > 0 ) {
								printf(
									'Добавьте ещё %d %s и&nbsp;получите скидку %d%%',
									(int) $to_next,
									( $to_next === 1 ) ? 'футболку' : 'футболки',
									(int) $next_disc
								);
							}
							?>
						</div>
						<div class="progress__sub">Скидка применяется автоматически</div>
					</div>

					<!-- Item list -->
					<ul class="items" data-items>
						<?php foreach ( $slots as $idx => $slot ) : ?>
							<?php if ( ! empty( $slot['filled'] ) ) : ?>
								<li class="item" data-item-slot="<?php echo $idx; ?>">
									<span class="item__num"><?php echo $idx; ?></span>
									<div class="item__shirt" data-shirt="<?php echo esc_attr( $slot['shirt'] ); ?>">
										<svg viewBox="0 0 200 240" class="tee-svg" aria-hidden="true"><use href="#tee"/></svg>
										<svg viewBox="0 0 80 100" class="tee-print" aria-hidden="true"><use href="#prn-<?php echo esc_attr( $slot['print'] ); ?>"/></svg>
									</div>
									<div class="item__body">
										<?php
										// имя из ref превращаем в "Print 01" а не "PRN 01"
										// ref имеет вид prn-01, prn-07, prn-12; нужно вытащить число и подставить
										$item_name = 'Print ' . sprintf( '%02d', $idx );
										if ( ! empty( $slot['ref'] ) && preg_match( '/(\d+)/', $slot['ref'], $m ) ) {
											$item_name = 'Print ' . sprintf( '%02d', (int) $m[1] );
										}
										?>
										<h4 class="item__name"><?php echo esc_html( $item_name ); ?></h4>
										<div class="item__specs">
											<span>Тип: <?php echo esc_html( $type_labels[ $slot['type'] ] ?? '' ); ?></span>
											<span>Цвет: <?php echo esc_html( $color_labels[ $slot['color'] ] ?? '' ); ?></span>
											<span>Размер: <?php echo esc_html( $slot['size'] ); ?></span>
										</div>
									</div>
									<button type="button" class="item__x" aria-label="Удалить">
										<svg aria-hidden="true"><use href="#bnd-ic-x"/></svg>
									</button>
								</li>
							<?php else : ?>
								<li class="item item--empty" data-item-slot="<?php echo $idx; ?>">
									<span class="item__num"><?php echo $idx; ?></span>
									<span class="item__plus" aria-hidden="true"><svg><use href="#bnd-ic-plus"/></svg></span>
									<span class="item__empty-t">Добавить футболку</span>
								</li>
							<?php endif; ?>
						<?php endforeach; ?>
					</ul>

					<!-- Totals -->
					<div class="totals">
						<div class="totals__row">
							<span>Цена за&nbsp;единицу</span>
							<span><?php echo number_format( $base_price, 0, '.', ' ' ); ?> BYN</span>
						</div>
						<div class="totals__row">
							<span>Товары (<span data-totals-count><?php echo $filled_count; ?></span>)</span>
							<span data-totals-gross><?php echo number_format( $gross, 0, '.', ' ' ); ?> BYN</span>
						</div>
						<div class="totals__row totals__row--discount" data-totals-discount<?php echo ( $savings === 0 ) ? ' hidden' : ''; ?>>
							<span>Скидка (<span data-totals-disc-pct><?php echo (int) $discount; ?></span>%)</span>
							<span class="dn">−<span data-totals-savings><?php echo number_format( $savings, 0, '.', ' ' ); ?></span> BYN</span>
						</div>
						<div class="totals__sep"></div>
						<div class="totals__row totals__row--total">
							<span>Итого</span>
							<span class="total-val" data-totals-final><?php echo number_format( $total, 0, '.', ' ' ); ?> BYN</span>
						</div>
						<div class="totals__save" data-totals-save<?php echo ( $savings === 0 ) ? ' hidden' : ''; ?>>
							<svg class="totals__save-ic" aria-hidden="true"><use href="#bnd-ic-info-mini"/></svg>
							Экономия <span data-totals-savings-2><?php echo number_format( $savings, 0, '.', ' ' ); ?></span> BYN
						</div>
					</div>

					<button type="button" class="btn btn--primary btn--lg btn--full" data-add-to-cart>
						<svg class="btn-ic" aria-hidden="true"><use href="#bnd-ic-cart"/></svg>
						Добавить в корзину
					</button>
					<button type="button" class="btn btn--ghost btn--lg btn--full">
						<svg class="btn-ic" aria-hidden="true"><use href="#bnd-ic-heart"/></svg>
						Сохранить набор
					</button>
					<a href="#" class="share-link">
						<svg class="share-link__ic" aria-hidden="true"><use href="#bnd-ic-share"/></svg>
						Поделиться набором
					</a>
				</div>
			</aside>

		</div>
	</div>

	<!-- 4. БЫСТРЫЙ СТАРТ — готовые сочетания -->
	<div class="chub-container">
		<header class="sect-head sect-head--row">
			<h2 class="sect-head__title">Быстрый старт — готовые сочетания</h2>
			<div class="carousel-ctrls">
				<button type="button" class="carbtn" aria-label="Назад"><svg aria-hidden="true"><use href="#bnd-ic-arrow-l"/></svg></button>
				<button type="button" class="carbtn" aria-label="Вперёд"><svg aria-hidden="true"><use href="#bnd-ic-arrow"/></svg></button>
			</div>
		</header>

		<div class="similar-rail">
			<div class="similar-track">
				<?php foreach ( $presets as $p ) : ?>
					<?php podval_builder_render_preset_card( $p ); ?>
				<?php endforeach; ?>
				<?php /* peek-card на краю */ ?>
				<article class="bcard bcard--peek" aria-hidden="true">
					<div class="bcard__visual">
						<span class="bcard__save">−14%</span>
						<div class="bcard-stage">
							<?php podval_builder_render_mini_tee( 'black', 'E', 'mini-tee--a' ); ?>
							<?php podval_builder_render_mini_tee( 'white', 'D', 'mini-tee--b' ); ?>
						</div>
						<div class="bcard__strip"></div>
					</div>
					<div class="bcard__info">
						<div class="bcard__cat">Graphic</div>
						<h3 class="bcard__name">Raw Lines</h3>
					</div>
				</article>
			</div>
		</div>
	</div>

	<!-- 5. BENEFITS -->
	<div class="chub-container">
		<div class="benefits">
			<div class="benefit">
				<svg class="benefit__icon" aria-hidden="true"><use href="#bnd-ic-cotton"/></svg>
				<div>
					<div class="benefit__title">100% хлопок</div>
					<div class="benefit__sub">Мягкая и&nbsp;дышащая ткань премиального качества</div>
				</div>
			</div>
			<div class="benefit">
				<svg class="benefit__icon" aria-hidden="true"><use href="#bnd-ic-printer"/></svg>
				<div>
					<div class="benefit__title">Стойкая DTF-печать</div>
					<div class="benefit__sub">Яркие принты, которые не&nbsp;трескаются и&nbsp;не&nbsp;выцветают</div>
				</div>
			</div>
			<div class="benefit">
				<svg class="benefit__icon" aria-hidden="true"><use href="#bnd-ic-ruler"/></svg>
				<div>
					<div class="benefit__title">Свои размеры</div>
					<div class="benefit__sub">Размер каждой футболки выбираешь сам</div>
				</div>
			</div>
			<div class="benefit">
				<svg class="benefit__icon" aria-hidden="true"><use href="#bnd-ic-factory"/></svg>
				<div>
					<div class="benefit__title">Собственное производство</div>
					<div class="benefit__sub">Контроль качества на&nbsp;каждом этапе</div>
				</div>
			</div>
		</div>
	</div>

	<!-- Sticky bottom bar (mobile) -->
	<div class="m-stickybar" data-sticky-bar aria-hidden="true">
		<div class="m-stickybar__price">
			<div class="m-stickybar__sub" data-sticky-count><?php echo $filled_count; ?>/5 футболок</div>
			<div>
				<span class="m-stickybar__now" data-sticky-total><?php echo number_format( $total, 0, '.', ' ' ); ?> BYN</span>
				<?php if ( $savings > 0 ) : ?>
					<s class="m-stickybar__old" data-sticky-gross><?php echo number_format( $gross, 0, '.', ' ' ); ?> BYN</s>
				<?php endif; ?>
			</div>
		</div>
		<button type="button" class="btn btn--primary btn--md" data-add-to-cart>
			<svg class="btn-ic" aria-hidden="true"><use href="#bnd-ic-cart"/></svg>
			В корзину
		</button>
	</div>

</main>

<script>
/* Bundle builder — клиентское взаимодействие.
   На MVP не отправляет ничего на сервер (нет CPT podval_bundle ещё).
   Реализована визуальная сторона:
   - выбор Тип/Цвет (toggle is-active)
   - удаление футболки из слота (× button)
   - sticky bottom bar появляется при скролле мимо hero (mobile)
   Полный recalc цены/прогресса/таймлайна делаем когда добавим
   функционал «выбрать принт из каталога → добавить в слот».
   Для статического превью этого достаточно. */
(function(){
	var page = document.querySelector('.bundle-builder-page');
	if (!page) return;

	// Toggle Тип
	page.querySelectorAll('.bi-types').forEach(function(group){
		group.addEventListener('click', function(e){
			var btn = e.target.closest('.size-btn');
			if (!btn || btn.disabled) return;
			group.querySelectorAll('.size-btn').forEach(function(b){ b.classList.remove('is-active'); });
			btn.classList.add('is-active');
		});
	});

	// Toggle Цвет
	page.querySelectorAll('.bi-colors').forEach(function(group){
		group.addEventListener('click', function(e){
			var sw = e.target.closest('.sw');
			if (!sw || sw.classList.contains('is-disabled')) return;
			group.querySelectorAll('.sw').forEach(function(s){ s.classList.remove('is-active'); });
			sw.classList.add('is-active');
		});
	});

	// Удаление слота — пока только UI (TODO: пересчёт)
	page.querySelectorAll('.slot-x, .item__x').forEach(function(btn){
		btn.addEventListener('click', function(e){
			e.preventDefault();
			alert('TODO: удаление слота с пересчётом скидки — добавится в следующей итерации');
		});
	});

	// Pill click — пока подсветка
	var pills = page.querySelector('[data-catalog-pills]');
	if (pills) {
		pills.addEventListener('click', function(e){
			var b = e.target.closest('.chub-pill');
			if (!b) return;
			pills.querySelectorAll('.chub-pill').forEach(function(p){ p.classList.remove('is-active'); });
			b.classList.add('is-active');
		});
	}

	// Add to cart — заглушка
	page.querySelectorAll('[data-add-to-cart]').forEach(function(btn){
		btn.addEventListener('click', function(e){
			e.preventDefault();
			alert('TODO: интеграция с корзиной WC (CPT podval_bundle и AJAX-хук — следующая итерация)');
		});
	});

	// Sticky bottom bar (mobile)
	(function(){
		var bar = page.querySelector('[data-sticky-bar]');
		var hero = page.querySelector('.builder-hero');
		if (!bar || !hero || !('IntersectionObserver' in window)) return;
		var obs = new IntersectionObserver(function(entries){
			entries.forEach(function(e){
				bar.classList.toggle('is-visible', !e.isIntersecting);
				bar.setAttribute('aria-hidden', e.isIntersecting ? 'true' : 'false');
			});
		}, { rootMargin: '-100px 0px 0px 0px' });
		obs.observe(hero);
	})();
})();
</script>

<?php
get_footer();

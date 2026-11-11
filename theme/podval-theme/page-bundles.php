<?php
/**
 * Template Name: Наборы (Bundles)
 *
 * Родительская landing-страница раздела «Наборы футболок».
 * На старте использует mock-данные ($bundles ниже), потому что
 * товаров-бандлов в БД ещё нет. Когда они появятся — переключим
 * на WP_Query / WC product type.
 *
 * URL: /bundles/ (slug страницы можно задать в wp-admin)
 *
 * Стили:  assets/css/style-bundles.css (условный enqueue в functions.php)
 * SVG:    template-parts/bundles-svg-defs.php (inline)
 *
 * @package podval-print
 */
defined( 'ABSPATH' ) || exit;

/* ------------------------------------------------------------------
 * Гейт публичности
 * Раздел бандлов скрыт от гостей, пока podval_bundles_public = OFF.
 * Админам и редакторам листинг доступен (для проверки), остальным —
 * 404. Single-страницы бандлов остаются доступны по прямой ссылке.
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
 * Хелперы темы и контактные данные
 * ------------------------------------------------------------------ */
$shop_url  = function_exists( 'podval_get_catalog_url' ) ? podval_get_catalog_url() : home_url( '/' );

/* ------------------------------------------------------------------
 * Hero — настройки из Customizer
 * Дефолты намеренно совпадают с прежним хардкодом — без визуальной
 * регрессии. Управление: Внешний вид → Настроить → 🛒 Podval Print
 * → «Бандлы — Hero».
 * ------------------------------------------------------------------ */
$hero_label     = get_theme_mod( 'podval_bundles_hero_label',     '→ Новый раздел' );
$hero_title     = get_theme_mod( 'podval_bundles_hero_title',     'Наборы<br>футболок' );
$hero_sub       = get_theme_mod( 'podval_bundles_hero_sub',       '' );
$hero_cta_text  = get_theme_mod( 'podval_bundles_hero_cta_text',  'Смотреть наборы' );
$hero_cta_url   = get_theme_mod( 'podval_bundles_hero_cta_url',   '#bundles-grid' );
$hero_badge_num = get_theme_mod( 'podval_bundles_hero_badge_num', '−15%' );
$hero_badge_sub = get_theme_mod( 'podval_bundles_hero_badge_sub', 'за комплект' );
$hero_image_id  = (int) get_theme_mod( 'podval_bundles_hero_image', 0 );
$hero_image_url = $hero_image_id ? wp_get_attachment_image_url( $hero_image_id, 'full' ) : '';
$hero_image_alt = $hero_image_id ? (string) get_post_meta( $hero_image_id, '_wp_attachment_image_alt', true ) : '';

/* ------------------------------------------------------------------
 * Бандлы из CPT podval_bundle.
 * Структура каждого элемента $bundles — см. podval_bundle_to_array().
 * Список pills — все термы таксономии bundle_theme + «Все».
 * ------------------------------------------------------------------ */
$bundles_query = podval_bundle_get_query();
$bundles       = array();
if ( $bundles_query->have_posts() ) {
	foreach ( $bundles_query->posts as $bp ) {
		$b = podval_bundle_to_array( $bp );
		if ( ! empty( $b ) ) {
			$bundles[] = $b;
		}
	}
}
wp_reset_postdata();

$pills = podval_bundle_get_pills();

/* ------------------------------------------------------------------
 * Helper-функции для рендера
 * ------------------------------------------------------------------ */
if ( ! function_exists( 'podval_bundles_render_tee_svg' ) ) {
	/**
	 * Рендерит одну мини-футболку в featured-композиции.
	 * Если у WC product есть картинка — выводит <img>, иначе SVG-композицию.
	 *
	 * @param array  $shirt_data  Slot из $bundle['shirts']: {image, shirt, print, name, ...}
	 * @param string $class       Доп. класс для позиционирования (mini-tee--a etc.)
	 */
	function podval_bundles_render_tee_svg( $shirt_data, $class ) {
		$class      = esc_attr( $class );
		$image      = isset( $shirt_data['image'] ) ? (string) $shirt_data['image'] : '';
		$name       = isset( $shirt_data['name'] )  ? (string) $shirt_data['name']  : '';
		$shirt_kind = ( isset( $shirt_data['shirt'] ) && $shirt_data['shirt'] === 'white' ) ? 'white' : 'black';
		$print      = isset( $shirt_data['print'] ) && preg_match( '/^[A-H]$/', $shirt_data['print'] ) ? $shirt_data['print'] : 'A';

		if ( $image !== '' ) {
			?>
			<div class="mini-tee mini-tee--img <?php echo $class; ?>">
				<img src="<?php echo esc_url( $image ); ?>" alt="<?php echo esc_attr( $name ); ?>" loading="lazy">
			</div>
			<?php
		} else {
			?>
			<div class="mini-tee <?php echo $class; ?>" data-shirt="<?php echo $shirt_kind; ?>">
				<svg viewBox="0 0 200 240" class="tee-svg" aria-hidden="true"><use href="#tee"/></svg>
				<svg viewBox="0 0 80 100" class="tee-print" aria-hidden="true"><use href="#prn-<?php echo $print; ?>"/></svg>
			</div>
			<?php
		}
	}
}

if ( ! function_exists( 'podval_bundles_render_tnail' ) ) {
	/**
	 * Рендерит одну мини-превью для нижней полоски карточки.
	 *
	 * @param array $shirt_data  Slot из $bundle['shirts']
	 */
	function podval_bundles_render_tnail( $shirt_data ) {
		$image      = isset( $shirt_data['image'] ) ? (string) $shirt_data['image'] : '';
		$name       = isset( $shirt_data['name'] )  ? (string) $shirt_data['name']  : '';
		$shirt_kind = ( isset( $shirt_data['shirt'] ) && $shirt_data['shirt'] === 'white' ) ? 'white' : 'black';
		$print      = isset( $shirt_data['print'] ) && preg_match( '/^[A-H]$/', $shirt_data['print'] ) ? $shirt_data['print'] : 'A';

		if ( $image !== '' ) {
			?>
			<div class="tnail tnail--img">
				<img src="<?php echo esc_url( $image ); ?>" alt="<?php echo esc_attr( $name ); ?>" loading="lazy">
			</div>
			<?php
		} else {
			?>
			<div class="tnail" data-shirt="<?php echo $shirt_kind; ?>">
				<svg viewBox="0 0 200 240" class="tee-svg" aria-hidden="true"><use href="#tee"/></svg>
				<svg viewBox="0 0 80 100" class="tee-print" aria-hidden="true"><use href="#prn-<?php echo $print; ?>"/></svg>
			</div>
			<?php
		}
	}
}

if ( ! function_exists( 'podval_bundles_render_card' ) ) {
	/**
	 * Рендерит основную карточку бандла (для .chub-grid).
	 */
	function podval_bundles_render_card( $b ) {
		$url = ! empty( $b['url'] ) ? $b['url'] : '#';

		$mini_classes = array( 'mini-tee--ga', 'mini-tee--gb', 'mini-tee--gc', 'mini-tee--gd' );
		$shirts_count = count( $b['shirts'] );
		$stage_extra  = ( $shirts_count === 3 ) ? ' is-3' : '';
		$has_cover    = ! empty( $b['thumbnail'] );
		?>
		<a href="<?php echo esc_url( $url ); ?>"
		   class="bcard<?php echo $has_cover ? ' bcard--cover' : ''; ?>"
		   data-cat="<?php echo esc_attr( $b['cat_slug'] ); ?>"
		   aria-label="<?php echo esc_attr( $b['name'] ); ?>">
			<div class="bcard__visual">
				<?php if ( ! empty( $b['status'] ) ) : ?>
					<span class="bcard__status bcard__status--<?php echo esc_attr( $b['status'] ); ?>">
						<?php echo esc_html( strtoupper( $b['status'] ) ); ?>
					</span>
				<?php endif; ?>
				<?php if ( ! empty( $b['save'] ) ) : ?>
					<span class="bcard__save">−<?php echo (int) $b['save']; ?>%</span>
				<?php endif; ?>

				<?php if ( $has_cover ) : ?>
					<div class="bcard__cover">
						<img src="<?php echo esc_url( $b['thumbnail'] ); ?>" alt="<?php echo esc_attr( $b['thumbnail_alt'] ?? $b['name'] ); ?>" loading="lazy">
					</div>
				<?php else : ?>
					<div class="bcard-stage<?php echo $stage_extra; ?>">
						<?php
						foreach ( $b['shirts'] as $i => $shirt_data ) {
							if ( ! isset( $mini_classes[ $i ] ) ) {
								break;
							}
							podval_bundles_render_tee_svg( $shirt_data, $mini_classes[ $i ] );
						}
						?>
					</div>
				<?php endif; ?>

				<div class="bcard__strip">
					<?php
					foreach ( $b['shirts'] as $shirt_data ) {
						podval_bundles_render_tnail( $shirt_data );
					}
					?>
				</div>
			</div>

			<div class="bcard__info">
				<div class="bcard__cat"><?php echo esc_html( $b['cat'] ); ?></div>
				<h3 class="bcard__name"><?php echo esc_html( $b['name'] ); ?></h3>
				<div class="bcard__spec">
					<?php echo esc_html( $b['subtitle'] ); ?>
				</div>
				<div class="bcard__bot">
					<div class="bcard__price">
						<span class="bcard__price-now">от <span data-price-byn="<?php echo (int) $b['price']; ?>"><?php echo number_format( $b['price'], 2, ',', '' ); ?>&nbsp;Br</span></span>
						<?php if ( ! empty( $b['old_price'] ) && $b['old_price'] > $b['price'] ) : ?>
							<span class="bcard__price-old" data-price-byn="<?php echo (int) $b['old_price']; ?>"><?php echo number_format( $b['old_price'], 2, ',', '' ); ?>&nbsp;Br</span>
						<?php endif; ?>
					</div>
					<div class="bcard__go">Смотреть →</div>
				</div>
			</div>
		</a>
		<?php
	}
}

if ( ! function_exists( 'podval_bundles_render_featured_inner' ) ) {
	/**
	 * Рендерит внутренности featured-карточки (stage + body).
	 * Используется для tall / wide / small вариантов.
	 *
	 * @param array  $b     Бандл
	 * @param string $stage 'tall' | 'wide' | 'small'
	 */
	function podval_bundles_render_featured_inner( $b, $stage ) {
		$count     = count( $b['shirts'] );
		$has_cover = ! empty( $b['thumbnail'] );

		if ( $stage === 'wide' ) {
			$stage_class  = 'fcard-stage fcard-stage--wide';
			$mini_classes = array( 'mini-tee--wa', 'mini-tee--wb', 'mini-tee--wc', 'mini-tee--wd' );
		} elseif ( $stage === 'small' ) {
			$stage_class  = 'fcard-stage fcard-stage--small';
			// для small карточки берём первые 3 футболки в композицию
			$mini_classes = array( 'mini-tee--sa', 'mini-tee--sb', 'mini-tee--sc' );
		} else {
			$stage_class  = 'fcard-stage';
			$mini_classes = array( 'mini-tee--a', 'mini-tee--b', 'mini-tee--c', 'mini-tee--d' );
		}
		?>
		<div class="<?php echo esc_attr( $stage_class . ( $has_cover ? ' fcard-stage--cover' : '' ) ); ?>">
			<?php if ( $has_cover ) : ?>
				<img class="fcard-stage__cover" src="<?php echo esc_url( $b['thumbnail'] ); ?>" alt="<?php echo esc_attr( $b['thumbnail_alt'] ?? $b['name'] ); ?>" loading="lazy">
			<?php else :
				foreach ( $b['shirts'] as $i => $shirt_data ) {
					if ( ! isset( $mini_classes[ $i ] ) ) {
						break;
					}
					podval_bundles_render_tee_svg( $shirt_data, $mini_classes[ $i ] );
				}
				// для wide-mystery бандла добавим декоративный «?» поверх
				if ( $stage === 'wide' && $b['cat_slug'] === 'mystery' ) {
					echo '<div class="mystery-mark" aria-hidden="true">?</div>';
				}
			endif; ?>
		</div>
		<div class="chub-fcard__over"></div>
		<div class="chub-fcard__body<?php echo ( $stage === 'wide' ) ? ' chub-fcard__body--wide' : ''; ?>">
			<?php if ( $stage === 'wide' ) : ?>
				<div>
					<span class="fcat"><?php echo esc_html( $b['cat'] ); ?></span>
					<h3 class="fname"><?php echo esc_html( $b['name'] ); ?></h3>
					<div class="fmeta">
						<?php echo esc_html( $b['subtitle'] ); ?> · <span data-price-byn="<?php echo (int) $b['price']; ?>"><?php echo number_format( $b['price'], 2, ',', '' ); ?>&nbsp;Br</span>
					</div>
				</div>
				<div class="fcard-cta">Собрать →</div>
			<?php else : ?>
				<span class="fcat"><?php echo esc_html( $b['cat'] ); ?></span>
				<h3 class="fname<?php echo ( $stage === 'small' ) ? ' fname--sm' : ''; ?>">
					<?php echo esc_html( $b['name'] ); ?>
				</h3>
				<div class="fmeta">
					<?php echo esc_html( $b['subtitle'] ); ?> · от <span data-price-byn="<?php echo (int) $b['price']; ?>"><?php echo number_format( $b['price'], 2, ',', '' ); ?>&nbsp;Br</span>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}

/* ------------------------------------------------------------------
 * Разделение бандлов: featured и обычные
 * ------------------------------------------------------------------ */
$featured = array_values( array_filter( $bundles, function ( $b ) { return ! empty( $b['featured'] ); } ) );
$rest     = array_values( array_filter( $bundles, function ( $b ) { return empty( $b['featured'] ); } ) );

// Раскладываем featured по слотам: 2 tall + 1 wide + до 3 small
$featured_tall  = array_values( array_filter( $featured, function ( $b ) { return $b['stage'] === 'tall'; } ) );
$featured_wide  = array_values( array_filter( $featured, function ( $b ) { return $b['stage'] === 'wide'; } ) );
$featured_small = array_values( array_filter( $featured, function ( $b ) { return $b['stage'] === 'small'; } ) );

$total_count = count( $bundles );
?>

<?php /* SVG defs — нужны для всех композиций ниже */ ?>
<?php get_template_part( 'template-parts/bundles-svg-defs' ); ?>

<main class="bundles-page">

	<!-- Breadcrumbs -->
	<div class="chub-container">
		<div class="crumbs">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>">Главная</a> — Наборы футболок
		</div>
	</div>

	<!-- 1. HERO -->
	<div class="chub-container">
		<section class="bundles-hero<?php echo $hero_image_url ? ' bundles-hero--with-image' : ''; ?>">
			<?php if ( $hero_image_url ) : ?>
				<div class="bundles-hero__bg bundles-hero__bg--image" aria-hidden="true">
					<img src="<?php echo esc_url( $hero_image_url ); ?>" alt="<?php echo esc_attr( $hero_image_alt ); ?>" loading="eager" fetchpriority="high">
				</div>
			<?php else : ?>
				<div class="bundles-hero__bg" aria-hidden="true">
					<div class="hero-tee hero-tee--1" data-shirt="black">
						<svg viewBox="0 0 200 240" class="tee-svg"><use href="#tee"/></svg>
						<svg viewBox="0 0 80 100" class="tee-print"><use href="#prn-A"/></svg>
					</div>
					<div class="hero-tee hero-tee--2" data-shirt="white">
						<svg viewBox="0 0 200 240" class="tee-svg"><use href="#tee"/></svg>
						<svg viewBox="0 0 80 100" class="tee-print"><use href="#prn-E"/></svg>
					</div>
					<div class="hero-tee hero-tee--3" data-shirt="black">
						<svg viewBox="0 0 200 240" class="tee-svg"><use href="#tee"/></svg>
						<svg viewBox="0 0 80 100" class="tee-print"><use href="#prn-C"/></svg>
					</div>
					<div class="hero-tee hero-tee--4" data-shirt="white">
						<svg viewBox="0 0 200 240" class="tee-svg"><use href="#tee"/></svg>
						<svg viewBox="0 0 80 100" class="tee-print"><use href="#prn-G"/></svg>
					</div>
				</div>
			<?php endif; ?>
			<div class="bundles-hero__overlay" aria-hidden="true"></div>
			<div class="bundles-hero__body">
				<?php if ( $hero_label ) : ?>
					<div class="bundles-hero__label"><?php echo esc_html( $hero_label ); ?></div>
				<?php endif; ?>
				<h1 class="bundles-hero__title"><?php echo wp_kses_post( $hero_title ); ?></h1>
				<?php if ( $hero_sub ) : ?>
					<p class="bundles-hero__sub"><?php echo wp_kses_post( $hero_sub ); ?></p>
				<?php endif; ?>
				<?php if ( $hero_cta_text && $hero_cta_url ) : ?>
					<div class="bundles-hero__ctas">
						<a href="<?php echo esc_attr( $hero_cta_url ); ?>" class="chub-hero__btn">
							<?php echo esc_html( $hero_cta_text ); ?>
							<svg class="btn-arrow"><use href="#bnd-ic-arrow"/></svg>
						</a>
					</div>
				<?php endif; ?>
			</div>
			<?php if ( $hero_badge_num ) : ?>
				<div class="bundles-hero__discount" aria-hidden="true">
					<span><?php echo esc_html( $hero_badge_num ); ?></span>
					<?php if ( $hero_badge_sub ) : ?>
						<small><?php echo esc_html( $hero_badge_sub ); ?></small>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</section>
	</div>

	<!-- 2. TOOLBAR + PILLS -->
	<div class="chub-container">
		<div class="chub-toolbar">
			<div class="chub-toolbar__left">
				<span class="chub-toolbar__line"></span>
				<h2 class="chub-toolbar__title">Все наборы</h2>
			</div>
			<?php if ( count( $pills ) > 1 ) : ?>
			<div class="chub-pills" role="tablist" data-bundles-pills>
				<?php $first = true; foreach ( $pills as $slug => $label ) : ?>
					<button type="button"
					        class="chub-pill<?php echo $first ? ' is-active' : ''; ?>"
					        data-filter="<?php echo esc_attr( $slug ); ?>">
						<?php echo esc_html( $label ); ?>
					</button>
				<?php $first = false; endforeach; ?>
			</div>
			<?php endif; ?>
		</div>
	</div>

	<!-- 3. FEATURED -->
	<?php if ( ! empty( $featured ) ) : ?>
	<div class="chub-container">
		<div class="chub-featured">

			<?php /* левая tall */ ?>
			<?php if ( isset( $featured_tall[0] ) ) : $b = $featured_tall[0]; ?>
				<a href="<?php echo esc_url( $b['url'] ); ?>" class="chub-fcard chub-fcard--tall" data-cat="<?php echo esc_attr( $b['cat_slug'] ); ?>">
					<?php podval_bundles_render_featured_inner( $b, 'tall' ); ?>
				</a>
			<?php endif; ?>

			<?php /* центральная tall */ ?>
			<?php if ( isset( $featured_tall[1] ) ) : $b = $featured_tall[1]; ?>
				<a href="<?php echo esc_url( $b['url'] ); ?>" class="chub-fcard chub-fcard--tall" data-cat="<?php echo esc_attr( $b['cat_slug'] ); ?>">
					<?php podval_bundles_render_featured_inner( $b, 'tall' ); ?>
				</a>
			<?php endif; ?>

			<?php /* правая колонка: wide + row из 3 small */ ?>
			<div class="chub-featured__right">
				<?php if ( isset( $featured_wide[0] ) ) : $b = $featured_wide[0]; ?>
					<a href="<?php echo esc_url( $b['url'] ); ?>" class="chub-fcard chub-fcard--wide" data-cat="<?php echo esc_attr( $b['cat_slug'] ); ?>">
						<?php podval_bundles_render_featured_inner( $b, 'wide' ); ?>
					</a>
				<?php endif; ?>

				<div class="chub-featured__row">
					<?php foreach ( array_slice( $featured_small, 0, 3 ) as $b ) : ?>
						<a href="<?php echo esc_url( $b['url'] ); ?>" class="chub-fcard chub-fcard--small" data-cat="<?php echo esc_attr( $b['cat_slug'] ); ?>">
							<?php podval_bundles_render_featured_inner( $b, 'small' ); ?>
						</a>
					<?php endforeach; ?>
				</div>
			</div>

		</div>
	</div>
	<?php endif; ?>

	<!-- 4. CONSTRUCTOR CTA — скрыта до Q4 2026 (см. прежней версии).
	     Шаблон page-bundle-builder.php и стили style-bundle-builder.css
	     остаются в теме как «крошки» для будущей реализации. -->

	<!-- 5. MAIN BUNDLES GRID -->
	<div class="chub-container" id="bundles-grid">
		<header class="promo-head">
			<span class="promo-head__dot" aria-hidden="true"></span>
			<h2 class="promo-head__title">Все наборы</h2>
			<span class="promo-head__rule" aria-hidden="true"></span>
			<span class="promo-head__count" data-bundles-count>
				<?php
				$n10  = $total_count % 10;
				$n100 = $total_count % 100;
				if ( $n100 >= 11 && $n100 <= 14 ) {
					$w = 'наборов';
				} elseif ( $n10 === 1 ) {
					$w = 'набор';
				} elseif ( $n10 >= 2 && $n10 <= 4 ) {
					$w = 'набора';
				} else {
					$w = 'наборов';
				}
				echo (int) $total_count . ' ' . esc_html( $w );
				?>
			</span>
		</header>

		<div class="chub-grid" data-bundles-grid>
			<?php foreach ( $bundles as $b ) : ?>
				<?php podval_bundles_render_card( $b ); ?>
			<?php endforeach; ?>
			<div class="bundles-empty" data-bundles-empty <?php echo empty( $bundles ) ? '' : 'hidden'; ?>>
				<?php if ( empty( $bundles ) ) : ?>
					Пока нет ни одного бандла. Создайте первый в админке: <strong>Бандлы → Добавить новый</strong>.
				<?php else : ?>
					В этой категории пока нет наборов
				<?php endif; ?>
			</div>
		</div>
	</div>

	<!-- 6. BENEFITS -->
	<div class="chub-container">
		<div class="benefits">
			<div class="benefit">
				<svg class="benefit__icon" aria-hidden="true"><use href="#bnd-ic-arrow-dr"/></svg>
				<div>
					<div class="benefit__title">Выгода до 20%</div>
					<div class="benefit__sub">В&nbsp;сравнении с&nbsp;покупкой по&nbsp;одной</div>
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
				<svg class="benefit__icon" aria-hidden="true"><use href="#bnd-ic-printer"/></svg>
				<div>
					<div class="benefit__title">DTF-печать</div>
					<div class="benefit__sub">Стойкая печать на&nbsp;качественном хлопке</div>
				</div>
			</div>
			<div class="benefit">
				<svg class="benefit__icon" aria-hidden="true"><use href="#bnd-ic-factory"/></svg>
				<div>
					<div class="benefit__title">Собственное производство</div>
					<div class="benefit__sub">Шьём и&nbsp;печатаем в&nbsp;Минске</div>
				</div>
			</div>
		</div>
	</div>

	<!-- 7. SEO -->
	<?php $bundles_seo = (array) podval_copy( 'bundles_seo' ); if ( ! empty( $bundles_seo['text'] ) ) : ?>
	<div class="chub-container">
		<section class="seo">
			<?php if ( ! empty( $bundles_seo['title'] ) ) : ?><h3 class="seo__title"><?php echo esc_html( $bundles_seo['title'] ); ?></h3><?php endif; ?>
			<p class="seo__body"><?php echo wp_kses_post( $bundles_seo['text'] ); ?></p>
		</section>
	</div>
	<?php endif; ?>

</main>

<script>
/* Простой фильтр по pill'ам. data-cat на карточках сверяется с data-filter на pill'е. */
(function(){
	var grid  = document.querySelector('[data-bundles-grid]');
	var pills = document.querySelector('[data-bundles-pills]');
	var empty = document.querySelector('[data-bundles-empty]');
	var count = document.querySelector('[data-bundles-count]');
	if (!grid || !pills) return;

	var total = grid.querySelectorAll('.bcard').length;
	var word  = function(n){
		var n10 = n % 10, n100 = n % 100;
		if (n10 === 1 && n100 !== 11) return 'набор';
		if (n10 >= 2 && n10 <= 4 && (n100 < 10 || n100 >= 20)) return 'набора';
		return 'наборов';
	};

	pills.addEventListener('click', function(e){
		var btn = e.target.closest('.chub-pill');
		if (!btn) return;
		var filter = btn.getAttribute('data-filter');
		if (!filter) return;

		pills.querySelectorAll('.chub-pill').forEach(function(p){ p.classList.remove('is-active'); });
		btn.classList.add('is-active');

		var shown = 0;
		grid.querySelectorAll('.bcard').forEach(function(card){
			var cat = card.getAttribute('data-cat');
			var match = (filter === 'all') || (cat === filter);
			card.classList.toggle('is-hidden', !match);
			if (match) shown++;
		});

		if (empty) empty.hidden = shown > 0;
		if (count) count.textContent = shown + ' ' + word(shown);
	});
})();
</script>

<?php
get_footer();

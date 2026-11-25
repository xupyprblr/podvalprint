<?php
/**
 * Главная — по макету design/screens/Home.html.
 *
 * Тексты — inc/copy-data.php → 'home', цены — реестр inc/prices.php,
 * реквизиты — inc/brand.php. Секция без текста или без данных не выводится.
 *
 * @package Podval Print
 */

defined( 'ABSPATH' ) || exit;

get_header();

$home = (array) podval_copy( 'home', array() );

/**
 * Кнопка-ссылка из описания { label, link, icon }. Ссылка не нашлась — пусто.
 */
$podval_btn = function ( $btn, string $class ) {
	if ( empty( $btn['label'] ) ) {
		return '';
	}
	$url = podval_pp_link( (string) ( $btn['link'] ?? '' ) );
	if ( '' === $url ) {
		return '';
	}
	$icon = ! empty( $btn['icon'] ) ? podval_pp_icon( (string) $btn['icon'] ) : '';
	return '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . $icon . esc_html( $btn['label'] ) . '</a>';
};

$podval_head = function ( array $sec, string $tag = 'h2' ) {
	$out = '<div class="pp-sec__title">';
	if ( ! empty( $sec['label'] ) ) {
		$out .= '<span class="pp-label pp-muted">' . esc_html( $sec['label'] ) . '</span>';
	}
	$out .= '<' . $tag . ' class="pp-h pp-h--2">' . esc_html( $sec['title'] ) . '</' . $tag . '></div>';
	return $out;
};
?>

<div class="pp-home">

<?php /* ---------- Первый экран ---------- */ ?>
<?php if ( ! empty( $home['hero']['title'] ) ) : $hero = $home['hero']; ?>
	<section class="pp-wrap pp-hero">
		<div class="pp-hero__text">
			<?php if ( ! empty( $hero['kicker'] ) ) : ?>
				<div class="pp-hero__kicker">
					<span class="pp-orderno pp-label"><?php echo esc_html( $hero['kicker'] ); ?></span>
					<?php if ( ! empty( $hero['note'] ) ) : ?><span class="pp-small"><?php echo esc_html( $hero['note'] ); ?></span><?php endif; ?>
				</div>
			<?php endif; ?>
			<h1 class="pp-h pp-h--display pp-hero__title"><?php echo esc_html( $hero['title'] ); ?></h1>
			<?php if ( ! empty( $hero['lead'] ) ) : ?>
				<p class="pp-lead pp-hero__lead"><?php echo podval_pp_text( $hero['lead'] ); // phpcs:ignore ?></p>
			<?php endif; ?>
			<div class="pp-actions">
				<?php
				$primary   = $podval_btn( $hero['primary'] ?? array(), 'pp-btn pp-btn--accent pp-btn--l' );
				$secondary = $podval_btn( $hero['secondary'] ?? array(), 'pp-btn pp-btn--secondary pp-btn--l' );
				echo $primary; // phpcs:ignore
				if ( podval_pp_link( (string) ( $hero['primary']['link'] ?? '' ) ) !== podval_pp_link( (string) ( $hero['secondary']['link'] ?? '' ) ) ) {
					echo $secondary; // phpcs:ignore
				}
				?>
			</div>
			<?php if ( ! empty( $hero['facts'] ) ) : ?>
				<dl class="pp-facts">
					<?php foreach ( $hero['facts'] as $fact ) : $value = trim( wp_strip_all_tags( do_shortcode( $fact['value'] ) ) ); ?>
						<?php if ( preg_match( '/\d/', $value ) ) : // пустая цена — без цифр, факт не выводим ?>
							<div><dt class="pp-label pp-muted"><?php echo esc_html( $fact['label'] ); ?></dt><dd><?php echo esc_html( $value ); ?></dd></div>
						<?php endif; ?>
					<?php endforeach; ?>
				</dl>
			<?php endif; ?>
		</div>
		<div class="pp-hero__media" aria-hidden="true">
			<?php if ( ! empty( $hero['printsize'] ) ) : ?><div class="pp-dim pp-dim--v pp-label"><?php echo esc_html( $hero['printsize']['h'] ?? '' ); ?></div><?php endif; ?>
			<div style="display:grid;gap:12px">
				<figure class="pp-tex-concrete pp-corners pp-hero__figure">
					<?php
					$hero_img = (int) get_theme_mod( 'podval_pp_hero_image', 0 );
					echo $hero_img
						? wp_get_attachment_image( $hero_img, 'large', false, array( 'alt' => $hero['image_alt'] ?? '', 'fetchpriority' => 'high' ) ) // phpcs:ignore
						: podval_pp_svg( 'hero-tee' ); // phpcs:ignore
					?>
					<?php if ( ! empty( $hero['printsize'] ) ) : ?>
						<span class="pp-printsize"><b><?php echo esc_html( $hero['printsize']['format'] ); ?></b><?php echo esc_html( $hero['printsize']['size'] ); ?></span>
					<?php endif; ?>
				</figure>
				<?php if ( ! empty( $hero['printsize'] ) ) : ?><div class="pp-dim pp-label"><?php echo esc_html( $hero['printsize']['w'] ?? '' ); ?></div><?php endif; ?>
			</div>
		</div>
	</section>
	<div class="pp-hazard"></div>
<?php endif; ?>

<?php /* ---------- Изделия ---------- */ ?>
<?php if ( ! empty( $home['garments']['items'] ) ) : $sec = $home['garments']; ?>
	<section class="pp-sec--surface">
		<div class="pp-wrap pp-stack pp-gap-40">
			<div class="pp-sec__head">
				<?php echo $podval_head( $sec ); // phpcs:ignore ?>
				<?php if ( ! empty( $sec['text'] ) ) : ?><p class="pp-text pp-muted" style="max-width:440px"><?php echo esc_html( $sec['text'] ); ?></p><?php endif; ?>
			</div>
			<div class="pp-cards pp-cards--3">
				<?php foreach ( $sec['items'] as $item ) :
					$price = podval_price_garment( (string) $item['garment'] );
					$url   = podval_pp_link( (string) ( $item['link'] ?? '' ) );
					?>
					<article class="pp-card pp-card--garment">
						<div class="pp-card__media pp-tex-concrete">
							<?php if ( ! empty( $item['badge'] ) ) : ?><div class="pp-card__badges"><span class="pp-badge pp-badge--limit"><?php echo esc_html( $item['badge'] ); ?></span></div><?php endif; ?>
							<?php echo podval_pp_svg( (string) ( $item['art'] ?? '' ) ); // phpcs:ignore ?>
						</div>
						<div class="pp-card__body">
							<h3 class="pp-card__title"><?php if ( $url ) : ?><a href="<?php echo esc_url( $url ); ?>"><?php endif; ?><?php echo esc_html( $item['title'] ); ?><?php if ( $url ) : ?></a><?php endif; ?></h3>
							<?php if ( ! empty( $item['meta'] ) ) : ?><p class="pp-card__meta"><?php echo esc_html( $item['meta'] ); ?></p><?php endif; ?>
							<?php if ( ! empty( $item['colors'] ) ) : ?>
								<div class="pp-card__colors">
									<div class="pp-mini-dots" aria-hidden="true"><?php foreach ( $item['colors'] as $c ) : ?><i style="--swatch:<?php echo esc_attr( $c ); ?>"></i><?php endforeach; ?></div>
									<?php if ( ! empty( $item['colors_text'] ) ) : ?><span class="pp-small"><?php echo esc_html( $item['colors_text'] ); ?></span><?php endif; ?>
								</div>
							<?php endif; ?>
							<div class="pp-card__foot">
								<?php echo podval_pp_price_html( $price, __( 'от', 'podval-print' ) ); // phpcs:ignore ?>
								<?php if ( $url && ! empty( $item['button'] ) ) : ?><a class="pp-btn pp-btn--primary" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $item['button'] ); ?></a><?php endif; ?>
							</div>
						</div>
					</article>
				<?php endforeach; ?>
				<?php if ( ! empty( $sec['soon']['title'] ) ) : $soon = $sec['soon']; ?>
					<article class="pp-soon">
						<div class="pp-stack pp-gap-12">
							<span class="pp-label pp-muted"><?php echo esc_html( $soon['label'] ?? '' ); ?></span>
							<h3 class="pp-h pp-h--3"><?php echo esc_html( $soon['title'] ); ?></h3>
							<?php if ( ! empty( $soon['text'] ) ) : ?><p class="pp-text pp-muted"><?php echo esc_html( $soon['text'] ); ?></p><?php endif; ?>
						</div>
						<?php echo podval_pp_svg( 'soon-outline' ); // phpcs:ignore ?>
						<?php echo $podval_btn( array( 'label' => $soon['button'] ?? '', 'link' => $soon['link'] ?? '' ), 'pp-btn pp-btn--text' ); // phpcs:ignore ?>
					</article>
				<?php endif; ?>
			</div>
		</div>
	</section>
<?php endif; ?>

<?php /* ---------- Как это работает ---------- */ ?>
<?php if ( ! empty( $home['steps']['items'] ) ) : $sec = $home['steps']; ?>
	<section class="pp-wrap pp-sec pp-stack pp-gap-40">
		<?php echo $podval_head( $sec ); // phpcs:ignore ?>
		<ol class="pp-steps">
			<?php foreach ( $sec['items'] as $n => $step ) : ?>
				<li>
					<div class="pp-steps__n"><span><?php echo esc_html( sprintf( '%02d', $n + 1 ) ); ?></span><?php echo podval_pp_icon( (string) ( $step['icon'] ?? '' ), 'l' ); // phpcs:ignore ?></div>
					<h3 class="pp-h pp-h--3"><?php echo esc_html( $step['title'] ); ?></h3>
					<p class="pp-text pp-muted"><?php echo esc_html( $step['text'] ); ?></p>
				</li>
			<?php endforeach; ?>
		</ol>
	</section>
<?php endif; ?>

<?php /* ---------- Шаблоны: товары категории ---------- */ ?>
<?php
$sec      = (array) ( $home['templates'] ?? array() );
$products = ! empty( $sec['title'] ) ? podval_pp_products( (string) ( $sec['category'] ?? '' ), 4 ) : array();
if ( $products ) :
	$all = podval_pp_link( 'cat:' . $sec['category'] );
	?>
	<section class="pp-wrap pp-stack pp-gap-32" style="padding-bottom:96px">
		<div class="pp-sec__head">
			<?php echo $podval_head( $sec ); // phpcs:ignore ?>
			<?php if ( $all && ! empty( $sec['all'] ) ) : ?><a class="pp-btn pp-btn--text" href="<?php echo esc_url( $all ); ?>"><?php echo esc_html( $sec['all'] ); ?><?php echo podval_pp_icon( 'arrow-right' ); // phpcs:ignore ?></a><?php endif; ?>
		</div>
		<div class="pp-cards pp-cards--4">
			<?php foreach ( $products as $product ) { echo podval_pp_product_card( $product, (string) ( $sec['button'] ?? '' ) ); } // phpcs:ignore ?>
		</div>
	</section>
<?php endif; ?>

<?php /* ---------- Тёмный раздел (Deadlock) ---------- */ ?>
<?php
$sec      = (array) ( $home['dark'] ?? array() );
$cat_url  = ! empty( $sec['category'] ) ? podval_pp_link( 'cat:' . $sec['category'] ) : '';
if ( ! empty( $sec['title'] ) && $cat_url ) :
	$products = podval_pp_products( (string) $sec['category'], 2 );
	?>
	<section class="pp-dark-teaser" data-theme="dark" style="background:transparent">
		<div class="pp-torn-top"></div>
		<div class="pp-grain">
			<div class="pp-wrap pp-dark-teaser__grid">
				<div class="pp-dark-teaser__text">
					<span class="pp-label pp-muted"><?php echo esc_html( $sec['label'] ?? '' ); ?></span>
					<h2 class="pp-h pp-h--display" style="font-size:clamp(40px,5vw,60px)"><?php echo wp_kses( $sec['title'], array( 'span' => array( 'class' => true ) ) ); ?></h2>
					<?php if ( ! empty( $sec['text'] ) ) : ?><p class="pp-text pp-muted" style="max-width:460px"><?php echo esc_html( $sec['text'] ); ?></p><?php endif; ?>
					<div class="pp-route-line" aria-hidden="true"><span class="pp-marker">1</span><div class="pp-route"></div><span class="pp-marker">2</span><div class="pp-route"></div><span class="pp-marker">3</span></div>
					<div><a class="pp-btn pp-btn--accent pp-btn--l" href="<?php echo esc_url( $cat_url ); ?>"><?php echo esc_html( $sec['button'] ?? '' ); ?><?php echo podval_pp_icon( 'arrow-right' ); // phpcs:ignore ?></a></div>
					<?php if ( ! empty( $sec['note']['title'] ) ) : ?><div class="pp-note" style="max-width:300px;margin-top:8px"><b><?php echo esc_html( $sec['note']['title'] ); ?></b><?php echo esc_html( $sec['note']['text'] ?? '' ); ?></div><?php endif; ?>
				</div>
				<?php if ( $products ) : ?>
					<div class="pp-dark-teaser__cards">
						<?php foreach ( $products as $product ) { echo podval_pp_product_card( $product, (string) ( $sec['card_button'] ?? '' ), true ); } // phpcs:ignore ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<div class="pp-torn-bottom"></div>
	</section>
<?php endif; ?>

<?php /* ---------- Цены: из реестра ---------- */ ?>
<?php
$sec  = (array) ( $home['prices'] ?? array() );
$rows = array();
foreach ( (array) ( $sec['rows'] ?? array() ) as $key ) {
	$p = podval_price_garment( (string) $key );
	if ( null !== $p ) {
		$rows[] = array( 'label' => podval_prices()['garments'][ $key ]['label'] ?? $key, 'price' => $p, 'hl' => 'oversize' === $key );
	}
}
if ( ! empty( $sec['title'] ) && $rows ) :
	$second = podval_price_placement( 'second_side' );
	$sleeve = podval_price_placement( 'sleeve' );
	$cols   = (array) ( $sec['cols'] ?? array() );
	?>
	<section class="pp-sec--surface">
		<div class="pp-wrap pp-split">
			<div class="pp-split__aside">
				<span class="pp-label pp-muted"><?php echo esc_html( $sec['label'] ?? '' ); ?></span>
				<h2 class="pp-h pp-h--2"><?php echo esc_html( $sec['title'] ); ?></h2>
				<?php if ( ! empty( $sec['text'] ) ) : ?><p class="pp-text pp-muted"><?php echo esc_html( $sec['text'] ); ?></p><?php endif; ?>
				<?php echo $podval_btn( $sec['link'] ?? array(), 'pp-btn pp-btn--text' ); // phpcs:ignore ?>
			</div>
			<div class="pp-split__main pp-table-wrap">
				<table class="pp-table">
					<?php if ( ! empty( $sec['caption'] ) ) : ?><caption><?php echo esc_html( $sec['caption'] ); ?></caption><?php endif; ?>
					<thead><tr>
						<th scope="col"><?php echo esc_html( $cols[0] ?? '' ); ?></th>
						<th scope="col" class="is-num"><?php echo esc_html( $cols[1] ?? '' ); ?></th>
						<?php if ( null !== $second ) : ?><th scope="col" class="is-num"><?php echo esc_html( $cols[2] ?? '' ); ?></th><?php endif; ?>
						<?php if ( null !== $sleeve ) : ?><th scope="col" class="is-num"><?php echo esc_html( $cols[3] ?? '' ); ?></th><?php endif; ?>
					</tr></thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr<?php echo $row['hl'] ? ' class="is-hl"' : ''; ?>>
								<td><?php echo esc_html( $row['label'] ); ?></td>
								<td class="is-num"><?php echo esc_html( sprintf( __( 'от %s Br', 'podval-print' ), podval_price_num( $row['price'] ) ) ); ?></td>
								<?php if ( null !== $second ) : ?><td class="is-num">+<?php echo esc_html( podval_price_num( $second ) ); ?>&nbsp;Br</td><?php endif; ?>
								<?php if ( null !== $sleeve ) : ?><td class="is-num">+<?php echo esc_html( podval_price_num( $sleeve ) ); ?>&nbsp;Br</td><?php endif; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</section>
<?php endif; ?>

<?php /* ---------- Вопросы ---------- */ ?>
<?php if ( ! empty( $home['faq']['items'] ) ) : $sec = $home['faq']; ?>
	<section class="pp-wrap pp-sec pp-split">
		<div class="pp-split__aside">
			<span class="pp-label pp-muted"><?php echo esc_html( $sec['label'] ?? '' ); ?></span>
			<h2 class="pp-h pp-h--2"><?php echo esc_html( $sec['title'] ); ?></h2>
			<?php if ( ! empty( $sec['text'] ) ) : ?><p class="pp-text pp-muted"><?php echo esc_html( $sec['text'] ); ?></p><?php endif; ?>
			<?php echo $podval_btn( $sec['button'] ?? array(), 'pp-btn pp-btn--secondary' ); // phpcs:ignore ?>
		</div>
		<div class="pp-split__main pp-faq">
			<?php
			$faq_schema = array();
			foreach ( $sec['items'] as $n => $item ) :
				$answer       = podval_pp_text( $item['a'] );
				$faq_schema[] = array( 'q' => $item['q'], 'a' => $answer );
				?>
				<details<?php echo 0 === $n ? ' open' : ''; ?>><summary><?php echo esc_html( $item['q'] ); ?></summary><div class="pp-faq__a"><?php echo $answer; // phpcs:ignore ?></div></details>
			<?php endforeach; ?>
		</div>
	</section>
	<?php
	if ( function_exists( 'podval_seo_faq_schema' ) ) {
		podval_seo_faq_schema( $faq_schema );
	}
	?>
<?php endif; ?>

<?php /* ---------- Где мы ---------- */ ?>
<?php
$sec     = (array) ( $home['where'] ?? array() );
$address = (string) get_theme_mod( 'podval_footer_address', podval_brand( 'address' ) );
$hours   = (array) podval_brand( 'hours', array() );
$phone   = (string) get_theme_mod( 'podval_header_phone', podval_brand( 'phone' ) );
$map     = (string) podval_brand( 'map_embed_url' );
if ( ! empty( $sec['title'] ) && ( $address || $phone || $hours ) ) :
	$labels = (array) ( $sec['labels'] ?? array() );
	?>
	<section class="pp-wrap pp-sec pp-split pp-where">
		<div class="pp-split__aside" style="gap:20px">
			<span class="pp-label pp-muted"><?php echo esc_html( $sec['label'] ?? '' ); ?></span>
			<h2 class="pp-h pp-h--2"><?php echo esc_html( $sec['title'] ); ?></h2>
			<dl>
				<?php if ( $address ) : ?><div><dt class="pp-label pp-muted"><?php echo esc_html( $labels['address'] ?? '' ); ?></dt><dd><?php echo esc_html( $address ); ?></dd></div><?php endif; ?>
				<?php if ( $hours ) : ?><div><dt class="pp-label pp-muted"><?php echo esc_html( $labels['hours'] ?? '' ); ?></dt><dd><?php echo esc_html( implode( ' · ', array_map( function ( $l ) { return is_array( $l ) ? implode( ' ', $l ) : (string) $l; }, $hours ) ) ); ?></dd></div><?php endif; ?>
				<?php if ( $address && ! empty( $sec['pickup'] ) ) : ?><div><dt class="pp-label pp-muted"><?php echo esc_html( $labels['pickup'] ?? '' ); ?></dt><dd><?php echo esc_html( $sec['pickup'] ); ?></dd></div><?php endif; ?>
				<?php if ( $phone ) : ?><div><dt class="pp-label pp-muted"><?php echo esc_html( $labels['phone'] ?? '' ); ?></dt><dd><a class="pp-link" href="tel:<?php echo esc_attr( podval_brand_tel( $phone ) ); ?>"><?php echo esc_html( $phone ); ?></a></dd></div><?php endif; ?>
			</dl>
		</div>
		<div class="pp-tex-concrete pp-corners pp-map">
			<?php echo podval_pp_svg( 'map-grid' ); // phpcs:ignore ?>
			<?php if ( $map ) : ?>
				<button type="button" class="pp-map__btn js-pp-map" data-src="<?php echo esc_url( $map ); ?>" data-title="<?php echo esc_attr( $sec['title'] ); ?>">
					<span class="pp-map__pin"><?php echo podval_pp_icon( 'pin' ); // phpcs:ignore ?></span>
					<span class="pp-label"><?php echo esc_html( $sec['map'] ?? '' ); ?></span>
				</button>
			<?php else : ?>
				<span class="pp-map__btn"><span class="pp-map__pin"><?php echo podval_pp_icon( 'pin' ); // phpcs:ignore ?></span></span>
			<?php endif; ?>
		</div>
	</section>
<?php endif; ?>

</div><!-- /.pp-home -->

<?php
get_footer();

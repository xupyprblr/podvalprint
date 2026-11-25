<?php
/**
 * Шапка сайта — компонент Header дизайн-системы (design/system/components/Header).
 *
 * Меню — область «Основное меню» в админке; пока она пустая, пункты собирает
 * podval_pp_primary_nav() из каталога и служебных страниц.
 *
 * @package Podval Print
 */

defined( 'ABSPATH' ) || exit;

$podval_nav        = podval_pp_primary_nav();
$podval_phone      = (string) get_theme_mod( 'podval_header_phone', podval_brand( 'phone' ) );
$podval_wc         = function_exists( 'WC' ) && WC()->cart;
$podval_cart_count = $podval_wc ? (int) WC()->cart->get_cart_contents_count() : 0;
$podval_cart_url   = podval_pp_link( 'cart' );
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?> data-theme="<?php echo esc_attr( podval_pp_body_theme() ); ?>">
<?php wp_body_open(); ?>
<a class="pp-skip" href="#main-content"><?php esc_html_e( 'К содержимому', 'podval-print' ); ?></a>

<div class="site-wrapper">

<div class="pp-site-header" id="site-header">
	<header class="pp-header">
		<a class="pp-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home" aria-label="<?php echo esc_attr( sprintf( __( '%s — на главную', 'podval-print' ), podval_brand( 'name', get_bloginfo( 'name' ) ) ) ); ?>">
			<?php echo podval_pp_logo(); // phpcs:ignore WordPress.Security.EscapeOutput -- SVG из файла темы ?>
		</a>

		<nav class="pp-header__nav" aria-label="<?php esc_attr_e( 'Основное', 'podval-print' ); ?>">
			<ul class="pp-nav">
				<?php foreach ( $podval_nav as $i => $item ) : ?>
					<li>
						<?php if ( ! empty( $item['children'] ) ) : ?>
							<button type="button" class="js-pp-drop" aria-expanded="false" aria-controls="pp-drop-<?php echo (int) $i; ?>">
								<?php echo esc_html( $item['label'] ); ?><?php echo podval_pp_icon( 'chevron-down', 's' ); // phpcs:ignore ?>
							</button>
							<div class="pp-dropdown" id="pp-drop-<?php echo (int) $i; ?>" hidden>
								<?php if ( ! empty( $item['url'] ) && '#' !== $item['url'] ) : ?>
									<a href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['label'] ); ?><span><?php esc_html_e( 'Весь раздел', 'podval-print' ); ?></span></a>
								<?php endif; ?>
								<?php foreach ( $item['children'] as $child ) : ?>
									<a href="<?php echo esc_url( $child['url'] ); ?>"<?php echo $child['current'] ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $child['label'] ); ?><?php if ( ! empty( $child['desc'] ) ) : ?><span><?php echo esc_html( $child['desc'] ); ?></span><?php endif; ?></a>
								<?php endforeach; ?>
							</div>
						<?php else : ?>
							<a href="<?php echo esc_url( $item['url'] ); ?>"<?php echo $item['current'] ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $item['label'] ); ?></a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</nav>

		<div class="pp-header__tools">
			<button type="button" class="pp-btn pp-btn--icon pp-btn--ghost js-search-toggle" aria-label="<?php esc_attr_e( 'Поиск', 'podval-print' ); ?>"><?php echo podval_pp_icon( 'search' ); // phpcs:ignore ?></button>
			<?php if ( '' !== $podval_phone ) : ?>
				<a class="pp-btn pp-btn--text pp-header__phone" href="tel:<?php echo esc_attr( podval_brand_tel( $podval_phone ) ); ?>"><?php echo esc_html( $podval_phone ); ?></a>
			<?php endif; ?>
			<?php if ( '' !== $podval_cart_url ) : ?>
				<a class="pp-btn pp-btn--accent pp-cart js-cart-icon" href="<?php echo esc_url( $podval_cart_url ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Корзина, товаров: %d', 'podval-print' ), $podval_cart_count ) ); ?>">
					<?php echo podval_pp_icon( 'cart' ); // phpcs:ignore ?><span class="pp-cart__label"><?php esc_html_e( 'Корзина', 'podval-print' ); ?></span>
					<span class="pp-badge pp-badge--count js-cart-count<?php echo $podval_cart_count ? '' : ' is-empty'; ?>"><?php echo (int) $podval_cart_count; ?></span>
				</a>
			<?php endif; ?>
			<button type="button" class="pp-btn pp-btn--icon pp-btn--ghost pp-header__burger js-pp-burger" aria-expanded="false" aria-controls="pp-mnav" aria-label="<?php esc_attr_e( 'Меню', 'podval-print' ); ?>"
				data-icon-menu="<?php echo esc_attr( podval_pp_icon( 'menu' ) ); ?>" data-icon-close="<?php echo esc_attr( podval_pp_icon( 'close' ) ); ?>">
				<span class="js-pp-burger-icon" style="display:contents"><?php echo podval_pp_icon( 'menu' ); // phpcs:ignore ?></span>
			</button>
		</div>
	</header>

	<div class="pp-mnav-panel" id="pp-mnav" hidden>
		<nav class="pp-mnav" aria-label="<?php esc_attr_e( 'Основное', 'podval-print' ); ?>">
			<?php foreach ( $podval_nav as $item ) : ?>
				<?php if ( ! empty( $item['url'] ) && '#' !== $item['url'] ) : ?>
					<a href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['label'] ); ?><?php echo podval_pp_icon( 'chevron-right' ); // phpcs:ignore ?></a>
				<?php endif; ?>
				<?php foreach ( (array) $item['children'] as $child ) : ?>
					<a href="<?php echo esc_url( $child['url'] ); ?>" class="is-sub"><?php echo esc_html( $child['label'] ); ?></a>
				<?php endforeach; ?>
			<?php endforeach; ?>
			<?php if ( '' !== $podval_phone ) : ?>
				<a class="pp-btn pp-btn--secondary pp-btn--block" style="margin-top:16px;font:600 15px var(--font-text);min-height:44px;border-bottom:1.5px solid;justify-content:center" href="tel:<?php echo esc_attr( podval_brand_tel( $podval_phone ) ); ?>"><?php echo podval_pp_icon( 'phone' ); // phpcs:ignore ?><?php esc_html_e( 'Позвонить', 'podval-print' ); ?></a>
			<?php endif; ?>
		</nav>
	</div>
</div>

<main id="main-content">

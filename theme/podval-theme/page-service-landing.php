<?php
/**
 * Template Name: Лендинг услуги
 *
 * Сервисный SEO-лендинг. Порядок секций фиксированный (§2 ТЗ),
 * пустая секция не выводится. Данные — мета _podval_sl (inc/service-landing.php),
 * SEO-текст — обычный контент страницы, выводится на месте секции 11.
 *
 * @package Podval Print
 */

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) :
	the_post();

	$sl_id = get_the_ID();
	$sl    = podval_sl_get( $sl_id );
	$on    = podval_sl_order( $sl );
	$data  = array(
		'post_id' => $sl_id,
		'sl'      => $sl,
		'h1'      => podval_sl_h1( $sl_id ),
		'calc'    => podval_sl_calc_config( $sl ),
		'from'    => podval_sl_from_info( $sl['calc']['garments'], (bool) $sl['calc']['own'] ),
	);
	?>
<article class="sl" id="sl-<?php echo (int) $sl_id; ?>">

	<div class="sl-wrap">
		<nav class="sl-crumbs" aria-label="Хлебные крошки">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>">Главная</a>
			<span class="sl-crumbs__sep" aria-hidden="true">—</span>
			<a href="<?php echo esc_url( podval_sl_hub_url() ); ?>">Услуги</a>
			<span class="sl-crumbs__sep" aria-hidden="true">—</span>
			<span aria-current="page"><?php echo esc_html( $data['h1'] ); ?></span>
		</nav>
	</div>

	<?php
	foreach ( podval_sl_order( $sl ) as $section ) {
		podval_sl_render_section( $section, $data );
	}
	?>

	<?php
	// Sticky-бар (мобиль): «от X Br · Рассчитать». Без цены — просто «Рассчитать».
	// Ведём только на секцию, которая реально выводится на этой странице.
	$sticky_target = '';
	if ( $data['calc'] && in_array( 'calc', $on, true ) ) {
		$sticky_target = '#calc';
	} elseif ( $sl['form']['enabled'] && in_array( 'form', $on, true ) ) {
		$sticky_target = '#zayavka';
	}
	if ( $sticky_target ) :
		?>
	<div class="sl-sticky" id="sl-sticky">
		<div class="sl-sticky__price">
			<?php if ( $data['from'] ) : ?>
			от <?php echo podval_sl_byn( $data['from']['value'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php endif; ?>
		</div>
		<a class="sl-sticky__btn" href="<?php echo esc_attr( $sticky_target ); ?>">Рассчитать</a>
	</div>
	<?php endif; ?>

</article>
	<?php
endwhile;

get_footer();

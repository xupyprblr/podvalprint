<?php
/**
 * Лендинг · 08 ГОТОВЫЕ ПРИНТЫ — живой ряд карточек из категории.
 * ID товаров кэшируются в транзиенте podval_q_* (сбрасывает podval_flush_all_cache).
 *
 * @var array $args post_id, sl, h1, calc, from
 */
defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wc_get_product' ) ) {
	return;
}
$pr   = $args['sl']['products'];
$slug = trim( (string) $pr['category'] );
if ( $slug === '' ) {
	return;
}
$term = get_term_by( 'slug', $slug, 'product_cat' );
if ( ! $term || is_wp_error( $term ) ) {
	return;
}
$limit = max( 4, min( 12, (int) $pr['limit'] ) );

$qargs = array(
	'post_type'              => 'product',
	'post_status'            => 'publish',
	'posts_per_page'         => $limit,
	'fields'                 => 'ids',
	'no_found_rows'          => true,
	'update_post_meta_cache' => false,
	'update_post_term_cache' => false,
	'orderby'                => 'menu_order date',
	'order'                  => 'ASC',
	'tax_query'              => array( array( 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $term->term_id, 'include_children' => true ) ),
);
$key = 'podval_q_sl_' . md5( wp_json_encode( $qargs ) );
$ids = get_transient( $key );
if ( ! is_array( $ids ) ) {
	$q   = new WP_Query( $qargs );
	$ids = array_map( 'intval', $q->posts );
	set_transient( $key, $ids, 6 * HOUR_IN_SECONDS );
}
if ( ! $ids ) {
	return;
}
$term_link = get_term_link( $term );
?>
<section class="sl-sec sl-sec--products" id="gotovye-printy">
	<div class="sl-wrap">
		<header class="sl-head sl-head--row">
			<h2 class="sl-h2"><?php echo esc_html( $pr['title'] ); ?></h2>
			<?php if ( ! is_wp_error( $term_link ) ) : ?>
			<a class="sl-link" href="<?php echo esc_url( $term_link ); ?>">Все принты →</a>
			<?php endif; ?>
		</header>
		<div class="catalog-grid sl-products">
			<?php
			foreach ( $ids as $pid ) {
				$p = wc_get_product( $pid );
				if ( ! $p || ! $p->is_visible() ) {
					continue;
				}
				$GLOBALS['post']    = get_post( $pid ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
				$GLOBALS['product'] = $p;
				setup_postdata( $GLOBALS['post'] );
				get_template_part( 'template-parts/content/product', 'card' );
			}
			wp_reset_postdata();
			?>
		</div>
	</div>
</section>

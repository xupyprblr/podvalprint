<?php
/**
 * Карусель изображений в карточке каталога.
 *
 * Включается точечно чекбоксом `_podval_card_carousel` в карточке товара.
 * Кадры: главное фото + первые 4 из галереи (максимум 5).
 * Работает только на главной и в разделе «Свой дизайн» — в категориях,
 * поиске и прочем каталоге карточка остаётся статичной.
 *
 * Автоплей включён, но с тремя предохранителями:
 *   1. IntersectionObserver — крутятся только видимые карточки
 *   2. prefers-reduced-motion — автоплей отключается полностью
 *   3. Кадры 2..5 грузятся лениво, первый переход отложен
 *
 * @package Podval Print
 *
 */

defined( 'ABSPATH' ) || exit;

/* ═══════════════════════════════════════════
   1. МЕТА-БОКС ТОВАРА
   ═══════════════════════════════════════════ */

add_action( 'add_meta_boxes', function() {
	add_meta_box(
		'podval_card_carousel_box',
		'Карусель в каталоге',
		'podval_card_carousel_box_render',
		'product',
		'side',
		'default'
	);
} );

/**
 * Рендер мета-бокса карусели.
 *
 * @param WP_Post $post Товар.
 */
function podval_card_carousel_box_render( $post ) {
	wp_nonce_field( 'podval_card_carousel_save', 'podval_card_carousel_nonce' );

	$on      = get_post_meta( $post->ID, '_podval_card_carousel', true ) === '1';
	$product = function_exists( 'wc_get_product' ) ? wc_get_product( $post->ID ) : null;
	$gallery = $product ? $product->get_gallery_image_ids() : array();
	$count   = min( 5, ( $product && $product->get_image_id() ? 1 : 0 ) + count( $gallery ) );

	echo '<label style="display:flex;gap:8px;align-items:flex-start">';
	echo '<input type="checkbox" name="podval_card_carousel" value="1"' . checked( $on, true, false ) . '>';
	echo '<span><strong>Крутить варианты дизайна</strong></span></label>';

	echo '<p class="description" style="margin-top:8px">Главное фото + первые 4 из галереи. Сейчас доступно кадров: <strong>' . (int) $count . '</strong>.</p>';

	if ( $count < 2 ) {
		echo '<p class="description" style="color:#b32d2e">Нужно минимум 2 изображения — добавьте кадры в галерею товара.</p>';
	}

	echo '<p class="description">Работает на главной и в разделе «Свой дизайн». В категориях и поиске карточка остаётся статичной.</p>';
}

add_action( 'save_post_product', function( $post_id ) {
	if ( ! isset( $_POST['podval_card_carousel_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_key( $_POST['podval_card_carousel_nonce'] ), 'podval_card_carousel_save' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	if ( ! empty( $_POST['podval_card_carousel'] ) ) {
		update_post_meta( $post_id, '_podval_card_carousel', '1' );
	} else {
		delete_post_meta( $post_id, '_podval_card_carousel' );
	}

	if ( function_exists( 'podval_flush_all_cache' ) ) {
		podval_flush_all_cache( 'card_carousel_save' );
	}
} );

/* ═══════════════════════════════════════════
   2. КОНТЕКСТ
   ═══════════════════════════════════════════ */

/**
 * Разрешена ли карусель в текущем контексте вывода.
 * Главная + раздел «Свой дизайн» (включая дочерние категории).
 *
 * @return bool
 */
function podval_card_carousel_context() {
	static $ctx = null;
	if ( null !== $ctx ) {
		return $ctx;
	}

	$ctx = false;

	if ( is_front_page() || is_home() ) {
		$ctx = true;
	} elseif ( function_exists( 'is_product_category' ) && is_product_category() ) {
		$term = get_queried_object();
		if ( $term instanceof WP_Term ) {
			if ( 'custom-design' === $term->slug ) {
				$ctx = true;
			} else {
				$ancestors = get_ancestors( $term->term_id, 'product_cat', 'taxonomy' );
				foreach ( $ancestors as $anc_id ) {
					$anc = get_term( $anc_id, 'product_cat' );
					if ( $anc instanceof WP_Term && 'custom-design' === $anc->slug ) {
						$ctx = true;
						break;
					}
				}
			}
		}
	}

	return apply_filters( 'podval_card_carousel_context', $ctx );
}

/**
 * Кадры карусели для товара: главное фото + до 4 из галереи.
 *
 * @param WC_Product $product Товар.
 * @return array Список [ id, src, srcset, alt ]; пустой массив — карусель не нужна.
 */
function podval_card_carousel_frames( $product ) {
	if ( ! $product instanceof WC_Product ) {
		return array();
	}
	if ( get_post_meta( $product->get_id(), '_podval_card_carousel', true ) !== '1' ) {
		return array();
	}
	if ( ! podval_card_carousel_context() ) {
		return array();
	}

	$ids  = array();
	$main = $product->get_image_id();
	if ( $main ) {
		$ids[] = (int) $main;
	}
	foreach ( array_slice( $product->get_gallery_image_ids(), 0, 4 ) as $gid ) {
		$gid = (int) $gid;
		if ( $gid && ! in_array( $gid, $ids, true ) ) {
			$ids[] = $gid;
		}
	}
	$ids = array_slice( $ids, 0, 5 );

	// Меньше двух кадров — крутить нечего.
	if ( count( $ids ) < 2 ) {
		return array();
	}

	$name   = $product->get_name();
	$frames = array();

	foreach ( $ids as $id ) {
		$src = wp_get_attachment_image_url( $id, 'podval-card-sm' );
		if ( ! $src ) {
			continue;
		}
		$frames[] = array(
			'id'     => $id,
			'src'    => $src,
			'srcset' => wp_get_attachment_image_srcset( $id, 'podval-card-sm' ),
			'alt'    => get_post_meta( $id, '_wp_attachment_image_alt', true ) ?: $name,
		);
	}

	return count( $frames ) >= 2 ? $frames : array();
}

/* ═══════════════════════════════════════════
   3. ASSETS
   ═══════════════════════════════════════════ */

add_action( 'wp_enqueue_scripts', function() {
	if ( ! podval_card_carousel_context() ) {
		return;
	}

	$ver = wp_get_theme()->get( 'Version' );

	wp_enqueue_style(
		'podval-card-carousel',
		get_template_directory_uri() . '/assets/css/style-card-carousel.css',
		array( 'podval-style-core' ),
		$ver
	);

	$js = function_exists( 'podval_js_file' )
		? podval_js_file( 'card-carousel.js' )
		: get_template_directory_uri() . '/assets/js/card-carousel.js';

	wp_enqueue_script( 'podval-card-carousel', $js, array(), $ver, true );
}, 20 );

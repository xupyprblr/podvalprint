<?php
/**
 * Тексты сайта — слой данных.
 *
 * В шаблонах нет маркетинговых и SEO-текстов: они лежат в inc/copy-data.php
 * (или приходят фильтром podval_copy). Нет текста — блок не выводится.
 *
 * @package Podval Print
 */

defined( 'ABSPATH' ) || exit;

/**
 * Блок текста по ключу.
 *
 * @param string $key     Ключ верхнего уровня из inc/copy-data.php.
 * @param mixed  $default Значение, если блока нет.
 * @return mixed
 */
function podval_copy( string $key, $default = array() ) {
	static $copy = null;
	if ( null === $copy ) {
		$file = get_theme_file_path( 'inc/copy-data.php' );
		$copy = file_exists( $file ) ? (array) require $file : array();
		$copy = (array) apply_filters( 'podval_copy', $copy );
	}
	return isset( $copy[ $key ] ) && array() !== $copy[ $key ] && '' !== $copy[ $key ] ? $copy[ $key ] : $default;
}

/**
 * Вывод блока «заголовок + абзацы + список + FAQ».
 *
 * Формат блока:
 *   array(
 *     'title'    => 'H2',
 *     'sections' => array( array( 'title' => 'H3', 'text' => 'Абзац' ), … ),
 *     'benefits' => array( 'Пункт', … ),
 *     'faq'      => array( array( 'q' => 'Вопрос', 'a' => 'Ответ' ), … ),
 *   )
 *
 * @param array  $block Блок текста.
 * @param string $class Базовый CSS-класс.
 */
function podval_copy_render( array $block, string $class = 'sp-seo' ): void {
	if ( empty( $block ) ) {
		return;
	}
	$c = esc_attr( $class );
	echo '<section class="' . $c . '"><div class="' . $c . '__inner">';
	if ( ! empty( $block['title'] ) ) {
		echo '<h2 class="' . $c . '__title">' . esc_html( $block['title'] ) . '</h2>';
	}
	foreach ( (array) ( $block['sections'] ?? array() ) as $s ) {
		if ( ! empty( $s['title'] ) ) {
			echo '<h3 class="' . $c . '__subtitle">' . esc_html( $s['title'] ) . '</h3>';
		}
		if ( ! empty( $s['text'] ) ) {
			echo '<p class="' . $c . '__text">' . wp_kses_post( $s['text'] ) . '</p>';
		}
	}
	if ( ! empty( $block['benefits'] ) ) {
		echo '<ul class="' . $c . '__benefits">';
		foreach ( (array) $block['benefits'] as $b ) {
			echo '<li>' . esc_html( $b ) . '</li>';
		}
		echo '</ul>';
	}
	if ( ! empty( $block['faq'] ) ) {
		echo '<h2 class="' . $c . '__title ' . $c . '__title--faq">Частые вопросы</h2><div class="cd-faq__list">';
		foreach ( (array) $block['faq'] as $f ) {
			echo '<div class="cd-faq__item"><button class="cd-faq__q js-cd-faq"><span>' . esc_html( $f['q'] ) . '</span>'
				. '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M6 9l6 6 6-6"/></svg></button>'
				. '<div class="cd-faq__a"><p>' . wp_kses_post( $f['a'] ) . '</p></div></div>';
		}
		echo '</div>';
	}
	echo '</div></section>';
	if ( ! empty( $block['faq'] ) && function_exists( 'podval_seo_faq_schema' ) ) {
		podval_seo_faq_schema( $block['faq'] );
	}
}

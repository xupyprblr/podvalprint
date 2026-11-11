<?php
/**
 * Образец определения лендинга. Файлы с подчёркиванием в начале сидер пропускает.
 *
 * Имя файла = слаг страницы: inc/landings/pechat-na-futbolkah.php → /pechat-na-futbolkah/.
 * Поля блока 'sl' описаны схемой podval_sl_schema() в inc/service-landing.php.
 * Цены — только шорткодом [podval_price key="…"], числом в тексте их не пишем.
 *
 * @package Podval Print
 */

defined( 'ABSPATH' ) || exit;

return array(
	'num'     => 1,                      // номер в ядре запросов (seo/keywords.csv)
	'title'   => '',                     // H1 страницы
	'order'   => 10,                     // порядок в меню и на хабе
	'kw'      => '',                     // главный запрос
	'desc'    => '',                     // meta description, до 155 знаков
	'sl'      => array(
		'hero' => array(
			'subtitle' => '',
			'badge'    => '',
			'facts'    => array(),       // array( array( 'value' => '…', 'label' => '…' ), … )
		),
		'nav'  => array( 'group' => 'clothing', 'order' => 10, 'short_title' => '' ),
		'card' => array( 'excerpt' => '', 'lead_time' => '' ),
		'calc' => array( 'enabled' => true, 'mode_default' => 'ours', 'garments' => array(), 'placements' => array() ),
		'faq'  => array(),               // array( array( 'q' => '…', 'a' => '…' ), … )
		'form' => array( 'enabled' => true, 'title' => '', 'subtitle' => '' ),
		'local' => array( 'show' => true ),
	),
	// SEO-текст страницы: список блоков для podval_sl_seed_blocks().
	'content' => podval_sl_seed_blocks( array(
		// array( 'h2', 'Заголовок' ), array( 'p', 'Абзац' ), array( 'ul', array( 'Пункт', 'Пункт' ) ),
	) ),
);

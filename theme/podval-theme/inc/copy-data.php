<?php
/**
 * Тексты сайта. Пишутся с нуля под этот бренд; пустой блок не выводится.
 *
 * Ключи:
 *   product_seo     — SEO-блок карточки товара со своим дизайном.
 *                     array( '<слаг товара>' => блок, 'default' => блок )
 *   custom_hub      — тексты раздела «Свой дизайн»: benefits, steps, seo, faq.
 *   service_product — тексты страницы товара-услуги: advantages, faq.
 *   bundles_seo     — array( 'title' => …, 'text' => … ) на странице наборов.
 *   home_hero       — слайд по умолчанию, когда слайды не заданы в настройках.
 *   ticker          — слова бегущей строки в подвале, когда нет меток товаров.
 *   product_info    — раскрывающиеся блоки карточки товара (доставка, возврат, уход):
 *                     array( array( 'title' => …, 'html' => …, 'html_custom' => … ), … )
 *
 * Формат блока — в podval_copy_render() (inc/copy.php).
 *
 * @package Podval Print
 */

defined( 'ABSPATH' ) || exit;

return array(
	'product_seo'     => array(),
	'custom_hub'      => array(),
	'service_product' => array(),
	'bundles_seo'     => array(),
	'home_hero'       => array(),
	'ticker'          => array(),
	'product_info'    => array(),
);

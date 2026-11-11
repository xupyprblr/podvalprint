<?php
/**
 * Template Name: Доставка и оплата
 *
 * Текст страницы редактируется в WordPress; шаблон даёт оформление.
 *
 * @package Podval Print
 */

defined( 'ABSPATH' ) || exit;

get_header();
get_template_part( 'template-parts/legal-page', null, array( 'requisites' => false ) );
get_footer();

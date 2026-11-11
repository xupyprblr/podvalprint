<?php
/**
 * Template Name: Политика конфиденциальности
 *
 * Текст страницы редактируется в WordPress; шаблон даёт оформление и реквизиты из конфига бренда.
 *
 * @package Podval Print
 */

defined( 'ABSPATH' ) || exit;

get_header();
get_template_part( 'template-parts/legal-page', null, array( 'requisites' => true ) );
get_footer();

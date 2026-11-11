<?php
/**
 * Podval Print — Thank You Page (full page wrapper)
 */
defined( 'ABSPATH' ) || exit;
get_header();

// Load the thankyou content partial
include get_stylesheet_directory() . '/woocommerce/checkout/thankyou.php';

get_footer();

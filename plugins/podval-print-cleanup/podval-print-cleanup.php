<?php
/**
 * Plugin Name: Podval Print Cleanup
 * Description: Управление жизненным циклом клиентских файлов принтов — автоочистка по cron, ручные операции, диагностика. Админ-панель: WooCommerce → Очистка принтов.
 * Version: 0.1.0
 * Author: Podval Print
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * Text Domain: podval-print-cleanup
 *
 * @package PodvalPrintCleanup
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'PFC_VERSION',       '0.1.0' );
define( 'PFC_PLUGIN_FILE',   __FILE__ );
define( 'PFC_PLUGIN_DIR',    plugin_dir_path( __FILE__ ) );
define( 'PFC_PLUGIN_URL',    plugin_dir_url( __FILE__ ) );
define( 'PFC_CRON_HOOK',     'podval_cleanup_print_files' );
define( 'PFC_LOG_MAX',       20 );

/**
 * Declare HPOS compatibility (WooCommerce High-Performance Order Storage).
 */
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            PFC_PLUGIN_FILE,
            true
        );
    }
} );

/**
 * Bail out if WooCommerce is not active.
 */
add_action( 'plugins_loaded', function() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>Podval Print Cleanup</strong>: требуется активный WooCommerce.</p></div>';
        } );
        return;
    }

    require_once PFC_PLUGIN_DIR . 'includes/class-pfc-settings.php';
    require_once PFC_PLUGIN_DIR . 'includes/class-pfc-stats.php';
    require_once PFC_PLUGIN_DIR . 'includes/class-pfc-cleanup.php';

    PFC_Cleanup::instance();

    if ( is_admin() ) {
        require_once PFC_PLUGIN_DIR . 'includes/class-pfc-admin.php';
        PFC_Admin::instance();
    }
} );

/**
 * Activation: migrate settings from theme_mod (if first time), schedule cron.
 */
register_activation_hook( __FILE__, 'pfc_on_activate' );
function pfc_on_activate() {
    require_once PFC_PLUGIN_DIR . 'includes/class-pfc-settings.php';

    // Migrate from theme_mod if no settings yet.
    if ( ! get_option( 'pfc_settings' ) ) {
        $enabled = get_theme_mod( 'podval_cleanup_enabled', '1' );
        $days    = (int) get_theme_mod( 'podval_cleanup_days', 30 );
        PFC_Settings::save( array(
            'enabled'     => ( $enabled === '1' || $enabled === true || $enabled === 1 ) ? 1 : 0,
            'days'        => $days > 0 ? $days : 30,
            'statuses'    => array( 'wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed' ),
            'orphan_days' => 7,
            'date_field'  => 'date_created',
        ) );
    }

    // Schedule cron: start in 5 min to let activation finish.
    if ( ! wp_next_scheduled( PFC_CRON_HOOK ) ) {
        wp_schedule_event( time() + 300, 'daily', PFC_CRON_HOOK );
    }
}

/**
 * Deactivation: unschedule cron.
 */
register_deactivation_hook( __FILE__, 'pfc_on_deactivate' );
function pfc_on_deactivate() {
    wp_clear_scheduled_hook( PFC_CRON_HOOK );
}

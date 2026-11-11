<?php
/**
 * Admin UI: submenu under WooCommerce, settings form, AJAX handlers.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class PFC_Admin {

    private static $instance;

    /**
     * @return PFC_Admin
     */
    public static function instance() {
        if ( ! self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu',             array( $this, 'register_menu' ) );
        add_action( 'admin_enqueue_scripts',  array( $this, 'enqueue_assets' ) );
        add_action( 'admin_post_pfc_save_settings', array( $this, 'handle_save_settings' ) );

        add_action( 'wp_ajax_pfc_preview',        array( $this, 'ajax_preview' ) );
        add_action( 'wp_ajax_pfc_run_now',        array( $this, 'ajax_run_now' ) );
        add_action( 'wp_ajax_pfc_scan_ghosts',    array( $this, 'ajax_scan_ghosts' ) );
        add_action( 'wp_ajax_pfc_delete_ghosts',  array( $this, 'ajax_delete_ghosts' ) );
        add_action( 'wp_ajax_pfc_clear_log',      array( $this, 'ajax_clear_log' ) );

        // Plugins-list row meta
        add_filter( 'plugin_action_links_' . plugin_basename( PFC_PLUGIN_FILE ), array( $this, 'action_links' ) );
    }

    /**
     * Add submenu: WooCommerce → Очистка принтов.
     */
    public function register_menu() {
        add_submenu_page(
            'woocommerce',
            'Очистка принтов',
            '🗑 Очистка принтов',
            'manage_woocommerce',
            'pfc-cleanup',
            array( $this, 'render_page' )
        );
    }

    /**
     * Add "Settings" link on plugins list.
     */
    public function action_links( $links ) {
        $url = admin_url( 'admin.php?page=pfc-cleanup' );
        array_unshift( $links, '<a href="' . esc_url( $url ) . '">Настройки</a>' );
        return $links;
    }

    /**
     * Enqueue CSS/JS only on plugin page.
     */
    public function enqueue_assets( $hook ) {
        if ( strpos( (string) $hook, 'pfc-cleanup' ) === false ) return;

        wp_enqueue_style( 'pfc-admin',
            PFC_PLUGIN_URL . 'assets/admin.css',
            array(),
            PFC_VERSION
        );
        wp_enqueue_script( 'pfc-admin',
            PFC_PLUGIN_URL . 'assets/admin.js',
            array( 'jquery' ),
            PFC_VERSION,
            true
        );
        wp_localize_script( 'pfc-admin', 'PFC', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'pfc_ajax' ),
            'i18n'    => array(
                'confirmRun'      => 'Запустить очистку сейчас? Действие нельзя отменить.',
                'confirmGhosts'   => 'Удалить ВСЕ файлы-призраки с диска? Они не связаны ни с одним attachment в БД.',
                'confirmClearLog' => 'Очистить историю запусков?',
                'running'         => 'Выполняется…',
                'error'           => 'Ошибка: ',
            ),
        ) );
    }

    /**
     * Render admin page.
     */
    public function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Недостаточно прав' );
        }

        $settings = PFC_Settings::get();
        $folder   = PFC_Stats::folder();
        $atts     = PFC_Stats::attachments();
        $cron     = PFC_Stats::cron();
        $last     = PFC_Stats::last_run();
        $log      = PFC_Cleanup::instance()->get_log();

        include PFC_PLUGIN_DIR . 'templates/admin-page.php';
    }

    /**
     * Save settings (admin-post).
     */
    public function handle_save_settings() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Недостаточно прав' );
        check_admin_referer( 'pfc_save_settings' );

        $statuses = array();
        if ( isset( $_POST['statuses'] ) && is_array( $_POST['statuses'] ) ) {
            $statuses = array_map( 'sanitize_text_field', wp_unslash( $_POST['statuses'] ) );
        }

        PFC_Settings::save( array(
            'enabled'     => isset( $_POST['enabled'] ) ? 1 : 0,
            'days'        => (int) ( $_POST['days'] ?? 30 ),
            'orphan_days' => (int) ( $_POST['orphan_days'] ?? 7 ),
            'statuses'    => $statuses,
            'date_field'  => sanitize_text_field( wp_unslash( $_POST['date_field'] ?? 'date_created' ) ),
        ) );

        wp_safe_redirect( add_query_arg(
            array( 'page' => 'pfc-cleanup', 'saved' => 1 ),
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    /**
     * Shared AJAX guard.
     */
    private function verify_ajax() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'Недостаточно прав' ), 403 );
        }
        check_ajax_referer( 'pfc_ajax', 'nonce' );
    }

    public function ajax_preview() {
        $this->verify_ajax();
        wp_send_json_success( PFC_Cleanup::instance()->preview() );
    }

    public function ajax_run_now() {
        $this->verify_ajax();
        wp_send_json_success( PFC_Cleanup::instance()->run( 'manual', false ) );
    }

    public function ajax_scan_ghosts() {
        $this->verify_ajax();
        $ghosts = PFC_Cleanup::instance()->find_ghost_files();
        $total_bytes = 0;
        foreach ( $ghosts as $g ) $total_bytes += (int) $g['size'];
        $preview = array_slice( $ghosts, 0, 20 );
        wp_send_json_success( array(
            'count'   => count( $ghosts ),
            'bytes'   => $total_bytes,
            'preview' => $preview,
        ) );
    }

    public function ajax_delete_ghosts() {
        $this->verify_ajax();
        wp_send_json_success( PFC_Cleanup::instance()->delete_ghost_files() );
    }

    public function ajax_clear_log() {
        $this->verify_ajax();
        PFC_Cleanup::instance()->clear_log();
        wp_send_json_success();
    }
}

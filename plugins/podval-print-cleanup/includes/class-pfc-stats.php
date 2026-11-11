<?php
/**
 * Statistics helpers for admin dashboard.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class PFC_Stats {

    /**
     * Recursive size + file count of customer-prints/ folder.
     *
     * @return array{size:int, files:int, exists:bool, path:string}
     */
    public static function folder() {
        $upload_dir = wp_upload_dir();
        $base = trailingslashit( $upload_dir['basedir'] ) . 'customer-prints';

        if ( ! is_dir( $base ) ) {
            return array( 'size' => 0, 'files' => 0, 'exists' => false, 'path' => $base );
        }

        $size  = 0;
        $files = 0;
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS )
            );
            foreach ( $iterator as $file ) {
                if ( $file->isFile() ) {
                    $size += (int) $file->getSize();
                    $files++;
                }
            }
        } catch ( Exception $e ) {
            // Permissions issue — return what we have.
        }

        return array( 'size' => $size, 'files' => $files, 'exists' => true, 'path' => $base );
    }

    /**
     * Count attachments tagged as customer prints.
     *
     * @return array{total:int, orphans:int, attached:int}
     */
    public static function attachments() {
        global $wpdb;

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                 AND pm.meta_key = '_podval_print_upload' AND pm.meta_value = '1'
             WHERE p.post_type = 'attachment'"
        );

        $orphans = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                 AND pm.meta_key = '_podval_print_upload' AND pm.meta_value = '1'
             WHERE p.post_type = 'attachment' AND p.post_parent = 0"
        );

        return array(
            'total'    => $total,
            'orphans'  => $orphans,
            'attached' => max( 0, $total - $orphans ),
        );
    }

    /**
     * Cron status for the cleanup hook.
     *
     * @return array{next:int|false, disabled:bool}
     */
    public static function cron() {
        return array(
            'next'     => wp_next_scheduled( PFC_CRON_HOOK ),
            'disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
        );
    }

    /**
     * Last run info.
     *
     * @return array|null
     */
    public static function last_run() {
        $val = get_option( PFC_Cleanup::LAST_RUN_KEY, null );
        return is_array( $val ) ? $val : null;
    }

    /**
     * Human-readable byte size.
     *
     * @param int $bytes
     * @return string
     */
    public static function format_bytes( $bytes ) {
        $bytes = (int) $bytes;
        if ( $bytes < 1024 ) return $bytes . ' B';
        if ( $bytes < 1048576 ) return round( $bytes / 1024, 1 ) . ' KB';
        if ( $bytes < 1073741824 ) return round( $bytes / 1048576, 1 ) . ' MB';
        return round( $bytes / 1073741824, 2 ) . ' GB';
    }
}

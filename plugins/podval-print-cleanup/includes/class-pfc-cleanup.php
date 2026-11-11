<?php
/**
 * Cleanup engine: cron handler, manual runs, ghost files, preview.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class PFC_Cleanup {

    const LOG_KEY      = 'pfc_run_log';
    const LAST_RUN_KEY = 'pfc_last_run';
    const ORDER_LIMIT  = 50;
    const ORPHAN_LIMIT = 100;

    private static $instance;

    /**
     * @return PFC_Cleanup
     */
    public static function instance() {
        if ( ! self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( PFC_CRON_HOOK, array( $this, 'run_scheduled' ) );
    }

    /**
     * Cron entrypoint.
     */
    public function run_scheduled() {
        $this->run( 'cron', false );
    }

    /**
     * Main cleanup runner.
     *
     * @param string $trigger 'cron' | 'manual' | 'preview'
     * @param bool   $dry_run If true — only count, no deletion.
     * @return array
     */
    public function run( $trigger = 'cron', $dry_run = false ) {
        $start    = microtime( true );
        $settings = PFC_Settings::get();

        // If called by cron but autocleanup is disabled — skip silently.
        if ( ! $dry_run && $trigger === 'cron' && empty( $settings['enabled'] ) ) {
            return array(
                'orders_processed' => 0,
                'files_deleted'    => 0,
                'bytes_deleted'    => 0,
                'orphans_deleted'  => 0,
                'orphans_bytes'    => 0,
                'duration_ms'      => 0,
                'skipped'          => true,
            );
        }

        $orders_result  = $this->cleanup_orders( $settings, $dry_run );
        $orphans_result = $this->cleanup_orphans( $settings, $dry_run );

        $duration_ms = (int) round( ( microtime( true ) - $start ) * 1000 );

        $result = array(
            'orders_processed' => (int) $orders_result['orders'],
            'files_deleted'    => (int) $orders_result['files'],
            'bytes_deleted'    => (int) $orders_result['bytes'],
            'orphans_deleted'  => (int) $orphans_result['count'],
            'orphans_bytes'    => (int) $orphans_result['bytes'],
            'duration_ms'      => $duration_ms,
        );

        if ( ! $dry_run ) {
            update_option( self::LAST_RUN_KEY, array(
                'time'    => time(),
                'trigger' => $trigger,
                'result'  => $result,
            ) );
            $this->append_log( array_merge( $result, array(
                'time'    => time(),
                'trigger' => $trigger,
            ) ) );

            if ( $result['files_deleted'] > 0 || $result['orphans_deleted'] > 0 ) {
                error_log( sprintf(
                    '[PFC] %s run: %d files (%s), %d orphans, %d orders',
                    $trigger,
                    $result['files_deleted'],
                    PFC_Stats::format_bytes( $result['bytes_deleted'] ),
                    $result['orphans_deleted'],
                    $result['orders_processed']
                ) );
            }
        }

        return $result;
    }

    /**
     * Remove files from orders matching status/age criteria.
     */
    private function cleanup_orders( $settings, $dry_run ) {
        $cutoff     = gmdate( 'Y-m-d H:i:s', strtotime( "-{$settings['days']} days" ) );
        $date_param = ( $settings['date_field'] === 'date_modified' ) ? 'modified_before' : 'date_before';

        $order_ids = wc_get_orders( array(
            'limit'        => self::ORDER_LIMIT,
            'status'       => $settings['statuses'],
            $date_param    => $cutoff,
            'meta_key'     => '_podval_print_file_ids',
            'meta_compare' => 'EXISTS',
            'return'       => 'ids',
        ) );

        $orders_processed = 0;
        $files_deleted    = 0;
        $bytes_deleted    = 0;

        foreach ( $order_ids as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) continue;

            $file_ids = $order->get_meta( '_podval_print_file_ids', true );
            if ( empty( $file_ids ) || ! is_array( $file_ids ) ) continue;

            foreach ( $file_ids as $att_id ) {
                $att_id = (int) $att_id;
                if ( ! $att_id ) continue;

                // Only our flagged attachments, for safety.
                if ( get_post_meta( $att_id, '_podval_print_upload', true ) !== '1' ) continue;

                $path = get_attached_file( $att_id );
                $size = ( $path && file_exists( $path ) ) ? (int) filesize( $path ) : 0;

                if ( $dry_run ) {
                    $files_deleted++;
                    $bytes_deleted += $size;
                    continue;
                }

                if ( wp_delete_attachment( $att_id, true ) ) {
                    $files_deleted++;
                    $bytes_deleted += $size;
                }
            }

            if ( ! $dry_run ) {
                $order->delete_meta_data( '_podval_print_file_ids' );
                $order->save();
            }
            $orders_processed++;
        }

        return array(
            'orders' => $orders_processed,
            'files'  => $files_deleted,
            'bytes'  => $bytes_deleted,
        );
    }

    /**
     * Remove orphan attachments (tagged but post_parent = 0 for N days).
     */
    private function cleanup_orphans( $settings, $dry_run ) {
        global $wpdb;

        $cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$settings['orphan_days']} days" ) );

        $orphan_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                 AND pm.meta_key = '_podval_print_upload' AND pm.meta_value = '1'
             WHERE p.post_type = 'attachment'
             AND p.post_parent = 0
             AND p.post_date < %s
             LIMIT %d",
            $cutoff,
            self::ORPHAN_LIMIT
        ) );

        $count = 0;
        $bytes = 0;

        foreach ( $orphan_ids as $att_id ) {
            $att_id = (int) $att_id;
            $path = get_attached_file( $att_id );
            $size = ( $path && file_exists( $path ) ) ? (int) filesize( $path ) : 0;

            if ( $dry_run ) {
                $count++;
                $bytes += $size;
                continue;
            }

            if ( wp_delete_attachment( $att_id, true ) ) {
                $count++;
                $bytes += $size;
            }
        }

        return array( 'count' => $count, 'bytes' => $bytes );
    }

    /**
     * Dry-run — no deletion.
     *
     * @return array
     */
    public function preview() {
        return $this->run( 'preview', true );
    }

    /**
     * Find files on disk inside customer-prints/ that have no matching attachment row.
     *
     * @return array[] list of ['path','rel','size','mtime']
     */
    public function find_ghost_files() {
        $upload_dir = wp_upload_dir();
        $base       = trailingslashit( $upload_dir['basedir'] ) . 'customer-prints';

        if ( ! is_dir( $base ) ) return array();

        global $wpdb;
        $known = $wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_wp_attached_file'
             AND meta_value LIKE 'customer-prints/%'"
        );
        $known_paths = array_flip( array_map( function( $p ) {
            return str_replace( '\\', '/', ltrim( $p, '/' ) );
        }, $known ) );

        $ghosts   = array();
        $basedir  = $upload_dir['basedir'];

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS )
            );
            foreach ( $iterator as $file ) {
                if ( ! $file->isFile() ) continue;
                $full = $file->getPathname();
                $rel  = ltrim( str_replace( array( $basedir, '\\' ), array( '', '/' ), $full ), '/' );

                if ( ! isset( $known_paths[ $rel ] ) ) {
                    $ghosts[] = array(
                        'path'  => $full,
                        'rel'   => $rel,
                        'size'  => (int) $file->getSize(),
                        'mtime' => (int) $file->getMTime(),
                    );
                }
            }
        } catch ( Exception $e ) {
            // Return whatever we collected.
        }

        return $ghosts;
    }

    /**
     * Delete all ghost files found on disk.
     *
     * @return array{count:int, bytes:int}
     */
    public function delete_ghost_files() {
        $ghosts = $this->find_ghost_files();
        $count  = 0;
        $bytes  = 0;

        $upload_dir = wp_upload_dir();
        $prints_dir = trailingslashit( $upload_dir['basedir'] ) . 'customer-prints';
        $prints_real = realpath( $prints_dir );

        foreach ( $ghosts as $g ) {
            // Safety #1: path must contain /customer-prints/
            if ( strpos( str_replace( '\\', '/', $g['path'] ), '/customer-prints/' ) === false ) continue;

            // Safety #2: realpath resolves under customer-prints/ (prevents symlink escape).
            $real = realpath( $g['path'] );
            if ( ! $real || ! $prints_real || strpos( $real, $prints_real ) !== 0 ) continue;

            if ( @unlink( $g['path'] ) ) {
                $count++;
                $bytes += (int) $g['size'];
            }
        }

        // Clean up empty subdirectories.
        $this->remove_empty_dirs( $prints_dir );

        return array( 'count' => $count, 'bytes' => $bytes );
    }

    /**
     * Recursively remove empty subdirectories under $dir (keeps $dir itself).
     */
    private function remove_empty_dirs( $dir ) {
        if ( ! is_dir( $dir ) ) return;
        $items = glob( rtrim( $dir, '/' ) . '/*', GLOB_ONLYDIR );
        if ( ! is_array( $items ) ) return;
        foreach ( $items as $sub ) {
            $this->remove_empty_dirs( $sub );
            @rmdir( $sub ); // fails silently if not empty
        }
    }

    /**
     * Append entry to run log, trim to PFC_LOG_MAX.
     */
    private function append_log( $entry ) {
        $log = get_option( self::LOG_KEY, array() );
        if ( ! is_array( $log ) ) $log = array();
        array_unshift( $log, $entry );
        if ( count( $log ) > PFC_LOG_MAX ) {
            $log = array_slice( $log, 0, PFC_LOG_MAX );
        }
        update_option( self::LOG_KEY, $log, false );
    }

    /**
     * @return array
     */
    public function get_log() {
        $log = get_option( self::LOG_KEY, array() );
        return is_array( $log ) ? $log : array();
    }

    /**
     * Clear run log.
     */
    public function clear_log() {
        delete_option( self::LOG_KEY );
    }
}

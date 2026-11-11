<?php
/**
 * Settings storage and sanitization.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class PFC_Settings {

    const OPTION_KEY = 'pfc_settings';

    /**
     * Default settings.
     *
     * @return array
     */
    public static function defaults() {
        return array(
            'enabled'     => 1,
            'days'        => 30,
            'statuses'    => array( 'wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed' ),
            'orphan_days' => 7,
            'date_field'  => 'date_created',
        );
    }

    /**
     * Get current settings, merged with defaults.
     *
     * @return array
     */
    public static function get() {
        $saved = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $saved ) ) $saved = array();
        return wp_parse_args( $saved, self::defaults() );
    }

    /**
     * Save sanitized settings.
     *
     * @param array $data
     * @return array sanitized settings
     */
    public static function save( $data ) {
        $clean = self::sanitize( $data );
        update_option( self::OPTION_KEY, $clean );
        return $clean;
    }

    /**
     * Sanitize input.
     *
     * @param array $data
     * @return array
     */
    public static function sanitize( $data ) {
        $d   = self::defaults();
        $out = array();

        $out['enabled']     = ! empty( $data['enabled'] ) ? 1 : 0;
        $out['days']        = max( 1, min( 365, (int) ( $data['days'] ?? 30 ) ) );
        $out['orphan_days'] = max( 1, min( 90,  (int) ( $data['orphan_days'] ?? 7 ) ) );

        $allowed_statuses = array(
            'wc-pending', 'wc-on-hold', 'wc-processing',
            'wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed',
        );
        $raw = ( isset( $data['statuses'] ) && is_array( $data['statuses'] ) )
            ? $data['statuses']
            : $d['statuses'];
        $out['statuses'] = array_values( array_intersect( $allowed_statuses, $raw ) );
        if ( empty( $out['statuses'] ) ) {
            $out['statuses'] = $d['statuses'];
        }

        $out['date_field'] = in_array( $data['date_field'] ?? '', array( 'date_created', 'date_modified' ), true )
            ? $data['date_field']
            : 'date_created';

        return $out;
    }
}

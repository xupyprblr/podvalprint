<?php
/**
 * Podval Print — Currency Switcher (BYN / RUB)
 *
 * - Customizer: exchange rate setting
 * - PHP helpers for raw price data attributes
 * - JS-based conversion (cache-safe — no server-side switching)
 *
 *
 */
defined( 'ABSPATH' ) || exit;

/* ══════════════════════════════════════════
   CUSTOMIZER: Валюта
   ══════════════════════════════════════════ */
add_action( 'customize_register', function( WP_Customize_Manager $wp_customize ) {

    $wp_customize->add_section( 'podval_currency', [
        'title'       => '💱 Валюта (RUB)',
        'panel'       => 'podval_panel',
        'description' => 'Переключатель BYN / RUB для российских покупателей. Оплата всегда в BYN.',
        'priority'    => 26,
    ] );

    // Включить переключатель
    $wp_customize->add_setting( 'podval_currency_enabled', [
        'default'           => '1',
        'sanitize_callback' => 'absint',
    ] );
    $wp_customize->add_control( 'podval_currency_enabled', [
        'label'       => 'Включить переключатель валют',
        'description' => 'Показывает BYN/RUB селектор в шапке сайта рядом с корзиной',
        'section'     => 'podval_currency',
        'type'        => 'checkbox',
    ] );

    // Курс BYN → RUB
    $wp_customize->add_setting( 'podval_currency_rate', [
        'default'           => '28.8',
        'sanitize_callback' => function( $val ) {
            $val = str_replace( ',', '.', $val );
            $num = floatval( $val );
            return $num > 0 ? (string) $num : '28.8';
        },
    ] );
    $wp_customize->add_control( 'podval_currency_rate', [
        'label'       => 'Курс: 1 BYN = ? RUB',
        'description' => 'Например: 28.8 — означает 1 BYN = 28.8 RUB. Цены в RUB округляются вверх до 50.',
        'section'     => 'podval_currency',
        'type'        => 'text',
    ] );

    // Шаг округления
    $wp_customize->add_setting( 'podval_currency_round_step', [
        'default'           => '50',
        'sanitize_callback' => 'absint',
    ] );
    $wp_customize->add_control( 'podval_currency_round_step', [
        'label'       => 'Шаг округления (RUB)',
        'description' => 'Цена в RUB округляется вверх до ближайшего кратного этому числу. По умолчанию: 50',
        'section'     => 'podval_currency',
        'type'        => 'number',
        'input_attrs' => [ 'min' => 1, 'max' => 500, 'step' => 1 ],
    ] );

} );


/* ══════════════════════════════════════════
   FILTER: добавляем data-price-byn к wc_price() output
   ══════════════════════════════════════════ */
add_filter( 'wc_price', function( $return, $price, $args ) {
    if ( ! get_theme_mod( 'podval_currency_enabled', true ) ) {
        return $return;
    }
    // Добавляем data-атрибут с сырой ценой в BYN для JS-конвертации
    $raw = floatval( $price );
    $return = str_replace(
        '<span class="woocommerce-Price-amount amount"',
        '<span class="woocommerce-Price-amount amount" data-price-byn="' . esc_attr( $raw ) . '"',
        $return
    );
    return $return;
}, 10, 3 );


/* ══════════════════════════════════════════
   FILTER: Cart total — add data attribute
   ══════════════════════════════════════════ */
add_filter( 'woocommerce_cart_totals_order_total_html', function( $value ) {
    if ( ! get_theme_mod( 'podval_currency_enabled', true ) ) {
        return $value;
    }
    $total = WC()->cart ? (float) WC()->cart->get_total( 'edit' ) : 0;
    return '<span data-price-byn="' . esc_attr( $total ) . '">' . $value . '</span>';
} );


/* ══════════════════════════════════════════
   ENQUEUE: currency.js + config
   ══════════════════════════════════════════ */
add_action( 'wp_enqueue_scripts', function() {
    if ( ! get_theme_mod( 'podval_currency_enabled', true ) ) {
        return;
    }

    wp_enqueue_script(
        'podval-currency',
        function_exists( 'podval_js_file' )
            ? podval_js_file( 'currency.js' )
            : get_template_directory_uri() . '/assets/js/currency.js',
        [],
        wp_get_theme()->get( 'Version' ) ?: '2.0.26',
        true
    );

    wp_localize_script( 'podval-currency', 'PodvalCurrency', [
        'rate'      => (float) get_theme_mod( 'podval_currency_rate', 28.8 ),
        'roundStep' => (int) get_theme_mod( 'podval_currency_round_step', 50 ),
        'symbol'    => '₽',
        'bynSymbol' => 'Br',
    ] );
}, 20 );

<?php
/**
 * Plugin Name: Podval — СДЭК Доставка
 * Description: Интеграция доставки СДЭК: расчёт стоимости, виджет ПВЗ, сохранение данных в заказ.
 * Version: 0.1.0
 * Author: Podval Print
 * Text Domain: podval-cdek
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 */

defined( 'ABSPATH' ) || exit;

define( 'PODVAL_CDEK_VERSION', '0.1.0' );
define( 'PODVAL_CDEK_PATH', plugin_dir_path( __FILE__ ) );
define( 'PODVAL_CDEK_URL', plugin_dir_url( __FILE__ ) );

/**
 * Main plugin class
 */
final class Podval_CDEK {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Admin settings
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );

        // AJAX endpoints (for logged-in and guest users)
        add_action( 'wp_ajax_podval_cdek_calc', [ $this, 'ajax_calculate' ] );
        add_action( 'wp_ajax_nopriv_podval_cdek_calc', [ $this, 'ajax_calculate' ] );

        add_action( 'wp_ajax_podval_cdek_pvz_list', [ $this, 'ajax_pvz_list' ] );
        add_action( 'wp_ajax_nopriv_podval_cdek_pvz_list', [ $this, 'ajax_pvz_list' ] );

        add_action( 'wp_ajax_podval_cdek_city_search', [ $this, 'ajax_city_search' ] );
        add_action( 'wp_ajax_nopriv_podval_cdek_city_search', [ $this, 'ajax_city_search' ] );

        // Save CDEK data to order meta
        add_action( 'woocommerce_checkout_create_order', [ $this, 'save_order_meta' ], 10, 2 );

        // Display CDEK info in admin order page
        add_action( 'woocommerce_admin_order_data_after_shipping_address', [ $this, 'display_order_meta' ] );

        // Enqueue frontend assets on checkout
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_checkout_assets' ] );
    }

    /* ───────────────────────────────────────────
       SETTINGS / OPTIONS
    ─────────────────────────────────────────── */

    /**
     * Get all plugin options with defaults
     */
    public static function get_options() {
        return wp_parse_args(
            get_option( 'podval_cdek_options', [] ),
            [
                'account'           => '',
                'secure'            => '',
                'contract_type'     => 'online_store', // online_store | delivery
                'city_from'         => 'Минск',
                'city_from_code'    => '', // CDEK city code, resolved automatically
                'mode'              => 'pvz', // pvz | door
                'tariff_code'       => '136', // 136=склад-склад, 137=склад-дверь
                'markup'            => '',
                'min_order'         => '',
                'free_from'         => '',
                'default_width'     => '20',
                'default_height'    => '2',
                'default_length'    => '30',
                'default_weight'    => '150',
                'enabled'           => '1',
                'test_mode'         => '0',
                'ymaps_key'         => '',
                'round_mode'        => 'rub_up', // none | rub_up (до целого рубля вверх) | dime_up (до 10 коп. вверх)
            ]
        );
    }

    /**
     * Get API base URL based on test mode
     */
    public static function api_base() {
        $opts = self::get_options();
        return ! empty( $opts['test_mode'] ) ? 'https://api.edu.cdek.ru/v2' : 'https://api.cdek.ru/v2';
    }

    /* ───────────────────────────────────────────
       ADMIN MENU & PAGE
    ─────────────────────────────────────────── */

    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'СДЭК Доставка',
            'СДЭК Доставка',
            'manage_woocommerce',
            'podval-cdek',
            [ $this, 'render_settings_page' ]
        );
    }

    public function register_settings() {
        register_setting( 'podval_cdek_group', 'podval_cdek_options', [
            'sanitize_callback' => [ $this, 'sanitize_options' ],
        ] );
    }

    public function sanitize_options( $input ) {
        $clean = [];
        $clean['account']        = sanitize_text_field( $input['account'] ?? '' );
        $clean['secure']         = sanitize_text_field( $input['secure'] ?? '' );
        $clean['contract_type']  = in_array( $input['contract_type'] ?? '', [ 'online_store', 'delivery' ] )
            ? $input['contract_type'] : 'online_store';
        $clean['city_from']      = sanitize_text_field( $input['city_from'] ?? 'Минск' );
        $clean['city_from_code'] = sanitize_text_field( $input['city_from_code'] ?? '' );
        $clean['mode']           = in_array( $input['mode'] ?? '', [ 'pvz', 'door' ] )
            ? $input['mode'] : 'pvz';
        $clean['tariff_code']    = sanitize_text_field( $input['tariff_code'] ?? '136' );
        $clean['markup']         = sanitize_text_field( $input['markup'] ?? '' );
        $clean['min_order']      = sanitize_text_field( $input['min_order'] ?? '' );
        $clean['free_from']      = sanitize_text_field( $input['free_from'] ?? '' );
        $clean['default_width']  = absint( $input['default_width'] ?? 20 );
        $clean['default_height'] = absint( $input['default_height'] ?? 2 );
        $clean['default_length'] = absint( $input['default_length'] ?? 30 );
        $clean['default_weight'] = absint( $input['default_weight'] ?? 150 );
        $clean['enabled']        = ! empty( $input['enabled'] ) ? '1' : '0';
        $clean['test_mode']      = ! empty( $input['test_mode'] ) ? '1' : '0';
        $clean['ymaps_key']      = sanitize_text_field( $input['ymaps_key'] ?? '' );
        $clean['round_mode']     = in_array( $input['round_mode'] ?? '', [ 'none', 'rub_up', 'dime_up' ], true )
            ? $input['round_mode'] : 'rub_up';

        // Auto-resolve city code if city name changed
        if ( ! empty( $clean['account'] ) && ! empty( $clean['secure'] ) ) {
            $resolved = self::resolve_city_code( $clean['city_from'], $clean );
            if ( $resolved ) {
                $clean['city_from_code'] = $resolved;
            }
        }

        // Clear cached token when credentials change
        delete_transient( 'podval_cdek_token' );

        return $clean;
    }

    public function render_settings_page() {
        $opts = self::get_options();
        $connection_ok = null;

        // Test connection if credentials are set
        if ( ! empty( $opts['account'] ) && ! empty( $opts['secure'] ) ) {
            delete_transient( 'podval_cdek_token' ); // always test fresh
            $token = self::get_token( true );
            $connection_ok = ! empty( $token );
        }

        $last_error = get_option( 'podval_cdek_last_error', '' );
        ?>
        <div class="wrap">
            <h1>СДЭК Доставка — Настройки</h1>

            <?php if ( $connection_ok === true ) : ?>
                <div class="notice notice-success"><p>✅ Подключение к API СДЭК успешно<?php
                    if ( ! empty( $opts['city_from_code'] ) ) echo ' · Город отправления: ' . esc_html( $opts['city_from'] ) . ' (код ' . esc_html( $opts['city_from_code'] ) . ')';
                ?></p></div>
            <?php elseif ( $connection_ok === false ) : ?>
                <div class="notice notice-error"><p>❌ Ошибка подключения к API СДЭК — проверьте учётные данные</p>
                <?php if ( $last_error ) : ?>
                    <p><code style="word-break:break-all;"><?php echo esc_html( $last_error ); ?></code></p>
                <?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields( 'podval_cdek_group' ); ?>

                <h2>Интеграция</h2>
                <p class="description">Интеграция используется для расчёта стоимости доставки.</p>

                <table class="form-table">
                    <tr>
                        <th>Учётная запись</th>
                        <td><input type="text" name="podval_cdek_options[account]" value="<?php echo esc_attr( $opts['account'] ); ?>" class="regular-text" autocomplete="off"></td>
                    </tr>
                    <tr>
                        <th>Секретный ключ</th>
                        <td><input type="password" name="podval_cdek_options[secure]" value="<?php echo esc_attr( $opts['secure'] ); ?>" class="regular-text" autocomplete="off"></td>
                    </tr>
                    <tr>
                        <th>Договор</th>
                        <td>
                            <select name="podval_cdek_options[contract_type]">
                                <option value="online_store" <?php selected( $opts['contract_type'], 'online_store' ); ?>>Интернет-магазин</option>
                                <option value="delivery" <?php selected( $opts['contract_type'], 'delivery' ); ?>>Доставка</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Город отправления</th>
                        <td>
                            <input type="text" name="podval_cdek_options[city_from]" value="<?php echo esc_attr( $opts['city_from'] ); ?>" class="regular-text">
                            <?php if ( ! empty( $opts['city_from_code'] ) ) : ?>
                                <p class="description">Код СДЭК: <?php echo esc_html( $opts['city_from_code'] ); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Режим доставки</th>
                        <td>
                            <label><input type="radio" name="podval_cdek_options[mode]" value="pvz" <?php checked( $opts['mode'], 'pvz' ); ?>> до ПВЗ</label>&nbsp;&nbsp;
                            <label><input type="radio" name="podval_cdek_options[mode]" value="door" <?php checked( $opts['mode'], 'door' ); ?>> до двери</label>
                        </td>
                    </tr>
                </table>

                <h2>Стоимость</h2>
                <table class="form-table">
                    <tr>
                        <th>Своя наценка</th>
                        <td><input type="text" name="podval_cdek_options[markup]" value="<?php echo esc_attr( $opts['markup'] ); ?>" class="small-text"> BYN <p class="description">Добавляется к рассчитанной стоимости СДЭК</p></td>
                    </tr>
                    <tr>
                        <th>Минимальный заказ</th>
                        <td><input type="text" name="podval_cdek_options[min_order]" value="<?php echo esc_attr( $opts['min_order'] ); ?>" class="small-text"> BYN <p class="description">СДЭК не отображается при сумме корзины ниже</p></td>
                    </tr>
                    <tr>
                        <th>Бесплатно при заказе</th>
                        <td><input type="text" name="podval_cdek_options[free_from]" value="<?php echo esc_attr( $opts['free_from'] ); ?>" class="small-text"> BYN <p class="description">Доставка бесплатна при сумме заказа от указанной</p></td>
                    </tr>
                    <tr>
                        <th>Округление итоговой стоимости</th>
                        <td>
                            <select name="podval_cdek_options[round_mode]">
                                <option value="none"    <?php selected( $opts['round_mode'], 'none' ); ?>>Без округления (как у СДЭК, до копеек)</option>
                                <option value="dime_up" <?php selected( $opts['round_mode'], 'dime_up' ); ?>>Вверх до 10 копеек</option>
                                <option value="rub_up"  <?php selected( $opts['round_mode'], 'rub_up' ); ?>>Вверх до целого рубля (BYN)</option>
                            </select>
                            <p class="description">Применяется к итоговой стоимости с НДС и доп. сборами (например, сбор за объявленную стоимость).</p>
                        </td>
                    </tr>
                </table>

                <h2>Габариты и вес товара по умолчанию</h2>
                <p class="description">Используются для расчёта если у товара не указаны собственные размеры.</p>
                <table class="form-table">
                    <tr>
                        <th>Ширина</th>
                        <td><input type="number" name="podval_cdek_options[default_width]" value="<?php echo esc_attr( $opts['default_width'] ); ?>" class="small-text" min="1"> см</td>
                    </tr>
                    <tr>
                        <th>Высота</th>
                        <td><input type="number" name="podval_cdek_options[default_height]" value="<?php echo esc_attr( $opts['default_height'] ); ?>" class="small-text" min="1"> см</td>
                    </tr>
                    <tr>
                        <th>Длина</th>
                        <td><input type="number" name="podval_cdek_options[default_length]" value="<?php echo esc_attr( $opts['default_length'] ); ?>" class="small-text" min="1"> см</td>
                    </tr>
                    <tr>
                        <th>Вес</th>
                        <td><input type="number" name="podval_cdek_options[default_weight]" value="<?php echo esc_attr( $opts['default_weight'] ); ?>" class="small-text" min="1"> г</td>
                    </tr>
                </table>

                <h2>Дополнительно</h2>
                <table class="form-table">
                    <tr>
                        <th>Ключ Яндекс.Карт</th>
                        <td><input type="text" name="podval_cdek_options[ymaps_key]" value="<?php echo esc_attr( $opts['ymaps_key'] ); ?>" class="regular-text" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                        <p class="description">API-ключ для отображения карты ПВЗ. <a href="https://developer.tech.yandex.ru/" target="_blank">Получить ключ</a></p></td>
                    </tr>
                    <tr>
                        <th>Включено</th>
                        <td><label><input type="checkbox" name="podval_cdek_options[enabled]" value="1" <?php checked( $opts['enabled'], '1' ); ?>> Показывать СДЭК в чекауте</label></td>
                    </tr>
                    <tr>
                        <th>Тестовый режим</th>
                        <td><label><input type="checkbox" name="podval_cdek_options[test_mode]" value="1" <?php checked( $opts['test_mode'], '1' ); ?>> Использовать тестовый сервер СДЭК (api.edu.cdek.ru)</label></td>
                    </tr>
                </table>

                <?php submit_button( 'Сохранить настройки' ); ?>
            </form>
        </div>
        <?php
    }

    /* ───────────────────────────────────────────
       API HELPERS
    ─────────────────────────────────────────── */

    /**
     * Get OAuth token (cached in transient)
     */
    public static function get_token( $force_refresh = false ) {
        if ( ! $force_refresh ) {
            $cached = get_transient( 'podval_cdek_token' );
            if ( $cached ) {
                return $cached;
            }
        }

        $opts = self::get_options();
        if ( empty( $opts['account'] ) || empty( $opts['secure'] ) ) {
            return false;
        }

        $base = self::api_base();
        $url  = $base . '/oauth/token';

        // CDEK API expects form-urlencoded POST with grant_type, client_id, client_secret
        $post_body = [
            'grant_type'    => 'client_credentials',
            'client_id'     => $opts['account'],
            'client_secret' => $opts['secure'],
        ];

        $response = wp_remote_post( $url, [
            'timeout'   => 20,
            'sslverify' => true,
            'headers'   => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => $post_body,
        ] );

        // Store last error for diagnostics
        if ( is_wp_error( $response ) ) {
            update_option( 'podval_cdek_last_error', 'WP Error: ' . $response->get_error_message() );
            // Retry without SSL verification (some shared hosts have outdated CA bundles)
            $response = wp_remote_post( $url, [
                'timeout'   => 20,
                'sslverify' => false,
                'headers'   => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => $post_body,
            ] );

            if ( is_wp_error( $response ) ) {
                update_option( 'podval_cdek_last_error', 'WP Error (no SSL): ' . $response->get_error_message() );
                return false;
            }
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $raw_body  = wp_remote_retrieve_body( $response );
        $body      = json_decode( $raw_body, true );

        if ( empty( $body['access_token'] ) ) {
            $err = 'URL: ' . $url . ' | HTTP ' . $http_code . ': ';
            if ( ! empty( $body['error'] ) ) {
                $err .= $body['error'] . ' — ' . ( $body['error_description'] ?? '' );
            } else {
                $err .= substr( $raw_body, 0, 300 );
            }
            update_option( 'podval_cdek_last_error', $err );
            return false;
        }

        // Clear error on success
        delete_option( 'podval_cdek_last_error' );

        $expires = ( $body['expires_in'] ?? 3600 ) - 60;
        set_transient( 'podval_cdek_token', $body['access_token'], max( $expires, 60 ) );

        return $body['access_token'];
    }

    /**
     * Make authenticated API request
     */
    public static function api_request( $method, $endpoint, $body = null ) {
        $token = self::get_token();
        if ( ! $token ) {
            return new WP_Error( 'cdek_auth', 'Не удалось получить токен СДЭК' );
        }

        $args = [
            'timeout' => 20,
            'method'  => strtoupper( $method ),
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
        ];

        if ( $body && $args['method'] === 'POST' ) {
            $args['body'] = wp_json_encode( $body );
        }

        $url = self::api_base() . $endpoint;
        if ( $body && $args['method'] === 'GET' ) {
            $url = add_query_arg( $body, $url );
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return json_decode( wp_remote_retrieve_body( $response ), true );
    }

    /**
     * Resolve CDEK city code by city name
     */
    public static function resolve_city_code( $city_name, $opts = null ) {
        if ( ! $opts ) {
            $opts = self::get_options();
        }

        // Temporarily use provided opts for auth
        $token = self::get_token();
        if ( ! $token ) {
            return false;
        }

        $result = self::api_request( 'GET', '/location/cities', [
            'city'          => $city_name,
            'country_codes' => 'BY',
            'size'          => 1,
        ] );

        if ( is_wp_error( $result ) || empty( $result[0]['code'] ) ) {
            return false;
        }

        return (string) $result[0]['code'];
    }

    /**
     * Calculate total package dimensions from cart
     */
    public static function get_package_from_cart() {
        $opts = self::get_options();

        $total_weight = 0;
        $max_width    = 0;
        $max_length   = 0;
        $total_height = 0;

        if ( function_exists( 'WC' ) && WC()->cart ) {
            foreach ( WC()->cart->get_cart() as $item ) {
                $product = $item['data'];
                $qty     = $item['quantity'];

                $w = $product->get_weight() ? (float) $product->get_weight() * 1000 : (float) $opts['default_weight'];
                $total_weight += $w * $qty;

                $width  = $product->get_width()  ? (float) $product->get_width()  : (float) $opts['default_width'];
                $length = $product->get_length() ? (float) $product->get_length() : (float) $opts['default_length'];
                $height = $product->get_height() ? (float) $product->get_height() : (float) $opts['default_height'];

                if ( $width > $max_width ) $max_width = $width;
                if ( $length > $max_length ) $max_length = $length;
                $total_height += $height * $qty;
            }
        }

        return [
            'weight' => max( (int) $total_weight, (int) $opts['default_weight'] ),
            'width'  => max( (int) $max_width, (int) $opts['default_width'] ),
            'length' => max( (int) $max_length, (int) $opts['default_length'] ),
            'height' => max( (int) $total_height, (int) $opts['default_height'] ),
        ];
    }

    /* ───────────────────────────────────────────
       AJAX: CALCULATE DELIVERY COST
    ─────────────────────────────────────────── */

    public function ajax_calculate() {
        check_ajax_referer( 'podval_cdek_nonce', 'nonce' );

        $opts = self::get_options();
        if ( $opts['enabled'] !== '1' ) {
            wp_send_json_error( 'СДЭК отключён' );
        }

        $city_code = sanitize_text_field( $_POST['city_code'] ?? '' );
        $city_name = sanitize_text_field( $_POST['city_name'] ?? '' );

        // If no city code, try to resolve by name
        if ( empty( $city_code ) && ! empty( $city_name ) ) {
            // Search city in CDEK database
            $cities = self::api_request( 'GET', '/location/cities', [
                'city'  => $city_name,
                'size'  => 5,
            ] );

            if ( ! is_wp_error( $cities ) && ! empty( $cities[0]['code'] ) ) {
                $city_code = $cities[0]['code'];
            } else {
                wp_send_json_error( 'Город не найден в базе СДЭК' );
            }
        }

        if ( empty( $city_code ) ) {
            wp_send_json_error( 'Не указан город доставки' );
        }

        // Determine tariff based on mode
        $tariff = $opts['mode'] === 'door' ? 137 : 136;

        // Get package dims
        $package = self::get_package_from_cart();

        // Объявленная стоимость отправления = сумма товаров в корзине (без купонов и сборов).
        // Передаём её в packages[].items[] — иначе СДЭК не посчитает сбор за объявленную стоимость
        // (либо посчитает его от минимума, что менее предсказуемо).
        $declared_value = 0.0;
        if ( function_exists( 'WC' ) && WC()->cart ) {
            // get_cart_contents_total — сумма товаров после скидок, до доставки и сборов.
            $declared_value = (float) WC()->cart->get_cart_contents_total();
        }
        // Минимум 1, чтобы СДЭК не ругнулся на нулевую стоимость на интернет-магазин-тарифах.
        $declared_value = max( 1.0, round( $declared_value, 2 ) );

        // Resolve sender city code if not cached
        $from_code = (int) $opts['city_from_code'];
        if ( ! $from_code && ! empty( $opts['city_from'] ) ) {
            $resolved = self::resolve_city_code( $opts['city_from'] );
            if ( $resolved ) {
                $from_code = (int) $resolved;
                // Save for future requests
                $opts_save = get_option( 'podval_cdek_options', [] );
                $opts_save['city_from_code'] = (string) $resolved;
                update_option( 'podval_cdek_options', $opts_save );
            }
        }

        if ( ! $from_code ) {
            wp_send_json_error( 'Не определён код города отправления. Пересохраните настройки плагина.' );
        }

        // Build calculator request (no currency = use contract default, BYN)
        // packages.items нужен для расчёта дополнительного сбора за объявленную стоимость.
        $calc_body = [
            'type'          => 1, // internet-shop
            'tariff_code'   => (int) $tariff,
            'from_location' => [ 'code' => $from_code ],
            'to_location'   => [ 'code' => (int) $city_code ],
            'packages'      => [
                [
                    'weight' => $package['weight'],
                    'length' => $package['length'],
                    'width'  => $package['width'],
                    'height' => $package['height'],
                    'items'  => [
                        [
                            'ware_key' => 'cart',
                            'name'     => 'Заказ',
                            'cost'     => $declared_value, // объявленная стоимость
                            'amount'   => 1,
                            'weight'   => $package['weight'],
                            'payment'  => [
                                'value' => 0, // без наложенного платежа
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = self::api_request( 'POST', '/calculator/tariff', $calc_body );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        if ( ! empty( $result['errors'] ) ) {
            $err_msg = $result['errors'][0]['message'] ?? 'Ошибка расчёта';
            wp_send_json_error( $err_msg );
        }

        // Базовая стоимость тарифа (без НДС и доп. сборов).
        $base_sum   = (float) ( $result['delivery_sum'] ?? 0 );
        // Итоговая стоимость с НДС и доп. сборами (объявленная стоимость и т.п.).
        // Если total_sum по какой-то причине не пришёл — fallback на delivery_sum.
        $total_sum  = isset( $result['total_sum'] ) ? (float) $result['total_sum'] : $base_sum;

        // Сумма доп. услуг (для прозрачности — например, сбор за объявленную стоимость с НДС).
        $services_sum = 0.0;
        if ( ! empty( $result['services'] ) && is_array( $result['services'] ) ) {
            foreach ( $result['services'] as $svc ) {
                // total_sum конкретной услуги уже включает её НДС; если его нет — sum + vat_sum.
                if ( isset( $svc['total_sum'] ) ) {
                    $services_sum += (float) $svc['total_sum'];
                } else {
                    $services_sum += (float) ( $svc['sum'] ?? 0 ) + (float) ( $svc['vat_sum'] ?? 0 );
                }
            }
        }

        // НДС на саму перевозку (вычисляем как разницу: total минус base минус доп. услуги).
        $vat_sum_calc = max( 0, round( $total_sum - $base_sum - $services_sum, 2 ) );

        $period_min   = (int) ( $result['period_min'] ?? 0 );
        $period_max   = (int) ( $result['period_max'] ?? 0 );

        // Берём итоговую стоимость как основу для оплаты пользователем.
        $delivery_sum = $total_sum;

        // Apply markup
        if ( ! empty( $opts['markup'] ) ) {
            $delivery_sum += (float) $opts['markup'];
        }

        // Округление по настройке (применяем к итоговой стоимости с НДС и сборами).
        $round_mode = $opts['round_mode'] ?? 'rub_up';
        $delivery_sum = self::apply_rounding( $delivery_sum, $round_mode );

        // Check free shipping threshold
        $cart_total = WC()->cart ? (float) WC()->cart->get_total( 'edit' ) : 0;
        if ( ! empty( $opts['free_from'] ) && $cart_total >= (float) $opts['free_from'] ) {
            $delivery_sum = 0;
        }

        // Format period text
        $period_text = '';
        if ( $period_min === $period_max ) {
            $period_text = $period_min . ' ' . self::plural_days( $period_min );
        } else {
            $period_text = $period_min . '–' . $period_max . ' ' . self::plural_days( $period_max );
        }

        wp_send_json_success( [
            // delivery_sum — это уже итоговая сумма к оплате (с НДС, сборами, наценкой и округлением).
            // Ключ оставлен прежним для обратной совместимости с фронтендом и темой.
            'delivery_sum'  => round( $delivery_sum, 2 ),
            // Дополнительные поля — для отладки и отображения деталей.
            'base_sum'      => round( $base_sum, 2 ),       // тариф без НДС
            'services_sum'  => round( $services_sum, 2 ),   // доп. услуги с НДС (объявленная стоимость и т.п.)
            'vat_sum'       => $vat_sum_calc,               // НДС на саму перевозку
            'total_sum_raw' => round( $total_sum, 2 ),      // итог от СДЭК до округления и наценки
            'period_min'    => $period_min,
            'period_max'    => $period_max,
            'period_text'   => $period_text,
            'formatted'     => number_format( $delivery_sum, 2, ',', ' ' ) . ' Br',
            'is_free'       => ( $delivery_sum <= 0 ),
        ] );
    }

    /**
     * Применить округление к стоимости доставки.
     *
     * @param float  $sum  Сумма (BYN).
     * @param string $mode none | dime_up | rub_up
     * @return float
     */
    public static function apply_rounding( $sum, $mode ) {
        if ( $sum <= 0 ) {
            return 0.0;
        }
        switch ( $mode ) {
            case 'dime_up':
                // вверх до 10 копеек (0.10 BYN)
                return ceil( $sum * 10 ) / 10;
            case 'rub_up':
                // вверх до целого рубля (1 BYN)
                return (float) ceil( $sum );
            case 'none':
            default:
                return round( $sum, 2 );
        }
    }

    /**
     * Russian plural for "день/дня/дней"
     */
    private static function plural_days( $n ) {
        $n = abs( $n ) % 100;
        $n1 = $n % 10;
        if ( $n > 10 && $n < 20 ) return 'дней';
        if ( $n1 > 1 && $n1 < 5 ) return 'дня';
        if ( $n1 === 1 ) return 'день';
        return 'дней';
    }

    /* ───────────────────────────────────────────
       AJAX: CITY SEARCH
    ─────────────────────────────────────────── */

    public function ajax_city_search() {
        check_ajax_referer( 'podval_cdek_nonce', 'nonce' );

        $query = sanitize_text_field( $_POST['query'] ?? '' );
        if ( strlen( $query ) < 2 ) {
            wp_send_json_error( 'Слишком короткий запрос' );
        }

        // Cache city searches for 12 hours
        $cache_key = 'podval_cdek_cities_' . md5( $query );
        $cached    = get_transient( $cache_key );
        if ( $cached ) {
            wp_send_json_success( $cached );
        }

        $result = self::api_request( 'GET', '/location/cities', [
            'city' => $query,
            'size' => 10,
        ] );

        if ( is_wp_error( $result ) || ! is_array( $result ) ) {
            wp_send_json_error( 'Ошибка поиска' );
        }

        $cities = [];
        foreach ( $result as $city ) {
            $region = $city['region'] ?? '';
            $country = $city['country'] ?? '';
            $label = $city['city'] ?? '';
            if ( $region ) $label .= ', ' . $region;
            if ( $country ) $label .= ' (' . $country . ')';

            $cities[] = [
                'code'  => $city['code'] ?? '',
                'name'  => $city['city'] ?? '',
                'label' => $label,
            ];
        }

        set_transient( $cache_key, $cities, 12 * HOUR_IN_SECONDS );

        wp_send_json_success( $cities );
    }

    /* ───────────────────────────────────────────
       AJAX: LIST PVZ (Pickup points)
    ─────────────────────────────────────────── */

    public function ajax_pvz_list() {
        check_ajax_referer( 'podval_cdek_nonce', 'nonce' );

        $city_code = sanitize_text_field( $_POST['city_code'] ?? '' );
        if ( empty( $city_code ) ) {
            wp_send_json_error( 'Не указан город' );
        }

        // Cache PVZ list per city for 1 hour
        $cache_key = 'podval_cdek_pvz_' . $city_code;
        $cached    = get_transient( $cache_key );
        if ( $cached ) {
            wp_send_json_success( $cached );
        }

        $result = self::api_request( 'GET', '/deliverypoints', [
            'city_code' => $city_code,
            'type'      => 'PVZ',
            'is_handout' => 'true',
        ] );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        // Simplify data for frontend
        $points = [];
        if ( is_array( $result ) ) {
            foreach ( $result as $point ) {
                $code         = $point['code'] ?? '';
                $short_addr   = $point['location']['address'] ?? '';      // "ул. Олешева, 3"
                $full_addr    = $point['location']['address_full'] ?? ''; // "220090, Республика Беларусь, ..."
                $city         = $point['location']['city'] ?? '';

                // Display name = short address (clean, no service codes)
                $display_name = $short_addr ?: $code;

                // For full address, strip postal code prefix if present to save space
                $display_addr = $full_addr;
                if ( $city && $full_addr ) {
                    // Show as "Минск, ул. Олешева, 3" instead of full address_full
                    $display_addr = $city . ', ' . $short_addr;
                }

                $points[] = [
                    'code'         => $code,
                    'name'         => $display_name,
                    'address'      => $display_addr,
                    'address_full' => $full_addr,
                    'work_time'    => $point['work_time'] ?? '',
                    'phone'        => $point['phones'][0]['number'] ?? '',
                    'note'         => $point['note'] ?? '',
                    'lat'          => $point['location']['latitude'] ?? 0,
                    'lon'          => $point['location']['longitude'] ?? 0,
                ];
            }
        }

        // Clear old cache format
        delete_transient( $cache_key );
        set_transient( $cache_key, $points, HOUR_IN_SECONDS );

        wp_send_json_success( $points );
    }

    /* ───────────────────────────────────────────
       FRONTEND ASSETS
    ─────────────────────────────────────────── */

    public function enqueue_checkout_assets() {
        // Only on checkout page
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
            return;
        }

        $opts = self::get_options();
        if ( $opts['enabled'] !== '1' ) {
            return;
        }

        wp_enqueue_style(
            'podval-cdek',
            PODVAL_CDEK_URL . 'assets/cdek-checkout.css',
            [],
            PODVAL_CDEK_VERSION
        );

        // Enqueue Yandex Maps API if key is set
        $ymaps_key = $opts['ymaps_key'] ?? '';
        if ( $ymaps_key ) {
            wp_enqueue_script(
                'ymaps3',
                'https://api-maps.yandex.ru/2.1/?apikey=' . urlencode( $ymaps_key ) . '&lang=ru_RU',
                [],
                null,
                false // load in head
            );
        }

        wp_enqueue_script(
            'podval-cdek',
            PODVAL_CDEK_URL . 'assets/cdek-checkout.js',
            $ymaps_key ? [ 'ymaps3' ] : [],
            PODVAL_CDEK_VERSION,
            true
        );

        // Pass config to JS
        $min_order = ! empty( $opts['min_order'] ) ? (float) $opts['min_order'] : 0;
        $free_from = ! empty( $opts['free_from'] ) ? (float) $opts['free_from'] : 0;

        wp_localize_script( 'podval-cdek', 'PodvalCDEK', [
            'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'podval_cdek_nonce' ),
            'enabled'    => (bool) $opts['enabled'],
            'mode'       => $opts['mode'],
            'cityFrom'   => $opts['city_from'],
            'minOrder'   => $min_order,
            'freeFrom'   => $free_from,
            'ymapsKey'   => $ymaps_key,
        ] );
    }

    /* ───────────────────────────────────────────
       ORDER META
    ─────────────────────────────────────────── */

    public function save_order_meta( $order, $data ) {
        $shipping = sanitize_text_field( $_POST['podval_shipping'] ?? '' );
        if ( $shipping !== 'cdek' ) {
            return;
        }

        $order->update_meta_data( '_podval_cdek_pvz_code', sanitize_text_field( $_POST['podval_cdek_pvz_code'] ?? '' ) );
        $order->update_meta_data( '_podval_cdek_pvz_address', sanitize_text_field( $_POST['podval_cdek_pvz_address'] ?? '' ) );
        $order->update_meta_data( '_podval_cdek_city', sanitize_text_field( $_POST['podval_cdek_city'] ?? '' ) );
        $order->update_meta_data( '_podval_cdek_city_code', sanitize_text_field( $_POST['podval_cdek_city_code'] ?? '' ) );
        $order->update_meta_data( '_podval_cdek_cost', sanitize_text_field( $_POST['podval_cdek_cost'] ?? '' ) );
        $order->update_meta_data( '_podval_cdek_period', sanitize_text_field( $_POST['podval_cdek_period'] ?? '' ) );

        // Also set shipping address from PVZ
        $pvz_address = sanitize_text_field( $_POST['podval_cdek_pvz_address'] ?? '' );
        if ( $pvz_address ) {
            $order->set_shipping_address_1( $pvz_address );
        }
    }

    public function display_order_meta( $order ) {
        $pvz_code = $order->get_meta( '_podval_cdek_pvz_code' );
        if ( ! $pvz_code ) {
            return;
        }

        echo '<div class="order_data_column" style="border-top:1px solid #e5e5e5;padding-top:12px;margin-top:12px;">';
        echo '<h3>СДЭК</h3>';
        echo '<p><strong>ПВЗ:</strong> ' . esc_html( $pvz_code ) . '</p>';
        echo '<p><strong>Адрес ПВЗ:</strong> ' . esc_html( $order->get_meta( '_podval_cdek_pvz_address' ) ) . '</p>';
        echo '<p><strong>Город:</strong> ' . esc_html( $order->get_meta( '_podval_cdek_city' ) ) . '</p>';

        $cost = $order->get_meta( '_podval_cdek_cost' );
        if ( $cost ) {
            echo '<p><strong>Стоимость доставки:</strong> ' . esc_html( $cost ) . ' Br</p>';
        }

        $period = $order->get_meta( '_podval_cdek_period' );
        if ( $period ) {
            echo '<p><strong>Срок:</strong> ' . esc_html( $period ) . '</p>';
        }

        echo '</div>';
    }
}

// Initialize
Podval_CDEK::instance();

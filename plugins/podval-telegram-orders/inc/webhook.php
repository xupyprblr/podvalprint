<?php
/**
 * REST-эндпоинт для апдейтов Telegram.
 *
 * @package podval-telegram-orders
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Пространство имён REST. */
const PTG_REST_NS = 'podval/v1';

/**
 * Регистрация маршрутов.
 *
 * @return void
 */
function ptg_register_routes() {
    // permission_callback обязателен, но проверка идёт внутри по
    // секретному заголовку: Telegram не умеет ни nonce, ни cookie-авторизацию.
    register_rest_route( PTG_REST_NS, '/tg', array(
        'methods'             => 'POST',
        'callback'            => 'ptg_webhook_handle',
        'permission_callback' => '__return_true',
    ) );

    register_rest_route( PTG_REST_NS, '/tg/health', array(
        'methods'             => 'GET',
        'callback'            => 'ptg_webhook_health',
        'permission_callback' => '__return_true',
    ) );
}
add_action( 'rest_api_init', 'ptg_register_routes' );

/**
 * URL вебхука.
 *
 * @return string
 */
function ptg_webhook_url() {
    return rest_url( PTG_REST_NS . '/tg' );
}

/**
 * Типы апдейтов, которые мы просим у Telegram.
 *
 * Без явного списка business-события НЕ приходят вовсе — это главная
 * причина «почему не работает» на этой интеграции.
 *
 * @return array
 */
function ptg_allowed_updates() {
    return array(
        'message',
        'edited_message',
        'callback_query',
        'business_connection',
        'business_message',
        'edited_business_message',
        'deleted_business_messages',
    );
}

/**
 * Health-check. Используется для проверки, не кэширует ли WP-Optimize REST:
 * два GET подряд должны отдать разный `t`.
 *
 * @return WP_REST_Response
 */
function ptg_webhook_health() {
    nocache_headers();
    return new WP_REST_Response( array(
        'ok'      => true,
        't'       => time(),
        'version' => PTG_VERSION,
    ), 200 );
}

/**
 * Приём апдейта.
 *
 * @param WP_REST_Request $request Запрос.
 * @return WP_REST_Response
 */
function ptg_webhook_handle( $request ) {
    nocache_headers();

    $secret = (string) get_option( 'ptg_webhook_secret', '' );
    $sent   = (string) $request->get_header( 'x_telegram_bot_api_secret_token' );

    if ( '' === $secret || ! hash_equals( $secret, $sent ) ) {
        ptg_log( 'warn', 'Вебхук: неверный secret-заголовок', array(
            'ip' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
        ) );
        return new WP_REST_Response( null, 403 );
    }

    $update = $request->get_json_params();
    if ( ! is_array( $update ) ) {
        return new WP_REST_Response( array( 'ok' => true ), 200 );
    }

    // Telegram ретраит при таймауте — один и тот же update_id может прийти
    // несколько раз.
    $update_id = (int) ( $update['update_id'] ?? 0 );
    if ( $update_id ) {
        $key = 'ptg_upd_' . $update_id;
        if ( get_transient( $key ) ) {
            ptg_log( 'info', 'Дубль update_id пропущен', array( 'update_id' => $update_id ) );
            return new WP_REST_Response( array( 'ok' => true ), 200 );
        }
        set_transient( $key, 1, 10 * MINUTE_IN_SECONDS );
    }

    // Любая внутренняя ошибка не должна превращаться в ответ не-200: иначе
    // Telegram уходит в ретраи и копит очередь.
    try {
        ptg_route_update( $update );
    } catch ( \Throwable $e ) {
        ptg_log( 'error', 'Исключение при обработке апдейта', array(
            'err'       => $e->getMessage(),
            'update_id' => $update_id,
        ) );
    }

    return new WP_REST_Response( array( 'ok' => true ), 200 );
}

/**
 * Роутер по типу апдейта.
 *
 * Ветки business_* пока только логируются — каркас под Фазу 2.
 *
 * @param array $update Апдейт.
 * @return void
 */
function ptg_route_update( $update ) {
    if ( isset( $update['callback_query'] ) ) {
        ptg_log( 'info', 'callback_query', array(
            'data' => $update['callback_query']['data'] ?? '',
            'from' => $update['callback_query']['from']['id'] ?? 0,
        ) );
        ptg_handle_callback_query( (array) $update['callback_query'] );
        return;
    }

    if ( isset( $update['business_connection'] ) ) {
        ptg_handle_business_connection( (array) $update['business_connection'] );
        return;
    }

    if ( isset( $update['business_message'] ) ) {
        ptg_handle_business_message( (array) $update['business_message'], false );
        return;
    }

    if ( isset( $update['edited_business_message'] ) ) {
        ptg_handle_business_message( (array) $update['edited_business_message'], true );
        return;
    }

    if ( isset( $update['deleted_business_messages'] ) ) {
        ptg_handle_business_deleted( (array) $update['deleted_business_messages'] );
        return;
    }

    if ( isset( $update['message'] ) ) {
        // Личка бота. Переписку клиентов сюда не заворачиваем — она идёт
        // через business_message; здесь только служебные команды.
        ptg_handle_plain_message( (array) $update['message'] );
        do_action( 'ptg_update_message', $update['message'], $update );
        return;
    }

    foreach ( array( 'edited_message' ) as $type ) {
        if ( isset( $update[ $type ] ) ) {
            ptg_log( 'info', 'Апдейт ' . $type );
            do_action( 'ptg_update_' . $type, $update[ $type ], $update );
            return;
        }
    }

    ptg_log( 'info', 'Апдейт неизвестного типа', array( 'keys' => array_keys( $update ) ) );
}

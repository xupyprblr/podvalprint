<?php
/**
 * Клиент Telegram Bot API.
 *
 * Единая точка входа: токен, транспорт, разбор ok/description и три
 * частных случая, которые иначе всплывают мусором в истории заказа.
 *
 * @package podval-telegram-orders
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Вызов метода Bot API.
 *
 * @param string $method Имя метода (sendMessage, editMessageText, …).
 * @param array  $args   Параметры.
 * @param bool   $retry  Внутреннее: это уже повтор после 429.
 * @return mixed|WP_Error Значение поля result или WP_Error.
 */
function ptg_api( $method, $args = array(), $retry = false ) {
    $token = trim( (string) get_option( 'ptg_bot_token', '' ) );
    if ( '' === $token ) {
        return new WP_Error( 'ptg_no_token', 'Не указан Bot Token' );
    }

    // Вложенные структуры Telegram принимает только JSON-строкой.
    foreach ( $args as $k => $v ) {
        if ( is_array( $v ) ) {
            $args[ $k ] = wp_json_encode( $v );
        } elseif ( is_bool( $v ) ) {
            $args[ $k ] = $v ? 'true' : 'false';
        }
    }

    $response = wp_remote_post(
        'https://api.telegram.org/bot' . $token . '/' . $method,
        array(
            'timeout' => 15,
            'body'    => $args,
        )
    );

    if ( is_wp_error( $response ) ) {
        ptg_log( 'error', 'HTTP-ошибка ' . $method, array( 'err' => $response->get_error_message() ) );
        return new WP_Error( 'ptg_http', $response->get_error_message() );
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( is_array( $body ) && ! empty( $body['ok'] ) ) {
        return isset( $body['result'] ) ? $body['result'] : true;
    }

    $desc = is_array( $body ) && isset( $body['description'] )
        ? (string) $body['description']
        : 'Неизвестная ошибка Telegram API';
    $desc_l = mb_strtolower( $desc );

    // «message is not modified» Telegram отдаёт ошибкой. Для нас это
    // нормальный исход безрезультатного синка — иначе на каждый такой
    // вызов в заказ падает нота об ошибке.
    if ( false !== mb_strpos( $desc_l, 'message is not modified' ) ) {
        return new WP_Error( 'ptg_not_modified', $desc );
    }

    // Сообщение удалили из группы руками.
    if ( false !== mb_strpos( $desc_l, 'message to edit not found' )
        || false !== mb_strpos( $desc_l, "message can't be edited" )
        || false !== mb_strpos( $desc_l, 'message identifier is not specified' ) ) {
        return new WP_Error( 'ptg_edit_not_found', $desc );
    }

    // Флуд-контроль.
    if ( is_array( $body ) && ( 429 === (int) ( $body['error_code'] ?? 0 ) ) ) {
        $wait = (int) ( $body['parameters']['retry_after'] ?? 1 );

        // На пути чекаута блокировать нельзя: клиент ждёт thank-you page.
        // Там ретрай уходит в отложенное задание (см. ptg_sync_order_message).
        if ( ! $retry && ! ptg_is_blocking_forbidden() ) {
            sleep( min( $wait, 5 ) );
            return ptg_api( $method, $args, true );
        }

        ptg_log( 'warn', '429 от Telegram, ' . $method, array( 'retry_after' => $wait ) );
        return new WP_Error( 'ptg_rate_limited', $desc, array( 'retry_after' => $wait ) );
    }

    ptg_log( 'error', 'Ошибка API ' . $method, array( 'desc' => $desc ) );
    return new WP_Error( 'ptg_api', $desc );
}

/**
 * Нельзя ли сейчас блокировать поток.
 *
 * Чекаут и REST-вебхук должны отвечать быстро: в первом случае ждёт клиент,
 * во втором — Telegram, который при таймауте уходит в ретраи.
 *
 * @return bool
 */
function ptg_is_blocking_forbidden() {
    if ( defined( 'PTG_NO_BLOCKING' ) && PTG_NO_BLOCKING ) {
        return true;
    }
    if ( did_action( 'woocommerce_checkout_order_processed' ) && ! did_action( 'shutdown' ) ) {
        return true;
    }
    if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
        return true;
    }
    return false;
}

/**
 * sendMessage.
 *
 * @param string|int $chat_id Чат.
 * @param string     $html    Текст в HTML-разметке Telegram.
 * @param array      $args    Доп. параметры (reply_markup, reply_to_message_id).
 * @return int|WP_Error message_id.
 */
function ptg_tg_send_message( $chat_id, $html, $args = array() ) {
    $defaults = array(
        'chat_id'    => $chat_id,
        'text'       => $html,
        'parse_mode' => 'HTML',
    );
    // Превью включается точечно через link_preview_options; по умолчанию
    // его нет, иначе Telegram подтянет картинку из первой попавшейся
    // ссылки в карточке.
    if ( ! isset( $args['link_preview_options'] ) ) {
        $defaults['link_preview_options'] = array( 'is_disabled' => true );
    }

    $res = ptg_api( 'sendMessage', array_merge( $defaults, $args ) );

    if ( is_wp_error( $res ) ) {
        return $res;
    }

    $mid = (int) ( $res['message_id'] ?? 0 );
    if ( ! $mid ) {
        return new WP_Error( 'ptg_api', 'Telegram не вернул message_id' );
    }
    return $mid;
}

/**
 * editMessageText.
 *
 * @param string|int $chat_id    Чат.
 * @param int        $message_id Сообщение.
 * @param string     $html       Текст.
 * @param array      $args       Доп. параметры.
 * @return true|WP_Error
 */
function ptg_tg_edit_message( $chat_id, $message_id, $html, $args = array() ) {
    $defaults = array(
        'chat_id'    => $chat_id,
        'message_id' => (int) $message_id,
        'text'       => $html,
        'parse_mode' => 'HTML',
    );
    if ( ! isset( $args['link_preview_options'] ) ) {
        $defaults['link_preview_options'] = array( 'is_disabled' => true );
    }

    $res = ptg_api( 'editMessageText', array_merge( $defaults, $args ) );

    if ( is_wp_error( $res ) ) {
        // Нечего менять — значит уже так, как надо.
        if ( 'ptg_not_modified' === $res->get_error_code() ) {
            return true;
        }
        return $res;
    }
    return true;
}

/**
 * editMessageReplyMarkup — смена клавиатуры без трогания текста.
 *
 * Используется для подменю оверрайдов: текст карточки при этом не мигает.
 *
 * @param string|int $chat_id    Чат.
 * @param int        $message_id Сообщение.
 * @param array      $markup     inline_keyboard.
 * @return true|WP_Error
 */
function ptg_tg_edit_markup( $chat_id, $message_id, $markup ) {
    $res = ptg_api( 'editMessageReplyMarkup', array(
        'chat_id'      => $chat_id,
        'message_id'   => (int) $message_id,
        'reply_markup' => $markup,
    ) );

    if ( is_wp_error( $res ) ) {
        if ( 'ptg_not_modified' === $res->get_error_code() ) {
            return true;
        }
        return $res;
    }
    return true;
}

/**
 * answerCallbackQuery.
 *
 * Без него кнопка у нажавшего крутится до таймаута.
 *
 * @param string $callback_id ID колбэка.
 * @param string $text        Текст.
 * @param bool   $alert       Показать модалкой.
 * @return bool
 */
function ptg_tg_answer_callback( $callback_id, $text = '', $alert = false ) {
    $res = ptg_api( 'answerCallbackQuery', array(
        'callback_query_id' => $callback_id,
        'text'              => mb_substr( (string) $text, 0, 200 ),
        'show_alert'        => $alert,
    ) );
    return ! is_wp_error( $res );
}

/**
 * sendPhoto — задел под Фазу 4 (превью макета картинкой).
 *
 * @param string|int $chat_id   Чат.
 * @param string     $photo_url URL картинки.
 * @param string     $caption   Подпись (HTML).
 * @param array      $args      Доп. параметры.
 * @return int|WP_Error message_id.
 */
function ptg_tg_send_photo( $chat_id, $photo_url, $caption = '', $args = array() ) {
    $res = ptg_api( 'sendPhoto', array_merge( array(
        'chat_id'    => $chat_id,
        'photo'      => $photo_url,
        'caption'    => $caption,
        'parse_mode' => 'HTML',
    ), $args ) );

    if ( is_wp_error( $res ) ) {
        return $res;
    }
    return (int) ( $res['message_id'] ?? 0 );
}

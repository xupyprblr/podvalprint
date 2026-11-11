<?php
/**
 * Первое сообщение клиенту.
 *
 * Стандартный порядок работы: первым пишет менеджер, сразу после
 * оформления. Раньше это делалось руками — отсюда разнобой в тексте и
 * забытые клиенты.
 *
 * Два режима, потому что до первого контакта плагин про клиента почти
 * ничего не знает:
 *
 * 1. Чат привязан → кнопка отправляет приветствие через Business-
 *    подключение. Клиент видит обычное сообщение от менеджера.
 * 2. Чат не привязан → Bot API не умеет резолвить @ник в chat_id и не
 *    даёт писать первым тому, кто не начинал диалог. Поэтому кнопка
 *    копирует текст в буфер (copy_text), а соседняя открывает личку
 *    клиента по нику с чекаута. Дальше менеджер вставляет и отправляет
 *    сам — а как только клиент ответит, чат привяжется автоматически.
 *
 * @package podval-telegram-orders
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Меты отправленного приветствия. */
const PTG_META_GREETING_AT = '_ptg_greeting_sent_at';
const PTG_META_GREETING_BY = '_ptg_greeting_sent_by';

/** Лимит Telegram на текст кнопки copy_text. */
const PTG_COPY_TEXT_MAX = 256;

/**
 * Шаблон приветствия.
 *
 * @return string
 */
function ptg_greeting_template() {
    $tpl = (string) get_option( 'ptg_greeting_template', '' );
    if ( '' === trim( $tpl ) ) {
        $tpl = 'Здравствуйте! Это ' . get_bloginfo( 'name' ) . '. Заказ №{номер} получили и взяли в работу.';
    }
    return $tpl;
}

/**
 * Подставляет данные заказа в шаблон.
 *
 * @param WC_Order $order Заказ.
 * @return string Готовый текст.
 */
function ptg_greeting_render( $order ) {
    $text = ptg_render_client_template( $order, ptg_greeting_template() );

    /**
     * Позволяет теме переопределить текст приветствия.
     *
     * @param string   $text  Текст.
     * @param WC_Order $order Заказ.
     */
    return trim( apply_filters( 'ptg_greeting_text', $text, $order ) );
}

/**
 * Текст для кнопки copy_text.
 *
 * @param WC_Order $order Заказ.
 * @return string
 */
function ptg_greeting_copy_text( $order ) {
    return mb_substr( ptg_greeting_render( $order ), 0, PTG_COPY_TEXT_MAX );
}

/**
 * Подключение конкретного менеджера.
 *
 * Приветствие должно уходить от того, кто нажал кнопку, а не от того,
 * чьё подключение записано в заказе.
 *
 * @param int $tg_id Telegram user_id.
 * @return string ID подключения или ''.
 */
function ptg_connection_for_owner( $tg_id ) {
    foreach ( ptg_business_connections() as $id => $conn ) {
        if ( (int) $conn['user_id'] === (int) $tg_id && ! empty( $conn['is_enabled'] ) ) {
            return (string) $id;
        }
    }
    return '';
}

/**
 * Есть ли у подключения право отвечать.
 *
 * @param string $conn_id Подключение.
 * @return bool
 */
function ptg_connection_can_reply( $conn_id ) {
    $list   = ptg_business_connections();
    $rights = (array) ( $list[ (string) $conn_id ]['rights'] ?? array() );
    return ! empty( $rights['can_reply'] );
}

/**
 * Отправляет текст клиенту от имени менеджера через Business-подключение.
 *
 * Общая часть приветствия и напоминания забрать заказ (2.7.0).
 *
 * @param WC_Order $order Заказ.
 * @param string   $text  Текст (без разметки).
 * @param int      $tg_id Кто отправляет.
 * @param array    $from  Объект from.
 * @return array|WP_Error [ name, message_id ].
 */
function ptg_send_client_text( $order, $text, $tg_id, $from = array() ) {
    $chat_id = (int) $order->get_meta( PTG_META_CLIENT_CHAT );
    if ( ! $chat_id ) {
        return new WP_Error( 'ptg_no_client', 'Чат клиента не привязан' );
    }

    // Сначала — подключение, через которое живёт сам чат: переписка и
    // история у клиента именно там. Если менеджер, нажавший кнопку,
    // другой, сообщение всё равно уйдёт в тот же диалог, а не заведёт
    // клиенту второй.
    $conn_id = (string) $order->get_meta( PTG_META_CONNECTION );
    if ( '' === $conn_id ) {
        $conn_id = ptg_connection_for_owner( $tg_id );
    }
    if ( '' === $conn_id ) {
        return new WP_Error( 'ptg_no_connection', 'Нет Business-подключения' );
    }
    if ( ! ptg_connection_can_reply( $conn_id ) ) {
        return new WP_Error( 'ptg_no_reply_right', 'Нет права отвечать от вашего аккаунта' );
    }

    // Без parse_mode: это обычный текст менеджера, и любая скобка или
    // амперсанд в имени клиента не должны ничего ломать.
    $res = ptg_api( 'sendMessage', array(
        'business_connection_id' => $conn_id,
        'chat_id'                => $chat_id,
        'text'                   => $text,
    ) );

    if ( is_wp_error( $res ) ) {
        return $res;
    }

    $name = ptg_staff_name( $tg_id, $from );

    // Пишем запись сразу, не дожидаясь эха от Telegram: иначе между
    // отправкой и приходом business_message карточка показывает
    // неверный счётчик. Дубль отсекается по message_id.
    ptg_chat_log_append( $order, array(
        't' => time(),
        'd' => 'out',
        'n' => $name,
        'x' => mb_substr( $text, 0, PTG_CHAT_TEXT_MAX ),
        'm' => (int) ( $res['message_id'] ?? 0 ),
        'f' => '',
    ) );

    return array( 'name' => $name, 'message_id' => (int) ( $res['message_id'] ?? 0 ) );
}

/**
 * Отправляет приветствие клиенту.
 *
 * @param WC_Order $order Заказ.
 * @param int      $tg_id Кто отправляет.
 * @param array    $from  Объект from.
 * @return true|WP_Error
 */
function ptg_send_greeting( $order, $tg_id, $from = array() ) {
    $res = ptg_send_client_text( $order, ptg_greeting_render( $order ), $tg_id, $from );
    if ( is_wp_error( $res ) ) {
        return $res;
    }

    $order->update_meta_data( PTG_META_GREETING_AT, current_time( 'mysql' ) );
    $order->update_meta_data( PTG_META_GREETING_BY, $res['name'] );
    $order->save();

    ptg_add_note( $order, sprintf( 'Приветствие отправлено клиенту (%s, Telegram)', $res['name'] ) );

    ptg_log( 'info', 'Приветствие отправлено', array(
        'order' => $order->get_id(),
        'by'    => (int) $tg_id,
    ) );

    return true;
}

/**
 * Подстановка данных заказа в любой шаблон сообщения клиенту.
 *
 * @param WC_Order $order Заказ.
 * @param string   $tpl   Шаблон.
 * @return string
 */
function ptg_render_client_template( $order, $tpl ) {
    $names = array();
    foreach ( $order->get_items() as $item ) {
        $names[] = $item->get_name();
    }
    return trim( strtr( $tpl, array(
        '{номер}'   => $order->get_order_number(),
        '{имя}'     => $order->get_billing_first_name(),
        '{фамилия}' => $order->get_billing_last_name(),
        '{сумма}'   => $order->get_total(),
        '{позиции}' => implode( ', ', $names ),
    ) ) );
}

/* ── Напоминание забрать заказ (2.7.0) ─────────────────────────── */

const PTG_META_REMIND_LOG = '_ptg_remind_log';

/**
 * Шаблон напоминания.
 *
 * @return string
 */
function ptg_remind_template() {
    $tpl = (string) get_option( 'ptg_remind_template', '' );
    if ( '' === trim( $tpl ) ) {
        $tpl = 'Здравствуйте, {имя}! Ваш заказ №{номер} готов и ждёт вас. Подскажите, когда вам удобно его забрать?';
    }
    return $tpl;
}

/**
 * Текст напоминания для заказа.
 *
 * @param WC_Order $order Заказ.
 * @return string
 */
function ptg_remind_render( $order ) {
    return (string) apply_filters( 'ptg_remind_text', ptg_render_client_template( $order, ptg_remind_template() ), $order );
}

/**
 * История напоминаний.
 *
 * @param WC_Order $order Заказ.
 * @return array[] [ t, by, mode(sent|copy) ].
 */
function ptg_remind_log( $order ) {
    $log = $order->get_meta( PTG_META_REMIND_LOG );
    return is_array( $log ) ? $log : array();
}

/**
 * Напомнить клиенту забрать заказ.
 *
 * Чат привязан — сообщение уходит от менеджера. Не привязан — текст
 * скопирован в приложении, здесь фиксируется только факт: иначе через
 * неделю никто не вспомнит, напоминали или нет.
 *
 * @param WC_Order $order Заказ.
 * @param int      $tg_id Кто.
 * @param array    $from  Объект from.
 * @param bool     $copy  Режим «скопировал текст».
 * @return true|WP_Error
 */
function ptg_send_pickup_reminder( $order, $tg_id, $from = array(), $copy = false ) {
    if ( $copy ) {
        $name = ptg_staff_name( $tg_id, $from );
        $mode = 'copy';
    } else {
        $res = ptg_send_client_text( $order, ptg_remind_render( $order ), $tg_id, $from );
        if ( is_wp_error( $res ) ) {
            return $res;
        }
        $name = $res['name'];
        $mode = 'sent';
    }

    $log   = ptg_remind_log( $order );
    $log[] = array( 't' => time(), 'by' => $name, 'mode' => $mode );
    $order->update_meta_data( PTG_META_REMIND_LOG, array_slice( $log, -20 ) );
    $order->save();

    ptg_add_note( $order, 'sent' === $mode
        ? sprintf( 'Клиенту отправлено напоминание забрать заказ (%s)', $name )
        : sprintf( 'Текст напоминания скопирован для отправки вручную (%s)', $name ) );

    return true;
}

/**
 * Данные приветствия для карточки.
 *
 * @param WC_Order $order Заказ.
 * @return array
 */
function ptg_order_greeting( $order ) {
    $at = (string) $order->get_meta( PTG_META_GREETING_AT );
    return array(
        'sent' => '' !== $at,
        'at'   => $at,
        'by'   => (string) $order->get_meta( PTG_META_GREETING_BY ),
        'text' => ptg_greeting_copy_text( $order ),
    );
}

/* ── Адрес самовывоза (2.11.0) ─────────────────────────────────── */

const PTG_META_PICKUP_AT = '_ptg_pickup_sent_at';
const PTG_META_PICKUP_BY = '_ptg_pickup_sent_by';

/**
 * Адрес пункта выдачи. По умолчанию — адрес магазина из настроек
 * WooCommerce, чтобы не дублировать его в двух местах.
 *
 * @return string
 */
function ptg_pickup_address() {
    $addr = trim( (string) get_option( 'ptg_pickup_address', '' ) );
    if ( '' !== $addr ) {
        return $addr;
    }
    $wc = function_exists( 'WC' ) ? WC() : null;
    if ( ! $wc || ! isset( $wc->countries ) ) {
        return '';
    }
    $c    = $wc->countries;
    $part = array( $c->get_base_address(), $c->get_base_address_2(), $c->get_base_city() );
    return implode( ', ', array_filter( array_map( 'trim', $part ) ) );
}

/**
 * Координаты точки на карте.
 *
 * @return array|null [ lat, lng ].
 */
function ptg_pickup_point() {
    $lat = (string) get_option( 'ptg_pickup_lat', '' );
    $lng = (string) get_option( 'ptg_pickup_lng', '' );
    if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
        return null;
    }
    $lat = (float) $lat;
    $lng = (float) $lng;
    if ( abs( $lat ) > 90 || abs( $lng ) > 180 || ( 0.0 === $lat && 0.0 === $lng ) ) {
        return null;
    }
    return array( $lat, $lng );
}

/**
 * Название точки для карточки места.
 *
 * @return string
 */
function ptg_pickup_title() {
    $t = trim( (string) get_option( 'ptg_pickup_title', '' ) );
    return '' !== $t ? $t : get_bloginfo( 'name' ) . ' — самовывоз';
}

/**
 * Шаблон сообщения с адресом.
 *
 * @return string
 */
function ptg_pickup_template() {
    $tpl = (string) get_option( 'ptg_pickup_template', '' );
    if ( '' === trim( $tpl ) ) {
        $tpl = "Ваш заказ №{номер} готов! Забрать можно по адресу: {адрес}.\nНапишите, когда вам удобно подъехать, и мы подготовим заказ.";
    }
    return $tpl;
}

/**
 * Текст сообщения с адресом. Если координат нет, ссылка на карту тоже
 * не добавляется — пусть лучше будет один адрес, чем ссылка в никуда.
 *
 * @param WC_Order $order Заказ.
 * @param bool     $with_link Добавить ссылку на карту (для копирования).
 * @return string
 */
function ptg_pickup_render( $order, $with_link = false ) {
    $text = ptg_render_client_template( $order, strtr( ptg_pickup_template(), array( '{адрес}' => ptg_pickup_address() ) ) );
    $pt   = ptg_pickup_point();
    if ( $with_link && $pt ) {
        $text .= "\n" . ptg_pickup_map_url();
    }
    return (string) apply_filters( 'ptg_pickup_text', $text, $order );
}

/**
 * Ссылка на карту — для чатов без привязки, где точку не отправить.
 *
 * @return string
 */
function ptg_pickup_map_url() {
    $pt = ptg_pickup_point();
    return $pt ? 'https://maps.google.com/?q=' . $pt[0] . ',' . $pt[1] : '';
}

/**
 * Настроен ли адрес вообще.
 *
 * @return bool
 */
function ptg_pickup_ready() {
    return '' !== ptg_pickup_address();
}

/**
 * Отправляет клиенту адрес самовывоза и точку на карте.
 *
 * @param WC_Order $order Заказ.
 * @param int      $tg_id Кто.
 * @param array    $from  Объект from.
 * @param bool     $copy  Режим «скопировал текст».
 * @return true|WP_Error
 */
function ptg_send_pickup_info( $order, $tg_id, $from = array(), $copy = false ) {
    if ( ! ptg_pickup_ready() ) {
        return new WP_Error( 'ptg_no_address', 'Адрес самовывоза не заполнен в настройках плагина' );
    }

    if ( $copy ) {
        $name = ptg_staff_name( $tg_id, $from );
    } else {
        $res = ptg_send_client_text( $order, ptg_pickup_render( $order ), $tg_id, $from );
        if ( is_wp_error( $res ) ) {
            return $res;
        }
        $name = $res['name'];

        // Точка отправляется отдельным сообщением: карточкой места её
        // можно открыть в навигаторе, ссылкой — нет.
        $pt = ptg_pickup_point();
        if ( $pt ) {
            $venue = ptg_api( 'sendVenue', array(
                'business_connection_id' => (string) $order->get_meta( PTG_META_CONNECTION ),
                'chat_id'                => (int) $order->get_meta( PTG_META_CLIENT_CHAT ),
                'latitude'               => $pt[0],
                'longitude'              => $pt[1],
                'title'                  => ptg_pickup_title(),
                'address'                => ptg_pickup_address(),
            ) );
            if ( is_wp_error( $venue ) ) {
                ptg_log( 'warn', 'Точку на карте отправить не удалось', array(
                    'order' => $order->get_id(),
                    'error' => $venue->get_error_message(),
                ) );
            } else {
                ptg_chat_log_append( $order, array(
                    't' => time(),
                    'd' => 'out',
                    'n' => $name,
                    'x' => ptg_pickup_title(),
                    'm' => (int) ( $venue['message_id'] ?? 0 ),
                    'f' => 'адрес',
                ) );
            }
        }
    }

    $order->update_meta_data( PTG_META_PICKUP_AT, time() );
    $order->update_meta_data( PTG_META_PICKUP_BY, $name );
    $order->save();

    ptg_add_note( $order, $copy
        ? sprintf( 'Текст с адресом самовывоза скопирован для отправки вручную (%s)', $name )
        : sprintf( 'Клиенту отправлен адрес самовывоза (%s)', $name ) );

    return true;
}

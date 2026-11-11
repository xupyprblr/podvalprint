<?php
/**
 * Фаза 5, 2.7.0. Действия из Mini App.
 *
 * Логика не дублируется: вызываются те же функции, что и у кнопок бота
 * (ptg_set_status, ptg_set_override, ptg_send_greeting…). Карточка в
 * группе после действия обновляется тем же синком, поэтому группа и
 * приложение не расходятся.
 *
 * Ответ — заказ целиком (ptg_app_order_payload), экран обновляется одним
 * запросом.
 *
 * @package podval-telegram-orders
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', function () {
    register_rest_route( PTG_REST_NS, '/app/order/(?P<id>\d+)/do', array(
        'methods'             => 'POST',
        'permission_callback' => 'ptg_app_permission',
        'callback'            => 'ptg_app_rest_action',
    ) );
} );

/**
 * Объект from в формате апдейта Telegram — для ptg_staff_name() и
 * ptg_maybe_assign(), которые ждут именно его.
 *
 * @param array $a Результат ptg_app_auth().
 * @return array
 */
function ptg_app_from( $a ) {
    $u = (array) $a['tg'];
    return array(
        'id'         => (int) $a['tg_id'],
        'first_name' => (string) ( $u['first_name'] ?? '' ),
        'last_name'  => (string) ( $u['last_name'] ?? '' ),
        'username'   => (string) ( $u['username'] ?? '' ),
    );
}

/**
 * Ошибка действия: 200 не подходит — клиенту надо понять, что ничего не
 * произошло; 4xx с кодом, который app.js показывает человеку.
 *
 * @param string $code    Код.
 * @param string $message Текст для человека.
 * @param int    $status  HTTP.
 * @param array  $extra   Доп. данные.
 * @return WP_Error
 */
function ptg_app_fail( $code, $message, $status = 400, $extra = array() ) {
    return new WP_Error( $code, $message, array_merge( array( 'status' => $status ), $extra ) );
}

/**
 * POST app/order/{id}/do  { action, ... }
 *
 * @param WP_REST_Request $request Запрос.
 * @return WP_REST_Response|WP_Error
 */
function ptg_app_rest_action( $request ) {
    $a     = ptg_app_auth( $request );
    $order = wc_get_order( (int) $request['id'] );
    if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) {
        return ptg_app_fail( 'ptg_app_not_found', 'Заказ не найден', 404 );
    }

    $p      = (array) $request->get_json_params();
    $action = (string) ( $p['action'] ?? '' );
    $tg_id  = (int) $a['tg_id'];
    $from   = ptg_app_from( $a );

    // Защита от двойного нажатия: одинаковое действие от того же человека
    // по тому же заказу в течение 5 секунд не повторяется.
    $dedup = 'ptg_app_do_' . md5( $tg_id . '|' . $order->get_id() . '|' . wp_json_encode( $p ) );
    if ( get_transient( $dedup ) ) {
        return ptg_app_response( ptg_app_order_payload( $order, $a ) );
    }
    set_transient( $dedup, 1, 5 );

    // Первое действие по заказу = ответственный. Показ скрыт настройкой,
    // но запись ведётся, как и у кнопок бота.
    ptg_maybe_assign( $order, $tg_id, $from );

    ptg_watch_fatal( array( 'order' => $order->get_id(), 'app_action' => $action ) );
    $result = ptg_app_do( $order, $action, $p, $tg_id, $from );
    ptg_watch_fatal_done();
    if ( is_wp_error( $result ) ) {
        delete_transient( $dedup );
        return $result;
    }

    // Статус синкается хуком смены статуса; остальное — явно.
    if ( ! in_array( $action, array( 'status', 'undo' ), true ) ) {
        ptg_force_next_sync( $order );
        ptg_sync_order_message( $order, 'app:' . $action );
    }

    ptg_log( 'info', 'Mini App: действие', array(
        'order'  => $order->get_id(),
        'action' => $action,
        'by'     => $tg_id,
    ) );

    $order = wc_get_order( $order->get_id() ); // свежие меты после синка
    $out   = ptg_app_order_payload( $order, $a );
    if ( is_array( $result ) ) {
        $out['result'] = $result;
    }
    return ptg_app_response( $out );
}

/**
 * Выполнение действия.
 *
 * @param WC_Order $order  Заказ.
 * @param string   $action Действие.
 * @param array    $p      Параметры.
 * @param int      $tg_id  Кто.
 * @param array    $from   Объект from.
 * @return true|array|WP_Error
 */
function ptg_app_do( $order, $action, $p, $tg_id, $from ) {
    switch ( $action ) {

        case 'status':
            return ptg_app_do_status( $order, (string) ( $p['to'] ?? '' ), ! empty( $p['verified'] ), $tg_id, $from );

        case 'undo':
            // Отмена случайного нажатия: вернуть статус, из которого заказ
            // пришёл, — только в первые две минуты и только если с тех пор
            // статус никто не менял (сверяем, что текущий = ожидаемый).
            $prev = (string) $order->get_meta( PTG_META_STATUS_PREV );
            $at   = (int) $order->get_meta( PTG_META_STATUS_AT );
            if ( '' === $prev || time() - $at > PTG_UNDO_WINDOW || $order->get_status() !== (string) ( $p['from'] ?? '' ) ) {
                return ptg_app_fail( 'ptg_stale_state', 'Отменить уже нельзя — статус изменился или прошло больше двух минут', 409 );
            }
            if ( ! isset( ptg_status_chain()[ $prev ] ) && 'on-hold' !== $prev ) {
                return ptg_app_fail( 'ptg_bad_transition', 'Этот статус из приложения не вернуть', 409 );
            }
            if ( 'on-hold' === $prev ) {
                $order->update_status( 'on-hold', sprintf( 'Отмена из Telegram (%s). ', ptg_staff_name( $tg_id, $from ) ), true );
                return true;
            }
            $res = ptg_set_status( $order, $prev, $tg_id, $from );
            return is_wp_error( $res ) ? ptg_app_fail( $res->get_error_code(), $res->get_error_message(), 409 ) : true;

        case 'greet':
            $res = ptg_send_greeting( $order, $tg_id, $from );
            return is_wp_error( $res ) ? ptg_app_fail( $res->get_error_code(), $res->get_error_message(), 409 ) : true;

        case 'remind':
            if ( ! in_array( $order->get_status(), array( 'ready', 'not-picked' ), true ) ) {
                return ptg_app_fail( 'ptg_bad_state', 'Напоминать можно только о готовом заказе', 409 );
            }
            $res = ptg_send_pickup_reminder( $order, $tg_id, $from, ! empty( $p['copy'] ) );
            return is_wp_error( $res ) ? ptg_app_fail( $res->get_error_code(), $res->get_error_message(), 409 ) : true;

        case 'pickup':
            if ( ! in_array( $order->get_status(), array( 'ready', 'not-picked' ), true ) ) {
                return ptg_app_fail( 'ptg_bad_state', 'Адрес отправляем, когда заказ готов', 409 );
            }
            $res = ptg_send_pickup_info( $order, $tg_id, $from, ! empty( $p['copy'] ) );
            return is_wp_error( $res ) ? ptg_app_fail( $res->get_error_code(), $res->get_error_message(), 409 ) : true;

        case 'pay_mark':
            $res = ptg_set_pay_mark( $order, (string) ( $p['mark'] ?? '' ), $tg_id, $from );
            return is_wp_error( $res ) ? ptg_app_fail( $res->get_error_code(), $res->get_error_message(), 409 ) : true;

        case 'override':
        case 'override_clear':
            if ( ptg_is_locked( $order ) ) {
                return ptg_app_fail( 'ptg_locked', 'Заказ уже в печати — чтобы менять параметры, верните статус назад', 409 );
            }
            $item_id = (int) ( $p['item_id'] ?? 0 );
            $field   = (string) ( $p['field'] ?? '' );
            if ( ! ptg_app_item_editable( $order, $item_id ) ) {
                return ptg_app_fail( 'ptg_bad_item', 'Эту позицию менять нельзя' );
            }
            if ( 'override_clear' === $action ) {
                ptg_clear_override( $order, $item_id, $field );
                return true;
            }
            $by_name = ptg_staff_name( $tg_id, $from );
            $res     = ptg_set_override( $order, $item_id, $field, (string) ( $p['value'] ?? '' ), $tg_id, $by_name );
            return is_wp_error( $res ) ? ptg_app_fail( $res->get_error_code(), $res->get_error_message() ) : true;
    }

    return ptg_app_fail( 'ptg_bad_action', 'Неизвестное действие' );
}

/**
 * Позиция принадлежит заказу и не бандл.
 *
 * @param WC_Order $order   Заказ.
 * @param int      $item_id Позиция.
 * @return bool
 */
function ptg_app_item_editable( $order, $item_id ) {
    $item = $order->get_item( $item_id );
    if ( ! $item || (int) $item->get_order_id() !== (int) $order->get_id() ) {
        return false;
    }
    $bm = $item->get_meta( '_bundle_meta' );
    return ! ( $bm && is_array( $bm ) );
}

/**
 * Смена статуса. Разрешены только переходы, которые есть на кнопках
 * карточки: следующий шаг, назад, отказ. Произвольный статус из
 * приложения не выставить — для этого админка.
 *
 * @param WC_Order $order    Заказ.
 * @param string   $to       Куда.
 * @param bool     $verified Сверка подтверждена.
 * @param int      $tg_id    Кто.
 * @param array    $from     Объект from.
 * @return true|WP_Error
 */
function ptg_app_do_status( $order, $to, $verified, $tg_id, $from ) {
    $st      = ptg_order_status_info( $order );
    $allowed = (array) $st['next'];
    $back    = ptg_app_back_status( $order );
    if ( $back ) {
        $allowed[] = $back;
    }
    if ( ! empty( $st['refusable'] ) ) {
        $allowed[] = 'refused';
    }
    // Эскиз можно отправить, не дожидаясь ответа клиента: подсказка
    // «вы отправили изображение» ведёт сюда и из «Получен» (2.8.0).
    if ( in_array( $order->get_status(), array( 'processing', 'on-hold' ), true ) ) {
        $allowed[] = 'sketch-sent';
    }

    if ( ! in_array( $to, $allowed, true ) ) {
        // Чаще всего — второй человек уже нажал то же самое.
        return ptg_app_fail( 'ptg_stale_state', 'Статус заказа уже изменился — обновил экран', 409, array(
            'current' => $order->get_status(),
        ) );
    }

    // Как в боте: в печать с правками — только после явной сверки.
    if ( 'in-print' === $to && ptg_needs_print_confirm( $order, ptg_order_data( $order ) ) ) {
        if ( ! $verified ) {
            return ptg_app_fail( 'ptg_need_verify', 'В заказе есть правки параметров — сверьте перед печатью', 409 );
        }
        ptg_mark_verified( $order, $tg_id, $from );
    }

    $res = ptg_set_status( $order, $to, $tg_id, $from );
    if ( is_wp_error( $res ) ) {
        return ptg_app_fail( $res->get_error_code(), $res->get_error_message(), 409 );
    }
    return true;
}

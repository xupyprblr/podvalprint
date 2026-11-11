<?php
/**
 * Обработка нажатий на кнопки карточки заказа.
 *
 * @package podval-telegram-orders
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Разрешённые Telegram user_id.
 *
 * Полноценный маппинг telegram_id → WP-юзер приходит в Фазе 2; пока это
 * плоский список из настроек.
 *
 * @return array
 */
function ptg_allowed_user_ids() {
    $raw = (string) get_option( 'ptg_allowed_user_ids', '' );
    $ids = preg_split( '/[^0-9]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
    return array_map( 'intval', (array) $ids );
}

/**
 * Имя нажавшего для истории заказа.
 *
 * @param array $from Объект from.
 * @return string
 */
function ptg_user_display_name( $from ) {
    $name = trim( ( $from['first_name'] ?? '' ) . ' ' . ( $from['last_name'] ?? '' ) );
    if ( '' === $name && ! empty( $from['username'] ) ) {
        $name = '@' . $from['username'];
    }
    if ( '' === $name ) {
        $name = 'ID ' . (int) ( $from['id'] ?? 0 );
    }
    return $name;
}

/**
 * Главный роутер нажатий.
 *
 * @param array $cb Объект callback_query.
 * @return void
 */
function ptg_handle_callback_query( $cb ) {
    $cb_id   = (string) ( $cb['id'] ?? '' );
    $from    = (array) ( $cb['from'] ?? array() );
    $user_id = (int) ( $from['id'] ?? 0 );
    $raw     = (string) ( $cb['data'] ?? '' );

    if ( '' === $cb_id ) {
        return;
    }

    // Защита от двойного нажатия и ретраев Telegram.
    $dedup_key = 'ptg_cb_' . md5( $cb_id );
    if ( get_transient( $dedup_key ) ) {
        ptg_tg_answer_callback( $cb_id );
        return;
    }
    set_transient( $dedup_key, 1, 60 );

    // С Фазы 2 право определяется привязкой к WP-юзеру с edit_shop_orders;
    // плоский список user_id остался фоллбэком на время перехода.
    if ( ! ptg_user_can_manage( $user_id ) ) {
        ptg_tg_answer_callback( $cb_id, 'Нет доступа', true );
        ptg_log( 'warn', 'Нажатие от неразрешённого пользователя', array( 'user' => $user_id, 'data' => $raw ) );
        return;
    }

    $parsed = ptg_cb_parse( $raw );
    if ( ! $parsed ) {
        ptg_tg_answer_callback( $cb_id, 'Не понял кнопку' );
        return;
    }

    $order = wc_get_order( $parsed['order_id'] );
    if ( ! $order ) {
        ptg_tg_answer_callback( $cb_id, 'Заказ не найден', true );
        return;
    }

    $chat_id    = (string) ( $cb['message']['chat']['id'] ?? $order->get_meta( PTG_META_CHAT ) );
    $message_id = (int) ( $cb['message']['message_id'] ?? $order->get_meta( PTG_META_MESSAGE ) );
    $args       = $parsed['args'];

    // Первое действие по заказу = «взял в работу». Явная кнопка ниже
    // нужна только чтобы отобрать заказ у другого.
    // Синкаем сразу: иначе при открытии подменю (там правится только
    // клавиатура) новый ответственный появится в карточке лишь со
    // следующим изменением заказа.
    if ( ptg_maybe_assign( $order, $user_id, $from ) ) {
        ptg_force_next_sync( $order );
        ptg_sync_order_message( $order, 'assign' );
    }

    $data = ptg_order_data( $order );

    switch ( $parsed['action'] ) {

        case 'st':
            $code = (string) ( $args[0] ?? '' );

            // Отправка в печать при наличии правок требует явной сверки:
            // имя файла принта собрано по исходным значениям и врёт.
            if ( 'pr' === $code && ptg_needs_print_confirm( $order, $data ) ) {
                ptg_swap_markup( $order, $chat_id, $message_id, ptg_keyboard_confirm_print( $data ) );
                ptg_tg_answer_callback( $cb_id, 'В заказе есть правки параметров — сверьте с баннером', true );
                return;
            }

            if ( 'prc' === $code ) {
                ptg_mark_verified( $order, $user_id, $from );
                $code = 'pr';
            }

            $status = ( 'new' === $code ) ? 'processing' : ptg_status_by_code( $code );

            $res = ptg_set_status( $order, $status, $user_id, $from );
            if ( is_wp_error( $res ) ) {
                ptg_tg_answer_callback( $cb_id, $res->get_error_message(), true );
                return;
            }
            // update_status() уже дёрнул синк через хук смены статуса.
            ptg_tg_answer_callback( $cb_id, ptg_status_chain()[ $status ] );
            return;

        case 'log':
            // Человек нажал кнопку — ответ нужен всегда, независимо от
            // настройки служебных сообщений.
            ptg_notify_group( $order, ptg_render_transcript( $order ), true );
            ptg_tg_answer_callback( $cb_id, 'Выгрузил в группу' );
            return;

        case 'hi':
            $res = ptg_send_greeting( $order, $user_id, $from );
            if ( is_wp_error( $res ) ) {
                ptg_tg_answer_callback( $cb_id, $res->get_error_message(), true );
                return;
            }
            ptg_force_next_sync( $order );
            ptg_sync_order_message( $order, 'greeting' );
            ptg_tg_answer_callback( $cb_id, 'Приветствие отправлено' );
            return;

        case 'take':
            $taken = ptg_maybe_assign( $order, $user_id, $from, true );
            if ( $taken ) {
                ptg_force_next_sync( $order );
                ptg_sync_order_message( $order, 'assign' );
            }
            ptg_tg_answer_callback( $cb_id, $taken ? 'Заказ за вами' : 'Заказ уже за вами' );
            return;

        case 'ovr':
            if ( ptg_is_locked( $order ) ) {
                ptg_tg_answer_callback(
                    $cb_id,
                    'Заказ ' . mb_strtolower( $data['status_info']['label'] ) . '. Чтобы менять параметры, верните статус назад.',
                    true
                );
                return;
            }
            $items = ptg_overridable_items( $data );
            if ( empty( $items ) ) {
                ptg_tg_answer_callback( $cb_id, 'В заказе нечего менять', true );
                return;
            }
            // Одна позиция — шаг выбора позиции пропускается.
            $markup = ( 1 === count( $items ) )
                ? ptg_keyboard_fields( $data, $items[0]['item_id'] )
                : ptg_keyboard_items( $data );
            ptg_swap_markup( $order, $chat_id, $message_id, $markup );
            ptg_tg_answer_callback( $cb_id );
            return;

        case 'ovi':
            $item_id = (int) ( $args[0] ?? 0 );
            ptg_swap_markup( $order, $chat_id, $message_id, ptg_keyboard_fields( $data, $item_id ) );
            ptg_tg_answer_callback( $cb_id );
            return;

        case 'ovf':
            $item_id = (int) ( $args[0] ?? 0 );
            $field   = (string) ( $args[1] ?? '' );
            if ( ! isset( ptg_garment_labels()[ $field ] ) ) {
                ptg_tg_answer_callback( $cb_id, 'Неизвестное поле', true );
                return;
            }
            ptg_swap_markup( $order, $chat_id, $message_id, ptg_keyboard_values( $data, $item_id, $field ) );
            ptg_tg_answer_callback( $cb_id );
            return;

        case 'ovs':
            if ( ptg_is_locked( $order ) ) {
                ptg_tg_answer_callback( $cb_id, 'Заказ уже в печати — параметры заблокированы', true );
                return;
            }
            $item_id = (int) ( $args[0] ?? 0 );
            $field   = (string) ( $args[1] ?? '' );
            $value   = (string) ( $args[2] ?? '' );

            $res = ptg_set_override( $order, $item_id, $field, $value, $user_id, ptg_user_display_name( $from ) );
            if ( is_wp_error( $res ) ) {
                ptg_tg_answer_callback( $cb_id, $res->get_error_message(), true );
                return;
            }

            ptg_force_next_sync( $order );
            ptg_sync_order_message( $order, 'override' );

            $labels = ptg_garment_labels();
            $text   = ! empty( $res['cleared'] )
                ? $labels[ $field ] . ': возвращено исходное'
                : $labels[ $field ] . ' → ' . ptg_garment_value_label( $field, $value );
            ptg_tg_answer_callback( $cb_id, $text );
            return;

        case 'ovc':
            if ( ptg_is_locked( $order ) ) {
                ptg_tg_answer_callback( $cb_id, 'Заказ уже в печати — параметры заблокированы', true );
                return;
            }
            $item_id = (int) ( $args[0] ?? 0 );
            $field   = (string) ( $args[1] ?? '' );
            $cleared = ptg_clear_override( $order, $item_id, $field );

            ptg_force_next_sync( $order );
            ptg_sync_order_message( $order, 'override-cancel' );
            ptg_tg_answer_callback( $cb_id, $cleared ? 'Изменение отменено' : 'Изменений не было' );
            return;

        case 'ovx':
            ptg_swap_markup( $order, $chat_id, $message_id, ptg_keyboard_root( $data ) );
            ptg_tg_answer_callback( $cb_id );
            return;
    }


    ptg_tg_answer_callback( $cb_id, 'Обработчик ещё не подключён' );
}

/**
 * Смена клавиатуры без трогания текста карточки.
 *
 * Меню живёт только в разметке, поэтому хэш сообщения помечается
 * «грязным»: иначе следующий синк увидит совпадение текста и не вернёт
 * основную клавиатуру на место.
 *
 * @param WC_Order   $order      Заказ.
 * @param string|int $chat_id    Чат.
 * @param int        $message_id Сообщение.
 * @param array      $markup     Клавиатура.
 * @return void
 */
function ptg_swap_markup( $order, $chat_id, $message_id, $markup ) {
    if ( ! $message_id ) {
        return;
    }
    ptg_tg_edit_markup( $chat_id, $message_id, $markup );
    ptg_force_next_sync( $order );
}

/**
 * Помечает сообщение как требующее правки при следующем синке.
 *
 * @param WC_Order $order Заказ.
 * @return void
 */
function ptg_force_next_sync( $order ) {
    $order->update_meta_data( PTG_META_HASH, 'menu' );
    $order->save();
}

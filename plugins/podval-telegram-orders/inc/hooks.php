<?php
/**
 * Хуки WooCommerce и синхронизация сообщения.
 *
 * Модель Фазы 0: у заказа одно живое сообщение в группе. Оно создаётся
 * один раз и дальше редактируется. Прежняя схема «одно уведомление на
 * каждый статус» оставляла в ленте три карточки одного заказа с разными
 * данными.
 *
 * @package podval-telegram-orders
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Меты живого сообщения. */
const PTG_META_CHAT    = '_ptg_chat_id';
const PTG_META_MESSAGE = '_ptg_message_id';
const PTG_META_HASH    = '_ptg_msg_hash';
const PTG_META_SENT_AT = '_ptg_sent_at';

/** Легаси-меты схемы 1.x. */
const PTG_LEGACY_KEYS = array(
    '_ptg_sent_pending',
    '_ptg_sent_processing',
    '_ptg_sent_on-hold',
    '_ptg_sent_completed',
);

/**
 * Синхронизация сообщения заказа.
 *
 * @param WC_Order $order  Заказ.
 * @param string   $reason Причина (в журнал).
 * @return bool Успех.
 */
function ptg_sync_order_message( $order, $reason = '' ) {
    static $running = array();

    if ( ! $order instanceof WC_Order ) {
        return false;
    }

    $order_id = $order->get_id();
    if ( isset( $running[ $order_id ] ) ) {
        return false; // Защита от рекурсии: save() внутри синка.
    }
    $running[ $order_id ] = true;

    try {
        return ptg_sync_order_message_inner( $order, $reason );
    } finally {
        unset( $running[ $order_id ] );
    }
}

/**
 * Тело синка.
 *
 * @param WC_Order $order  Заказ.
 * @param string   $reason Причина.
 * @return bool
 */
function ptg_sync_order_message_inner( $order, $reason ) {
    $chat_id = trim( (string) get_option( 'ptg_chat_id', '' ) );
    $token   = trim( (string) get_option( 'ptg_bot_token', '' ) );
    if ( '' === $chat_id || '' === $token ) {
        return false;
    }

    // Постоянный клиент: чат известен с прошлых заказов, переносим
    // привязку до рендера, чтобы карточка сразу была правильной.
    ptg_inherit_client_link( $order );

    $data   = ptg_order_data( $order );
    $html   = ptg_render_order_html( $data );
    $markup = ptg_keyboard_root( $data );

    // Хэш считается от текста ВМЕСТЕ с клавиатурой: иначе после отмены
    // поправки текст возвращается к исходному, хэш совпадает, edit не
    // вызывается — и на сообщении остаётся кнопка от прошлого состояния.
    $preview_args = ptg_link_preview_args( $data );

    // Картинка входит в хэш: смена макета должна приводить к правке
    // сообщения, даже если текст не изменился ни на символ.
    $hash = md5( $html . '|' . wp_json_encode( $markup ) . '|' . wp_json_encode( $preview_args ) );

    $message_id = (int) $order->get_meta( PTG_META_MESSAGE );
    $msg_chat   = (string) $order->get_meta( PTG_META_CHAT );
    if ( '' === $msg_chat ) {
        $msg_chat = $chat_id;
    }

    // ── Создание ─────────────────────────────────────────────────────
    if ( ! $message_id ) {
        if ( ptg_is_legacy_order( $order ) ) {
            // Заказ жил по схеме 1.x. Создавать ему сообщение нельзя:
            // сразу после обновления плагина в группу упадут все старые
            // заказы разом. Для ручных случаев есть действие «Пересоздать
            // сообщение» на экране заказа.
            return false;
        }

        $statuses = get_option( 'ptg_send_on_status', array( 'processing' ) );
        if ( ! in_array( $order->get_status(), (array) $statuses, true ) ) {
            return false;
        }

        $res = ptg_tg_send_message( $chat_id, $html, array_merge(
            array( 'reply_markup' => $markup ),
            $preview_args
        ) );
        if ( is_wp_error( $res ) ) {
            ptg_handle_sync_error( $order, $res, $reason );
            return false;
        }

        $order->update_meta_data( PTG_META_CHAT, $chat_id );
        $order->update_meta_data( PTG_META_MESSAGE, (int) $res );
        $order->update_meta_data( PTG_META_HASH, $hash );
        $order->update_meta_data( PTG_META_SENT_AT, current_time( 'mysql' ) );
        $order->save();
        ptg_add_note( $order, 'Telegram: сообщение создано' );
        ptg_sync_photo_message( $order, $data );

        ptg_log( 'info', 'Сообщение создано', array(
            'order'  => $order->get_id(),
            'msg'    => (int) $res,
            'reason' => $reason,
        ) );
        return true;
    }

    // ── Редактирование ───────────────────────────────────────────────
    if ( $hash === (string) $order->get_meta( PTG_META_HASH ) ) {
        return true; // Нечего менять — API не дёргаем вовсе.
    }

    $res = ptg_tg_edit_message( $msg_chat, $message_id, $html, array_merge(
        array( 'reply_markup' => $markup ),
        $preview_args
    ) );

    if ( is_wp_error( $res ) ) {
        if ( 'ptg_edit_not_found' === $res->get_error_code() ) {
            // Сообщение удалили из группы — отправляем заново.
            $order->delete_meta_data( PTG_META_MESSAGE );
            $order->delete_meta_data( PTG_META_HASH );
            $order->save();
            ptg_log( 'warn', 'Сообщение не найдено, пересоздаю', array( 'order' => $order->get_id() ) );
            return ptg_sync_order_message_inner( $order, $reason . '|recreate' );
        }
        ptg_handle_sync_error( $order, $res, $reason );
        return false;
    }

    $order->update_meta_data( PTG_META_HASH, $hash );
    $order->save();
    ptg_sync_photo_message( $order, $data );

    ptg_log( 'info', 'Сообщение обновлено', array(
        'order'  => $order->get_id(),
        'msg'    => $message_id,
        'reason' => $reason,
    ) );
    return true;
}

/**
 * Заказ создавался по схеме 1.x.
 *
 * @param WC_Order $order Заказ.
 * @return bool
 */
function ptg_is_legacy_order( $order ) {
    foreach ( PTG_LEGACY_KEYS as $key ) {
        if ( $order->get_meta( $key ) ) {
            return true;
        }
    }
    return false;
}

/**
 * Обработка ошибки синка.
 *
 * Нота об ошибке — не чаще раза в час на заказ: если Telegram лёг, каждое
 * сохранение заказа в админке иначе добавляет строку в историю.
 *
 * @param WC_Order $order  Заказ.
 * @param WP_Error $err    Ошибка.
 * @param string   $reason Причина синка.
 * @return void
 */
function ptg_handle_sync_error( $order, $err, $reason = '' ) {
    $code = $err->get_error_code();

    ptg_log( 'error', 'Синк не удался', array(
        'order'  => $order->get_id(),
        'code'   => $code,
        'msg'    => $err->get_error_message(),
        'reason' => $reason,
    ) );

    if ( 'ptg_rate_limited' === $code ) {
        $data  = $err->get_error_data();
        $delay = max( 1, (int) ( $data['retry_after'] ?? 5 ) );
        if ( ! wp_next_scheduled( 'ptg_retry_sync', array( $order->get_id() ) ) ) {
            wp_schedule_single_event( time() + $delay, 'ptg_retry_sync', array( $order->get_id() ) );
        }
        return;
    }

    $throttle_key = 'ptg_err_' . $order->get_id();
    if ( ! get_transient( $throttle_key ) ) {
        set_transient( $throttle_key, 1, HOUR_IN_SECONDS );
        ptg_add_note( $order, 'Telegram: ошибка отправки — ' . $err->get_error_message() );
    }
}

/**
 * Отложенный повтор после 429.
 */
add_action( 'ptg_retry_sync', 'ptg_do_retry_sync', 10, 1 );

/**
 * @param int $order_id Заказ.
 * @return void
 */
function ptg_do_retry_sync( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( $order ) {
        ptg_sync_order_message( $order, 'retry' );
    }
}

/**
 * Новый заказ с чекаута.
 *
 * Приоритет 99: к этому моменту тема уже проставила кастомный номер через
 * woocommerce_new_order.
 */
add_action( 'woocommerce_checkout_order_processed', 'ptg_on_order_created', 99, 1 );

/**
 * @param int $order_id Заказ.
 * @return void
 */
function ptg_on_order_created( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }

    // Для офлайн-оплаты pending пропускаем: WooCommerce тут же переведёт
    // заказ в processing, и сообщение создастся оттуда — без дубля.
    if ( 'pending' === $order->get_status() ) {
        $payment_method = $order->get_payment_method();
        if ( empty( $payment_method ) && ! empty( $_POST['payment_method'] ) ) {
            $payment_method = sanitize_text_field( wp_unslash( $_POST['payment_method'] ) );
        }

        $offline = array( 'cod', 'cheque', 'bacs', 'podval_cash', 'terminal' );
        if ( in_array( $payment_method, $offline, true ) ) {
            ptg_log( 'info', 'pending при офлайн-оплате пропущен', array(
                'order'  => $order_id,
                'method' => $payment_method,
            ) );
            return;
        }
    }

    ptg_sync_order_message( $order, 'checkout' );
}

/**
 * Смена статуса.
 */
add_action( 'woocommerce_order_status_changed', 'ptg_on_status_changed', 10, 4 );

/**
 * @param int      $order_id   Заказ.
 * @param string   $old_status Старый статус.
 * @param string   $new_status Новый статус.
 * @param WC_Order $order      Заказ.
 * @return void
 */
function ptg_on_status_changed( $order_id, $old_status, $new_status, $order ) {
    if ( ! $order instanceof WC_Order ) {
        $order = wc_get_order( $order_id );
    }
    if ( ! $order ) {
        return;
    }

    // Обработчик выполняется внутри перехода статуса, до того как
    // WooCommerce допишет свою запись в историю. Любая ошибка здесь
    // оборвала бы переход на полпути, поэтому ловим всё и уходим в
    // журнал: статус важнее нашей синхронизации.
    try {
        ptg_handle_status_changed( $order_id, $old_status, $new_status, $order );
    } catch ( Throwable $e ) {
        ptg_log( 'error', 'Ошибка обработки смены статуса', array(
            'order' => $order_id,
            'to'    => $new_status,
            'error' => $e->getMessage(),
            'where' => basename( $e->getFile() ) . ':' . $e->getLine(),
        ) );
    }
}

/**
 * Собственно обработка смены статуса.
 *
 * @param int      $order_id   Заказ.
 * @param string   $old_status Было.
 * @param string   $new_status Стало.
 * @param WC_Order $order      Заказ.
 * @return void
 */
function ptg_handle_status_changed( $order_id, $old_status, $new_status, $order ) {

    // Время смены статуса — для сортировки «давно ждут» в Mini App и
    // счётчика дней на выдаче. date_modified не годится: меняется от любой правки.
    $order->update_meta_data( PTG_META_STATUS_AT, time() );
    // Откуда пришли — для «Отменить» в приложении (2.9.0).
    $order->update_meta_data( PTG_META_STATUS_PREV, (string) $old_status );
    $order->save_meta_data();

    $had_message = (int) $order->get_meta( PTG_META_MESSAGE );

    ptg_sync_order_message( $order, 'status:' . $old_status . '>' . $new_status );

    // Оплата. Правка сообщения проходит тихо — в ленте её не видно,
    // поэтому к карточке добавляется короткий реплай.
    //
    // Раньше условием было строго pending → processing, и оплата со
    // второй попытки (pending → failed → processing) проходила молча:
    // сообщение «не оплачен» банк снимал, а нового не появлялось.
    // Теперь смотрим на сам факт оплаты, а не на конкретный переход,
    // и защищаемся метой от повторного реплая.
    $unpaid_before = in_array( $old_status, array( 'pending', 'failed', 'on-hold' ), true );
    $paid_now      = 'paid' === ptg_payment_state( $order )['state'];
    $announced     = (bool) $order->get_meta( PTG_META_PAID_SAID );

    if ( $unpaid_before && $paid_now && ! $announced && $had_message ) {
        ptg_announce_paid( $order );
    }
}

/**
 * Сохранение заказа в админке — правки должны доезжать до Telegram.
 */
add_action( 'woocommerce_process_shop_order_meta', 'ptg_on_admin_save', 99, 1 );

/**
 * @param int $order_id Заказ.
 * @return void
 */
function ptg_on_admin_save( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( $order ) {
        ptg_sync_order_message( $order, 'admin-save' );
    }
}

/**
 * Действие «Пересоздать сообщение» в выпадающем списке действий заказа.
 *
 * @param array $actions Действия.
 * @return array
 */
function ptg_order_actions( $actions ) {
    $actions['ptg_resend'] = 'Telegram: пересоздать сообщение';
    return $actions;
}
add_filter( 'woocommerce_order_actions', 'ptg_order_actions' );

/**
 * @param WC_Order $order Заказ.
 * @return void
 */
function ptg_order_action_resend( $order ) {
    $order->delete_meta_data( PTG_META_MESSAGE );
    $order->delete_meta_data( PTG_META_HASH );
    foreach ( PTG_LEGACY_KEYS as $key ) {
        $order->delete_meta_data( $key );
    }
    $order->save();

    if ( ptg_sync_order_message( $order, 'manual-resend' ) ) {
        ptg_add_note( $order, 'Telegram: сообщение пересоздано вручную' );
    }
}
add_action( 'woocommerce_order_action_ptg_resend', 'ptg_order_action_resend' );

/**
 * Баннер поправок на экране заказа: расхождение не должно быть видно
 * только в Telegram.
 *
 * @param WC_Order $order Заказ.
 * @return void
 */
function ptg_admin_order_banner( $order ) {
    $overrides = ptg_get_overrides( $order );
    if ( empty( $overrides ) ) {
        return;
    }

    $data = ptg_order_data( $order );
    if ( empty( $data['overrides'] ) ) {
        return;
    }

    echo '<div style="margin:12px 0;padding:12px 14px;border-left:4px solid #d63638;background:#fcf0f1;">';
    echo '<p style="margin:0 0 6px;font-weight:600;color:#d63638;">🛑 ИЗМЕНЕНО ПОСЛЕ ОФОРМЛЕНИЯ</p>';
    echo '<ul style="margin:0 0 6px 18px;list-style:disc;">';
    foreach ( $data['overrides'] as $ovr ) {
        $line = esc_html( $ovr['label'] ) . ': <s>' . esc_html( $ovr['from_label'] ) . '</s> → <strong>'
            . esc_html( $ovr['to_label'] ) . '</strong>';
        if ( null !== $ovr['delta'] ) {
            $sign  = ( $ovr['delta'] > 0 ) ? '+' : '−';
            $line .= ' · доплата ' . $sign . esc_html( ptg_fmt_amount( abs( $ovr['delta'] ) ) ) . ' Br';
        }
        if ( ! empty( $ovr['by_name'] ) ) {
            $line .= ' <em>(' . esc_html( $ovr['by_name'] ) . ')</em>';
        }
        echo '<li>' . wp_kses_post( $line ) . '</li>';
    }
    echo '</ul>';

    if ( ! empty( $data['flags']['mockup_stale'] ) ) {
        echo '<p style="margin:0 0 6px;font-weight:600;">🚫 Макет требует перерисовки — в печать не отдавать</p>';
    }
    if ( apply_filters( 'ptg_banner_file_warning', ptg_has_masked_files( $data ), $data ) ) {
        echo '<p style="margin:0;">⚠️ Имя файла принта и параметры позиций — старые. Наносить по этому блоку.</p>';
    }
    echo '</div>';
}
add_action( 'woocommerce_admin_order_data_after_order_details', 'ptg_admin_order_banner' );

/**
 * Метабокс с перепиской по заказу.
 *
 * Лог живёт в мете заказа, а не в order notes: переписка на два десятка
 * реплик забила бы историю заказа так, что в ней не нашлись бы сами
 * события заказа.
 *
 * @return void
 */
function ptg_add_chat_metabox() {
    $screens = array( 'shop_order' );
    if ( function_exists( 'wc_get_page_screen_id' ) ) {
        $screens[] = wc_get_page_screen_id( 'shop-order' ); // HPOS.
    }

    foreach ( array_unique( $screens ) as $screen ) {
        add_meta_box(
            'ptg_chat_log',
            'Переписка с клиентом',
            'ptg_render_chat_metabox',
            $screen,
            'normal',
            'default'
        );
    }
}
add_action( 'add_meta_boxes', 'ptg_add_chat_metabox' );

/**
 * Содержимое метабокса.
 *
 * @param WP_Post|WC_Order $post_or_order Объект экрана.
 * @return void
 */
function ptg_render_chat_metabox( $post_or_order ) {
    $order = ( $post_or_order instanceof WC_Order )
        ? $post_or_order
        : wc_get_order( $post_or_order->ID ?? 0 );

    if ( ! $order ) {
        return;
    }

    $client = ptg_order_client( $order );

    if ( ! $client['linked'] ) {
        echo '<p>Чат клиента не привязан. Откройте переписку с клиентом в Telegram '
            . 'и отправьте там команду <code>/link ' . esc_html( $order->get_order_number() ) . '</code> — '
            . 'команда удалится, а подтверждение придёт в группу заказов.</p>';
        return;
    }

    $who = $client['username'] ? '@' . $client['username'] : ( $client['name'] ?: 'ID ' . $client['chat_id'] );
    echo '<p><strong>' . esc_html( $who ) . '</strong> · сообщений: ' . (int) $client['messages'];
    echo ' · привязка: ' . esc_html( 'manual' === $client['source'] ? 'вручную' : 'по нику с чекаута' ) . '</p>';

    $log = $order->get_meta( PTG_META_CHAT_LOG );
    if ( ! is_array( $log ) || empty( $log ) ) {
        echo '<p>Сообщений пока нет.</p>';
        return;
    }

    echo '<div style="max-height:360px;overflow:auto;border:1px solid #dcdcde;padding:10px;background:#fff;">';
    foreach ( $log as $entry ) {
        $out   = ( 'out' === ( $entry['d'] ?? '' ) );
        $align = $out ? 'margin-left:60px;background:#f0f6fc;' : 'margin-right:60px;background:#f6f7f7;';
        $text  = (string) ( $entry['x'] ?? '' );

        if ( ! empty( $entry['f'] ) ) {
            $text = '[' . $entry['f'] . ']' . ( $text ? ' ' . $text : '' );
        }
        if ( ! empty( $entry['del'] ) ) {
            $text = '<s>' . esc_html( $text ) . '</s> <em>(удалено)</em>';
        } else {
            $text = esc_html( $text );
            if ( ! empty( $entry['ed'] ) ) {
                $text .= ' <em>(изменено)</em>';
            }
        }

        printf(
            '<div style="%s padding:6px 9px;border-radius:8px;margin-bottom:6px;"><small style="color:#646970;">%s · %s</small><br/>%s</div>',
            esc_attr( $align ),
            esc_html( (string) ( $entry['n'] ?? '' ) ),
            esc_html( gmdate( 'd.m H:i', (int) ( $entry['t'] ?? 0 ) + 3 * 3600 ) ),
            wp_kses_post( $text )
        );
    }
    echo '</div>';

    $files = ptg_chat_files( $order );
    if ( empty( $files ) ) {
        return;
    }

    echo '<p style="margin:12px 0 6px;"><strong>Файлы из переписки</strong></p>';
    echo '<div style="display:flex;flex-wrap:wrap;gap:10px;">';
    foreach ( $files as $f ) {
        $label = ( 'out' === $f['dir'] ? '→ ' : '← ' ) . $f['name'];
        echo '<a href="' . esc_url( $f['url'] ) . '" target="_blank" rel="noopener" '
            . 'style="display:block;width:120px;text-decoration:none;">';
        if ( 'photo' === $f['kind'] ) {
            echo '<img src="' . esc_url( $f['url'] ) . '" style="width:120px;height:120px;object-fit:cover;border-radius:6px;border:1px solid #dcdcde;" alt="" />';
        } else {
            echo '<span style="display:flex;width:120px;height:120px;align-items:center;justify-content:center;'
                . 'border:1px solid #dcdcde;border-radius:6px;background:#f6f7f7;font-size:28px;">📄</span>';
        }
        echo '<small style="display:block;margin-top:4px;word-break:break-all;">' . esc_html( $label ) . '</small>';
        echo '</a>';
    }
    echo '</div>';
}

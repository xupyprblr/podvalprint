<?php
/**
 * Фаза 3. Статусы производственного цикла.
 *
 * Раньше статус жил в эмодзи-реакциях под сообщением: ❤️ клиент ответил,
 * 👍 в печать, 🔥 готов. Реакция не говорит, кто её поставил, не
 * фильтруется, не попадает в историю заказа и не видна в админке. Теперь
 * это статусы WooCommerce, а эмодзи остались в подписях — чтобы лента
 * читалась привычно.
 *
 * Отдельно важное: в исходной схеме не было шага «эскиз отправлен, ждём
 * ответа». Именно на нём терялось понимание, кто чего ждёт.
 *
 * @package podval-telegram-orders
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Справочник статусов плагина.
 *
 * Ключ — без префикса `wc-`. Длина ключа со слагом ограничена: имя
 * post status в WordPress не длиннее 20 символов, а WooCommerce
 * добавляет свои три.
 *
 * `code` — короткий код для callback_data, чтобы укладываться в 64 байта.
 * `next` — куда можно перейти одним нажатием.
 *
 * @return array
 */
function ptg_statuses() {
    return apply_filters( 'ptg_statuses', array(
        'client-replied' => array(
            'label'  => 'Клиент ответил',
            'emoji'  => '❤️',
            'code'   => 'cr',
            'next'   => array( 'sketch-sent' ),
            'paid'   => true,
            'locked' => false,
        ),
        'sketch-sent'    => array(
            'label'  => 'Эскиз отправлен',
            'emoji'  => '🎨',
            'code'   => 'ss',
            'next'   => array( 'in-print' ),
            'paid'   => true,
            'locked' => false,
        ),
        'in-print'       => array(
            'label'  => 'В печати',
            'emoji'  => '👍',
            'code'   => 'pr',
            'next'   => array( 'ready' ),
            'paid'   => true,
            'locked' => true,
        ),
        'ready'          => array(
            'label'  => 'Готов к выдаче',
            'emoji'  => '🔥',
            'code'   => 'rd',
            'next'   => array( 'issued', 'not-picked' ),
            'paid'   => true,
            'locked' => true,
        ),
        'issued'         => array(
            'label'  => 'Выдан',
            'emoji'  => '✅',
            'code'   => 'is',
            'next'   => array(),
            'paid'   => true,
            'locked' => true,
        ),
        // Негативные исходы (2.6.0). Вне линейной цепочки: «назад» ведёт
        // в статус, из которого пришли (мета PTG_META_NEG_FROM), а не в
        // соседа по списку. Не оплачены: в выручку не попадают.
        'refused'        => array(
            'label'  => 'Отказались',
            'emoji'  => '🚫',
            'code'   => 'rf',
            'next'   => array(),
            'paid'   => false,
            'locked' => true,
            'neg'    => true,
        ),
        'not-picked'     => array(
            'label'  => 'Не забрали',
            'emoji'  => '📦',
            'code'   => 'np',
            'next'   => array( 'issued' ),
            'paid'   => false,
            'locked' => true,
            'neg'    => true,
        ),
    ) );
}

/**
 * Полная цепочка, включая стандартные статусы WooCommerce по краям.
 *
 * @return array Ключ статуса => подпись с эмодзи.
 */
function ptg_status_chain() {
    $chain = array( 'processing' => '🆕 Получен' );
    foreach ( ptg_statuses() as $key => $def ) {
        $chain[ $key ] = $def['emoji'] . ' ' . $def['label'];
    }
    return $chain;
}

/**
 * Определение статуса по короткому коду из callback_data.
 *
 * @param string $code Код.
 * @return string Ключ статуса или ''.
 */
function ptg_status_by_code( $code ) {
    foreach ( ptg_statuses() as $key => $def ) {
        if ( $def['code'] === $code ) {
            return $key;
        }
    }
    return '';
}

/**
 * Показывать ли ответственного (карточка, кнопка «Взять в работу»,
 * приложение). Назначение пишется всегда — выключается только показ:
 * пока заказами занимаются двое параллельно, строгая индикация мешает.
 *
 * @return bool
 */
function ptg_show_assignee() {
    return (bool) get_option( 'ptg_show_assignee', 0 );
}

/** Из какого статуса заказ ушёл в негативный — для кнопки «назад». */
const PTG_META_NEG_FROM = '_ptg_neg_from';

/** Когда заказ последний раз сменил статус (unix). Для сортировки «давно ждут». */
const PTG_META_STATUS_AT = '_ptg_status_at';

/** Предыдущий статус — для отмены случайного нажатия в приложении. */
const PTG_META_STATUS_PREV = '_ptg_status_prev';

/** Об оплате уже сообщили в группу (2.16.0). */
const PTG_META_PAID_SAID = '_ptg_paid_announced';

/** «После согласования»: как фактически оплатили — cash|terminal|link (2.17.0). */
const PTG_META_PAY_MARK    = '_ptg_pay_mark';
const PTG_META_PAY_MARK_AT = '_ptg_pay_mark_at';
const PTG_META_PAY_MARK_BY = '_ptg_pay_mark_by';

/** Заказ оформлен вручную из приложения (2.12.0). */
const PTG_META_MANUAL = '_ptg_manual';

/** Сколько секунд после смены статуса её можно отменить. */
const PTG_UNDO_WINDOW = 120;

/**
 * Негативный ли статус.
 *
 * @param string $key Ключ без wc-.
 * @return bool
 */
function ptg_is_negative_status( $key ) {
    $defs = ptg_statuses();
    return ! empty( $defs[ $key ]['neg'] );
}

/**
 * Статусы, из которых можно отметить «Отказались»: всё, что в работе,
 * плюс «Не забрали» — если клиент в итоге прямо отказался.
 *
 * @return string[]
 */
function ptg_refusable_statuses() {
    return array( 'pending', 'failed', 'processing', 'on-hold', 'client-replied', 'sketch-sent', 'in-print', 'ready', 'not-picked' );
}

/**
 * Регистрация статусов.
 *
 * @return void
 */
function ptg_register_statuses() {
    foreach ( ptg_statuses() as $key => $def ) {
        register_post_status( 'wc-' . $key, array(
            'label'                     => $def['label'],
            'public'                    => false,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            /* translators: %s: count */
            'label_count'               => _n_noop(
                $def['label'] . ' <span class="count">(%s)</span>',
                $def['label'] . ' <span class="count">(%s)</span>'
            ),
        ) );
    }
}
add_action( 'init', 'ptg_register_statuses', 9 );

/**
 * Добавляет статусы в выпадающий список WooCommerce.
 *
 * Вставляем сразу после «В обработке»: порядок в списке должен совпадать
 * с порядком реальных шагов, иначе в админке их приходится искать.
 *
 * @param array $statuses Статусы.
 * @return array
 */
function ptg_add_order_statuses( $statuses ) {
    $out = array();
    foreach ( $statuses as $key => $label ) {
        $out[ $key ] = $label;
        if ( 'wc-processing' === $key ) {
            foreach ( ptg_statuses() as $our_key => $def ) {
                $out[ 'wc-' . $our_key ] = $def['emoji'] . ' ' . $def['label'];
            }
        }
    }
    return $out;
}
add_filter( 'wc_order_statuses', 'ptg_add_order_statuses' );

/**
 * Наши статусы считаются оплаченными.
 *
 * Без этого `$order->is_paid()` станет false сразу после перевода заказа
 * из «в обработке» в любой производственный статус, и всё, что на это
 * завязано, начнёт вести себя странно.
 *
 * @param array $statuses Статусы.
 * @return array
 */
function ptg_paid_statuses( $statuses ) {
    foreach ( ptg_statuses() as $key => $def ) {
        if ( ! empty( $def['paid'] ) ) {
            $statuses[] = $key;
        }
    }
    return $statuses;
}
add_filter( 'woocommerce_order_is_paid_statuses', 'ptg_paid_statuses' );

/**
 * Статус заказа для карточки.
 *
 * @param WC_Order $order Заказ.
 * @return array [ key, label, emoji, next, locked ].
 */
function ptg_order_status_info( $order ) {
    $key  = $order->get_status();
    $defs = ptg_statuses();

    if ( isset( $defs[ $key ] ) ) {
        $back = '';
        if ( ! empty( $defs[ $key ]['neg'] ) ) {
            $back = (string) $order->get_meta( PTG_META_NEG_FROM );
            if ( '' === $back || ! isset( ptg_status_chain()[ $back ] ) || ptg_is_negative_status( $back ) ) {
                $back = ( 'not-picked' === $key ) ? 'ready' : 'processing';
            }
        }
        return array(
            'key'       => $key,
            'label'     => $defs[ $key ]['label'],
            'emoji'     => $defs[ $key ]['emoji'],
            'next'      => $defs[ $key ]['next'],
            'locked'    => ! empty( $defs[ $key ]['locked'] ),
            'negative'  => ! empty( $defs[ $key ]['neg'] ),
            'back'      => $back,
            'refusable' => in_array( $key, ptg_refusable_statuses(), true ),
        );
    }

    // Стандартные статусы: следующий шаг есть только у «в обработке».
    $labels = array(
        'pending'    => array( '⏳', 'Ожидает оплату' ),
        'processing' => array( '🆕', 'Получен' ),
        'on-hold'    => array( '⏸', 'Оплата после согласования' ),
        'completed'  => array( '✅', 'Завершён' ),
        'cancelled'  => array( '🚫', 'Отменён' ),
        'refunded'   => array( '↩️', 'Возврат' ),
        'failed'     => array( '⚠️', 'Ошибка оплаты' ),
    );

    return array(
        'key'       => $key,
        'label'     => $labels[ $key ][1] ?? $key,
        'emoji'     => $labels[ $key ][0] ?? '•',
        // on-hold — «Оплата после согласования»: живой заказ, ждёт ссылку
        // на оплату, поэтому идёт по циклу так же, как «Получен».
        //
        // pending и failed (2.16.1): оплата не прошла, но клиент в личке
        // подтвердил заказ — берём в работу кнопкой, а не через админку.
        // Заодно заказ перестаёт быть кандидатом на автоотмену по
        // таймауту неоплаченных.
        'next'      => in_array( $key, array( 'processing', 'on-hold', 'pending', 'failed' ), true ) ? array( 'client-replied' ) : array(),
        'locked'    => false,
        'negative'  => false,
        'back'      => '',
        'refusable' => in_array( $key, array( 'processing', 'on-hold', 'pending', 'failed' ), true ),
    );
}

/**
 * Заблокированы ли параметры заказа.
 *
 * После отправки в печать менять размер или цвет кнопкой нельзя: файл
 * уже у печатника, а баннер «изменено» он увидит только если полезет в
 * карточку заново. Разблокировка — явным возвратом статуса назад.
 *
 * @param WC_Order $order Заказ.
 * @return bool
 */
function ptg_is_locked( $order ) {
    $info = ptg_order_status_info( $order );
    return ! empty( $info['locked'] );
}

/**
 * Переводит заказ в статус.
 *
 * @param WC_Order $order  Заказ.
 * @param string   $status Ключ статуса.
 * @param int      $tg_id  Кто перевёл.
 * @param array    $from   Объект from.
 * @return true|WP_Error
 */
function ptg_set_status( $order, $status, $tg_id = 0, $from = array() ) {
    $chain = ptg_status_chain();
    if ( ! isset( $chain[ $status ] ) ) {
        return new WP_Error( 'ptg_bad_status', 'Неизвестный статус' );
    }

    if ( $order->get_status() === $status ) {
        return new WP_Error( 'ptg_same_status', 'Заказ уже в этом статусе' );
    }

    $name = ptg_staff_name( $tg_id, $from );
    $from_label = ptg_order_status_info( $order )['label'];

    // Уход в негативный статус запоминает исходный: «назад» из «Отказались»
    // должен вернуть туда, где заказ был, а не в «Получен».
    $cur = $order->get_status();
    if ( ptg_is_negative_status( $status ) ) {
        if ( 'refused' === $status && ! in_array( $cur, ptg_refusable_statuses(), true ) ) {
            return new WP_Error( 'ptg_bad_transition', 'Из этого статуса нельзя отметить отказ' );
        }
        if ( ! ptg_is_negative_status( $cur ) ) {
            $order->update_meta_data( PTG_META_NEG_FROM, $cur );
            $order->save_meta_data();
        }
    }

    // Запись делаем ДО перехода. WooCommerce дописывает свою последним
    // шагом, уже после хуков, и фатальная ошибка в них (нехватка памяти
    // при пересборке превью, таймаут запроса к Telegram) обрывает
    // переход: статус в базе уже новый, а в истории пусто. Запись
    // заранее хуже по смыслу на доли секунды, зато не теряется.
    ptg_add_note( $order, sprintf( 'Статус изменён из Telegram: %s → %s (%s)',
        $from_label,
        ptg_status_chain()[ $status ],
        $name
    ) );

    ptg_watch_fatal( array( 'order' => $order->get_id(), 'to' => $status ) );
    $order->update_status( $status, '', true );
    ptg_watch_fatal_done();

    ptg_log( 'info', 'Статус изменён', array(
        'order' => $order->get_id(),
        'to'    => $status,
        'by'    => (int) $tg_id,
    ) );

    return true;
}

/** Меты сверки параметров перед печатью. */
const PTG_META_VERIFIED_BY = '_ptg_verified_by';
const PTG_META_VERIFIED_AT = '_ptg_verified_at';

/**
 * Нужна ли сверка перед отправкой в печать.
 *
 * Только когда в заказе есть правки параметров: имя файла принта собрано
 * по маске из исходных значений и после правки врёт. Это единственный
 * момент, когда ошибка уже необратима — ткань испорчена.
 *
 * @param WC_Order $order Заказ.
 * @param array    $data  Данные заказа.
 * @return bool
 */
function ptg_needs_print_confirm( $order, $data ) {
    if ( empty( $data['overrides'] ) ) {
        return false;
    }
    return '' === (string) $order->get_meta( PTG_META_VERIFIED_AT );
}

/**
 * Фиксирует сверку.
 *
 * @param WC_Order $order Заказ.
 * @param int      $tg_id Кто сверил.
 * @param array    $from  Объект from.
 * @return void
 */
function ptg_mark_verified( $order, $tg_id, $from = array() ) {
    $name = ptg_staff_name( $tg_id, $from );
    $order->update_meta_data( PTG_META_VERIFIED_BY, $name );
    $order->update_meta_data( PTG_META_VERIFIED_AT, current_time( 'mysql' ) );
    $order->save();
    ptg_add_note( $order, sprintf( 'Параметры сверены перед печатью: %s (Telegram)', $name ) );
}

/**
 * Данные сверки для карточки.
 *
 * @param WC_Order $order Заказ.
 * @return array
 */
function ptg_order_verified( $order ) {
    return array(
        'by' => (string) $order->get_meta( PTG_META_VERIFIED_BY ),
        'at' => (string) $order->get_meta( PTG_META_VERIFIED_AT ),
    );
}

/**
 * Состояние оплаты для карточки и приложения (2.16.0).
 *
 * Про онлайн-оплату важно видеть, дошли ли деньги: клиент может
 * ошибиться в форме банка, попробовать ещё раз, и по карточке этого
 * никак не понять. Оплата при получении состояния не имеет — деньги
 * берут на выдаче, и «оплачен» там означало бы неправду, хотя
 * WooCommerce и проставляет дату оплаты при переходе в «Обработка».
 *
 * @param WC_Order $order Заказ.
 * @return array [ state, state_label ].
 */
function ptg_payment_state( $order ) {
    $status = $order->get_status();

    // 2.17.0. «Оплата после согласования» — это обещание оплатить
    // позже: ссылкой при доставке, наличными или картой на самовывозе.
    // WooCommerce проставляет date_paid при любом переходе в «Обработку»,
    // а способ оплаты вдобавок может сам вызвать payment_complete() —
    // так #509 получил «✅ оплачен» и реплай в группу, не заплатив ни
    // копейки. Поэтому здесь верим только ручной отметке менеджера.
    if ( ptg_payment_is_agreed( $order ) ) {
        $mark = ptg_pay_mark( $order );
        if ( $mark ) {
            return array(
                'state'       => 'paid',
                'state_label' => '✅ оплачен · ' . mb_strtolower( ptg_pay_mark_options()[ $mark ] ),
            );
        }
        return array( 'state' => 'agreed', 'state_label' => '💬 оплата по согласованию' );
    }

    $online = '' !== (string) $order->get_transaction_id()
        || ( function_exists( 'ptg_payment_category' )
            && in_array( ptg_payment_category( (string) $order->get_payment_method(), (string) $order->get_payment_method_title() ), array( 'online', 'link' ), true ) );

    if ( 'failed' === $status ) {
        return array( 'state' => 'failed', 'state_label' => '❌ платёж не прошёл' );
    }
    if ( 'pending' === $status ) {
        return array( 'state' => 'pending', 'state_label' => '⏳ ждём оплату' );
    }
    if ( $online && $order->get_date_paid() ) {
        return array( 'state' => 'paid', 'state_label' => '✅ оплачен' );
    }
    if ( $online ) {
        return array( 'state' => 'unpaid', 'state_label' => '⏳ оплата не подтверждена' );
    }
    return array( 'state' => '', 'state_label' => '' );
}

/**
 * Способ «Оплата после согласования» с чекаута.
 *
 * Заказы из приложения сюда не относятся: там способ выбирают сразу
 * фактический (ptg_cash, ptg_link…).
 *
 * @param WC_Order $order Заказ.
 * @return bool
 */
function ptg_payment_is_agreed( $order ) {
    $method = (string) $order->get_payment_method();
    if ( 0 === strpos( $method, 'ptg_' ) ) {
        return false;
    }
    $t = mb_strtolower( (string) $order->get_payment_method_title() . ' ' . $method );
    return (bool) apply_filters( 'ptg_payment_is_agreed', false !== mb_strpos( $t, 'согласован' ), $order );
}

/**
 * Чем фактически оплатили — варианты ручной отметки.
 *
 * Ключи совпадают с категориями отчёта, чтобы отмеченный заказ попадал
 * в отчёте в реальный способ, а не в «после согласования».
 *
 * @return array key => label.
 */
function ptg_pay_mark_options() {
    return array(
        'link'     => 'По ссылке',
        'cash'     => 'Наличными',
        'terminal' => 'Картой',
    );
}

/**
 * Ручная отметка оплаты.
 *
 * @param WC_Order $order Заказ.
 * @return string Ключ или ''.
 */
function ptg_pay_mark( $order ) {
    $m = (string) $order->get_meta( PTG_META_PAY_MARK );
    return isset( ptg_pay_mark_options()[ $m ] ) ? $m : '';
}

/**
 * Поставить или снять отметку оплаты.
 *
 * При первой отметке в группу уходит реплай «оплачен» — тот же, что у
 * онлайн-оплаты. При смене способа повторного реплая нет, при снятии
 * отметки реплай можно будет отправить снова.
 *
 * @param WC_Order $order Заказ.
 * @param string   $mark  Ключ или '' — снять.
 * @param int      $tg_id Кто.
 * @param array    $from  Объект from.
 * @return true|WP_Error
 */
function ptg_set_pay_mark( $order, $mark, $tg_id, $from ) {
    if ( ! ptg_payment_is_agreed( $order ) ) {
        return new WP_Error( 'ptg_bad_state', 'Отметка нужна только для оплаты после согласования' );
    }
    $opts = ptg_pay_mark_options();
    if ( '' !== $mark && ! isset( $opts[ $mark ] ) ) {
        return new WP_Error( 'ptg_bad_value', 'Неизвестный способ оплаты' );
    }
    $was = ptg_pay_mark( $order );
    if ( $was === $mark ) {
        return true;
    }
    $who = function_exists( 'ptg_staff_name' ) ? ptg_staff_name( $tg_id, $from ) : '';

    if ( '' === $mark ) {
        $order->delete_meta_data( PTG_META_PAY_MARK );
        $order->delete_meta_data( PTG_META_PAY_MARK_AT );
        $order->delete_meta_data( PTG_META_PAY_MARK_BY );
        $order->delete_meta_data( PTG_META_PAID_SAID );
        $order->save_meta_data();
        ptg_add_note( $order, 'Отметка об оплате снята' . ( $who ? ' (' . $who . ')' : '' ) );
        return true;
    }

    $order->update_meta_data( PTG_META_PAY_MARK, $mark );
    $order->update_meta_data( PTG_META_PAY_MARK_AT, time() );
    $order->update_meta_data( PTG_META_PAY_MARK_BY, $who );
    $order->save_meta_data();
    ptg_add_note( $order, 'Оплачено: ' . mb_strtolower( $opts[ $mark ] ) . ( $who ? ' (' . $who . ')' : '' ) );

    if ( '' === $was ) {
        ptg_announce_paid( $order );
    }
    return true;
}

/**
 * Реплай «✅ Заказ #N оплачен» к карточке — один раз на заказ.
 *
 * @param WC_Order $order Заказ.
 * @return void
 */
function ptg_announce_paid( $order ) {
    $msg = (int) $order->get_meta( PTG_META_MESSAGE );
    if ( ! $msg || $order->get_meta( PTG_META_PAID_SAID ) ) {
        return;
    }
    $order->update_meta_data( PTG_META_PAID_SAID, time() );
    $order->save_meta_data();
    $chat_id = (string) $order->get_meta( PTG_META_CHAT );
    if ( '' === $chat_id ) {
        $chat_id = (string) get_option( 'ptg_chat_id', '' );
    }
    $state = ptg_payment_state( $order );
    $tail  = 'paid' === $state['state'] && false !== mb_strpos( $state['state_label'], '·' )
        ? ' · ' . trim( mb_substr( $state['state_label'], mb_strpos( $state['state_label'], '·' ) + 1 ) )
        : '';
    $res = ptg_tg_send_message(
        $chat_id,
        '✅ <b>Заказ #' . ptg_esc( $order->get_order_number() ) . '</b> оплачен' . ptg_esc( $tail ),
        array( 'reply_to_message_id' => $msg )
    );
    if ( is_wp_error( $res ) ) {
        ptg_log( 'warn', 'Реплай об оплате не ушёл', array(
            'order' => $order->get_id(),
            'msg'   => $res->get_error_message(),
        ) );
    }
}

<?php
/**
 * Инлайн-клавиатуры карточки заказа.
 *
 * Формат callback_data: o:<order_id>:<action>[:<item_id>][:<value>]
 * Лимит Telegram — 64 байта, поэтому всё ASCII: слаги значений берутся из
 * podval_tpl_garment_options() и там латиница (white/black, xs…3xl,
 * classic/oversize, front/back). Кириллица в callback съедает по два
 * байта на символ и лимит пробивается быстро.
 *
 * Свободного ввода значений нет принципиально: иначе в баннере появятся
 * «эМ», «м» и «M(!)», и он перестанет читаться.
 *
 * @package podval-telegram-orders
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Собирает callback_data и проверяет лимит.
 *
 * @param int    $order_id Заказ.
 * @param string $action   Действие.
 * @param array  $parts    Доп. сегменты.
 * @return string
 */
function ptg_cb( $order_id, $action, $parts = array() ) {
    $data = 'o:' . (int) $order_id . ':' . $action;
    foreach ( $parts as $p ) {
        $data .= ':' . $p;
    }
    if ( strlen( $data ) > 64 ) {
        ptg_log( 'error', 'callback_data длиннее 64 байт', array( 'data' => $data, 'len' => strlen( $data ) ) );
        $data = substr( $data, 0, 64 );
    }
    return $data;
}

/**
 * Разбирает callback_data.
 *
 * @param string $data Строка.
 * @return array|null [ order_id, action, args ].
 */
function ptg_cb_parse( $data ) {
    $parts = explode( ':', (string) $data );
    if ( count( $parts ) < 3 || 'o' !== $parts[0] ) {
        return null;
    }
    return array(
        'order_id' => (int) $parts[1],
        'action'   => $parts[2],
        'args'     => array_slice( $parts, 3 ),
    );
}

/**
 * Основная клавиатура карточки.
 *
 * @param array $data Данные заказа.
 * @return array reply_markup.
 */
function ptg_keyboard_root( $data ) {
    $rows = array();

    // Отказ — конец истории. Оставляем только возврат в работу и
    // ссылки: остальные кнопки на таком заказе нечего нажимать.
    if ( 'refused' === ( $data['status_info']['key'] ?? '' ) ) {
        $back = (string) ( $data['status_info']['back'] ?? '' );
        if ( $back ) {
            $defs  = ptg_statuses();
            $code  = 'processing' === $back ? 'new' : ( $defs[ $back ]['code'] ?? 'new' );
            $rows[] = array( array(
                'text'          => '↩️ Вернуть в работу',
                'callback_data' => ptg_cb( $data['id'], 'st', array( $code ) ),
            ) );
        }
        return array( 'inline_keyboard' => array_merge( $rows, ptg_keyboard_link_rows( $data ) ) );
    }

    // Выдан — заказ закрыт (2.17.1). Под карточкой одна строка: вернуть
    // на «Готов к выдаче», если выдали по ошибке, и открыть в приложении.
    // Остальное на закрытом заказе только удлиняет ленту.
    if ( 'issued' === ( $data['status_info']['key'] ?? '' ) ) {
        $defs = ptg_statuses();
        $row  = array( array(
            'text'          => '↩️ Вернуть в работу',
            'callback_data' => ptg_cb( $data['id'], 'st', array( $defs['ready']['code'] ) ),
        ) );
        $app = function_exists( 'ptg_app_order_link' ) ? ptg_app_order_link( $data['id'] ) : '';
        if ( $app ) {
            $row[] = array( 'text' => '📱 В приложении', 'url' => $app );
        } elseif ( ! empty( $data['admin_url'] ) ) {
            $row[] = array( 'text' => '🗂 Админка', 'url' => $data['admin_url'] );
        }
        return array( 'inline_keyboard' => array( $row ) );
    }

    // Следующий шаг цикла — первым и единственным: список из шести
    // статусов на карточке читается хуже, чем одна очевидная кнопка.
    foreach ( ptg_keyboard_status_rows( $data ) as $row ) {
        $rows[] = $row;
    }

    if ( ptg_overridable_items( $data ) ) {
        $label = empty( $data['overrides'] )
            ? '✏️ Изменить параметры'
            : '✏️ Изменить параметры (' . count( $data['overrides'] ) . ')';
        $rows[] = array(
            array( 'text' => $label, 'callback_data' => ptg_cb( $data['id'], 'ovr' ) ),
        );
    }

    $rows = array_merge( $rows, ptg_keyboard_greeting_rows( $data ) );
    $rows = array_merge( $rows, ptg_keyboard_phone_rows( $data ) );

    if ( ! empty( $data['client']['messages'] ) || ! empty( $data['client']['files'] ) ) {
        $rows[] = array( array(
            'text'          => '📜 Переписка (' . (int) $data['client']['messages'] . ')',
            'callback_data' => ptg_cb( $data['id'], 'log' ),
        ) );
    }

    $second = array();

    // Кнопка ведёт в личку клиента. Через юзернейм — надёжнее: ссылка
    // tg://user?id= работает не во всех клиентах.
    if ( ! empty( $data['client']['linked'] ) ) {
        if ( ! empty( $data['client']['username'] ) ) {
            $second[] = array(
                'text' => '💬 Клиент',
                'url'  => 'https://t.me/' . $data['client']['username'],
            );
        } elseif ( ! empty( $data['client']['chat_id'] ) ) {
            $second[] = array(
                'text' => '💬 Клиент',
                'url'  => 'tg://user?id=' . (int) $data['client']['chat_id'],
            );
        }
    }

    if ( ptg_show_assignee() ) {
        $second[] = array(
            'text'          => empty( $data['assignee']['name'] ) ? '🙋 Взять в работу' : '🔄 Передать себе',
            'callback_data' => ptg_cb( $data['id'], 'take' ),
        );
    }

    // Отказ — не шаг цикла, поэтому не в ряду статусов: там он стоял бы
    // рядом с «следующим шагом» и в него промахивались бы.
    if ( ! empty( $data['status_info']['refusable'] ) ) {
        $second[] = array(
            'text'          => '🚫 Отказ',
            'callback_data' => ptg_cb( $data['id'], 'st', array( 'rf' ) ),
        );
    }

    if ( $second ) {
        $rows[] = $second;
    }

    $rows = array_merge( $rows, ptg_keyboard_link_rows( $data ) );

    return array( 'inline_keyboard' => $rows );
}

/**
 * Выбор позиции.
 *
 * @param array $data Данные заказа.
 * @return array
 */
function ptg_keyboard_items( $data ) {
    $rows = array();
    $n    = 0;
    foreach ( ptg_overridable_items( $data ) as $item ) {
        $n++;
        $title = mb_substr( $item['name'], 0, 28 );
        $rows[] = array( array(
            'text'          => $n . '. ' . $title,
            'callback_data' => ptg_cb( $data['id'], 'ovi', array( $item['item_id'] ) ),
        ) );
    }
    $rows[] = array( array( 'text' => '‹ Закрыть', 'callback_data' => ptg_cb( $data['id'], 'ovx' ) ) );

    return array( 'inline_keyboard' => $rows );
}

/**
 * Выбор поля для позиции.
 *
 * @param array $data    Данные заказа.
 * @param int   $item_id Позиция.
 * @return array
 */
function ptg_keyboard_fields( $data, $item_id ) {
    $labels = ptg_garment_labels();
    $row    = array();
    $rows   = array();

    foreach ( $labels as $field => $label ) {
        if ( ! ptg_garment_value_options( $field ) ) {
            continue;
        }
        $row[] = array(
            'text'          => $label,
            'callback_data' => ptg_cb( $data['id'], 'ovf', array( $item_id, $field ) ),
        );
        if ( 2 === count( $row ) ) {
            $rows[] = $row;
            $row    = array();
        }
    }
    if ( $row ) {
        $rows[] = $row;
    }

    $back = ( count( ptg_overridable_items( $data ) ) > 1 )
        ? ptg_cb( $data['id'], 'ovr' )
        : ptg_cb( $data['id'], 'ovx' );
    $rows[] = array( array( 'text' => '‹ Назад', 'callback_data' => $back ) );

    return array( 'inline_keyboard' => $rows );
}

/**
 * Выбор значения поля.
 *
 * @param array  $data    Данные заказа.
 * @param int    $item_id Позиция.
 * @param string $field   Поле.
 * @return array
 */
function ptg_keyboard_values( $data, $item_id, $field ) {
    $rows    = array();
    $row     = array();
    $options = ptg_garment_value_options( $field );

    $current  = '';
    $item_row = null;
    foreach ( $data['items'] as $item ) {
        if ( (int) $item['item_id'] === (int) $item_id ) {
            $item_row = $item;
            break;
        }
    }
    if ( $item_row ) {
        foreach ( $item_row['garment'] as $g ) {
            if ( $g['key'] === $field ) {
                $current = (string) $g['slug'];
            }
        }
    }

    $per_row = ( 'size' === $field ) ? 4 : 2;

    foreach ( $options as $slug => $label ) {
        $row[] = array(
            'text'          => ( $slug === $current ? '• ' : '' ) . $label,
            'callback_data' => ptg_cb( $data['id'], 'ovs', array( $item_id, $field, $slug ) ),
        );
        if ( count( $row ) === $per_row ) {
            $rows[] = $row;
            $row    = array();
        }
    }
    if ( $row ) {
        $rows[] = $row;
    }

    // Отмена поправки — только если она есть.
    foreach ( $data['overrides'] as $ovr ) {
        if ( (int) $ovr['item_id'] === (int) $item_id && $ovr['field'] === $field ) {
            $rows[] = array( array(
                'text'          => '↩️ Вернуть «' . $ovr['from_label'] . '»',
                'callback_data' => ptg_cb( $data['id'], 'ovc', array( $item_id, $field ) ),
            ) );
            break;
        }
    }

    $rows[] = array( array(
        'text'          => '‹ Назад',
        'callback_data' => ptg_cb( $data['id'], 'ovi', array( $item_id ) ),
    ) );

    return array( 'inline_keyboard' => $rows );
}

/**
 * Последний ряд: приложение и админка.
 *
 * @param array $data Данные заказа.
 * @return array Ряды кнопок.
 */
function ptg_keyboard_link_rows( $data ) {
    $row = array();
    $app = function_exists( 'ptg_app_order_link' ) ? ptg_app_order_link( $data['id'] ) : '';
    if ( $app ) {
        $row[] = array( 'text' => '📱 В приложении', 'url' => $app );
    }
    if ( ! empty( $data['admin_url'] ) ) {
        $row[] = array( 'text' => $app ? '🗂 Админка' : '🗂 Открыть в админке', 'url' => $data['admin_url'] );
    }
    return $row ? array( $row ) : array();
}

/**
 * Начало работы по заказу: клиент ещё не отвечал (2.14.1).
 *
 * С «❤️ Клиент ответил» переписка уже идёт: приветствие, открытие лички
 * и привязка по номеру там только занимают место под карточкой.
 *
 * @param array $data Данные заказа.
 * @return bool
 */
function ptg_keyboard_early_stage( $data ) {
    $key = (string) ( $data['status_info']['key'] ?? '' );
    return in_array( $key, array( 'pending', 'processing', 'on-hold' ), true );
}

/**
 * Кнопки привязки чата по телефону (2.14.0).
 *
 * Клиент часто оставляет только номер. Бот найти по нему аккаунт не
 * может — в Bot API такого метода нет, — зато Telegram по ссылке
 * t.me/+<цифры> откроет переписку сам. Команда привязки кладётся в
 * буфер соседней кнопкой, чтобы не набирать её руками.
 *
 * @param array $data Данные заказа.
 * @return array Ряды кнопок.
 */
function ptg_keyboard_phone_rows( $data ) {
    if ( ! empty( $data['client']['linked'] ) || ! ptg_keyboard_early_stage( $data ) ) {
        return array();
    }
    $link = ptg_phone_chat_link( $data['customer']['phone'] ?? '' );
    if ( '' === $link ) {
        return array();
    }
    return array( array(
        array( 'text' => '📲 Чат по номеру', 'url' => $link ),
        array(
            'text'      => '📋 /link ' . $data['number'],
            'copy_text' => array( 'text' => '/link ' . $data['number'] ),
        ),
    ) );
}

/**
 * Кнопки приветствия.
 *
 * До привязки чата отправить за менеджера нельзя: Bot API не резолвит
 * @ник в chat_id и не разрешает писать первым. Поэтому там кнопка не
 * отправляет, а кладёт текст в буфер обмена.
 *
 * @param array $data Данные заказа.
 * @return array Ряды кнопок.
 */
function ptg_keyboard_greeting_rows( $data ) {
    if ( 'service' === $data['type'] || ! ptg_keyboard_early_stage( $data ) ) {
        return array();
    }

    // Приветствие — разовое действие в начале работы по заказу. Как
    // только по этому заказу ушло хоть одно наше сообщение (кнопкой или
    // руками — ручную отправку видно по эху из Business-чата), кнопка
    // больше не нужна и только мешает.
    if ( ! empty( $data['greeting']['sent'] ) || ! empty( $data['client']['outgoing'] ) ) {
        return array();
    }

    if ( ! empty( $data['client']['linked'] ) ) {
        // Сюда попадает ровно тот случай, ради которого кнопка и нужна:
        // новый заказ от клиента, с которым уже работали, — чат привязан
        // с прошлого раза, писать ещё не начинали.
        return array( array( array(
            'text'          => '👋 Отправить приветствие',
            'callback_data' => ptg_cb( $data['id'], 'hi' ),
        ) ) );
    }

    $row  = array();
    $text = (string) ( $data['greeting']['text'] ?? '' );

    if ( '' !== $text ) {
        $row[] = array(
            'text'      => '📋 Скопировать приветствие',
            'copy_text' => array( 'text' => $text ),
        );
    }

    if ( ! empty( $data['client']['checkout'] ) ) {
        $row[] = array(
            'text' => '✍️ Открыть личку',
            'url'  => 'https://t.me/' . $data['client']['checkout'],
        );
    }

    return $row ? array( $row ) : array();
}

/**
 * Кнопки перехода по статусам.
 *
 * Показываем только следующий шаг и возврат назад. Контекстность здесь
 * не украшение: при шести статусах полный список кнопок занимает пол-
 * экрана и в него начинают промахиваться.
 *
 * @param array $data Данные заказа.
 * @return array Ряды кнопок.
 */
function ptg_keyboard_status_rows( $data ) {
    $st    = $data['status_info'];
    $defs  = ptg_statuses();
    $row   = array();

    foreach ( (array) $st['next'] as $next ) {
        if ( ! isset( $defs[ $next ] ) ) {
            continue;
        }
        $row[] = array(
            'text'          => $defs[ $next ]['emoji'] . ' ' . $defs[ $next ]['label'],
            'callback_data' => ptg_cb( $data['id'], 'st', array( $defs[ $next ]['code'] ) ),
        );
    }

    // Шаг назад нужен и сам по себе (ошиблись кнопкой), и как
    // единственный способ снять блокировку параметров.
    $prev = ! empty( $st['back'] ) ? $st['back'] : ptg_status_prev( $st['key'] );
    if ( $prev && empty( $st['negative'] ) && ptg_is_negative_status( $prev ) ) {
        $prev = ''; // из линейного статуса назад в негативный не ходим
    }
    if ( $prev ) {
        $label = ( 'processing' === $prev ) ? '🆕 Получен' : $defs[ $prev ]['emoji'] . ' ' . $defs[ $prev ]['label'];
        $code  = ( 'processing' === $prev ) ? 'new' : $defs[ $prev ]['code'];
        $row[] = array(
            'text'          => '↩️ ' . $label,
            'callback_data' => ptg_cb( $data['id'], 'st', array( $code ) ),
        );
    }

    return $row ? array( $row ) : array();
}

/**
 * Предыдущий статус в цепочке.
 *
 * @param string $key Текущий статус.
 * @return string Ключ или ''.
 */
function ptg_status_prev( $key ) {
    $keys = array_keys( ptg_status_chain() );
    $i    = array_search( $key, $keys, true );
    if ( false === $i || 0 === $i ) {
        return '';
    }
    return $keys[ $i - 1 ];
}

/**
 * Клавиатура подтверждения отправки в печать.
 *
 * @param array $data Данные заказа.
 * @return array
 */
function ptg_keyboard_confirm_print( $data ) {
    return array( 'inline_keyboard' => array(
        array( array(
            'text'          => '✅ Сверил, в печать',
            'callback_data' => ptg_cb( $data['id'], 'st', array( 'prc' ) ),
        ) ),
        array( array(
            'text'          => '‹ Отмена',
            'callback_data' => ptg_cb( $data['id'], 'ovx' ),
        ) ),
    ) );
}

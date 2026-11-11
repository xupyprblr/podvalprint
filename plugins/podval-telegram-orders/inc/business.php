<?php
/**
 * Фаза 2. Клиент, переписка, Business-подключение.
 *
 * Клиент общается с менеджером в личке, а не с ботом. Обычный бот личные
 * чаты не видит вообще — Bot API не имеет доступа к диалогам
 * пользователя. Userbot на MTProto технически смог бы, но это прямой
 * риск бана аккаунта, с которого идёт вся коммуникация, поэтому путь
 * один: Telegram Business. Менеджер подключает бота в настройках своего
 * аккаунта, и бот начинает получать `business_message` по чатам этого
 * аккаунта.
 *
 * Отсюда главное архитектурное следствие: привязка клиента к заказу
 * создаётся в момент контакта, а не ищется задним числом.
 *
 * Приватность. Через подключение приходят ВСЕ чаты аккаунта, включая
 * личные. Поэтому в заказ пишется только переписка чата, явно
 * привязанного к заказу; всё остальное живёт максимум 30 минут в
 * транзиенте и нужно лишь для того, чтобы при `/link` подтянуть начало
 * разговора.
 *
 * @package podval-telegram-orders
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Меты привязки клиента. */
const PTG_META_CLIENT_TG       = '_ptg_client_tg_id';
const PTG_META_CLIENT_CHAT     = '_ptg_client_chat_id';
const PTG_META_CLIENT_USERNAME = '_ptg_client_username';
const PTG_META_CLIENT_NAME     = '_ptg_client_name';
const PTG_META_CLIENT_SOURCE   = '_ptg_client_link_source';
const PTG_META_CONNECTION      = '_ptg_business_connection_id';
const PTG_META_CHAT_LOG        = '_ptg_chat_log';
const PTG_OPT_UNLINKED         = 'ptg_unlinked_chats';      // 2.12.0: чаты без заказа
const PTG_OPT_UNLINKED_HIDDEN  = 'ptg_unlinked_hidden';     // 2.16.6: скрытые вручную
const PTG_META_CHAT_LAST_AT    = '_ptg_chat_last_at';   // 2.8.0: последняя активность в чате
const PTG_META_OUT_MEDIA_AT    = '_ptg_out_media_at';   // 2.8.0: мы отправили фото/файл

/** Сколько сообщений храним в заказе и сколько символов от каждого. */
const PTG_CHAT_LOG_MAX  = 60;
const PTG_CHAT_TEXT_MAX = 400;

/** Сколько живёт буфер непривязанного чата. */
const PTG_BUFFER_TTL = 1800;

/* ═══════════════════════════════════════════
   Реестр подключений
   ═══════════════════════════════════════════ */

/**
 * Все Business-подключения.
 *
 * @return array connection_id => данные.
 */
function ptg_business_connections() {
    $list = get_option( 'ptg_business_connections', array() );
    return is_array( $list ) ? $list : array();
}

/**
 * Перечитывает подключение у Telegram (2.16.5).
 *
 * getBusinessConnection отдаёт то же, что приходит апдейтом: владельца,
 * права, включено ли. Спрашиваем не чаще раза в 5 минут на подключение,
 * чтобы не долбить API на каждом сообщении.
 *
 * @param string $conn_id Подключение.
 * @return bool Обновилось ли.
 */
function ptg_refresh_connection( $conn_id ) {
    if ( '' === $conn_id ) {
        return false;
    }
    $key = 'ptg_conn_refresh_' . md5( $conn_id );
    if ( get_transient( $key ) ) {
        return false;
    }
    set_transient( $key, 1, 5 * MINUTE_IN_SECONDS );

    $res = ptg_api( 'getBusinessConnection', array( 'business_connection_id' => $conn_id ) );
    if ( is_wp_error( $res ) || empty( $res['id'] ) ) {
        ptg_log( 'warn', 'Не удалось перечитать Business-подключение', array(
            'conn'  => $conn_id,
            'error' => is_wp_error( $res ) ? $res->get_error_message() : 'пустой ответ',
        ) );
        return false;
    }

    ptg_store_business_connection( (array) $res );
    ptg_log( 'info', 'Business-подключение перечитано', array(
        'conn'  => $conn_id,
        'owner' => (int) ( $res['user']['id'] ?? 0 ),
    ) );
    return true;
}

/**
 * Сохраняет/обновляет подключение.
 *
 * @param array $conn Объект business_connection.
 * @return void
 */
function ptg_store_business_connection( $conn ) {
    $id = (string) ( $conn['id'] ?? '' );
    if ( '' === $id ) {
        return;
    }

    $list = ptg_business_connections();

    // can_reply объявлен устаревшим с Bot API 9.0 — права теперь в
    // объекте rights. Читаем оба, чтобы работать и со старым слоем.
    $rights = (array) ( $conn['rights'] ?? array() );
    if ( empty( $rights ) && isset( $conn['can_reply'] ) ) {
        $rights = array( 'can_reply' => (bool) $conn['can_reply'] );
    }

    $list[ $id ] = array(
        'user_id'      => (int) ( $conn['user']['id'] ?? 0 ),
        'user_chat_id' => (int) ( $conn['user_chat_id'] ?? 0 ),
        'name'         => ptg_user_display_name( (array) ( $conn['user'] ?? array() ) ),
        'username'     => (string) ( $conn['user']['username'] ?? '' ),
        'is_enabled'   => ! empty( $conn['is_enabled'] ),
        'rights'       => $rights,
        'updated'      => current_time( 'mysql' ),
    );

    update_option( 'ptg_business_connections', $list, false );
}

/**
 * Владелец подключения — Telegram user_id менеджера.
 *
 * @param string $conn_id ID подключения.
 * @return int
 */
function ptg_connection_owner( $conn_id ) {
    $list = ptg_business_connections();
    return (int) ( $list[ (string) $conn_id ]['user_id'] ?? 0 );
}

/**
 * Есть ли у подключения право удалять сообщения бота.
 *
 * @param string $conn_id ID подключения.
 * @return bool
 */
function ptg_connection_can_delete( $conn_id ) {
    $list   = ptg_business_connections();
    $rights = (array) ( $list[ (string) $conn_id ]['rights'] ?? array() );
    return ! empty( $rights['can_delete_sent_messages'] ) || ! empty( $rights['can_delete_all_messages'] );
}

/**
 * Апдейт business_connection: подключили, отключили, поменяли права.
 *
 * @param array $conn Объект.
 * @return void
 */
function ptg_handle_business_connection( $conn ) {
    ptg_store_business_connection( $conn );

    $enabled = ! empty( $conn['is_enabled'] );
    ptg_log( $enabled ? 'info' : 'warn', $enabled ? 'Business-подключение активно' : 'Business-подключение выключено', array(
        'conn' => (string) ( $conn['id'] ?? '' ),
        'user' => (int) ( $conn['user']['id'] ?? 0 ),
    ) );
}

/* ═══════════════════════════════════════════
   Индекс «чат → заказ»
   ═══════════════════════════════════════════ */

/**
 * Ключ индекса.
 *
 * @param string $conn_id Подключение.
 * @param int    $chat_id Чат.
 * @return string
 */
function ptg_chat_key( $conn_id, $chat_id ) {
    return (string) $conn_id . '|' . (int) $chat_id;
}

/**
 * Заказ, привязанный к чату.
 *
 * Отдельный индекс в опции вместо перебора заказов: на каждое входящее
 * сообщение сканировать таблицу заказов нельзя.
 *
 * @param string $conn_id Подключение.
 * @param int    $chat_id Чат.
 * @return WC_Order|null
 */
function ptg_order_by_chat( $conn_id, $chat_id ) {
    $index    = get_option( 'ptg_chat_index', array() );
    $order_id = (int) ( $index[ ptg_chat_key( $conn_id, $chat_id ) ] ?? 0 );
    if ( ! $order_id ) {
        return null;
    }
    $order = wc_get_order( $order_id );
    return $order ? $order : null;
}

/**
 * Записывает соответствие чата и заказа.
 *
 * @param string $conn_id  Подключение.
 * @param int    $chat_id  Чат.
 * @param int    $order_id Заказ.
 * @return void
 */
function ptg_index_chat( $conn_id, $chat_id, $order_id ) {
    $index = get_option( 'ptg_chat_index', array() );
    if ( ! is_array( $index ) ) {
        $index = array();
    }
    $index[ ptg_chat_key( $conn_id, $chat_id ) ] = (int) $order_id;

    // Индекс не должен расти бесконечно.
    if ( count( $index ) > 2000 ) {
        $index = array_slice( $index, -2000, null, true );
    }

    update_option( 'ptg_chat_index', $index, false );
}

/* ═══════════════════════════════════════════
   Привязка клиента
   ═══════════════════════════════════════════ */

/**
 * Нормализует значение мессенджера для сравнения.
 *
 * Поле `Telegram` на чекауте обязательное, но `podval_normalize_messenger()`
 * пропускает телефоны: если строка начинается с цифры или `+`, `@` не
 * добавляется. Поэтому матч по нику срабатывает не всегда, и ручная
 * привязка командой — не запасной вариант, а полноценный второй путь.
 *
 * @param string $value Значение.
 * @return string Ник в нижнем регистре без @, или ''.
 */
function ptg_normalize_username( $value ) {
    $value = trim( (string) $value );
    if ( '' === $value ) {
        return '';
    }
    $value = ltrim( $value, '@' );
    $value = preg_replace( '#^https?://t\.me/#i', '', $value );

    // Телефон — не ник.
    if ( preg_match( '/^[\d\+\-\s\(\)]+$/', $value ) ) {
        return '';
    }

    return mb_strtolower( trim( $value ), 'UTF-8' );
}

/**
 * Ссылка на чат по номеру телефона (2.14.0).
 *
 * Бот не умеет находить аккаунт по номеру — такого метода в Bot API нет.
 * Зато сам Telegram умеет: ссылка t.me/+<цифры> открывает переписку с
 * владельцем номера, если он не скрыт настройками приватности. Дальше
 * привязка обычная — командой /link в этом чате.
 *
 * @param string $phone Телефон в любом виде.
 * @return string '' если номер не похож на телефон.
 */
function ptg_phone_chat_link( $phone ) {
    $digits = preg_replace( '/\D+/', '', (string) $phone );
    $len    = strlen( $digits );
    if ( $len < 9 || $len > 15 ) {
        return '';
    }
    return 'https://t.me/+' . $digits;
}

/**
 * Заказы-кандидаты на привязку по нику клиента.
 *
 * @param string $username Ник без @.
 * @return array Список WC_Order.
 */
function ptg_find_orders_by_username( $username ) {
    $username = ptg_normalize_username( $username );
    if ( '' === $username ) {
        return array();
    }

    $orders = wc_get_orders( array(
        'limit'        => 100,
        'orderby'      => 'date',
        'order'        => 'DESC',
        'status'       => array( 'pending', 'processing', 'on-hold' ),
        'date_created' => '>' . ( time() - 60 * DAY_IN_SECONDS ),
    ) );

    $out = array();
    foreach ( (array) $orders as $order ) {
        if ( ! $order instanceof WC_Order ) {
            continue;
        }
        if ( $order->get_meta( PTG_META_CLIENT_CHAT ) ) {
            continue; // Уже привязан.
        }

        $messenger = $order->get_meta( '_billing_messenger' );
        if ( ! $messenger ) {
            $messenger = $order->get_meta( 'billing_messenger' );
        }
        if ( ! $messenger ) {
            $messenger = $order->get_meta( '_billing_telegram' );
        }

        if ( ptg_normalize_username( $messenger ) === $username ) {
            $out[] = $order;
        }
    }

    return $out;
}

/**
 * Подтягивает привязку с прошлых заказов того же клиента.
 *
 * Привязка создаётся в момент контакта — но у постоянного клиента
 * контакт уже был. Без этого каждый следующий заказ висел бы
 * непривязанным до тех пор, пока клиент не напишет снова, хотя чат
 * известен и переписка идёт.
 *
 * @param WC_Order $order Заказ.
 * @return bool Привязка перенесена.
 */
function ptg_inherit_client_link( $order ) {
    if ( $order->get_meta( PTG_META_CLIENT_CHAT ) ) {
        return false;
    }

    $messenger = $order->get_meta( '_billing_messenger' );
    if ( ! $messenger ) {
        $messenger = $order->get_meta( 'billing_messenger' );
    }
    if ( ! $messenger ) {
        $messenger = $order->get_meta( '_billing_telegram' );
    }

    $username = ptg_normalize_username( $messenger );
    if ( '' === $username ) {
        return false;
    }

    // Промах кэшируется: иначе каждый синк непривязанного заказа
    // перебирает сотню заказов заново.
    $miss_key = 'ptg_inherit_' . md5( $username );
    if ( get_transient( $miss_key ) ) {
        return false;
    }

    $orders = wc_get_orders( array(
        'limit'   => 100,
        'orderby' => 'date',
        'order'   => 'DESC',
    ) );

    $source = null;
    foreach ( (array) $orders as $prev ) {
        if ( ! $prev instanceof WC_Order || $prev->get_id() === $order->get_id() ) {
            continue;
        }
        if ( ! $prev->get_meta( PTG_META_CLIENT_CHAT ) ) {
            continue;
        }

        $prev_name = ptg_normalize_username( $prev->get_meta( PTG_META_CLIENT_USERNAME ) );
        if ( $prev_name && $prev_name === $username ) {
            $source = $prev;
            break;
        }
    }

    if ( ! $source ) {
        set_transient( $miss_key, 1, HOUR_IN_SECONDS );
        return false;
    }

    $chat_id = (int) $source->get_meta( PTG_META_CLIENT_CHAT );
    $conn_id = (string) $source->get_meta( PTG_META_CONNECTION );

    $order->update_meta_data( PTG_META_CLIENT_CHAT, $chat_id );
    $order->update_meta_data( PTG_META_CLIENT_TG, (int) $source->get_meta( PTG_META_CLIENT_TG ) );
    $order->update_meta_data( PTG_META_CLIENT_USERNAME, (string) $source->get_meta( PTG_META_CLIENT_USERNAME ) );
    $order->update_meta_data( PTG_META_CLIENT_NAME, (string) $source->get_meta( PTG_META_CLIENT_NAME ) );
    $order->update_meta_data( PTG_META_CLIENT_SOURCE, 'inherited' );
    $order->update_meta_data( PTG_META_CONNECTION, $conn_id );
    $order->save();

    // Индекс ведёт на самый свежий заказ клиента: новые сообщения
    // относятся к нему, а не к закрытому прошлому.
    ptg_index_chat( $conn_id, $chat_id, $order->get_id() );
    ptg_forget_unlinked( $conn_id, $chat_id );

    ptg_add_note( $order, sprintf(
        'Чат клиента перенесён с заказа #%s (постоянный клиент)',
        $source->get_order_number()
    ) );

    ptg_log( 'info', 'Привязка унаследована', array(
        'order' => $order->get_id(),
        'from'  => $source->get_id(),
        'chat'  => $chat_id,
    ) );

    return true;
}

/**
 * Привязывает чат к заказу.
 *
 * @param WC_Order $order   Заказ.
 * @param string   $conn_id Подключение.
 * @param array    $chat    Объект chat.
 * @param string   $source  auto-username|manual.
 * @return void
 */
function ptg_link_client( $order, $conn_id, $chat, $source = 'manual' ) {
    $chat_id  = (int) ( $chat['id'] ?? 0 );
    $username = (string) ( $chat['username'] ?? '' );
    $name     = trim( ( $chat['first_name'] ?? '' ) . ' ' . ( $chat['last_name'] ?? '' ) );

    $order->update_meta_data( PTG_META_CLIENT_CHAT, $chat_id );
    $order->update_meta_data( PTG_META_CLIENT_TG, $chat_id ); // В привате chat.id == user.id.
    $order->update_meta_data( PTG_META_CLIENT_USERNAME, $username );
    $order->update_meta_data( PTG_META_CLIENT_NAME, $name );
    $order->update_meta_data( PTG_META_CLIENT_SOURCE, $source );
    $order->update_meta_data( PTG_META_CONNECTION, (string) $conn_id );
    $order->save();

    ptg_index_chat( $conn_id, $chat_id, $order->get_id() );
    ptg_forget_unlinked( $conn_id, $chat_id );

    $uname = ptg_normalize_username( $username );
    if ( '' !== $uname ) {
        delete_transient( 'ptg_inherit_' . md5( $uname ) );
    }

    ptg_add_note( $order, sprintf(
        'Чат клиента привязан: %s (%s)',
        $username ? '@' . $username : ( $name ? $name : 'ID ' . $chat_id ),
        'manual' === $source ? 'вручную' : 'по нику с чекаута'
    ) );

    // Всё, что успело накопиться в буфере до привязки, переносим в заказ:
    // иначе начало разговора — обычно самое важное — потеряется.
    foreach ( ptg_take_buffer( $conn_id, $chat_id ) as $entry ) {
        ptg_chat_log_append( $order, $entry, false );
    }
    $order->save();

    ptg_log( 'info', 'Клиент привязан', array(
        'order'  => $order->get_id(),
        'chat'   => $chat_id,
        'source' => $source,
    ) );
}

/* ═══════════════════════════════════════════
   Лог переписки
   ═══════════════════════════════════════════ */

/**
 * Добавляет сообщение в лог заказа.
 *
 * @param WC_Order $order Заказ.
 * @param array    $entry Запись.
 * @param bool     $save  Сохранить заказ сразу.
 * @return void
 */
function ptg_chat_log_append( $order, $entry, $save = true ) {
    $log = $order->get_meta( PTG_META_CHAT_LOG );
    if ( ! is_array( $log ) ) {
        $log = array();
    }

    $log[] = $entry;
    if ( count( $log ) > PTG_CHAT_LOG_MAX ) {
        $log = array_slice( $log, - PTG_CHAT_LOG_MAX );
    }

    $order->update_meta_data( PTG_META_CHAT_LOG, $log );

    // Для блока «Сейчас в переписке» в Mini App (2.8.0): искать по
    // времени внутри массива лога нельзя, нужна отдельная мета.
    $t = (int) ( $entry['t'] ?? time() );
    if ( $t >= (int) $order->get_meta( PTG_META_CHAT_LAST_AT ) ) {
        $order->update_meta_data( PTG_META_CHAT_LAST_AT, $t );
    }
    if ( 'out' === ( $entry['d'] ?? '' ) && in_array( (string) ( $entry['f'] ?? '' ), array( 'фото', 'файл' ), true ) ) {
        $order->update_meta_data( PTG_META_OUT_MEDIA_AT, $t );
    }

    if ( $save ) {
        $order->save();
    }
}

/**
 * Собирает запись лога из сообщения.
 *
 * @param array $msg      Объект message.
 * @param int   $owner_id Telegram user_id менеджера.
 * @return array
 */
function ptg_chat_entry( $msg, $owner_id ) {
    $from_id = (int) ( $msg['from']['id'] ?? 0 );

    $media = '';
    foreach ( array(
        'photo'      => 'фото',
        'document'   => 'файл',
        'voice'      => 'голосовое',
        'video'      => 'видео',
        'video_note' => 'кружок',
        'sticker'    => 'стикер',
        'audio'      => 'аудио',
    ) as $key => $label ) {
        if ( isset( $msg[ $key ] ) ) {
            $media = $label;
            break;
        }
    }

    $text = (string) ( $msg['text'] ?? ( $msg['caption'] ?? '' ) );

    return array(
        't' => (int) ( $msg['date'] ?? time() ),
        'd' => ( $from_id === (int) $owner_id ) ? 'out' : 'in',
        'n' => ptg_user_display_name( (array) ( $msg['from'] ?? array() ) ),
        'x' => mb_substr( $text, 0, PTG_CHAT_TEXT_MAX ),
        'm' => (int) ( $msg['message_id'] ?? 0 ),
        'f' => $media,
    );
}

/* ═══════════════════════════════════════════
   Буфер непривязанных чатов
   ═══════════════════════════════════════════ */

/**
 * Запоминает чат, у которого ещё нет заказа: из такого списка в
 * приложении оформляют заказ тем, кто пришёл сразу в Telegram (2.12.0).
 *
 * Буфер сообщений живёт 30 минут и лежит в транзиентах — для списка
 * «кому оформить заказ» нужен отдельный, более долгий реестр.
 *
 * @param string $conn_id Подключение.
 * @param array  $chat    Объект chat.
 * @param array  $entry   Запись лога.
 * @return void
 */
function ptg_remember_unlinked( $conn_id, $chat, $entry ) {
    $chat_id = (int) ( $chat['id'] ?? 0 );
    if ( ! $chat_id || ! get_option( 'ptg_remember_chats', 1 ) ) {
        return;
    }
    // Личные чаты, которые оператор убрал крестиком, больше не
    // возвращаются в список: бот видит всю личку, включая друзей и
    // родственников, и каждый их ответ иначе всплывал бы снова.
    $hidden = (array) get_option( PTG_OPT_UNLINKED_HIDDEN, array() );
    if ( in_array( ptg_chat_key( $conn_id, $chat_id ), $hidden, true ) ) {
        return;
    }
    $list = get_option( PTG_OPT_UNLINKED, array() );
    $list = is_array( $list ) ? $list : array();
    $key  = ptg_chat_key( $conn_id, $chat_id );

    $list[ $key ] = array(
        'conn'     => (string) $conn_id,
        'chat_id'  => $chat_id,
        'username' => (string) ( $chat['username'] ?? '' ),
        'name'     => trim( ( $chat['first_name'] ?? '' ) . ' ' . ( $chat['last_name'] ?? '' ) ),
        't'        => (int) ( $entry['t'] ?? time() ),
        'last'     => mb_substr( (string) ( $entry['x'] ?? '' ), 0, 80 ),
        'med'      => (string) ( $entry['f'] ?? '' ),
        'dir'      => (string) ( $entry['d'] ?? 'in' ),
    );

    uasort( $list, function ( $a, $b ) {
        return (int) $b['t'] <=> (int) $a['t'];
    } );
    update_option( PTG_OPT_UNLINKED, array_slice( $list, 0, 30, true ), false );
}

/**
 * Прячет чат из списка «кому оформить заказ» навсегда.
 *
 * @param string $key Ключ чата.
 * @return void
 */
function ptg_hide_unlinked( $key ) {
    $hidden = (array) get_option( PTG_OPT_UNLINKED_HIDDEN, array() );
    if ( ! in_array( $key, $hidden, true ) ) {
        $hidden[] = $key;
        update_option( PTG_OPT_UNLINKED_HIDDEN, array_slice( $hidden, -200 ), false );
    }
    $list = get_option( PTG_OPT_UNLINKED, array() );
    if ( is_array( $list ) && isset( $list[ $key ] ) ) {
        unset( $list[ $key ] );
        update_option( PTG_OPT_UNLINKED, $list, false );
    }
}

/**
 * Убирает чат из реестра непривязанных.
 *
 * @param string $conn_id Подключение.
 * @param int    $chat_id Чат.
 * @return void
 */
function ptg_forget_unlinked( $conn_id, $chat_id ) {
    $list = get_option( PTG_OPT_UNLINKED, array() );
    if ( ! is_array( $list ) ) {
        return;
    }
    $key = ptg_chat_key( $conn_id, (int) $chat_id );
    if ( isset( $list[ $key ] ) ) {
        unset( $list[ $key ] );
        update_option( PTG_OPT_UNLINKED, $list, false );
    }
}

/**
 * Чаты без заказа: свежие сверху, уже привязанные отсеиваются.
 *
 * @param int $max_age Максимальный возраст, сек.
 * @return array[]
 */
function ptg_unlinked_chats( $max_age = 604800 ) {
    $list = get_option( PTG_OPT_UNLINKED, array() );
    $list = is_array( $list ) ? $list : array();
    $out  = array();
    $now  = time();
    foreach ( $list as $key => $c ) {
        if ( $now - (int) $c['t'] > $max_age ) {
            continue;
        }
        if ( ptg_order_by_chat( (string) $c['conn'], (int) $c['chat_id'] ) ) {
            continue; // уже привязан к заказу
        }
        $c['key'] = (string) $key;
        $out[]    = $c;
    }
    return $out;
}

/**
 * Кладёт сообщение в короткоживущий буфер.
 *
 * @param string $conn_id Подключение.
 * @param int    $chat_id Чат.
 * @param array  $entry   Запись.
 * @return void
 */
function ptg_buffer_message( $conn_id, $chat_id, $entry ) {
    $key  = 'ptg_buf_' . md5( ptg_chat_key( $conn_id, $chat_id ) );
    $list = get_transient( $key );
    if ( ! is_array( $list ) ) {
        $list = array();
    }
    $list[] = $entry;
    if ( count( $list ) > 20 ) {
        $list = array_slice( $list, -20 );
    }
    set_transient( $key, $list, PTG_BUFFER_TTL );
}

/**
 * Забирает и очищает буфер.
 *
 * @param string $conn_id Подключение.
 * @param int    $chat_id Чат.
 * @return array
 */
function ptg_take_buffer( $conn_id, $chat_id ) {
    $key  = 'ptg_buf_' . md5( ptg_chat_key( $conn_id, $chat_id ) );
    $list = get_transient( $key );
    delete_transient( $key );
    return is_array( $list ) ? $list : array();
}

/* ═══════════════════════════════════════════
   Обработка business_message
   ═══════════════════════════════════════════ */

/**
 * Входящее/исходящее сообщение в чате подключённого аккаунта.
 *
 * @param array $msg    Объект message.
 * @param bool  $edited Это правка ранее отправленного.
 * @return void
 */
function ptg_handle_business_message( $msg, $edited = false ) {
    $conn_id = (string) ( $msg['business_connection_id'] ?? '' );
    if ( '' === $conn_id ) {
        return;
    }

    $chat = (array) ( $msg['chat'] ?? array() );
    if ( 'private' !== ( $chat['type'] ?? 'private' ) ) {
        return; // Групповые чаты аккаунта нас не касаются.
    }

    $chat_id = (int) ( $chat['id'] ?? 0 );
    $from_id = (int) ( $msg['from']['id'] ?? 0 );

    // Данные подключения приходят апдейтом business_connection. Если в
    // этот момент вебхук стоял на другом сайте (или строка устарела),
    // сайт про подключение ничего не знает: владелец неизвестен, команды
    // от менеджера считаются чужими и молча игнорируются, а ответить
    // клиенту нельзя. Поэтому спрашиваем Telegram напрямую.
    $owner_id = ptg_connection_owner( $conn_id );
    if ( ! $owner_id || ! ptg_connection_can_reply( $conn_id ) ) {
        ptg_refresh_connection( $conn_id );
        $owner_id = ptg_connection_owner( $conn_id );
    }

    // Последняя страховка: сообщение от того, кто и так управляет
    // заказами, считаем нашим исходящим, даже если подключение ещё не
    // доехало.
    $outgoing = ( $owner_id > 0 && $from_id === $owner_id )
        || ( ! $owner_id && $from_id && ptg_user_can_manage( $from_id ) );
    $text     = trim( (string) ( $msg['text'] ?? '' ) );

    // Команды принимаются только от менеджера и только в его чатах.
    if ( $outgoing && 0 === strpos( $text, '/' ) ) {
        if ( ptg_handle_business_command( $text, $msg, $conn_id, $chat, $from_id ) ) {
            return;
        }
    }

    $entry = ptg_chat_entry( $msg, $owner_id );
    $order = ptg_order_by_chat( $conn_id, $chat_id );

    if ( ! $order ) {
        // Пробуем узнать клиента по нику с чекаута.
        $candidates = ptg_find_orders_by_username( (string) ( $chat['username'] ?? '' ) );

        if ( 1 === count( $candidates ) ) {
            $order = $candidates[0];
            ptg_link_client( $order, $conn_id, $chat, 'auto-username' );
            ptg_notify_group( $order, sprintf(
                '🔗 Чат клиента %s привязан автоматически по нику с чекаута',
                ptg_esc( $chat['username'] ? '@' . $chat['username'] : ( 'ID ' . $chat_id ) )
            ) );
        } elseif ( count( $candidates ) > 1 ) {
            // Несколько открытых заказов на один ник — угадывать нельзя.
            ptg_buffer_message( $conn_id, $chat_id, $entry );
            ptg_remember_unlinked( $conn_id, $chat, $entry );
            ptg_prompt_ambiguous( $candidates, $chat );
            return;
        } else {
            // Чужой чат или заказ ещё не оформлен: в заказ ничего не
            // пишем, держим 30 минут в буфере на случай /link.
            ptg_buffer_message( $conn_id, $chat_id, $entry );
            ptg_remember_unlinked( $conn_id, $chat, $entry );
            return;
        }
    }

    if ( $edited ) {
        ptg_chat_log_mark( $order, (int) $entry['m'], 'edit', $entry['x'] );
    } elseif ( ptg_chat_log_has( $order, (int) $entry['m'] ) ) {
        // Отправленное ботом от имени аккаунта возвращается обратно
        // отдельным business_message. Без этой проверки собственное
        // приветствие попадает в лог дважды, разными именами.
        return;
    } else {
        $file = ptg_maybe_ingest( $msg, $order, $entry['d'] );
        if ( $file ) {
            $entry['a'] = (int) $file['id'];
            $entry['u'] = (string) $file['url'];
        }
        ptg_chat_log_append( $order, $entry );
    }

    // Менеджер написал клиенту — значит он этим заказом и занимается.
    if ( $outgoing ) {
        ptg_maybe_assign( $order, $from_id, (array) ( $msg['from'] ?? array() ) );
    } elseif ( ! $edited ) {
        ptg_maybe_auto_client_replied( $order );
    }

    ptg_sync_order_message( $order, 'chat' );
}

/**
 * Есть ли уже такое сообщение в логе.
 *
 * @param WC_Order $order      Заказ.
 * @param int      $message_id ID сообщения.
 * @return bool
 */
function ptg_chat_log_has( $order, $message_id ) {
    if ( ! $message_id ) {
        return false;
    }
    $log = $order->get_meta( PTG_META_CHAT_LOG );
    if ( ! is_array( $log ) ) {
        return false;
    }
    foreach ( $log as $entry ) {
        if ( (int) ( $entry['m'] ?? 0 ) === (int) $message_id ) {
            return true;
        }
    }
    return false;
}

/**
 * Автоперевод в «Клиент ответил».
 *
 * Смысл статуса — «мы написали, клиент откликнулся», поэтому нужны оба
 * условия: входящее сообщение и хотя бы одно наше до него. Если клиент
 * написал первым, заказ остаётся «Получен» — откликаться пока не на что.
 *
 * Двигаем только с «Получен» и только вперёд: заказ, уехавший в эскиз
 * или печать, новое сообщение клиента откатывать не должно.
 *
 * @param WC_Order $order Заказ.
 * @return bool Статус изменён.
 */
function ptg_maybe_auto_client_replied( $order ) {
    if ( ! get_option( 'ptg_auto_client_replied', 1 ) ) {
        return false;
    }
    if ( 'processing' !== $order->get_status() ) {
        return false;
    }

    $log = $order->get_meta( PTG_META_CHAT_LOG );
    if ( ! is_array( $log ) ) {
        return false;
    }

    $we_wrote = false;
    foreach ( $log as $entry ) {
        if ( 'out' === ( $entry['d'] ?? '' ) && empty( $entry['del'] ) ) {
            $we_wrote = true;
            break;
        }
    }
    if ( ! $we_wrote ) {
        return false;
    }

    $order->update_status(
        'client-replied',
        'Статус изменён автоматически: клиент ответил в Telegram. ',
        false
    );

    ptg_log( 'info', 'Автоперевод в «Клиент ответил»', array( 'order' => $order->get_id() ) );

    return true;
}

/**
 * Помечает запись лога как изменённую или удалённую.
 *
 * @param WC_Order $order      Заказ.
 * @param int      $message_id ID сообщения.
 * @param string   $what       edit|delete.
 * @param string   $new_text   Новый текст (для edit).
 * @return void
 */
function ptg_chat_log_mark( $order, $message_id, $what, $new_text = '' ) {
    $log = $order->get_meta( PTG_META_CHAT_LOG );
    if ( ! is_array( $log ) ) {
        return;
    }

    foreach ( $log as $i => $entry ) {
        if ( (int) ( $entry['m'] ?? 0 ) !== (int) $message_id ) {
            continue;
        }
        if ( 'delete' === $what ) {
            $log[ $i ]['del'] = true;
        } else {
            $log[ $i ]['x']   = $new_text;
            $log[ $i ]['ed']  = true;
        }
    }

    $order->update_meta_data( PTG_META_CHAT_LOG, $log );
    $order->save();
}

/**
 * Удаление сообщений в чате аккаунта.
 *
 * @param array $payload Объект business_messages_deleted.
 * @return void
 */
function ptg_handle_business_deleted( $payload ) {
    $conn_id = (string) ( $payload['business_connection_id'] ?? '' );
    $chat_id = (int) ( $payload['chat']['id'] ?? 0 );
    $order   = ptg_order_by_chat( $conn_id, $chat_id );
    if ( ! $order ) {
        return;
    }

    foreach ( (array) ( $payload['message_ids'] ?? array() ) as $mid ) {
        ptg_chat_log_mark( $order, (int) $mid, 'delete' );
    }
    ptg_sync_order_message( $order, 'chat-deleted' );
}

/* ═══════════════════════════════════════════
   Команды в чате клиента
   ═══════════════════════════════════════════ */

/**
 * Команды, которые менеджер набирает прямо в переписке с клиентом.
 *
 * Отвечать в этом чате нельзя: любое сообщение бота через подключение
 * выглядит как сообщение менеджера, и клиент его увидит. Поэтому команда
 * удаляется, а результат уходит в группу заказов реплаем на карточку.
 *
 * @param string $text     Текст сообщения.
 * @param array  $msg      Сообщение.
 * @param string $conn_id  Подключение.
 * @param array  $chat     Чат.
 * @param int    $from_id  Автор.
 * @return bool Команда распознана.
 */
function ptg_handle_business_command( $text, $msg, $conn_id, $chat, $from_id ) {
    $parts = preg_split( '/\s+/', $text );
    $cmd   = strtolower( ltrim( (string) $parts[0], '/' ) );
    $cmd   = preg_replace( '/@.*$/', '', $cmd ); // /link@имя_бота

    if ( ! in_array( $cmd, array( 'link', 'order', 'unlink' ), true ) ) {
        return false;
    }

    if ( ! ptg_user_can_manage( $from_id ) ) {
        // Молча игнорируем, но пишем в журнал: со стороны это выглядит
        // как «команда не работает», и без записи не понять, почему.
        ptg_log( 'warn', 'Команда в Business-чате от неизвестного менеджера', array(
            'cmd' => $cmd,
            'tg'  => (int) $from_id,
        ) );
        return true;
    }

    ptg_log( 'info', 'Команда в Business-чате', array(
        'cmd'  => $cmd,
        'tg'   => (int) $from_id,
        'chat' => (int) ( $chat['id'] ?? 0 ),
    ) );

    $chat_id = (int) ( $chat['id'] ?? 0 );
    ptg_delete_business_message( $conn_id, (int) ( $msg['message_id'] ?? 0 ) );

    if ( 'link' === $cmd ) {
        $order_id = (int) ( $parts[1] ?? 0 );
        $order    = $order_id ? ptg_order_by_number( $order_id ) : null;

        if ( ! $order ) {
            ptg_notify_group_plain( sprintf(
                '🔗 Не нашёл заказ #%s для привязки чата %s',
                ptg_esc( (string) $order_id ),
                ptg_esc( $chat['username'] ? '@' . $chat['username'] : ( 'ID ' . $chat_id ) )
            ) );
            return true;
        }

        ptg_link_client( $order, $conn_id, $chat, 'manual' );
        ptg_maybe_assign( $order, $from_id, (array) ( $msg['from'] ?? array() ) );
        ptg_sync_order_message( $order, 'link' );
        // Команду набрал человек — ответ нужен всегда, иначе непонятно,
        // сработала она или нет: сама команда из чата удаляется.
        ptg_notify_group( $order, sprintf(
            '🔗 Чат %s привязан к заказу вручную',
            ptg_esc( $chat['username'] ? '@' . $chat['username'] : ( 'ID ' . $chat_id ) )
        ), true );
        return true;
    }

    $order = ptg_order_by_chat( $conn_id, $chat_id );

    if ( 'unlink' === $cmd ) {
        if ( $order ) {
            ptg_unlink_client( $order, $conn_id, $chat_id );
            ptg_sync_order_message( $order, 'unlink' );
            ptg_notify_group( $order, '🔗 Привязка чата снята', true );
        }
        return true;
    }

    // /order — напомнить, какой заказ за этим чатом.
    if ( $order ) {
        ptg_notify_group( $order, '🔎 Этот чат привязан сюда', true );
    } else {
        ptg_notify_group_plain( '🔎 Чат ' . ptg_esc( $chat['username'] ? '@' . $chat['username'] : ( 'ID ' . $chat_id ) ) . ' ни к какому заказу не привязан' );
    }
    return true;
}

/**
 * Снимает привязку.
 *
 * @param WC_Order $order   Заказ.
 * @param string   $conn_id Подключение.
 * @param int      $chat_id Чат.
 * @return void
 */
function ptg_unlink_client( $order, $conn_id, $chat_id ) {
    foreach ( array(
        PTG_META_CLIENT_CHAT,
        PTG_META_CLIENT_TG,
        PTG_META_CLIENT_USERNAME,
        PTG_META_CLIENT_NAME,
        PTG_META_CLIENT_SOURCE,
        PTG_META_CONNECTION,
    ) as $key ) {
        $order->delete_meta_data( $key );
    }
    $order->save();

    $index = get_option( 'ptg_chat_index', array() );
    unset( $index[ ptg_chat_key( $conn_id, $chat_id ) ] );
    update_option( 'ptg_chat_index', $index, false );

    ptg_add_note( $order, 'Привязка чата клиента снята (Telegram)' );
}

/**
 * Заказ по отображаемому номеру или ID.
 *
 * Номер у темы кастомный (`_podval_order_number`), поэтому сначала ищем
 * по нему, и только потом считаем число за ID заказа.
 *
 * @param int $number Номер.
 * @return WC_Order|null
 */
function ptg_order_by_number( $number ) {
    $number = (int) $number;
    if ( ! $number ) {
        return null;
    }

    $orders = wc_get_orders( array(
        'limit'      => 1,
        'meta_key'   => '_podval_order_number',
        'meta_value' => (string) $number,
    ) );
    if ( ! empty( $orders[0] ) && $orders[0] instanceof WC_Order ) {
        return $orders[0];
    }

    $order = wc_get_order( $number );
    return $order instanceof WC_Order ? $order : null;
}

/**
 * Удаляет сообщение в Business-чате.
 *
 * @param string $conn_id    Подключение.
 * @param int    $message_id Сообщение.
 * @return void
 */
function ptg_delete_business_message( $conn_id, $message_id ) {
    if ( ! $message_id ) {
        return;
    }
    if ( ! ptg_connection_can_delete( $conn_id ) ) {
        ptg_log( 'warn', 'Нет права удалять сообщения в Business-чате', array( 'conn' => $conn_id ) );
        return;
    }

    ptg_api( 'deleteBusinessMessages', array(
        'business_connection_id' => $conn_id,
        'message_ids'            => array( (int) $message_id ),
    ) );
}

/* ═══════════════════════════════════════════
   Сообщения в группу заказов
   ═══════════════════════════════════════════ */

/**
 * Короткое сообщение реплаем на карточку заказа.
 *
 * Лента заказов — рабочий инструмент, и подтверждения вида «чат
 * привязан» её только засоряют: тот же факт уже виден в самой карточке
 * строкой привязки. Поэтому рутинные уведомления по умолчанию не
 * отправляются, а пишутся в журнал. Важные — те, на которые человек
 * должен отреагировать, — уходят всегда.
 *
 * @param WC_Order $order     Заказ.
 * @param string   $html      Текст.
 * @param bool     $important Требует реакции человека.
 * @return void
 */
function ptg_notify_group( $order, $html, $important = false ) {
    if ( ! $important && ! get_option( 'ptg_group_notices', 0 ) ) {
        ptg_log( 'info', 'Служебное уведомление подавлено', array(
            'order' => $order->get_id(),
            'text'  => wp_strip_all_tags( $html ),
        ) );
        return;
    }

    $chat_id = (string) $order->get_meta( PTG_META_CHAT );
    if ( '' === $chat_id ) {
        $chat_id = (string) get_option( 'ptg_chat_id', '' );
    }
    $mid = (int) $order->get_meta( PTG_META_MESSAGE );

    $args = $mid ? array( 'reply_to_message_id' => $mid ) : array();
    ptg_tg_send_message( $chat_id, $html, $args );
}

/**
 * Сообщение в группу без привязки к заказу.
 *
 * @param string $html      Текст.
 * @param bool   $important Требует реакции человека.
 * @return void
 */
function ptg_notify_group_plain( $html, $important = true ) {
    if ( ! $important && ! get_option( 'ptg_group_notices', 0 ) ) {
        ptg_log( 'info', 'Служебное уведомление подавлено', array( 'text' => wp_strip_all_tags( $html ) ) );
        return;
    }
    ptg_tg_send_message( (string) get_option( 'ptg_chat_id', '' ), $html );
}

/**
 * Несколько заказов на один ник — просим уточнить.
 *
 * @param array $candidates Заказы.
 * @param array $chat       Чат клиента.
 * @return void
 */
function ptg_prompt_ambiguous( $candidates, $chat ) {
    $chat_id = (int) ( $chat['id'] ?? 0 );
    $key     = 'ptg_ambig_' . md5( (string) $chat_id );
    if ( get_transient( $key ) ) {
        return; // Не спамим на каждое сообщение.
    }
    set_transient( $key, 1, HOUR_IN_SECONDS );

    $numbers = array();
    foreach ( $candidates as $order ) {
        $numbers[] = '#' . $order->get_order_number();
    }

    ptg_notify_group_plain( sprintf(
        "🔗 Клиент %s пишет, но у него несколько открытых заказов: %s\nПривяжите нужный: отправьте в его чате <code>/link НОМЕР</code>",
        ptg_esc( $chat['username'] ? '@' . $chat['username'] : ( 'ID ' . $chat_id ) ),
        ptg_esc( implode( ', ', $numbers ) )
    ) );
}

/* ═══════════════════════════════════════════
   Личка бота: /whoami
   ═══════════════════════════════════════════ */

/**
 * Обычное сообщение боту (не Business).
 *
 * Единственное, что нужно на этом этапе, — сказать человеку его user_id,
 * чтобы он вписал его в профиль WP.
 *
 * @param array $msg Сообщение.
 * @return void
 */
function ptg_handle_plain_message( $msg ) {
    $text = trim( (string) ( $msg['text'] ?? '' ) );
    $cmd  = preg_replace( '/@.*$/', '', strtolower( (string) strtok( $text, ' ' ) ) );

    if ( '/whoami' !== $cmd && '/start' !== $cmd ) {
        return;
    }

    $from    = (array) ( $msg['from'] ?? array() );
    $tg_id   = (int) ( $from['id'] ?? 0 );
    $chat_id = (int) ( $msg['chat']['id'] ?? 0 );
    $user    = ptg_user_by_telegram_id( $tg_id );

    $lines = array( 'Ваш Telegram user_id: <code>' . $tg_id . '</code>' );
    if ( $user ) {
        $lines[] = 'Привязан к пользователю сайта: <b>' . ptg_esc( $user->display_name ) . '</b>';
        $lines[] = user_can( $user, 'edit_shop_orders' )
            ? 'Права на заказы: есть'
            : 'Права на заказы: <b>нет</b> — нужна роль с edit_shop_orders';
    } else {
        $lines[] = 'К пользователю сайта не привязан. Впишите этот номер в профиль WP → раздел Telegram.';
    }

    ptg_tg_send_message( $chat_id, implode( "\n", $lines ) );
}

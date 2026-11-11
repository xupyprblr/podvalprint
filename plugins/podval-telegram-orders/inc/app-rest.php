<?php
/**
 * Фаза 5. REST для Mini App: список заказов и заказ целиком.
 *
 * 2.6.0 — только чтение. Действия (статусы, ответственный, правки,
 * напоминания) — 2.7.0, отчёты — 2.8.0.
 *
 * Данные собираются из ptg_order_data(): приложение и карточка в группе
 * показывают одно и то же, расхождений быть не может.
 *
 * @package podval-telegram-orders
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const PTG_APP_PAGE_SIZE = 30;
const PTG_APP_SCAN_CAP  = 250;   // сколько заказов вкладки перебираем для фильтров/сортировки
const PTG_APP_SEARCH_CAP = 60;

/**
 * Вкладки приложения.
 *
 * @return array key => [ label, emoji, statuses[] ].
 */
function ptg_app_tabs() {
    $defs = ptg_statuses();
    $tabs = array(
        'work' => array(
            'label'    => 'В работе',
            'emoji'    => '',
            'statuses' => array( 'processing', 'on-hold', 'client-replied', 'sketch-sent', 'in-print', 'ready' ),
        ),
        'processing' => array( 'label' => 'Получен', 'emoji' => '🆕', 'statuses' => array( 'processing', 'on-hold' ) ),
    );
    foreach ( $defs as $key => $def ) {
        $tabs[ $key ] = array( 'label' => $def['label'], 'emoji' => $def['emoji'], 'statuses' => array( $key ) );
    }
    // «Прочие» — служебные статусы WooCommerce плюс всё, что было в
    // работе до запуска приложения и с тех пор не трогалось
    // (см. ptg_app_since()).
    $tabs['other'] = array(
        'label'    => 'Прочие',
        'emoji'    => '',
        'statuses' => array( 'pending', 'completed', 'cancelled', 'refunded', 'failed' ),
    );
    return apply_filters( 'ptg_app_tabs', $tabs );
}

/**
 * Маршруты.
 *
 * @return void
 */
function ptg_app_register_routes() {
    register_rest_route( PTG_REST_NS, '/app/me', array(
        'methods'             => 'GET',
        'permission_callback' => 'ptg_app_permission',
        'callback'            => 'ptg_app_rest_me',
    ) );
    register_rest_route( PTG_REST_NS, '/app/orders', array(
        'methods'             => 'GET',
        'permission_callback' => 'ptg_app_permission',
        'callback'            => 'ptg_app_rest_orders',
    ) );
    register_rest_route( PTG_REST_NS, '/app/order/(?P<id>\d+)', array(
        'methods'             => 'GET',
        'permission_callback' => 'ptg_app_permission',
        'callback'            => 'ptg_app_rest_order',
    ) );
}
add_action( 'rest_api_init', 'ptg_app_register_routes' );

/**
 * Ответ без кэша.
 *
 * @param array $data Данные.
 * @return WP_REST_Response
 */
function ptg_app_response( $data ) {
    $r = new WP_REST_Response( $data, 200 );
    $r->header( 'Cache-Control', 'no-store, private' );
    return $r;
}

/**
 * Кто открыл приложение + справочники.
 *
 * @param WP_REST_Request $request Запрос.
 * @return WP_REST_Response
 */
function ptg_app_rest_me( $request ) {
    $a    = ptg_app_auth( $request );
    $tabs = array();
    foreach ( ptg_app_tabs() as $key => $t ) {
        $tabs[] = array( 'key' => $key, 'label' => $t['label'], 'emoji' => $t['emoji'] );
    }
    return ptg_app_response( array(
        'user'        => array(
            'tg_id' => $a['tg_id'],
            'name'  => $a['user']->display_name,
            'roles' => array_values( $a['user']->roles ),
        ),
        'start_param' => $a['start_param'],
        'tabs'        => $tabs,
        'show_assignee' => ptg_show_assignee(),
        'since'       => ptg_app_since(),
        'version'     => PTG_VERSION,
    ) );
}

/**
 * Момент запуска приложения. Заказы старше него, которые с тех пор
 * никто не трогал, в рабочие вкладки не попадают: до приложения статусы в
 * WooCommerce никто не вёл, и «Получен» там означает что угодно.
 *
 * Заказ возвращается в рабочие вкладки сам, как только ему меняют статус
 * (кнопкой в группе или в приложении) — _ptg_status_at пишется с 2.6.0.
 *
 * @return int unix.
 */
function ptg_app_since() {
    $ts = (int) get_option( 'ptg_app_since', 0 );
    if ( ! $ts ) {
        $ts = time();
        update_option( 'ptg_app_since', $ts, true );
    }
    return $ts;
}

/**
 * Статусы, которые живут в рабочих вкладках (цикл + негативные).
 *
 * @return string[]
 */
function ptg_app_managed_statuses() {
    return array_merge( array( 'processing', 'on-hold' ), array_keys( ptg_statuses() ) );
}

/**
 * «Свежие» заказы статуса: созданы после запуска или сменили статус
 * после него. Только ID — это два лёгких запроса без загрузки заказов.
 *
 * @param string $status Статус без wc-.
 * @return int[]
 */
function ptg_app_fresh_ids( $status ) {
    static $memo = array();
    if ( isset( $memo[ $status ] ) ) {
        return $memo[ $status ];
    }
    $since = ptg_app_since();
    $base  = array( 'status' => array( $status ), 'limit' => -1, 'return' => 'ids', 'type' => 'shop_order' );

    $a = wc_get_orders( $base + array( 'date_created' => '>=' . $since ) );
    $b = wc_get_orders( $base + array( 'meta_query' => array( array(
        'key'     => PTG_META_STATUS_AT,
        'value'   => $since,
        'compare' => '>=',
        'type'    => 'NUMERIC',
    ) ) ) );

    $ids = array_values( array_unique( array_map( 'intval', array_merge( (array) $a, (array) $b ) ) ) );
    rsort( $ids );
    return $memo[ $status ] = $ids;
}

/**
 * ID заказов вкладки, новые первыми.
 *
 * @param string $tab Вкладка.
 * @param int    $cap Предел.
 * @return int[]
 */
function ptg_app_tab_ids( $tab, $cap ) {
    $tabs = ptg_app_tabs();

    if ( 'other' !== $tab ) {
        $ids = array();
        foreach ( $tabs[ $tab ]['statuses'] as $st ) {
            $ids = array_merge( $ids, ptg_app_fresh_ids( $st ) );
        }
        $ids = array_values( array_unique( $ids ) );
        rsort( $ids );
        return array_slice( $ids, 0, $cap );
    }

    $fresh = array();
    foreach ( ptg_app_managed_statuses() as $st ) {
        $fresh = array_merge( $fresh, ptg_app_fresh_ids( $st ) );
    }
    $all = wc_get_orders( array(
        'status'  => array_merge( $tabs['other']['statuses'], ptg_app_managed_statuses() ),
        'limit'   => $cap + count( $fresh ),
        'orderby' => 'date',
        'order'   => 'DESC',
        'return'  => 'ids',
        'type'    => 'shop_order',
    ) );
    $ids = array_values( array_diff( array_map( 'intval', (array) $all ), $fresh ) );
    return array_slice( $ids, 0, $cap );
}

/**
 * Заказ «до приложения»: статус рабочий, но с момента запуска его никто
 * не трогал. В поиске помечается, чтобы не путать с живыми.
 *
 * @param WC_Order $o Заказ.
 * @return bool
 */
function ptg_app_is_legacy( $o ) {
    $st = $o->get_status();
    if ( ! in_array( $st, ptg_app_managed_statuses(), true ) ) {
        return false;
    }
    return ! in_array( $o->get_id(), ptg_app_fresh_ids( $st ), true );
}

/**
 * Счётчики вкладок.
 *
 * @return array key => int.
 */
function ptg_app_tab_counts() {
    $tabs  = ptg_app_tabs();
    $fresh = array();
    $stale = 0;
    foreach ( ptg_app_managed_statuses() as $st ) {
        $fresh[ $st ] = count( ptg_app_fresh_ids( $st ) );
        $stale       += max( 0, (int) wc_orders_count( $st ) - $fresh[ $st ] );
    }

    $out = array();
    foreach ( $tabs as $key => $t ) {
        $n = 0;
        foreach ( $t['statuses'] as $st ) {
            $n += ( 'other' === $key ) ? (int) wc_orders_count( $st ) : ( $fresh[ $st ] ?? 0 );
        }
        $out[ $key ] = ( 'other' === $key ) ? $n + $stale : $n;
    }
    return $out;
}

/**
 * Список заказов.
 *
 * Параметры: tab, q, sort (new|old|sum|wait), f (через запятую:
 * mine,free,changed,stale,photos,pickup,delivery), page.
 *
 * Поиск идёт по всем статусам: оператор на выдаче не должен угадывать
 * вкладку, чтобы найти человека.
 *
 * @param WP_REST_Request $request Запрос.
 * @return WP_REST_Response
 */
function ptg_app_rest_orders( $request ) {
    $a       = ptg_app_auth( $request );
    $tabs    = ptg_app_tabs();
    $tab     = (string) $request->get_param( 'tab' );
    $tab     = isset( $tabs[ $tab ] ) ? $tab : 'work';
    $q       = trim( (string) $request->get_param( 'q' ) );
    $sort    = (string) $request->get_param( 'sort' );
    $filters = array_filter( array_map( 'trim', explode( ',', (string) $request->get_param( 'f' ) ) ) );
    $page    = max( 1, (int) $request->get_param( 'page' ) );

    if ( ! in_array( $sort, array( 'new', 'old', 'sum', 'wait' ), true ) ) {
        // На выдаче и «не забрали» полезнее видеть тех, кто ждёт дольше всех.
        $sort = in_array( $tab, array( 'ready', 'not-picked' ), true ) ? 'wait' : 'new';
    }

    if ( '' !== $q ) {
        $ids = ptg_app_search_ids( $q );
    } else {
        $ids = ptg_app_tab_ids( $tab, PTG_APP_SCAN_CAP );
    }

    $orders = array();
    foreach ( $ids as $id ) {
        $o = wc_get_order( $id );
        if ( $o instanceof WC_Order && ! $o instanceof WC_Order_Refund ) {
            $orders[] = $o;
        }
    }

    $orders = array_values( array_filter( $orders, function ( $o ) use ( $filters, $a ) {
        return ptg_app_passes_filters( $o, $filters, $a['tg_id'] );
    } ) );

    usort( $orders, function ( $x, $y ) use ( $sort ) {
        switch ( $sort ) {
            case 'old':
                return ptg_app_created_ts( $x ) <=> ptg_app_created_ts( $y );
            case 'sum':
                return (float) $y->get_total() <=> (float) $x->get_total();
            case 'wait':
                return ptg_app_status_ts( $x ) <=> ptg_app_status_ts( $y );
            default:
                return ptg_app_created_ts( $y ) <=> ptg_app_created_ts( $x );
        }
    } );

    $total = count( $orders );
    $slice = array_slice( $orders, ( $page - 1 ) * PTG_APP_PAGE_SIZE, PTG_APP_PAGE_SIZE );
    $rows  = array();
    foreach ( $slice as $o ) {
        $rows[] = ptg_app_row( $o, $a['tg_id'] );
    }

    return ptg_app_response( array(
        'tab'     => $tab,
        'q'       => $q,
        'sort'    => $sort,
        'filters' => array_values( $filters ),
        'page'    => $page,
        'pages'   => max( 1, (int) ceil( $total / PTG_APP_PAGE_SIZE ) ),
        'total'   => $total,
        'capped'  => ( '' === $q && count( $ids ) >= PTG_APP_SCAN_CAP ),
        'counts'  => ptg_app_tab_counts(),
        'items'   => $rows,
        'active'  => ( '' === $q && 1 === $page ) ? ptg_app_active_rows( $a['tg_id'] ) : array(),
        'now'     => time(),
    ) );
}

/**
 * @param WC_Order $o Заказ.
 * @return int
 */
function ptg_app_created_ts( $o ) {
    $d = $o->get_date_created();
    return $d ? $d->getTimestamp() : 0;
}

/**
 * Когда заказ пришёл в текущий статус. Для заказов до 2.6.0 меты нет —
 * берём дату изменения как лучшее приближение.
 *
 * @param WC_Order $o Заказ.
 * @return int
 */
function ptg_app_status_ts( $o ) {
    $ts = (int) $o->get_meta( PTG_META_STATUS_AT );
    if ( $ts ) {
        return $ts;
    }
    $d = $o->get_date_modified();
    return $d ? $d->getTimestamp() : ptg_app_created_ts( $o );
}

/**
 * Фильтры списка. Все дешёвые — по метам и полям заказа, без
 * ptg_order_data().
 *
 * @param WC_Order $o       Заказ.
 * @param string[] $filters Фильтры.
 * @param int      $tg_id   Кто смотрит.
 * @return bool
 */
function ptg_app_passes_filters( $o, $filters, $tg_id ) {
    if ( ! ptg_show_assignee() ) {
        $filters = array_diff( $filters, array( 'mine', 'free' ) );
    }
    foreach ( $filters as $f ) {
        switch ( $f ) {
            case 'mine':
                if ( (int) $o->get_meta( PTG_META_ASSIGNEE_TG ) !== (int) $tg_id ) {
                    return false;
                }
                break;
            case 'free':
                if ( (int) $o->get_meta( PTG_META_ASSIGNEE_TG ) ) {
                    return false;
                }
                break;
            case 'changed':
                if ( empty( ptg_get_overrides( $o ) ) ) {
                    return false;
                }
                break;
            case 'stale':
                if ( '1' !== (string) $o->get_meta( PTG_MOCKUP_STALE_META ) ) {
                    return false;
                }
                break;
            case 'pickup':
            case 'delivery':
                $ship = ptg_order_shipping( $o );
                if ( ( 'pickup' === $f ) !== (bool) $ship['is_pickup'] ) {
                    return false;
                }
                break;
        }
    }
    return true;
}

/**
 * Поиск: номер заказа, имя, телефон, почта, ник (с чекаута и
 * привязанного чата).
 *
 * @param string $q Запрос.
 * @return int[] ID заказов.
 */
function ptg_app_search_ids( $q ) {
    global $wpdb;

    $q      = mb_substr( $q, 0, 64 );
    $digits = preg_replace( '/\D+/', '', $q );
    $ids    = array();

    // Номер: «47», «#47». Номер магазина живёт в _podval_order_number и
    // может не совпадать с ID.
    if ( '' !== $digits && preg_match( '/^#?\d{1,7}$/', $q ) ) {
        $o = ptg_order_by_number( (int) $digits );
        if ( $o ) {
            $ids[] = $o->get_id();
        }
    }

    // Ник: с @ или без, в обоих местах, где он хранится.
    $nick = ptg_normalize_username( $q );
    if ( '' !== $nick && strlen( $nick ) >= 3 ) {
        $found = wc_get_orders( array(
            'limit'      => PTG_APP_SEARCH_CAP,
            'return'     => 'ids',
            'type'       => 'shop_order',
            'meta_query' => array(
                'relation' => 'OR',
                array( 'key' => PTG_META_CLIENT_USERNAME, 'value' => $nick, 'compare' => 'LIKE' ),
                array( 'key' => '_billing_messenger', 'value' => $nick, 'compare' => 'LIKE' ),
                array( 'key' => '_billing_telegram', 'value' => $nick, 'compare' => 'LIKE' ),
            ),
        ) );
        $ids = array_merge( $ids, (array) $found );
    }

    // Телефон: сравниваем только цифры — в заказах он записан как попало:
    // «+375 (29) 123-45-67», «80291234567», «291234567».
    if ( strlen( $digits ) >= 5 && ptg_app_hpos() ) {
        $table = $wpdb->prefix . 'wc_order_addresses';
        $norm  = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'(',''),')',''),'+','')";
        $found = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT order_id FROM {$table} WHERE {$norm} LIKE %s ORDER BY order_id DESC LIMIT %d", // phpcs:ignore
            '%' . $wpdb->esc_like( substr( $digits, -9 ) ) . '%',
            PTG_APP_SEARCH_CAP
        ) );
        $ids = array_merge( $ids, array_map( 'intval', (array) $found ) );
    }

    // Имя и почта — встроенный поиск WooCommerce.
    if ( mb_strlen( $q ) >= 2 && '' === $digits ) {
        $found = wc_get_orders( array(
            's'      => $q,
            'limit'  => PTG_APP_SEARCH_CAP,
            'return' => 'ids',
            'type'   => 'shop_order',
        ) );
        $ids = array_merge( $ids, (array) $found );
    }

    $ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
    return array_slice( $ids, 0, PTG_APP_SEARCH_CAP );
}

/**
 * Включены ли таблицы HPOS.
 *
 * @return bool
 */
function ptg_app_hpos() {
    return class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
        && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
}

/**
 * Тяжёлая часть строки списка (превью, позиции) — с кэшем по дате
 * изменения заказа. ptg_order_data() на каждый заказ в каждом запросе
 * — это секунды на вкладке «Выдан».
 *
 * @param WC_Order $o Заказ.
 * @return array
 */
function ptg_app_row_heavy( $o ) {
    $mod = $o->get_date_modified();
    $ver = ( $mod ? $mod->getTimestamp() : 0 ) . ':' . PTG_VERSION;
    $key = 'ptg_app_row_' . $o->get_id();

    $hit = get_transient( $key );
    if ( is_array( $hit ) && ( $hit['v'] ?? '' ) === $ver ) {
        return $hit['d'];
    }

    $data = ptg_order_data( $o );
    $qty  = 0;
    foreach ( $data['items'] as $it ) {
        $qty += max( 1, (int) $it['qty'] );
    }
    $lines = ptg_app_item_lines( $data );

    $d = array(
        'preview'  => $data['preview'] ? array(
            'url'    => (string) $data['preview']['url'],
            'source' => (string) $data['preview']['source'],
        ) : null,
        'previews' => ptg_app_item_previews( $data, $o, 4 ),
        'lines'    => $lines,
        'items'    => implode( ' + ', array_map( function ( $l ) {
            return $l['text'] . ( $l['qty'] > 1 ? ' ×' . $l['qty'] : '' );
        }, $lines ) ),
        'qty'      => $qty,
        'photos'   => ! empty( $data['flags']['awaiting_photos'] ),
        'changed'  => count( $data['overrides'] ),
        'stale'    => ! empty( $data['flags']['mockup_stale'] ),
        'service'  => ( 'service' === $data['type'] ),
    );

    set_transient( $key, array( 'v' => $ver, 'd' => $d ), 12 * HOUR_IN_SECONDS );
    return $d;
}

/**
 * Строка списка.
 *
 * @param WC_Order $o     Заказ.
 * @param int      $tg_id Кто смотрит.
 * @return array
 */
function ptg_app_row( $o, $tg_id ) {
    $heavy  = ptg_app_row_heavy( $o );
    $st     = ptg_order_status_info( $o );
    $cust   = ptg_order_customer( $o );
    $ship   = ptg_order_shipping( $o );
    $client = (string) $o->get_meta( PTG_META_CLIENT_USERNAME );
    if ( '' === $client ) {
        $client = ptg_normalize_username( $cust['messenger'] );
    }
    $as_tg = (int) $o->get_meta( PTG_META_ASSIGNEE_TG );

    return array_merge( $heavy, array(
        'id'        => $o->get_id(),
        'number'    => (string) $o->get_order_number(),
        'status'    => array(
            'key'      => $st['key'],
            'label'    => $st['label'],
            'emoji'    => $st['emoji'],
            'negative' => ! empty( $st['negative'] ),
        ),
        'created'   => ptg_app_created_ts( $o ),
        'status_at' => ptg_app_status_ts( $o ),
        'total'     => (float) $o->get_total(),
        'name'      => $cust['name'],
        'username'  => $client,
        'phone'     => $cust['phone'],
        'pickup'    => (bool) $ship['is_pickup'],
        'payment'   => (string) $o->get_payment_method_title(),
        'assignee'  => ptg_show_assignee() ? (string) $o->get_meta( PTG_META_ASSIGNEE_NAME ) : '',
        'mine'      => ptg_show_assignee() && $as_tg && $as_tg === (int) $tg_id,
        'linked'    => (bool) $o->get_meta( PTG_META_CLIENT_CHAT ),
        'legacy'    => ptg_app_is_legacy( $o ),
        'reminded'  => count( ptg_remind_log( $o ) ),
        'quick'     => ptg_app_quick( $o, ! empty( $heavy['service'] ) ),
    ) );
}

/**
 * Заказ целиком.
 *
 * @param WP_REST_Request $request Запрос.
 * @return WP_REST_Response|WP_Error
 */
function ptg_app_rest_order( $request ) {
    $a = ptg_app_auth( $request );
    $o = wc_get_order( (int) $request['id'] );
    if ( ! $o instanceof WC_Order || $o instanceof WC_Order_Refund ) {
        return new WP_Error( 'ptg_app_not_found', 'Заказ не найден', array( 'status' => 404 ) );
    }
    return ptg_app_response( ptg_app_order_payload( $o, $a ) );
}

/**
 * Заказ целиком для приложения. Отдельно от маршрута: действия (2.7.0)
 * возвращают тот же формат, чтобы экран обновлялся одним запросом.
 *
 * @param WC_Order $o Заказ.
 * @param array    $a Результат ptg_app_auth().
 * @return array
 */
function ptg_app_order_payload( $o, $a ) {
    $d     = ptg_order_data( $o );
    $strip = function ( $s ) {
        return trim( wp_strip_all_tags( (string) $s ) );
    };

    $items = array();
    foreach ( $d['items'] as $it ) {
        $notices = array();
        foreach ( (array) $it['notices'] as $n ) {
            $notices[] = array( 'level' => (string) ( $n['level'] ?? 'info' ), 'text' => $strip( $n['text'] ?? '' ) );
        }
        $items[] = array(
            'item_id'  => (int) $it['item_id'],
            'editable' => 'bundle' !== $it['kind'],
            'name'     => $it['name'],
            'qty'      => (int) $it['qty'],
            'subtotal' => (float) $it['subtotal'],
            'kind'     => $it['kind'],
            'garment'  => array_map( function ( $g ) {
                return array(
                    'key'        => (string) $g['key'],
                    'slug'       => (string) ( $g['slug'] ?? '' ),
                    'label'      => (string) $g['label'],
                    'value'      => (string) $g['value'],
                    'emoji'      => (string) ( $g['emoji'] ?? '' ),
                    'overridden' => ! empty( $g['overridden'] ),
                    'from'       => (string) ( $g['from_value'] ?? '' ),
                );
            }, (array) $it['garment'] ),
            'details'  => array_map( function ( $x ) use ( $strip ) {
                return array( 'label' => $strip( $x['label'] ?? '' ), 'value' => $strip( $x['value'] ?? '' ) );
            }, (array) $it['details'] ),
            'texts'    => array_map( function ( $x ) use ( $strip ) {
                return array( 'label' => $strip( $x['label'] ?? '' ), 'value' => $strip( $x['value'] ?? '' ) );
            }, (array) $it['texts'] ),
            'comment'  => $strip( $it['comment'] ),
            'links'    => array_map( function ( $l ) {
                return array( 'label' => (string) $l['label'], 'url' => esc_url_raw( (string) $l['url'] ), 'role' => (string) ( $l['role'] ?? '' ) );
            }, (array) $it['links'] ),
            'notices'  => $notices,
            'bundle'   => ! empty( $it['bundle'] ),
        );
    }

    $log   = $o->get_meta( PTG_META_CHAT_LOG );
    $log   = is_array( $log ) ? array_slice( $log, -30 ) : array();
    $chat  = array();
    $files_left = ptg_chat_files( $o );
    foreach ( $log as $e ) {
        $chat[] = array(
            't'    => (int) ( $e['t'] ?? 0 ),
            'dir'  => (string) ( $e['d'] ?? 'in' ),
            'who'  => (string) ( $e['n'] ?? '' ),
            'txt'  => (string) ( $e['x'] ?? '' ),
            'med'  => (string) ( $e['f'] ?? '' ),
            'del'  => ! empty( $e['del'] ),
            'file' => ptg_app_chat_file( $e, $files_left ),
        );
    }

    $notes = array();
    $note_args = array(
        'order_id' => $o->get_id(),
        'limit'    => 30,
        'orderby'  => 'date_created_gmt',
        'order'    => 'DESC',
    );
    foreach ( wc_get_order_notes( $note_args ) as $n ) {
        $notes[] = array(
            't'        => $n->date_created ? $n->date_created->getTimestamp() : 0,
            'txt'      => $strip( $n->content ),
            'customer' => (bool) $n->customer_note,
            'by'       => (string) $n->added_by,
        );
    }

    $st   = $d['status_info'];
    $defs = ptg_statuses();
    $lbl  = function ( $key ) use ( $defs ) {
        if ( 'processing' === $key ) {
            return '🆕 Получен';
        }
        return isset( $defs[ $key ] ) ? $defs[ $key ]['emoji'] . ' ' . $defs[ $key ]['label'] : $key;
    };
    $opt  = function ( $key ) use ( $lbl ) {
        return array( 'key' => $key, 'label' => $lbl( $key ) );
    };
    $back = ptg_app_back_status( $o );

    // Справочник параметров для правки: только поля, у которых тема
    // отдаёт список значений — как в кнопках бота.
    $fields = array();
    foreach ( ptg_garment_labels() as $field => $label ) {
        $opts = ptg_garment_value_options( $field );
        if ( ! $opts ) {
            continue;
        }
        $list = array();
        foreach ( $opts as $slug => $olabel ) {
            $list[] = array( 'slug' => (string) $slug, 'label' => (string) $olabel );
        }
        $fields[] = array( 'key' => $field, 'label' => $label, 'options' => $list );
    }

    $remind = ptg_remind_log( $o );
    $last_r = $remind ? end( $remind ) : null;
    $is_svc = ( 'service' === $d['type'] );

    return array(
        'id'        => $d['id'],
        'number'    => (string) $d['number'],
        'type'      => $d['type'],
        'created'   => ptg_app_created_ts( $o ),
        'status_at' => ptg_app_status_ts( $o ),
        'legacy'    => ptg_app_is_legacy( $o ),
        'manual'    => (bool) $o->get_meta( PTG_META_MANUAL ),
        'status'    => array(
            'key'      => $st['key'],
            'label'    => $st['label'],
            'emoji'    => $st['emoji'],
            'negative' => ! empty( $st['negative'] ),
            'locked'   => ! empty( $st['locked'] ),
            // 2.18.0: заявки из формы (DTF и др.) идут по тому же циклу,
            // что и заказы, — иначе их нечем было закрыть.
            'next'     => array_map( $opt, (array) $st['next'] ),
            'back'     => $back ? $opt( $back ) : null,
            'refuse'   => ! empty( $st['refusable'] ),
            'verify'   => ! empty( $d['overrides'] ) && '' === (string) ( $d['verified']['at'] ?? '' ),
        ),
        'fields'    => $fields,
        'hint'      => ptg_app_hint( $o ),
        'pickup'    => array(
            'ready' => ptg_pickup_ready(),
            'point' => (bool) ptg_pickup_point(),
            'text'  => ptg_pickup_ready() ? ptg_pickup_render( $o, true ) : '',
            'at'    => (int) $o->get_meta( PTG_META_PICKUP_AT ),
            'by'    => (string) $o->get_meta( PTG_META_PICKUP_BY ),
        ),
        'remind'    => array(
            'count' => count( $remind ),
            'last'  => $last_r ? (int) $last_r['t'] : 0,
            'by'    => $last_r ? (string) $last_r['by'] : '',
            'text'  => ptg_remind_render( $o ),
        ),
        'customer'  => $d['customer'],
        'client'    => array(
            'linked'   => ! empty( $d['client']['linked'] ),
            'phone_chat' => empty( $d['client']['linked'] ) ? ptg_phone_chat_link( $d['customer']['phone'] ?? '' ) : '',
            'link_cmd' => '/link ' . $d['number'],
            'username' => $d['client']['username'] ?: $d['client']['checkout'],
            'name'     => $d['client']['name'],
            'source'   => $d['client']['source'],
            'messages' => (int) $d['client']['messages'],
        ),
        'shipping'  => $d['shipping'],
        'payment'   => array(
            'title' => (string) $d['payment']['title'],
            'total' => (float) $d['payment']['total'],
            'state' => (string) ( $d['payment']['state'] ?? '' ),
            'state_label' => (string) ( $d['payment']['state_label'] ?? '' ),
            // 2.17.0: ручная отметка для «после согласования».
            'agreed'      => ptg_payment_is_agreed( $o ),
            'mark'        => ptg_pay_mark( $o ),
            'mark_opts'   => ptg_payment_is_agreed( $o ) ? array_map( function ( $k, $v ) {
                return array( 'key' => $k, 'label' => $v );
            }, array_keys( ptg_pay_mark_options() ), ptg_pay_mark_options() ) : array(),
        ),
        'note'      => $strip( $d['note'] ),
        'items'     => $items,
        'files'     => array_map( function ( $f ) {
            return array( 'label' => (string) ( $f['label'] ?? 'Файл' ), 'url' => esc_url_raw( (string) ( $f['url'] ?? '' ) ) );
        }, (array) $d['files'] ),
        'chat_files' => array_map( function ( $f ) {
            return array( 'name' => (string) ( $f['name'] ?? '' ), 'url' => esc_url_raw( (string) ( $f['url'] ?? '' ) ), 'kind' => (string) ( $f['kind'] ?? '' ) );
        }, ptg_chat_files( $o ) ),
        'overrides' => array_map( function ( $r ) {
            return array(
                'item_id' => (int) $r['item_id'],
                'field'   => (string) $r['field'],
                'item'  => $r['item_name'],
                'label' => $r['label'],
                'from'  => $r['from_label'],
                'to'    => $r['to_label'],
                'by'    => $r['by_name'],
                'delta' => $r['delta'],
            );
        }, (array) $d['overrides'] ),
        'flags'     => array(
            'stale'  => ! empty( $d['flags']['mockup_stale'] ),
            'photos' => ! empty( $d['flags']['awaiting_photos'] ),
        ),
        'preview'   => $d['preview'] ? array( 'url' => esc_url_raw( $d['preview']['url'] ), 'source' => $d['preview']['source'] ) : null,
        'previews'  => ptg_app_item_previews( $d, $o, 6 ),
        'assignee'  => ptg_show_assignee() ? array(
            'name' => $d['assignee']['name'],
            'mine' => (int) $d['assignee']['tg_id'] === (int) $a['tg_id'],
        ) : null,
        'verified'  => $d['verified'],
        'greeting'  => array(
            'sent' => ! empty( $d['greeting']['sent'] ),
            'by'   => (string) ( $d['greeting']['by'] ?? '' ),
            // Кнопку показываем, пока по заказу не ушло ни одного нашего
            // сообщения — то же правило, что у карточки.
            'show' => ! $is_svc && empty( $d['greeting']['sent'] ) && empty( $d['client']['outgoing'] ),
            'text' => ptg_greeting_render( $o ),
        ),
        'chat'      => $chat,
        'notes'     => $notes,
        'admin_url' => $d['admin_url'],
        'tg_link'   => ptg_app_group_message_link( $o ),
        'now'       => time(),
    );
}

/**
 * Куда ведёт «назад» — то же правило, что у кнопки в карточке.
 *
 * @param WC_Order $o Заказ.
 * @return string Ключ статуса или ''.
 */
function ptg_app_back_status( $o ) {
    $st   = ptg_order_status_info( $o );
    $prev = ! empty( $st['back'] ) ? $st['back'] : ptg_status_prev( $st['key'] );
    if ( $prev && empty( $st['negative'] ) && ptg_is_negative_status( $prev ) ) {
        return '';
    }
    return (string) $prev;
}

/**
 * Ссылка на карточку заказа в группе (t.me/c/…), если группа —
 * супергруппа. В обычной группе ссылок на сообщения нет.
 *
 * @param WC_Order $o Заказ.
 * @return string
 */
function ptg_app_group_message_link( $o ) {
    $chat = (string) $o->get_meta( PTG_META_CHAT );
    $msg  = (int) $o->get_meta( PTG_META_MESSAGE );
    if ( ! $msg || 0 !== strpos( $chat, '-100' ) ) {
        return '';
    }
    return 'https://t.me/c/' . substr( $chat, 4 ) . '/' . $msg;
}


/**
 * Файл сообщения переписки для показа в логе (2.8.0).
 *
 * С 2.3.0 запись лога хранит ID вложения (`a`) и адрес (`u`). Для
 * записей, где их нет (пришли из буфера до /link), файл ищется в общем
 * списке по направлению и времени — ±10 секунд между приходом сообщения
 * и скачиванием.
 *
 * @param array $e     Запись лога.
 * @param array $files Файлы заказа (по ссылке: найденный вычёркивается).
 * @return array|null [ kind, url, thumb, name ].
 */
function ptg_app_chat_file( $e, &$files ) {
    if ( '' === (string) ( $e['f'] ?? '' ) || ! empty( $e['del'] ) ) {
        return null;
    }

    $att = (int) ( $e['a'] ?? 0 );
    $url = (string) ( $e['u'] ?? '' );
    $rec = null;

    foreach ( $files as $i => $f ) {
        $match = $att
            ? ( (int) ( $f['id'] ?? 0 ) === $att )
            : ( ( $f['dir'] ?? '' ) === ( $e['d'] ?? '' ) && abs( (int) ( $f['t'] ?? 0 ) - (int) ( $e['t'] ?? 0 ) ) <= 10 );
        if ( $match ) {
            $rec = $f;
            unset( $files[ $i ] );
            break;
        }
    }

    if ( ! $att && $rec ) {
        $att = (int) ( $rec['id'] ?? 0 );
    }
    if ( '' === $url && $rec ) {
        $url = (string) ( $rec['url'] ?? '' );
    }
    if ( '' === $url ) {
        return null;
    }

    $kind  = $rec ? (string) ( $rec['kind'] ?? '' ) : '';
    if ( '' === $kind ) {
        $map  = array( 'фото' => 'photo', 'файл' => 'document', 'видео' => 'video', 'голосовое' => 'voice' );
        $kind = $map[ (string) $e['f'] ] ?? 'document';
    }
    $thumb = '';
    if ( 'photo' === $kind || ( 'document' === $kind && ptg_is_image_url( $url ) ) ) {
        $thumb = $att ? (string) wp_get_attachment_image_url( $att, 'medium' ) : '';
        $thumb = $thumb ?: $url;
    }

    return array(
        'kind'  => $kind,
        'url'   => esc_url_raw( $url ),
        'thumb' => $thumb ? esc_url_raw( $thumb ) : '',
        'name'  => (string) ( $rec['name'] ?? '' ),
    );
}

/** Окно блока «Сейчас в переписке», сек. */
const PTG_APP_ACTIVE_WINDOW = 2 * HOUR_IN_SECONDS;

/**
 * Подсказка «вы отправили изображение → Эскиз отправлен» (2.8.0).
 *
 * Только подсказка: не каждая картинка — эскиз (пример, размерная
 * сетка). Условие: заказ ещё до эскиза, а изображение ушло клиенту после
 * последней смены статуса и не раньше 6 часов назад.
 *
 * @param WC_Order $o Заказ.
 * @return array|null [ to, label, t ].
 */
function ptg_app_hint( $o ) {
    if ( ! in_array( $o->get_status(), array( 'processing', 'on-hold', 'client-replied' ), true ) ) {
        return null;
    }
    $t = (int) $o->get_meta( PTG_META_OUT_MEDIA_AT );
    if ( ! $t || time() - $t > 6 * HOUR_IN_SECONDS ) {
        return null;
    }
    $changed = (int) $o->get_meta( PTG_META_STATUS_AT );
    if ( $changed && $t < $changed ) {
        return null;
    }
    $defs = ptg_statuses();
    return array(
        'to'    => 'sketch-sent',
        'label' => $defs['sketch-sent']['emoji'] . ' ' . $defs['sketch-sent']['label'],
        't'     => $t,
    );
}

/**
 * Заказы с активной перепиской за последние два часа — для возврата в
 * заказ после чата с клиентом. Не больше трёх.
 *
 * @param int $tg_id Кто смотрит.
 * @return array[]
 */
function ptg_app_active_rows( $tg_id ) {
    $statuses = array_diff( ptg_app_managed_statuses(), array( 'issued', 'refused' ) );
    $orders   = wc_get_orders( array(
        'status'     => array_values( $statuses ),
        'limit'      => 12,
        'type'       => 'shop_order',
        'meta_query' => array( array(
            'key'     => PTG_META_CHAT_LAST_AT,
            'value'   => time() - PTG_APP_ACTIVE_WINDOW,
            'compare' => '>=',
            'type'    => 'NUMERIC',
        ) ),
    ) );

    usort( $orders, function ( $x, $y ) {
        return (int) $y->get_meta( PTG_META_CHAT_LAST_AT ) <=> (int) $x->get_meta( PTG_META_CHAT_LAST_AT );
    } );

    $out = array();
    foreach ( array_slice( $orders, 0, 3 ) as $o ) {
        $row  = ptg_app_row( $o, $tg_id );
        $log  = $o->get_meta( PTG_META_CHAT_LOG );
        $last = is_array( $log ) && $log ? end( $log ) : null;
        $hint = ptg_app_hint( $o );

        $row['chat']  = $last ? array(
            't'   => (int) ( $last['t'] ?? 0 ),
            'dir' => (string) ( $last['d'] ?? 'in' ),
            'txt' => mb_substr( (string) ( $last['x'] ?? '' ), 0, 80 ),
            'med' => (string) ( $last['f'] ?? '' ),
        ) : null;
        $row['hint']  = $hint;
        $out[]        = $row;
    }
    return $out;
}


/**
 * Позиции заказа коротко, для списка (2.9.0): не название товара
 * («Печать на Футболке»), а что это и какое изделие —
 * «Свой дизайн · Классическая M 🔴 спина». Одинаковые строки
 * схлопываются с суммой количества.
 *
 * @param array $data Данные заказа.
 * @return array[] [ text, qty ].
 */
function ptg_app_item_lines( $data ) {
    $out = array();
    foreach ( $data['items'] as $it ) {
        if ( 'bundle' === $it['kind'] ) {
            $kind = 'Набор';
        } elseif ( 'tpl' === $it['kind'] ) {
            $kind = 'Шаблон';
        } elseif ( ! empty( $it['artwork'] ) ) {
            $kind = 'Свой дизайн';
        } else {
            $kind = ptg_app_short_name( $it['name'] );
        }

        $g = array();
        foreach ( (array) $it['garment'] as $row ) {
            $g[ $row['key'] ] = $row;
        }
        // Размер и цвет — первыми: на узком экране хвост строки
        // обрезается, а ориентироваться нужно именно по ним.
        $parts = array();
        if ( isset( $g['size'] ) ) {
            $parts[] = $g['size']['value'];
        }
        if ( isset( $g['color'] ) ) {
            $parts[] = '' !== (string) $g['color']['emoji'] ? $g['color']['emoji'] : $g['color']['value'];
        }
        if ( isset( $g['type'] ) ) {
            $parts[] = $g['type']['value'];
        }
        if ( isset( $g['side'] ) ) {
            $parts[] = mb_strtolower( $g['side']['value'] );
        }

        $text = $kind . ( $parts ? ' · ' . implode( ' ', $parts ) : '' );
        $qty  = max( 1, (int) $it['qty'] );
        if ( isset( $out[ $text ] ) ) {
            $out[ $text ]['qty'] += $qty;
        } else {
            $out[ $text ] = array( 'text' => $text, 'qty' => $qty );
        }
    }
    return array_values( $out );
}

/**
 * Название готового товара без служебного хвоста — для строки списка.
 *
 * @param string $name Название.
 * @return string
 */
function ptg_app_short_name( $name ) {
    $name = trim( preg_replace( '/\s+[-–—]\s+.*$/u', '', (string) $name ) );
    return mb_strlen( $name ) > 26 ? rtrim( mb_substr( $name, 0, 25 ) ) . '…' : $name;
}

/**
 * Картинка каждой позиции (2.9.0): у заказа из двух футболок с разными
 * принтами в превью должно быть видно оба. Приоритет внутри позиции тот
 * же, что у превью карточки: макет → артворк → фото товара.
 *
 * @param array    $data  Данные заказа.
 * @param WC_Order $order Заказ.
 * @param int      $max   Предел.
 * @return array[] [ url, source, name, qty ].
 */
function ptg_app_item_previews( $data, $order, $max = 4 ) {
    $out  = array();
    $seen = array();
    $wc   = $order->get_items();

    foreach ( $data['items'] as $it ) {
        $pick = null;
        foreach ( array( 'preview', 'artwork' ) as $role ) {
            foreach ( (array) $it['links'] as $l ) {
                if ( $l['role'] === $role && ptg_is_image_url( $l['url'] ) ) {
                    $pick = array( 'url' => ptg_preview_variant( (string) $l['url'] ), 'source' => $role );
                    break 2;
                }
            }
        }
        if ( ! $pick && get_option( 'ptg_preview_product_fallback', 1 ) && isset( $wc[ $it['item_id'] ] ) ) {
            $product = $wc[ $it['item_id'] ]->get_product();
            $img     = $product ? (int) $product->get_image_id() : 0;
            $url     = $img ? (string) wp_get_attachment_image_url( $img, 'medium' ) : '';
            if ( $url ) {
                $pick = array( 'url' => $url, 'source' => 'product' );
            }
        }
        if ( ! $pick ) {
            continue;
        }
        if ( isset( $seen[ $pick['url'] ] ) ) {
            $out[ $seen[ $pick['url'] ] ]['qty'] += max( 1, (int) $it['qty'] );
            continue;
        }
        $seen[ $pick['url'] ] = count( $out );
        $out[] = array(
            'url'    => esc_url_raw( $pick['url'] ),
            'source' => $pick['source'],
            'name'   => (string) $it['name'],
            'qty'    => max( 1, (int) $it['qty'] ),
        );
        if ( count( $out ) >= $max ) {
            break;
        }
    }
    return $out;
}

/**
 * Кнопка следующего шага для строки списка (2.9.0): подсказка по фото,
 * иначе первый следующий статус.
 *
 * @param WC_Order $o       Заказ.
 * @param bool     $service Услуга.
 * @return array|null [ key, label, hint ].
 */
function ptg_app_quick( $o, $service ) {
    unset( $service ); // 2.18.0: у заявок тот же цикл, что у заказов
    $hint = ptg_app_hint( $o );
    if ( $hint ) {
        return array( 'key' => $hint['to'], 'label' => $hint['label'], 'hint' => true );
    }
    $next = (array) ptg_order_status_info( $o )['next'];
    if ( ! $next ) {
        return null;
    }
    $defs = ptg_statuses();
    $key  = $next[0];
    return array(
        'key'   => $key,
        'label' => isset( $defs[ $key ] ) ? $defs[ $key ]['emoji'] . ' ' . $defs[ $key ]['label'] : $key,
        'hint'  => false,
    );
}

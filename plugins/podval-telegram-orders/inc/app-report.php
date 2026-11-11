<?php
/**
 * Фаза 5, 2.10.0. Мини-отчёты в приложении.
 *
 * Ориентировочные цифры — доли и динамика, не касса:
 * - выручка = сумма выданных заказов (get_total), по дате выдачи;
 * - способ оплаты — тот, что выбран при оформлении (в ~90% совпадает с
 *   фактическим, так решено осознанно);
 * - отказы и «не забрали» — по дате перехода в этот статус.
 *
 * Дата перехода — `_ptg_status_at` (пишется с 2.6.0), поэтому отчёты
 * покрывают время работы в приложении, а не всю историю магазина.
 *
 * @package podval-telegram-orders
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', function () {
    register_rest_route( PTG_REST_NS, '/app/report', array(
        'methods'             => 'GET',
        'permission_callback' => 'ptg_app_permission',
        'callback'            => 'ptg_app_rest_report',
    ) );
} );

/**
 * Категории оплаты для отчёта.
 *
 * @return array key => label.
 */
function ptg_payment_categories() {
    return array(
        'cash'     => 'Наличные',
        'terminal' => 'Карта через терминал',
        'online'   => 'Онлайн при заказе',
        'link'     => 'По ссылке',
        'agreed'   => 'После согласования (способ не отмечен)',
        'other'    => 'Другое',
    );
}

/**
 * Категория оплаты заказа — по названию способа с чекаута.
 *
 * Сопоставление по словам, а не по ID шлюза: ID у самописных способов
 * произвольные, а названия («Оплата наличными», «Оплата картой через
 * терминал», «Оплата онлайн», «Оплата после согласования») стабильны.
 * Фильтр ptg_payment_category позволяет поправить без правки кода.
 *
 * @param string $method_id Идентификатор способа.
 * @param string $title     Название способа.
 * @return string Ключ категории.
 */
function ptg_payment_category( $method_id, $title ) {
    // Заказы, оформленные из приложения, сразу несут наш ключ.
    if ( 0 === strpos( $method_id, 'ptg_' ) ) {
        $key = substr( $method_id, 4 );
        if ( isset( ptg_payment_categories()[ $key ] ) ) {
            return $key;
        }
    }

    $t   = mb_strtolower( $title . ' ' . $method_id );
    $cat = 'other';
    if ( false !== mb_strpos( $t, 'согласован' ) ) {
        // 2.17.0: это не способ, а «договоримся». Фактический способ —
        // ручная отметка, см. ptg_order_payment_category().
        $cat = 'agreed';
    } elseif ( false !== mb_strpos( $t, 'терминал' ) ) {
        $cat = 'terminal';
    } elseif ( false !== mb_strpos( $t, 'налич' ) || 'cod' === $method_id ) {
        $cat = 'cash';
    } elseif ( preg_match( '/онлайн|online|alfa|альфа|bepaid|webpay|assist|card/u', $t ) ) {
        $cat = 'online';
    }
    return (string) apply_filters( 'ptg_payment_category', $cat, $method_id, $title );
}

/**
 * Категория оплаты конкретного заказа: для «после согласования» —
 * ручная отметка, если она есть.
 *
 * @param WC_Order $order Заказ.
 * @return string
 */
function ptg_order_payment_category( $order ) {
    if ( function_exists( 'ptg_payment_is_agreed' ) && ptg_payment_is_agreed( $order ) ) {
        $mark = ptg_pay_mark( $order );
        return $mark ? $mark : 'agreed';
    }
    return ptg_payment_category( (string) $order->get_payment_method(), (string) $order->get_payment_method_title() );
}

/**
 * Границы периода в часовом поясе сайта.
 *
 * @param string $from Y-m-d.
 * @param string $to   Y-m-d (включительно).
 * @return array [ from_ts, to_ts (не включая), days ] или null.
 */
function ptg_report_range( $from, $to ) {
    $tz = wp_timezone();
    $a  = date_create_immutable( $from . ' 00:00:00', $tz );
    $b  = date_create_immutable( $to . ' 00:00:00', $tz );
    if ( ! $a || ! $b || $b < $a ) {
        return null;
    }
    $b    = $b->modify( '+1 day' );
    $days = (int) round( ( $b->getTimestamp() - $a->getTimestamp() ) / DAY_IN_SECONDS );
    if ( $days > 400 ) {
        return null;
    }
    return array( $a->getTimestamp(), $b->getTimestamp(), $days );
}

/**
 * Заказы статуса, перешедшие в него в периоде.
 *
 * @param string $status Статус.
 * @param int    $from   Начало.
 * @param int    $to     Конец (не включая).
 * @return WC_Order[]
 */
function ptg_report_orders( $status, $from, $to ) {
    $orders = wc_get_orders( array(
        'status'     => array( $status ),
        'limit'      => -1,
        'type'       => 'shop_order',
        'meta_query' => array( array(
            'key'     => PTG_META_STATUS_AT,
            'value'   => array( $from, $to - 1 ),
            'compare' => 'BETWEEN',
            'type'    => 'NUMERIC',
        ) ),
    ) );
    return array_values( array_filter( (array) $orders, function ( $o ) use ( $from, $to ) {
        $t = (int) $o->get_meta( PTG_META_STATUS_AT );
        return $t >= $from && $t < $to;
    } ) );
}

/**
 * Считает отчёт за период. Отдельно от REST — для тестов.
 *
 * @param int $from Начало (unix).
 * @param int $to   Конец (unix, не включая).
 * @return array
 */
function ptg_report_build( $from, $to ) {
    $issued  = ptg_report_orders( 'issued', $from, $to );
    $refused = ptg_report_orders( 'refused', $from, $to );
    $notpick = ptg_report_orders( 'not-picked', $from, $to );

    $cats = array();
    foreach ( ptg_payment_categories() as $k => $label ) {
        $cats[ $k ] = array( 'key' => $k, 'label' => $label, 'count' => 0, 'sum' => 0.0, 'titles' => array() );
    }

    $sum    = 0.0;
    $items  = 0;
    $pickup = 0;
    foreach ( $issued as $o ) {
        $total = (float) $o->get_total();
        $sum  += $total;
        foreach ( $o->get_items() as $it ) {
            $items += max( 1, (int) $it->get_quantity() );
        }
        $title = (string) $o->get_payment_method_title();
        $cat   = ptg_order_payment_category( $o );
        if ( ! isset( $cats[ $cat ] ) ) {
            $cat = 'other';
        }
        $cats[ $cat ]['count']++;
        $cats[ $cat ]['sum'] += $total;
        if ( '' !== $title ) {
            $cats[ $cat ]['titles'][ $title ] = true;
        }
        if ( function_exists( 'ptg_order_shipping' ) && ! empty( ptg_order_shipping( $o )['is_pickup'] ) ) {
            $pickup++;
        }
    }

    $payments = array();
    foreach ( $cats as $c ) {
        if ( ! $c['count'] ) {
            continue;
        }
        $c['share']  = $sum > 0 ? round( $c['sum'] / $sum * 100, 1 ) : 0;
        $c['titles'] = array_keys( $c['titles'] );
        $c['sum']    = round( $c['sum'], 2 );
        $payments[]  = $c;
    }

    $n      = count( $issued );
    $closed = $n + count( $refused );

    return array(
        'issued'     => $n,
        'revenue'    => round( $sum, 2 ),
        'avg'        => $n ? round( $sum / $n, 2 ) : 0,
        'items'      => $items,
        'pickup'     => $pickup,
        'refused'    => count( $refused ),
        'refused_share' => $closed ? round( count( $refused ) / $closed * 100, 1 ) : 0,
        'not_picked' => count( $notpick ),
        'payments'   => $payments,
        'series'     => ptg_report_series( $issued, $from, $to ),
    );
}

/**
 * Динамика: по дням до двух месяцев, дальше по неделям.
 *
 * @param WC_Order[] $issued Выданные.
 * @param int        $from   Начало.
 * @param int        $to     Конец.
 * @return array[] [ label, from, count, sum ].
 */
function ptg_report_series( $issued, $from, $to ) {
    $tz    = wp_timezone();
    $days  = (int) round( ( $to - $from ) / DAY_IN_SECONDS );
    $step  = $days > 62 ? '+1 week' : '+1 day';
    $out   = array();
    $cur   = ( new DateTimeImmutable( '@' . $from ) )->setTimezone( $tz );
    $limit = 120;

    while ( $cur->getTimestamp() < $to && $limit-- > 0 ) {
        $next  = $cur->modify( $step );
        $out[] = array(
            'label' => wp_date( 'd.m', $cur->getTimestamp(), $tz ),
            'from'  => $cur->getTimestamp(),
            'to'    => min( $next->getTimestamp(), $to ),
            'count' => 0,
            'sum'   => 0.0,
        );
        $cur = $next;
    }
    foreach ( $issued as $o ) {
        $t = (int) $o->get_meta( PTG_META_STATUS_AT );
        foreach ( $out as &$b ) {
            if ( $t >= $b['from'] && $t < $b['to'] ) {
                $b['count']++;
                $b['sum'] += (float) $o->get_total();
                break;
            }
        }
        unset( $b );
    }
    foreach ( $out as &$b ) {
        $b['sum'] = round( $b['sum'], 2 );
        unset( $b['to'] );
    }
    unset( $b );
    return $out;
}

/**
 * GET app/report?from=Y-m-d&to=Y-m-d
 *
 * @param WP_REST_Request $request Запрос.
 * @return WP_REST_Response|WP_Error
 */
function ptg_app_rest_report( $request ) {
    $from = (string) $request->get_param( 'from' );
    $to   = (string) $request->get_param( 'to' );
    $re   = '/^\d{4}-\d{2}-\d{2}$/';
    if ( ! preg_match( $re, $from ) || ! preg_match( $re, $to ) ) {
        return new WP_Error( 'ptg_bad_period', 'Период задан неверно', array( 'status' => 400 ) );
    }
    $range = ptg_report_range( $from, $to );
    if ( ! $range ) {
        return new WP_Error( 'ptg_bad_period', 'Период задан неверно (не больше 400 дней)', array( 'status' => 400 ) );
    }
    list( $a, $b, $days ) = $range;

    $cur  = ptg_report_build( $a, $b );
    $prev = ptg_report_build( $a - ( $b - $a ), $a );

    return ptg_app_response( array(
        'from'   => $from,
        'to'     => $to,
        'days'   => $days,
        'since'  => ptg_app_since(),
        'cur'    => $cur,
        'prev'   => array(
            'issued'  => $prev['issued'],
            'revenue' => $prev['revenue'],
            'avg'     => $prev['avg'],
            'refused' => $prev['refused'],
        ),
    ) );
}

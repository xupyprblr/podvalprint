<?php
/**
 * Фаза 1. Оверрайды параметров изделия.
 *
 * Позиции заказа, цены и вариации не трогаем: учёта остатков через
 * WooCommerce нет, а перед нанесением есть обязательная сверка с заказом
 * в Telegram — значит расхождение внутри WooCommerce терпимо, а вот
 * переписывание позиций задним числом ломает и цену, и историю.
 *
 * Вместо правки — слой поправок поверх заказа.
 *
 * @package podval-telegram-orders
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Мета-ключ со списком поправок. */
const PTG_OVERRIDES_META = '_podval_overrides';

/** Мета-флаг «макет требует перерисовки». */
const PTG_MOCKUP_STALE_META = '_podval_mockup_stale';

/**
 * Список поправок заказа.
 *
 * @param WC_Order $order Заказ.
 * @return array
 */
function ptg_get_overrides( $order ) {
    $list = $order->get_meta( PTG_OVERRIDES_META );
    return is_array( $list ) ? $list : array();
}

/**
 * Записывает поправку.
 *
 * Повторное изменение того же поля перезаписывает запись, `from` берётся
 * из самой первой: иначе баннер превращается в лог правок.
 *
 * @param WC_Order $order    Заказ.
 * @param int      $item_id  Позиция.
 * @param string   $field    type|size|color|side.
 * @param string   $to_slug  Новое значение (слаг).
 * @param int      $by       Telegram user_id.
 * @param string   $by_name  Имя нажавшего.
 * @return array|WP_Error Запись поправки.
 */
function ptg_set_override( $order, $item_id, $field, $to_slug, $by = 0, $by_name = '' ) {
    $labels = ptg_garment_labels();
    if ( ! isset( $labels[ $field ] ) ) {
        return new WP_Error( 'ptg_bad_field', 'Неизвестное поле' );
    }

    $options = ptg_garment_value_options( $field );
    if ( $options && ! isset( $options[ $to_slug ] ) ) {
        return new WP_Error( 'ptg_bad_value', 'Неизвестное значение' );
    }

    $item = $order->get_item( $item_id );
    if ( ! $item ) {
        return new WP_Error( 'ptg_no_item', 'Позиция не найдена' );
    }

    $row     = ptg_order_item_data( (int) $item_id, $item );
    $current = ptg_garment_current( $row, $field );

    $list = ptg_get_overrides( $order );
    $from = $current['slug'];

    // Первое зафиксированное «было» переживает все последующие правки.
    foreach ( $list as $rec ) {
        if ( (int) $rec['item_id'] === (int) $item_id && $rec['field'] === $field ) {
            $from = (string) $rec['from'];
            break;
        }
    }

    // Вернулись к исходному значению — поправка не нужна.
    if ( $from === $to_slug ) {
        ptg_clear_override( $order, $item_id, $field );
        return array(
            'item_id' => (int) $item_id,
            'field'   => $field,
            'from'    => $from,
            'to'      => $to_slug,
            'cleared' => true,
        );
    }

    $record = array(
        'item_id' => (int) $item_id,
        'field'   => $field,
        'from'    => $from,
        // Подпись «было» на случай, когда слаг распознать не удалось:
        // у вариации в мете может лежать значение, которого нет в
        // справочнике темы, и без этого баннер покажет пустое место.
        'from_raw' => ( '' === $from ) ? $current['label'] : '',
        'to'      => (string) $to_slug,
        'by'      => (int) $by,
        'by_name' => (string) $by_name,
        'at'      => current_time( 'mysql' ),
    );

    $out     = array();
    $replaced = false;
    foreach ( $list as $rec ) {
        if ( (int) $rec['item_id'] === (int) $item_id && $rec['field'] === $field ) {
            $out[]    = $record;
            $replaced = true;
            continue;
        }
        $out[] = $rec;
    }
    if ( ! $replaced ) {
        $out[] = $record;
    }

    $order->update_meta_data( PTG_OVERRIDES_META, $out );

    // Смена цвета обесценивает собранный макет: inkColor() в tpl-builder.js
    // выбирает полярность краски по цвету футболки, и после смены принт
    // становится нечитаемым. Размер/тип/сторона на сам файл не влияют.
    if ( 'color' === $field ) {
        $order->update_meta_data( PTG_MOCKUP_STALE_META, '1' );
    }

    $order->save();

    $from_label = ( '' === $from ) ? ( $current['label'] ?: '—' ) : ptg_garment_value_label( $field, $from );
    $to_label   = ptg_garment_value_label( $field, $to_slug );
    ptg_add_note( $order, sprintf(
        'Параметр изменён: %s %s → %s (%s, Telegram)',
        $labels[ $field ],
        $from_label,
        $to_label,
        $by_name ? $by_name : ( 'ID ' . $by )
    ) );

    ptg_log( 'info', 'Оверрайд записан', array(
        'order' => $order->get_id(),
        'item'  => (int) $item_id,
        'field' => $field,
        'from'  => $from,
        'to'    => $to_slug,
        'by'    => (int) $by,
    ) );

    return $record;
}

/**
 * Снимает поправку по полю.
 *
 * @param WC_Order $order   Заказ.
 * @param int      $item_id Позиция.
 * @param string   $field   Поле.
 * @return bool Была ли поправка.
 */
function ptg_clear_override( $order, $item_id, $field ) {
    $list  = ptg_get_overrides( $order );
    $out   = array();
    $found = false;

    foreach ( $list as $rec ) {
        if ( (int) $rec['item_id'] === (int) $item_id && $rec['field'] === $field ) {
            $found = true;
            continue;
        }
        $out[] = $rec;
    }

    if ( ! $found ) {
        return false;
    }

    $order->update_meta_data( PTG_OVERRIDES_META, $out );

    // Флаг перерисовки снимается только если не осталось ни одной
    // поправки по цвету — при двух позициях вторая могла его тоже ставить.
    if ( 'color' === $field ) {
        $has_color = false;
        foreach ( $out as $rec ) {
            if ( 'color' === $rec['field'] ) {
                $has_color = true;
                break;
            }
        }
        if ( ! $has_color ) {
            $order->delete_meta_data( PTG_MOCKUP_STALE_META );
        }
    }

    $order->save();

    $labels = ptg_garment_labels();
    ptg_add_note( $order, sprintf(
        'Изменение параметра отменено: %s (Telegram)',
        $labels[ $field ] ?? $field
    ) );

    return true;
}

/**
 * Текущее значение параметра позиции (до поправок).
 *
 * @param array  $row   Строка позиции из ptg_order_item_data().
 * @param string $field Поле.
 * @return array [ slug, label ].
 */
function ptg_garment_current( $row, $field ) {
    foreach ( (array) $row['garment'] as $g ) {
        if ( $g['key'] === $field ) {
            return array( 'slug' => (string) $g['slug'], 'label' => (string) $g['value'] );
        }
    }
    return array( 'slug' => '', 'label' => '—' );
}

/**
 * Накладывает поправки на данные заказа.
 *
 * Поправки на удалённые позиции отбрасываются: заказ могли отредактировать
 * в админке, и запись повиснет в пустоту.
 *
 * @param WC_Order $order Заказ.
 * @param array    $data  Данные заказа.
 * @return array
 */
function ptg_apply_overrides( $order, $data ) {
    $list = ptg_get_overrides( $order );
    if ( empty( $list ) ) {
        $data['flags']['mockup_stale'] = false;
        return $data;
    }

    $labels     = ptg_garment_labels();
    $item_index = array();
    foreach ( $data['items'] as $i => $item ) {
        $item_index[ (int) $item['item_id'] ] = $i;
    }

    $applied = array();
    $alive   = array();

    // Порядок строк баннера задаётся порядком полей в ptg_garment_labels(),
    // а не временем правки: иначе одинаковые заказы выглядят по-разному.
    $field_order = array_keys( $labels );
    usort( $list, function ( $a, $b ) use ( $field_order ) {
        $ia = array_search( $a['field'], $field_order, true );
        $ib = array_search( $b['field'], $field_order, true );
        if ( $a['item_id'] !== $b['item_id'] ) {
            return (int) $a['item_id'] <=> (int) $b['item_id'];
        }
        return (int) $ia <=> (int) $ib;
    } );

    foreach ( $list as $rec ) {
        $item_id = (int) $rec['item_id'];
        if ( ! isset( $item_index[ $item_id ] ) ) {
            continue;
        }
        $alive[] = $rec;

        $idx   = $item_index[ $item_id ];
        $field = (string) $rec['field'];
        if ( ! isset( $labels[ $field ] ) ) {
            continue;
        }

        $from_label = ( '' === (string) $rec['from'] )
            ? ( (string) ( $rec['from_raw'] ?? '' ) ?: '—' )
            : ptg_garment_value_label( $field, $rec['from'] );
        $to_label   = ptg_garment_value_label( $field, $rec['to'] );

        // Правим строку garment в теле позиции.
        $found = false;
        foreach ( $data['items'][ $idx ]['garment'] as $gi => $g ) {
            if ( $g['key'] !== $field ) {
                continue;
            }
            $found = true;
            $data['items'][ $idx ]['garment'][ $gi ]['from_value']  = $g['value'];
            $data['items'][ $idx ]['garment'][ $gi ]['value']       = $to_label;
            $data['items'][ $idx ]['garment'][ $gi ]['slug']        = (string) $rec['to'];
            $data['items'][ $idx ]['garment'][ $gi ]['overridden']  = true;
            $data['items'][ $idx ]['garment'][ $gi ]['emoji']       = ( 'color' === $field ) ? ptg_color_emoji( $to_label ) : '';
        }
        if ( ! $found ) {
            // Параметра не было в позиции вовсе — показываем как добавленный.
            $data['items'][ $idx ]['garment'][] = array(
                'key'        => $field,
                'label'      => $labels[ $field ],
                'value'      => $to_label,
                'slug'       => (string) $rec['to'],
                'emoji'      => ( 'color' === $field ) ? ptg_color_emoji( $to_label ) : '',
                'from_value' => $from_label,
                'overridden' => true,
            );
        }

        $applied[] = array(
            'item_id'    => $item_id,
            'item_name'  => $data['items'][ $idx ]['name'],
            'field'      => $field,
            'label'      => $labels[ $field ],
            'from'       => (string) $rec['from'],
            'to'         => (string) $rec['to'],
            'from_label' => $from_label,
            'to_label'   => $to_label,
            'by_name'    => (string) ( $rec['by_name'] ?? '' ),
            'at'         => (string) ( $rec['at'] ?? '' ),
            'delta'      => ptg_override_price_delta( $data['items'][ $idx ], $field, (string) $rec['to'] ),
        );
    }

    // Подчистка «мёртвых» записей, чтобы они не копились.
    if ( count( $alive ) !== count( $list ) ) {
        $order->update_meta_data( PTG_OVERRIDES_META, $alive );
        $order->save();
    }

    $data['overrides']             = $applied;
    $data['flags']['mockup_stale'] = ( '1' === (string) $order->get_meta( PTG_MOCKUP_STALE_META ) );
    $data['flags']['multi_item']   = ( count( $data['items'] ) > 1 );

    return $data;
}

/**
 * Дельта цены от смены параметра.
 *
 * Только для позиций конструктора: у вариаций цена сидит в самой вариации,
 * а мы её не ищем и не меняем. Ничего не пересчитывается автоматически —
 * решение о доплате принимает человек на выдаче.
 *
 * @param array  $item_row Строка позиции.
 * @param string $field    Поле.
 * @param string $to_slug  Новое значение.
 * @return float|null Разница в Br или null.
 */
function ptg_override_price_delta( $item_row, $field, $to_slug ) {
    if ( 'tpl' !== $item_row['kind'] || empty( $item_row['tpl'] ) ) {
        return null;
    }
    if ( ! function_exists( 'podval_tpl_calc_addons' ) ) {
        return null;
    }

    $before = $item_row['tpl'];
    if ( ! isset( $before['garment'] ) || ! is_array( $before['garment'] ) ) {
        return null;
    }

    $after                      = $before;
    $after['garment'][ $field ] = $to_slug;

    $sum = function ( $rows ) {
        $t = 0.0;
        foreach ( (array) $rows as $r ) {
            $t += (float) ( $r['amount'] ?? 0 );
        }
        return $t;
    };

    $delta = $sum( podval_tpl_calc_addons( $after ) ) - $sum( podval_tpl_calc_addons( $before ) );

    return ( abs( $delta ) < 0.01 ) ? null : $delta;
}

/**
 * Позиции, для которых есть смысл показывать кнопки.
 *
 * Бандлы исключены: там параметры лежат внутри каждой футболки состава,
 * это отдельная структура и отдельная задача (Фаза 3).
 *
 * @param array $data Данные заказа.
 * @return array Список строк позиций.
 */
function ptg_overridable_items( $data ) {
    $out = array();
    foreach ( $data['items'] as $item ) {
        if ( 'bundle' === $item['kind'] ) {
            continue;
        }
        $out[] = $item;
    }
    return $out;
}

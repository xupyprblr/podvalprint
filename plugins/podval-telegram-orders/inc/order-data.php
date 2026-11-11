<?php
/**
 * Заказ → нормализованный массив.
 *
 * Отделено от рендера, потому что одни и те же данные нужны трём
 * потребителям: сообщению в Telegram, баннеру в админке и (Фаза 5)
 * Mini App. Пока это была одна функция на 300 строк, собирающая
 * готовую строку, достать из неё структуру было нельзя.
 *
 * @package podval-telegram-orders
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Подписи параметров изделия. Одни и те же для конструктора и вариаций:
 * менеджер читает ленту сплошняком, и одинаковые заказы должны выглядеть
 * одинаково независимо от источника данных.
 *
 * Порядок ключей здесь же задаёт порядок строк в сообщении и в баннере.
 *
 * @return array
 */
function ptg_garment_labels() {
    return apply_filters( 'ptg_garment_labels', array(
        'type'  => 'Модель',
        'size'  => 'Размер',
        'color' => 'Цвет',
        'side'  => 'Печать',
    ) );
}

/**
 * Карта «как поле может называться у вариации» → машинный ключ.
 *
 * Логика сравнения подсмотрена в podval_pf_get_attr() из print-filenames.php:
 * кастомные атрибуты лежат у позиции с кириллическим urlencoded ключом
 * (attribute_%d0%9c%d0%be%d0%b4%d0%b5%d0%bb%d1%8c), таксономические — как
 * attribute_pa_size.
 *
 * @return array
 */
function ptg_garment_label_map() {
    return apply_filters( 'ptg_garment_label_map', array(
        'type'  => array( 'модель', 'тип', 'фасон', 'model', 'type', 'size-type' ),
        'size'  => array( 'размер', 'size' ),
        'color' => array( 'цвет', 'color', 'colour' ),
        'side'  => array( 'где печатаем?', 'где печатаем', 'печать', 'сторона', 'side' ),
    ) );
}

/**
 * Приводит ключ меты позиции к сравнимому виду.
 *
 * @param string $key Ключ меты.
 * @return string
 */
function ptg_normalize_attr_key( $key ) {
    $key = str_replace( array( 'attribute_', 'pa_' ), '', (string) $key );
    $key = urldecode( $key );
    $key = mb_strtolower( $key, 'UTF-8' );
    return str_replace( 'ё', 'е', trim( $key ) );
}

/**
 * Машинный ключ параметра изделия по названию атрибута, или '' если это
 * не параметр изделия.
 *
 * @param string $raw_key Ключ меты позиции.
 * @return string type|size|color|side|''
 */
function ptg_garment_key_from_attr( $raw_key ) {
    $norm = ptg_normalize_attr_key( $raw_key );
    foreach ( ptg_garment_label_map() as $key => $candidates ) {
        foreach ( $candidates as $candidate ) {
            $c = str_replace( 'ё', 'е', mb_strtolower( $candidate, 'UTF-8' ) );
            if ( $norm === $c ) {
                return $key;
            }
        }
    }
    return '';
}

/**
 * Эмодзи-квадрат перед названием цвета.
 *
 * Сравнение по основе слова: у вариаций цвет мужского рода («Чёрный»),
 * у конструктора женского («Чёрная»). Ё приводится к Е.
 *
 * @param string $color_value Значение цвета.
 * @return string Эмодзи с пробелом или пустая строка.
 */
function ptg_color_emoji( $color_value ) {
    $v = mb_strtolower( (string) $color_value );
    $v = str_replace( 'ё', 'е', $v );

    if ( 0 === mb_strpos( $v, 'бел' ) || 0 === mb_strpos( $v, 'white' ) ) {
        return '⬜️ ';
    }
    if ( 0 === mb_strpos( $v, 'черн' ) || 0 === mb_strpos( $v, 'black' ) ) {
        return '⬛️ ';
    }
    return '';
}

/**
 * Plural-form для русского.
 *
 * @param int    $n    Число.
 * @param string $one  Форма для 1.
 * @param string $few  Форма для 2-4.
 * @param string $many Форма для 5+.
 * @return string
 */
function ptg_ru_plural( $n, $one, $few, $many ) {
    $n10  = $n % 10;
    $n100 = $n % 100;
    if ( $n100 >= 11 && $n100 <= 14 ) {
        return $many;
    }
    if ( 1 === $n10 ) {
        return $one;
    }
    if ( $n10 >= 2 && $n10 <= 4 ) {
        return $few;
    }
    return $many;
}

/**
 * Справочник значений параметра изделия: slug => подпись.
 *
 * Источник — тема. Без темы возвращаем пустой список: кнопок значений
 * не будет, но сообщение соберётся.
 *
 * @param string $field type|size|color|side.
 * @return array
 */
function ptg_garment_value_options( $field ) {
    if ( ! function_exists( 'podval_tpl_garment_options' ) ) {
        return array();
    }
    $defs = podval_tpl_garment_options();
    if ( empty( $defs[ $field ]['options'] ) ) {
        return array();
    }
    $out = array();
    foreach ( $defs[ $field ]['options'] as $slug => $def ) {
        $out[ (string) $slug ] = isset( $def['label'] ) ? (string) $def['label'] : (string) $slug;
    }
    return $out;
}

/**
 * Подпись значения по слагу.
 *
 * @param string $field Поле.
 * @param string $slug  Слаг.
 * @return string
 */
function ptg_garment_value_label( $field, $slug ) {
    $opts = ptg_garment_value_options( $field );
    return isset( $opts[ $slug ] ) ? $opts[ $slug ] : (string) $slug;
}

/**
 * Обратный поиск: подпись значения → слаг.
 *
 * Нужен для позиций-вариаций: там в мете лежит человекочитаемое «Белая»
 * или «L», а кнопкам и оверрайдам нужен ASCII-слаг.
 *
 * @param string $field Поле.
 * @param string $label Подпись из меты.
 * @return string Слаг или ''.
 */
function ptg_garment_slug_from_label( $field, $label ) {
    $needle = str_replace( 'ё', 'е', mb_strtolower( trim( (string) $label ), 'UTF-8' ) );
    if ( '' === $needle ) {
        return '';
    }

    $opts = ptg_garment_value_options( $field );

    // Точное совпадение подписи.
    foreach ( $opts as $slug => $opt_label ) {
        $l = str_replace( 'ё', 'е', mb_strtolower( $opt_label, 'UTF-8' ) );
        if ( $l === $needle ) {
            return (string) $slug;
        }
    }

    // Совпадение со слагом (размеры приходят как «L», «2XL»).
    foreach ( $opts as $slug => $opt_label ) {
        if ( mb_strtolower( (string) $slug, 'UTF-8' ) === $needle ) {
            return (string) $slug;
        }
    }

    // По основе слова: «Чёрный» (вариация) против «Чёрная» (конструктор).
    $stems = array(
        'color' => array( 'бел' => 'white', 'черн' => 'black', 'white' => 'white', 'black' => 'black' ),
        'type'  => array( 'классическ' => 'classic', 'classic' => 'classic', 'оверсайз' => 'oversize', 'oversize' => 'oversize' ),
        'side'  => array( 'перед' => 'front', 'спереди' => 'front', 'front' => 'front', 'сзади' => 'back', 'спин' => 'back', 'back' => 'back' ),
    );
    if ( isset( $stems[ $field ] ) ) {
        foreach ( $stems[ $field ] as $stem => $slug ) {
            if ( 0 === mb_strpos( $needle, $stem ) && isset( $opts[ $slug ] ) ) {
                return $slug;
            }
        }
    }

    return '';
}

/**
 * Корректный URL редактирования заказа (HPOS-совместимый).
 *
 * @param WC_Order|int $order Заказ.
 * @return string
 */
function ptg_get_order_admin_edit_url( $order ) {
    if ( is_numeric( $order ) ) {
        $order = wc_get_order( $order );
    }
    if ( ! $order instanceof WC_Order ) {
        return '';
    }
    if ( method_exists( $order, 'get_edit_order_url' ) ) {
        return $order->get_edit_order_url();
    }
    return admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' );
}

/**
 * Главная функция: заказ → массив.
 *
 * @param WC_Order $order Заказ.
 * @return array
 */
function ptg_order_data( $order ) {
    $order_type = $order->get_meta( '_order_type' );
    $is_service = ( 'service' === $order_type );

    $date_obj = $order->get_date_created();
    $created  = $date_obj ? gmdate( 'd.m.Y H:i', $date_obj->getTimestamp() + 3 * 3600 ) : '';

    $data = array(
        'id'        => $order->get_id(),
        'number'    => $order->get_order_number(),
        'type'      => $is_service ? 'service' : 'order',
        'status'    => $order->get_status(),
        'created'   => $created,
        'admin_url' => ptg_get_order_admin_edit_url( $order ),
        'customer'  => ptg_order_customer( $order ),
        'shipping'  => ptg_order_shipping( $order ),
        'payment'   => array_merge(
            array(
                'title' => $order->get_payment_method_title(),
                'total' => $order->get_total(),
            ),
            ptg_payment_state( $order )
        ),
        'note'      => $order->get_customer_note(),
        'items'     => array(),
        'files'     => array(),
        'flags'     => array(
            'awaiting_photos' => false,
            'tpl_missing'     => ! function_exists( 'podval_tpl_summary' ),
        ),
        'overrides' => array(),
        'client'    => ptg_order_client( $order ),
        'assignee'  => ptg_order_assignee( $order ),
        'greeting'  => ptg_order_greeting( $order ),
        'status_info' => ptg_order_status_info( $order ),
        'verified'    => ptg_order_verified( $order ),
    );

    $shown_ids = array();
    foreach ( $order->get_items() as $item_id => $item ) {
        $row = ptg_order_item_data( (int) $item_id, $item );
        $data['items'][] = $row;

        if ( ! empty( $row['notices'] ) ) {
            foreach ( $row['notices'] as $n ) {
                if ( 'awaiting_photos' === ( $n['code'] ?? '' ) ) {
                    $data['flags']['awaiting_photos'] = true;
                }
            }
        }
        foreach ( $row['_shown_attachment_ids'] as $aid ) {
            $shown_ids[] = (int) $aid;
        }
    }

    $data['files'] = ptg_order_checkout_files( $order, $shown_ids );

    // Оверрайды Фазы 1 накладываются последними: они меняют значения
    // garment и добавляют список правок для баннера.
    $data = ptg_apply_overrides( $order, $data );

    // Превью считается после оверрайдов: ссылки на файлы к этому моменту
    // окончательные.
    $data['preview'] = ptg_order_preview( $data, $order );

    return apply_filters( 'ptg_order_data', $data, $order );
}

/**
 * Привязка чата клиента (Фаза 2).
 *
 * @param WC_Order $order Заказ.
 * @return array
 */
function ptg_order_client( $order ) {
    $chat_id = (int) $order->get_meta( PTG_META_CLIENT_CHAT );
    $log     = $order->get_meta( PTG_META_CHAT_LOG );
    $log     = is_array( $log ) ? $log : array();

    $unread   = 0;
    $outgoing = 0;
    foreach ( $log as $entry ) {
        if ( ! empty( $entry['del'] ) ) {
            continue;
        }
        if ( 'in' === ( $entry['d'] ?? '' ) ) {
            $unread++;
        } else {
            $outgoing++;
        }
    }

    // Ник, указанный клиентом на чекауте. Нужен до привязки: по нему
    // строится ссылка «открыть личку».
    $messenger = $order->get_meta( '_billing_messenger' );
    if ( ! $messenger ) {
        $messenger = $order->get_meta( 'billing_messenger' );
    }
    if ( ! $messenger ) {
        $messenger = $order->get_meta( '_billing_telegram' );
    }

    return array(
        'linked'    => (bool) $chat_id,
        'checkout'  => ptg_normalize_username( $messenger ),
        'chat_id'   => $chat_id,
        'username'  => (string) $order->get_meta( PTG_META_CLIENT_USERNAME ),
        'name'      => (string) $order->get_meta( PTG_META_CLIENT_NAME ),
        'source'    => (string) $order->get_meta( PTG_META_CLIENT_SOURCE ),
        'messages'  => count( $log ),
        'files'     => count( ptg_chat_files( $order ) ),
        'incoming'  => $unread,
        'outgoing'  => $outgoing,
        'last'      => $log ? end( $log ) : null,
    );
}

/**
 * Ответственный за заказ (Фаза 2).
 *
 * @param WC_Order $order Заказ.
 * @return array
 */
function ptg_order_assignee( $order ) {
    $tg = (int) $order->get_meta( PTG_META_ASSIGNEE_TG );
    return array(
        'tg_id' => $tg,
        'name'  => (string) $order->get_meta( PTG_META_ASSIGNEE_NAME ),
        'at'    => (string) $order->get_meta( PTG_META_ASSIGNEE_AT ),
    );
}

/**
 * Данные клиента.
 *
 * @param WC_Order $order Заказ.
 * @return array
 */
function ptg_order_customer( $order ) {
    $messenger = $order->get_meta( '_billing_messenger' );
    if ( ! $messenger ) {
        $messenger = $order->get_meta( 'billing_messenger' );
    }
    if ( ! $messenger ) {
        $messenger = $order->get_meta( '_billing_telegram' );
    }

    return array(
        'name'      => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
        'phone'     => $order->get_billing_phone(),
        'email'     => $order->get_billing_email(),
        'messenger' => (string) $messenger,
    );
}

/**
 * Доставка.
 *
 * @param WC_Order $order Заказ.
 * @return array
 */
function ptg_order_shipping( $order ) {
    $label = $order->get_meta( '_podval_shipping_label' );
    if ( ! $label ) {
        foreach ( $order->get_shipping_methods() as $method ) {
            $label = $method->get_name();
            break;
        }
    }

    $is_pickup = false;
    if ( $label ) {
        $podval_method = $order->get_meta( '_podval_shipping_method' );
        $is_pickup    = ( 'pickup' === $podval_method || false !== stripos( $label, 'самовывоз' ) );
    }

    $address = '';
    if ( ! $is_pickup ) {
        $shipping_addr = $order->get_shipping_address_1();
        if ( $shipping_addr ) {
            $parts = array_filter( array(
                $shipping_addr,
                $order->get_shipping_city(),
                $order->get_shipping_state(),
                $order->get_shipping_postcode(),
            ) );
        } else {
            $parts = array_filter( array(
                $order->get_billing_address_1(),
                $order->get_billing_address_2(),
                $order->get_billing_city(),
                $order->get_billing_state(),
                $order->get_billing_postcode(),
            ) );
        }
        if ( $parts ) {
            $address = implode( ', ', $parts );
        }
    }

    return array(
        'label'     => (string) $label,
        'is_pickup' => $is_pickup,
        'address'   => $address,
    );
}

/**
 * Одна позиция заказа в единой форме.
 *
 * @param int                   $item_id ID позиции.
 * @param WC_Order_Item_Product $item    Позиция.
 * @return array
 */
function ptg_order_item_data( $item_id, $item ) {
    $row = array(
        'item_id'               => (int) $item_id,
        'kind'                  => 'simple',
        'name'                  => $item->get_name(),
        'qty'                   => (int) $item->get_quantity(),
        'subtotal'              => (int) $item->get_subtotal(),
        'garment'               => array(),
        'details'               => array(),
        'texts'                 => array(),
        'comment'               => '',
        'links'                 => array(),
        'notices'               => array(),
        'bundle'                => null,
        'tpl'                   => null,
        'artwork'               => false, // 2.9.0: позиция «Свой дизайн»
        '_shown_attachment_ids' => array(),
    );

    // ── Бандл ────────────────────────────────────────────────────────
    $bundle_meta = $item->get_meta( '_bundle_meta' );
    if ( $bundle_meta && is_array( $bundle_meta ) ) {
        $row['kind']   = 'bundle';
        $row['bundle'] = ptg_normalize_bundle( $bundle_meta );
        return $row;
    }

    $tpl_data = $item->get_meta( '_podval_tpl' );
    $is_tpl   = ( $tpl_data && is_array( $tpl_data ) );

    if ( $is_tpl ) {
        $row['kind'] = 'tpl';
        $row['tpl']  = $tpl_data;
        ptg_fill_tpl_item( $row, $tpl_data, $item );
    } else {
        ptg_fill_simple_item( $row, $item );
    }

    ptg_fill_artwork( $row, $item, $is_tpl );

    return $row;
}

/**
 * Позиция-вариация: параметры изделия из атрибутов.
 *
 * @param array                 $row  Строка позиции (по ссылке).
 * @param WC_Order_Item_Product $item Позиция.
 * @return void
 */
function ptg_fill_simple_item( &$row, $item ) {
    $garment = array();
    $details = array();

    foreach ( $item->get_meta_data() as $meta ) {
        $meta_row = $meta->get_data();
        $key      = (string) $meta_row['key'];

        if ( 0 === strpos( $key, '_' ) ) {
            continue;
        }

        $value = $meta_row['value'];
        if ( ! is_scalar( $value ) || '' === (string) $value ) {
            continue;
        }
        $value = (string) $value;

        // Slug таксономии → имя термина.
        $taxonomy = str_replace( 'attribute_', '', $key );
        if ( taxonomy_exists( $taxonomy ) ) {
            $term = get_term_by( 'slug', $value, $taxonomy );
            if ( $term ) {
                $value = $term->name;
            }
        }

        $label = wc_attribute_label( $key, $item->get_product() );

        // Сопоставляем и по ключу меты, и по подписи. Один ключ не
        // годится: у таксономических атрибутов он латинский слаг
        // (attribute_pa_print-side), и кириллический словарь по нему не
        // срабатывает — параметр уезжает в общий список атрибутов вместо
        // блока изделия.
        $gkey = ptg_garment_key_from_attr( $key );
        if ( ! $gkey && $label ) {
            $gkey = ptg_garment_key_from_attr( $label );
        }
        if ( $gkey ) {
            $garment[ $gkey ] = $value;
            continue;
        }

        if ( $label ) {
            $details[] = array( 'label' => $label, 'value' => $value );
        }
    }

    $row['garment'] = ptg_build_garment_rows( $garment, false );
    $row['details'] = array_merge( $row['details'], $details );
}

/**
 * Позиция конструктора: параметры изделия из _podval_tpl.
 *
 * @param array $row      Строка позиции (по ссылке).
 * @param array $tpl_data Мета _podval_tpl.
 * @return void
 */
function ptg_fill_tpl_item( &$row, $tpl_data, $item = null ) {
    $garment_slugs = array();
    foreach ( (array) ( $tpl_data['garment'] ?? array() ) as $field => $slug ) {
        $garment_slugs[ $field ] = (string) $slug;
    }
    $row['garment'] = ptg_build_garment_rows( $garment_slugs, true );

    if ( function_exists( 'podval_tpl_summary' ) ) {
        foreach ( podval_tpl_summary( $tpl_data ) as $s_row ) {
            $key = (string) ( $s_row['key'] ?? '' );
            if ( $key && 0 === strpos( $key, 'garment:' ) ) {
                continue; // Изделие выведено отдельным блоком.
            }
            $row['details'][] = array(
                'label' => (string) $s_row['label'],
                'value' => (string) $s_row['value'],
            );
        }
    } else {
        $row['details'][] = array( 'label' => 'Основа', 'value' => (string) ( $tpl_data['tpl_id'] ?? '—' ) );
        $row['details'][] = array( 'label' => 'Фото', 'value' => count( (array) ( $tpl_data['slots'] ?? array() ) ) . ' шт' );
    }

    // Фото обещаны позже — менеджеру писать клиенту, а не в печать.
    if ( ! empty( $tpl_data['photos_later'] ) ) {
        $have = 0;
        foreach ( (array) ( $tpl_data['slots'] ?? array() ) as $slot ) {
            if ( ! empty( $slot['attachment_id'] ) ) {
                $have++;
            }
        }
        $need = (int) ( $tpl_data['slots_needed'] ?? 0 );
        $row['notices'][] = array(
            'level' => 'warn',
            'code'  => 'awaiting_photos',
            'slot'  => 'tpl',
            'text'  => sprintf( '<b>Фото пришлёт позже</b> — загружено %d из %d, запросить в Telegram', $have, $need ),
        );
    }

    // Собранный макет.
    if ( ! empty( $tpl_data['preview_id'] ) ) {
        $pid = (int) $tpl_data['preview_id'];
        $url = wp_get_attachment_url( $pid );
        if ( $url ) {
            $row['links'][] = array( 'label' => 'Собранный макет', 'url' => $url, 'role' => 'preview' );
        }
        $row['_shown_attachment_ids'][] = $pid;
    }

    // 2.17.3: печатный файл 300 ppi (podval-theme 3.7.24+). ID берём из
    // меты позиции — после переименования по маске там ID копии с
    // номером заказа; в _podval_tpl лежит исходный. Роль своя (`print`):
    // в превью карточки и Mini App он не попадает (там preview/artwork),
    // а как фото Telegram его не принял бы — ~3500×5200 px.
    $print_id = $item ? (int) $item->get_meta( '_podval_tpl_print_file' ) : 0;
    if ( ! $print_id ) {
        $print_id = (int) ( $tpl_data['print_id'] ?? 0 );
    }
    if ( $print_id ) {
        $p_url = wp_get_attachment_url( $print_id );
        if ( $p_url ) {
            $label = 'Файл для печати';
            $ppi   = (int) get_post_meta( $print_id, '_podval_tpl_ppi', true );
            $meta  = wp_get_attachment_metadata( $print_id );
            if ( $ppi && ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
                $label .= sprintf(
                    ' %s×%s см, %d ppi',
                    rtrim( rtrim( number_format( $meta['width'] / $ppi * 2.54, 1, '.', '' ), '0' ), '.' ),
                    rtrim( rtrim( number_format( $meta['height'] / $ppi * 2.54, 1, '.', '' ), '0' ), '.' ),
                    $ppi
                );
            }
            $row['links'][] = array( 'label' => $label, 'url' => $p_url, 'role' => 'print' );
        }
        // Иначе он всплыл бы ещё раз в общем списке «файлы заказа».
        $row['_shown_attachment_ids'][] = $print_id;
    }

    // Исходники по слотам.
    $n = 0;
    foreach ( (array) ( $tpl_data['slots'] ?? array() ) as $slot ) {
        $fid = (int) ( $slot['attachment_id'] ?? 0 );
        if ( ! $fid ) {
            continue;
        }
        $row['_shown_attachment_ids'][] = $fid;
        $url = wp_get_attachment_url( $fid );
        if ( ! $url ) {
            continue;
        }
        $n++;
        $row['links'][] = array( 'label' => 'Фото ' . $n, 'url' => $url, 'role' => 'photo' );
    }
}

/**
 * Файлы и надписи artwork + схемы расположения.
 *
 * @param array                 $row    Строка позиции (по ссылке).
 * @param WC_Order_Item_Product $item   Позиция.
 * @param bool                  $is_tpl Позиция из конструктора.
 * @return void
 */
function ptg_fill_artwork( &$row, $item, $is_tpl ) {
    $slot_link_labels = array(
        'front'  => 'Принт на перед',
        'back'   => 'Принт на спину',
        'sleeve' => 'Принт на рукав',
    );
    $slot_text_labels = array(
        'front'  => 'Надпись на перед',
        'back'   => 'Надпись на спину',
        'sleeve' => 'Надпись на рукав',
    );
    $slot_placement_labels = array(
        'front'  => 'Расположение: перед',
        'back'   => 'Расположение: спина',
        'sleeve' => 'Расположение: рукав',
    );

    $aw_files   = $item->get_meta( '_artwork_files' );
    $aw_texts   = $item->get_meta( '_artwork_texts' );
    $aw_comment = $item->get_meta( '_artwork_comment' );
    $aw_skipped = $item->get_meta( '_artwork_skipped' );

    if ( ! $is_tpl && ( ! empty( $aw_files ) || ! empty( $aw_texts ) || $aw_skipped ) ) {
        $row['artwork'] = true;
    }

    if ( is_array( $aw_files ) && ! empty( $aw_files ) ) {
        foreach ( $aw_files as $slot => $att_id ) {
            $att_id = (int) $att_id;
            $row['_shown_attachment_ids'][] = $att_id;
            $url = wp_get_attachment_url( $att_id );
            if ( $url ) {
                $row['links'][] = array(
                    'label' => $slot_link_labels[ $slot ] ?? 'Файл',
                    'url'   => $url,
                    'role'  => 'artwork',
                );
            }
        }
    } elseif ( $aw_skipped && empty( $aw_texts ) ) {
        $row['notices'][] = array(
            'level' => 'warn',
            'code'  => 'awaiting_photos',
            'slot'  => 'artwork',
            'text'  => '<i>Файл будет отправлен позже</i>',
        );
    }

    if ( is_array( $aw_texts ) && ! empty( $aw_texts ) ) {
        foreach ( $aw_texts as $slot => $text_val ) {
            if ( ! $text_val ) {
                continue;
            }
            $row['texts'][] = array(
                'label' => $slot_text_labels[ $slot ] ?? 'Надпись',
                'value' => (string) $text_val,
            );
        }
    }

    if ( $aw_comment ) {
        $row['comment'] = (string) $aw_comment;
    }

    // Схемы расположения. У позиции конструктора схема — это и есть
    // собранный макет, ссылка на него уже есть выше; второй раз — дубль.
    $aw_extra = $item->get_meta( '_artwork_extra_files' );
    if ( is_array( $aw_extra ) && ! empty( $aw_extra ) ) {
        foreach ( $aw_extra as $ex_id ) {
            $ex_id = (int) $ex_id;
            $row['_shown_attachment_ids'][] = $ex_id;

            if ( $is_tpl ) {
                continue;
            }
            if ( '1' !== get_post_meta( $ex_id, '_podval_placement', true ) ) {
                continue;
            }
            $url = wp_get_attachment_url( $ex_id );
            if ( ! $url ) {
                continue;
            }
            // Регистронезависимо: после переименования по маске в имени
            // лежит «-BACK», а не «-back».
            $fname = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
            $slot  = 'front';
            if ( false !== stripos( $fname, '-back' ) ) {
                $slot = 'back';
            } elseif ( false !== stripos( $fname, '-sleeve' ) ) {
                $slot = 'sleeve';
            }
            $row['links'][] = array(
                'label' => $slot_placement_labels[ $slot ] ?? 'Расположение',
                'url'   => $url,
                'role'  => 'placement',
            );
        }
    }
}

/**
 * Приводит значения параметров изделия к единой форме.
 *
 * @param array $values    key => значение (слаг для конструктора, подпись для вариации).
 * @param bool  $are_slugs Значения уже слаги.
 * @return array Список [ key, label, value, slug, emoji ].
 */
function ptg_build_garment_rows( $values, $are_slugs ) {
    $rows   = array();
    $labels = ptg_garment_labels();

    foreach ( $labels as $key => $label ) {
        if ( ! isset( $values[ $key ] ) || '' === (string) $values[ $key ] ) {
            continue;
        }
        $raw = (string) $values[ $key ];

        if ( $are_slugs ) {
            $slug  = $raw;
            $value = ptg_garment_value_label( $key, $raw );
        } else {
            $value = $raw;
            $slug  = ptg_garment_slug_from_label( $key, $raw );
        }

        $rows[] = array(
            'key'   => $key,
            'label' => $label,
            'value' => $value,
            'slug'  => $slug,
            'emoji' => ( 'color' === $key ) ? ptg_color_emoji( $value ) : '',
        );
    }

    return $rows;
}

/**
 * Нормализует _bundle_meta.
 *
 * @param array $bm Мета бандла.
 * @return array
 */
function ptg_normalize_bundle( $bm ) {
    $shirts = array();
    foreach ( (array) ( $bm['shirts'] ?? array() ) as $sh ) {
        $shirts[] = array(
            'name'  => (string) ( $sh['name'] ?? '' ),
            'color' => (string) ( $sh['color'] ?? '' ),
            'size'  => (string) ( $sh['size'] ?? '' ),
            'type'  => (string) ( $sh['type'] ?? '' ),
        );
    }

    return array(
        'name'             => (string) ( $bm['bundle_name'] ?? '' ),
        'total'            => isset( $bm['total_price'] ) ? (int) round( $bm['total_price'] ) : 0,
        'discount_percent' => isset( $bm['discount_percent'] ) ? (int) $bm['discount_percent'] : 0,
        'saved'            => isset( $bm['saved'] ) ? (int) round( $bm['saved'] ) : 0,
        'shirts'           => $shirts,
    );
}

/**
 * Файлы из формы чекаута, кроме тех, что уже показаны под позициями.
 *
 * @param WC_Order $order     Заказ.
 * @param array    $shown_ids Уже выведенные attachment ID.
 * @return array Список [ label, url ].
 */
function ptg_order_checkout_files( $order, $shown_ids ) {
    $urls      = array();
    $shown_ids = array_map( 'intval', $shown_ids );

    $podval_file_ids = $order->get_meta( '_podval_print_file_ids' );
    if ( $podval_file_ids ) {
        $ids = is_array( $podval_file_ids ) ? $podval_file_ids : array( $podval_file_ids );
        foreach ( $ids as $att_id ) {
            $att_id = (int) $att_id;
            if ( in_array( $att_id, $shown_ids, true ) ) {
                continue;
            }
            $url = wp_get_attachment_url( $att_id );
            if ( $url ) {
                $urls[] = $url;
            }
        }
    }

    if ( empty( $urls ) && empty( $shown_ids ) ) {
        $file_keys = array(
            '_wc_order_uploads', 'order_uploads',
            '_billing_file', 'billing_file',
            '_custom_file', 'custom_file',
            '_upload_files', 'upload_files',
            '_wc_order_attachment_ids', 'order_attachment_ids',
        );
        foreach ( $file_keys as $fk ) {
            $val = $order->get_meta( $fk );
            if ( ! $val ) {
                continue;
            }
            if ( is_array( $val ) ) {
                foreach ( $val as $f ) {
                    if ( is_string( $f ) && is_numeric( $f ) ) {
                        $url = wp_get_attachment_url( (int) $f );
                        if ( $url ) {
                            $urls[] = $url;
                        }
                    } elseif ( is_string( $f ) && filter_var( $f, FILTER_VALIDATE_URL ) ) {
                        $urls[] = $f;
                    } elseif ( is_array( $f ) && ! empty( $f['url'] ) ) {
                        $urls[] = $f['url'];
                    }
                }
            } elseif ( is_string( $val ) && is_numeric( $val ) ) {
                $url = wp_get_attachment_url( (int) $val );
                if ( $url ) {
                    $urls[] = $url;
                }
            } elseif ( is_string( $val ) && filter_var( $val, FILTER_VALIDATE_URL ) ) {
                $urls[] = $val;
            }
        }
    }

    $urls  = array_values( array_unique( $urls ) );
    $files = array();
    foreach ( $urls as $i => $url ) {
        $files[] = array(
            'label' => 'Доп. файл ' . ( $i + 1 ),
            'url'   => $url,
        );
    }
    return $files;
}

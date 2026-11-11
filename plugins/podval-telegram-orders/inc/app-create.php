<?php
/**
 * Фаза 5, 2.12.0. Заказ, оформленный вручную из Mini App.
 *
 * Клиенты часто приходят сразу в Telegram, минуя сайт. Такой заказ надо
 * завести за минуту и привязать к нему переписку, чтобы дальше он шёл по
 * общему циклу: карточка в группе, статусы, выдача, отчёты.
 *
 * Картинку такому заказу даёт переписка: превью берётся из последнего
 * изображения, отправленного клиенту (см. ptg_chat_preview()).
 *
 * @package podval-telegram-orders
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', function () {
    register_rest_route( PTG_REST_NS, '/app/clients/hide', array(
        'methods'             => 'POST',
        'permission_callback' => 'ptg_app_permission',
        'callback'            => function ( $request ) {
            $key = (string) ( (array) $request->get_json_params() )['key'] ?? '';
            if ( '' === $key ) {
                return ptg_app_fail( 'ptg_bad_key', 'Не указан чат' );
            }
            ptg_hide_unlinked( $key );
            return ptg_app_response( array( 'hidden' => $key ) );
        },
    ) );

    register_rest_route( PTG_REST_NS, '/app/new', array(
        array(
            'methods'             => 'GET',
            'permission_callback' => 'ptg_app_permission',
            'callback'            => 'ptg_app_rest_new_form',
        ),
        array(
            'methods'             => 'POST',
            'permission_callback' => 'ptg_app_permission',
            'callback'            => 'ptg_app_rest_new_order',
        ),
    ) );
} );

/**
 * Прайс для заказов, оформленных вручную.
 *
 * ВРЕМЕННОЕ РЕШЕНИЕ. Цены захардкожены, потому что у товаров в каталоге
 * цена 0 (они продаются через конструктор). Когда на сайте появится
 * управление ценами, эта функция должна читать их оттуда — менять
 * придётся только её, форма и расчёт берут всё отсюда. До тех пор
 * поправить можно здесь или фильтром ptg_price_rules.
 *
 * base    — по ключевому слову в названии модели или товара;
 * sides   — первая сторона в базовой цене, вторая доплачивается;
 * sleeve  — печать на рукаве.
 *
 * @return array
 */
function ptg_price_rules() {
    return (array) apply_filters( 'ptg_price_rules', array(
        'base'    => array(
            'худи'       => 150.0,
            'свитшот'    => 150.0,
            'оверсайз'   => 75.0,
            'oversize'   => 75.0,
            'классическ' => 55.0,
        ),
        'default' => 55.0,
        'side'    => 10.0,
        'sleeve'  => 5.0,
    ) );
}

/**
 * Базовая цена изделия: сначала по выбранной модели, потом по названию
 * товара, потом цена товара из каталога, и только потом — умолчание.
 *
 * @param string $product_name Название товара.
 * @param float  $product_price Цена из каталога.
 * @param string $type_label   Подпись выбранной модели.
 * @return float
 */
function ptg_price_base( $product_name, $product_price, $type_label = '' ) {
    $rules = ptg_price_rules();
    foreach ( array( $type_label, $product_name ) as $hay ) {
        $hay = mb_strtolower( (string) $hay );
        if ( '' === $hay ) {
            continue;
        }
        foreach ( $rules['base'] as $needle => $price ) {
            if ( false !== mb_strpos( $hay, (string) $needle ) ) {
                return (float) $price;
            }
        }
    }
    return $product_price > 0 ? (float) $product_price : (float) $rules['default'];
}

/**
 * Сумма позиции: база + доплаты за вторую сторону и рукав, на количество.
 *
 * @param float $base   База за изделие.
 * @param array $sides  Слаги выбранных сторон.
 * @param bool  $sleeve Печать на рукаве.
 * @param int   $qty    Количество.
 * @return float
 */
function ptg_price_total( $base, $sides, $sleeve, $qty ) {
    $rules = ptg_price_rules();
    $extra = max( 0, count( array_unique( (array) $sides ) ) - 1 ) * (float) $rules['side'];
    if ( $sleeve ) {
        $extra += (float) $rules['sleeve'];
    }
    return round( ( (float) $base + $extra ) * max( 1, (int) $qty ), 2 );
}

/**
 * Подпись поля «печать на рукаве» — как на сайте.
 *
 * @return string
 */
function ptg_sleeve_label() {
    return (string) apply_filters( 'ptg_sleeve_label', 'Печать на рукаве' );
}

/**
 * Товары для формы: опубликованные, покупаемые, свежие сверху.
 *
 * @return array[]
 */
function ptg_app_new_products() {
    $products = wc_get_products( array(
        'status'  => 'publish',
        'limit'   => 60,
        'orderby' => 'date',
        'order'   => 'DESC',
    ) );

    $out = array();
    foreach ( (array) $products as $p ) {
        if ( $p->is_type( 'variable' ) || $p->is_type( 'grouped' ) ) {
            continue; // вариации в быстрой форме не выбрать
        }
        $price = (float) wc_get_price_to_display( $p );
        $out[] = array(
            'id'    => $p->get_id(),
            'name'  => $p->get_name(),
            'price' => $price,
            'base'  => ptg_price_base( $p->get_name(), $price ),
        );
    }
    return $out;
}

/**
 * GET app/new — справочники для формы.
 *
 * @param WP_REST_Request $request Запрос.
 * @return WP_REST_Response
 */
function ptg_app_rest_new_form( $request ) {
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

    $pays = array();
    foreach ( ptg_payment_categories() as $key => $label ) {
        if ( 'other' === $key ) {
            continue;
        }
        $pays[] = array( 'key' => $key, 'label' => $label );
    }

    $clients = array();
    foreach ( ptg_unlinked_chats() as $c ) {
        $clients[] = array(
            'key'      => $c['key'],
            'username' => $c['username'],
            'name'     => $c['name'],
            't'        => $c['t'],
            'last'     => ( 'out' === $c['dir'] ? 'Вы: ' : '' ) . ( $c['med'] ? '[' . $c['med'] . '] ' : '' ) . $c['last'],
        );
    }

    $rules = ptg_price_rules();

    return ptg_app_response( array(
        'products' => ptg_app_new_products(),
        'fields'   => $fields,
        'pricing'  => array(
            'base'   => $rules['base'],
            'side'   => (float) $rules['side'],
            'sleeve' => (float) $rules['sleeve'],
            'sleeve_label' => ptg_sleeve_label(),
        ),
        'payments' => $pays,
        'clients'  => $clients,
        'now'      => time(),
    ) );
}

/**
 * POST app/new — создать заказ.
 *
 * @param WP_REST_Request $request Запрос.
 * @return WP_REST_Response|WP_Error
 */
function ptg_app_rest_new_order( $request ) {
    $a    = ptg_app_auth( $request );
    $p    = (array) $request->get_json_params();
    $from = ptg_app_from( $a );

    $product = wc_get_product( (int) ( $p['product_id'] ?? 0 ) );
    if ( ! $product || 'publish' !== $product->get_status() ) {
        return ptg_app_fail( 'ptg_bad_product', 'Товар не найден' );
    }

    $qty = (int) ( $p['qty'] ?? 1 );
    if ( $qty < 1 || $qty > 50 ) {
        return ptg_app_fail( 'ptg_bad_qty', 'Количество от 1 до 50' );
    }

    $price = isset( $p['price'] ) && '' !== $p['price']
        ? (float) str_replace( ',', '.', (string) $p['price'] )
        : null; // посчитаем ниже, когда разберём параметры


    $name = sanitize_text_field( (string) ( $p['name'] ?? '' ) );
    $nick = ptg_normalize_username( (string) ( $p['username'] ?? '' ) );
    if ( '' === $name && '' === $nick ) {
        return ptg_app_fail( 'ptg_no_client', 'Укажите имя или ник клиента' );
    }

    $pay = (string) ( $p['payment'] ?? '' );
    if ( ! isset( ptg_payment_categories()[ $pay ] ) || 'other' === $pay ) {
        return ptg_app_fail( 'ptg_bad_payment', 'Выберите способ оплаты' );
    }

    // Чат выбирается из реестра, а не приходит с клиента: так из
    // приложения нельзя привязать произвольный chat_id.
    $chat = null;
    if ( ! empty( $p['client_key'] ) ) {
        foreach ( ptg_unlinked_chats() as $c ) {
            if ( $c['key'] === (string) $p['client_key'] ) {
                $chat = $c;
                break;
            }
        }
        if ( ! $chat ) {
            return ptg_app_fail( 'ptg_no_chat', 'Этот чат уже привязан к другому заказу или устарел', 409 );
        }
    }

    $garment = array();
    $sides   = array();
    $type    = '';
    foreach ( ptg_garment_labels() as $field => $label ) {
        $opts = ptg_garment_value_options( $field );

        // Печать может быть сразу с нескольких сторон: спереди и сзади,
        // спереди и на рукаве. Поэтому здесь массив, а не одно значение.
        if ( 'side' === $field ) {
            $chosen = (array) ( $p['garment'][ $field ] ?? array() );
            $names  = array();
            foreach ( $chosen as $slug ) {
                $slug = (string) $slug;
                if ( '' === $slug ) {
                    continue;
                }
                if ( ! isset( $opts[ $slug ] ) ) {
                    return ptg_app_fail( 'ptg_bad_garment', 'Недопустимое значение: ' . $label );
                }
                $sides[] = $slug;
                $names[] = $opts[ $slug ];
            }
            if ( $names ) {
                $garment[ $label ] = implode( ', ', $names );
            }
            continue;
        }

        $slug = (string) ( $p['garment'][ $field ] ?? '' );
        if ( '' === $slug ) {
            continue;
        }
        if ( ! isset( $opts[ $slug ] ) ) {
            return ptg_app_fail( 'ptg_bad_garment', 'Недопустимое значение: ' . $label );
        }
        $garment[ $label ] = $opts[ $slug ];
        if ( 'type' === $field ) {
            $type = $opts[ $slug ];
        }
    }

    $sleeve = ! empty( $p['sleeve'] );
    $garment[ ptg_sleeve_label() ] = $sleeve ? 'Да' : 'Нет';

    if ( null === $price ) {
        $price = ptg_price_total(
            ptg_price_base( $product->get_name(), (float) wc_get_price_to_display( $product ), $type ),
            $sides,
            $sleeve,
            $qty
        );
    }
    if ( $price < 0 || $price > 1000000 ) {
        return ptg_app_fail( 'ptg_bad_price', 'Сумма указана неверно' );
    }

    $pickup = ! empty( $p['pickup'] );
    $note   = sanitize_textarea_field( (string) ( $p['note'] ?? '' ) );
    $phone  = sanitize_text_field( (string) ( $p['phone'] ?? '' ) );

    $order = wc_create_order( array( 'status' => 'pending' ) );
    if ( is_wp_error( $order ) ) {
        return ptg_app_fail( 'ptg_create_failed', 'Не удалось создать заказ', 500 );
    }

    $item_id = $order->add_product( $product, $qty, array(
        'subtotal' => $price,
        'total'    => $price,
    ) );
    $item = $order->get_item( $item_id );
    if ( $item ) {
        // Параметры пишем подписями («Модель» => «Оверсайз»): так их
        // читает ptg_order_data() и у заказов с сайта.
        foreach ( $garment as $label => $value ) {
            $item->add_meta_data( $label, $value, true );
        }
        $item->save();
    }

    $order->set_billing_first_name( $name );
    if ( '' !== $phone ) {
        $order->set_billing_phone( $phone );
    }
    if ( '' !== $nick ) {
        $order->update_meta_data( '_billing_messenger', '@' . $nick );
    }
    $order->set_payment_method( 'ptg_' . $pay );
    $order->set_payment_method_title( ptg_payment_categories()[ $pay ] );
    $order->update_meta_data( '_podval_shipping_method', $pickup ? 'pickup' : 'delivery' );
    $order->update_meta_data( '_podval_shipping_label', $pickup ? 'Самовывоз' : 'Доставка' );
    $order->update_meta_data( PTG_META_MANUAL, 1 );
    if ( '' !== $note ) {
        $order->set_customer_note( $note );
    }
    $order->set_total( $price );
    $order->save();

    ptg_add_note( $order, sprintf(
        'Заказ оформлен вручную из Mini App (%s)',
        ptg_staff_name( (int) $a['tg_id'], $from )
    ) );

    if ( $chat ) {
        ptg_link_client( $order, $chat['conn'], array(
            'id'         => $chat['chat_id'],
            'username'   => $chat['username'],
            'first_name' => $chat['name'],
        ), 'manual' );
    }

    // Статус ставим после наполнения: на переходе уходит карточка в
    // группу, и она должна содержать уже готовый заказ.
    $order->set_status( 'processing', 'Оформлен из Mini App' );
    $order->save();

    ptg_maybe_assign( $order, (int) $a['tg_id'], $from );
    ptg_force_next_sync( $order );
    ptg_sync_order_message( $order, 'app:new' );

    ptg_log( 'info', 'Mini App: заказ создан', array(
        'order' => $order->get_id(),
        'by'    => (int) $a['tg_id'],
        'chat'  => $chat ? (int) $chat['chat_id'] : 0,
    ) );

    $order = wc_get_order( $order->get_id() );
    return ptg_app_response( ptg_app_order_payload( $order, $a ) );
}

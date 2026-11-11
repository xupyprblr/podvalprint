<?php
/**
 * Переименование файлов принта по производственной маске.
 *
 * Маска: {НОМЕР}-{ТИП}-{РАЗМЕР}-{ЦВЕТ}-{СТОРОНА}
 * Пример: 280-OVR-L-BLK-FRONT
 *
 * Зачем: печатник получает файлы вне контекста сайта. Имя вида
 * cp-logo-20260722-190612-431.jpg не говорит ничего о том, к какому
 * заказу файл относится и на что его наносить.
 *
 * Почему на сохранении заказа, а не на загрузке:
 * podval_handle_print_upload() срабатывает ДО создания заказа — номера
 * ещё не существует. Поэтому файлы загружаются под временным именем
 * cp-*, а переименовываются здесь, когда номер уже присвоен.
 *
 * EWWW: файлы остаются в customer-prints/, а все bypass-фильтры в
 * functions.php (пп. 3-7) проверяют вхождение 'customer-prints' в путь,
 * поэтому переименование их не ломает.
 *
 * @package podval-theme
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Словари нормализации кириллических значений атрибутов в ASCII-токены.
 *
 * Транслитерация здесь не годится: sanitize_title() на кириллице даёт
 * пустую строку, а «Классическая» → «klassicheskaya» печатнику ничего
 * не даёт. Нужны короткие предсказуемые токены.
 */
function podval_pf_dictionaries() {
    return array(
        'type' => array(
            'оверсайз'      => 'OVR',
            'oversize'      => 'OVR',
            'over'          => 'OVR',
            'классическ'    => 'CLS',
            'classic'       => 'CLS',
            'худи'          => 'HOD',
            'hoodie'        => 'HOD',
            'свитшот'       => 'SWT',
            'лонгслив'      => 'LNG',
            'футболка'      => 'CLS',
        ),
        'color' => array(
            'чёрн'      => 'BLK',
            'черн'      => 'BLK',
            'black'     => 'BLK',
            'бел'       => 'WHT',
            'white'     => 'WHT',
            'сер'       => 'GRY',
            'graphite'  => 'GRF',
            'графит'    => 'GRF',
            'gray'      => 'GRY',
            'grey'      => 'GRY',
            'красн'     => 'RED',
            'син'       => 'BLU',
            'зелен'     => 'GRN',
            'зелён'     => 'GRN',
            'бежев'     => 'BEI',
            'песочн'    => 'SND',
            'хаки'      => 'KHK',
        ),
        'side' => array(
            'front'  => 'FRONT',
            'перед'  => 'FRONT',
            'back'   => 'BACK',
            'спин'   => 'BACK',
            'sleeve' => 'SLEEVE',
            'рукав'  => 'SLEEVE',
        ),
    );
}

/**
 * Нормализует значение атрибута в ASCII-токен по словарю.
 *
 * @param string $value    Исходное значение (может быть кириллицей / urlencoded).
 * @param string $dict_key Ключ словаря: type|color|side.
 * @param string $fallback Значение, если распознать не удалось.
 * @return string
 */
function podval_pf_normalize( $value, $dict_key, $fallback = 'NA' ) {
    $value = trim( (string) $value );
    if ( $value === '' ) {
        return $fallback;
    }

    // Атрибуты вариаций могут прийти urlencoded (кириллические ключи WC).
    if ( strpos( $value, '%' ) !== false ) {
        $value = urldecode( $value );
    }

    $needle = mb_strtolower( $value, 'UTF-8' );

    $dicts = podval_pf_dictionaries();
    $dict  = isset( $dicts[ $dict_key ] ) ? $dicts[ $dict_key ] : array();

    foreach ( $dict as $key => $token ) {
        if ( mb_strpos( $needle, $key, 0, 'UTF-8' ) !== false ) {
            return $token;
        }
    }

    // Не нашли в словаре — если значение латиницей/цифрами, берём как есть
    // (это покрывает размеры: S, M, L, XL, 2XL и артикулы).
    $ascii = preg_replace( '/[^A-Za-z0-9]/', '', $value );
    if ( $ascii !== '' ) {
        return strtoupper( $ascii );
    }

    return $fallback;
}

/**
 * Достаёт значение атрибута позиции заказа по списку возможных названий.
 *
 * Кастомные (не taxonomy) атрибуты хранятся у позиции обычным текстом
 * с кириллическим ключом — «Размер», «Цвет», «Модель». Taxonomy-атрибуты
 * приходят как attribute_pa_size. Проверяем оба варианта.
 *
 * @param WC_Order_Item_Product $item
 * @param array                 $names Варианты названия атрибута.
 * @return string
 */
function podval_pf_get_attr( $item, $names ) {
    foreach ( $item->get_meta_data() as $meta ) {
        $data = $meta->get_data();
        $key  = (string) $data['key'];

        // Системные меты (_artwork_files и т.п.) не атрибуты.
        if ( strpos( $key, '_' ) === 0 ) {
            continue;
        }

        $key_norm = mb_strtolower( urldecode( str_replace( array( 'attribute_', 'pa_' ), '', $key ) ), 'UTF-8' );

        foreach ( $names as $candidate ) {
            if ( $key_norm === mb_strtolower( $candidate, 'UTF-8' ) ) {
                $value = $data['value'];
                if ( is_scalar( $value ) && (string) $value !== '' ) {
                    return (string) $value;
                }
            }
        }
    }

    return '';
}

/**
 * Определяет сторону печати для конкретного файла.
 *
 * Источники, в порядке надёжности:
 *  1. Слот в _artwork_files (ключ массива: front|back|sleeve).
 *  2. Имя файла схемы размещения: placement-back.jpg → BACK.
 *
 * @param int   $att_id      ID вложения.
 * @param array $slot_by_id  Карта [ attachment_id => slot ].
 * @return string
 */
function podval_pf_detect_side( $att_id, $slot_by_id ) {
    if ( isset( $slot_by_id[ $att_id ] ) ) {
        return podval_pf_normalize( $slot_by_id[ $att_id ], 'side', 'FRONT' );
    }

    $file = get_post_meta( $att_id, '_wp_attached_file', true );
    $name = mb_strtolower( basename( (string) $file ), 'UTF-8' );

    if ( strpos( $name, 'back' ) !== false ) {
        return 'BACK';
    }
    if ( strpos( $name, 'sleeve' ) !== false ) {
        return 'SLEEVE';
    }

    return 'FRONT';
}

/**
 * Переименовывает файл вложения ЛИБО создаёт его копию для другого заказа.
 *
 * Зачем копия:
 * Клиент оформил заказ, не оплатил онлайн, вернулся назад и сменил способ
 * оплаты. Корзина при этом не очищается, поэтому создаётся ВТОРОЙ заказ
 * с теми же attachment ID. Если просто переименовать, файл получит номер
 * нового заказа, а у старого заказа ссылка станет вести на файл с чужим
 * номером — печатник получит неверную маску.
 *
 * Поэтому: первый заказ забирает оригинал, каждый следующий получает
 * физическую копию под своим именем и СВОЙ attachment ID. У обоих заказов
 * файл с корректным номером.
 *
 * Критично обновить и _wp_attached_file, и _wp_attachment_metadata['file'],
 * и guid — иначе wp_get_attachment_url() вернёт битую ссылку, и файлы
 * пропадут из админки и Telegram.
 *
 * @param int    $att_id   ID вложения.
 * @param string $new_base Имя без расширения.
 * @param int    $order_id Заказ, для которого готовим файл.
 * @return int|false ID вложения для этого заказа (может быть новым), либо false.
 */
function podval_pf_rename_attachment( $att_id, $new_base, $order_id = 0 ) {
    // прежней версии (P0), вторая линия защиты: переименовываем на диске только файлы,
    // загруженные клиентом (podval_upload_print / конструктор). Фото товаров и
    // прочие вложения медиатеки не трогаем, даже если их ID попал в заказ.
    if ( get_post_meta( $att_id, '_podval_print_upload', true ) !== '1' ) {
        return false;
    }

    $rel = get_post_meta( $att_id, '_wp_attached_file', true );
    if ( ! $rel ) {
        return false;
    }

    $uploads  = wp_upload_dir();
    $old_path = trailingslashit( $uploads['basedir'] ) . $rel;

    if ( ! file_exists( $old_path ) ) {
        return false;
    }

    $ext     = strtolower( pathinfo( $old_path, PATHINFO_EXTENSION ) );
    $dir_rel = dirname( $rel );
    $dir_abs = dirname( $old_path );

    // Кто уже владеет этим файлом.
    $owner   = (int) get_post_meta( $att_id, '_podval_print_order', true );
    $already = (string) get_post_meta( $att_id, '_podval_print_renamed', true );

    // Уже переименован под ЭТОТ же заказ (повторный save) — no-op.
    // Сверяемся с сохранённой отметкой, а не с $new_base: при коллизии
    // файл лежит под именем с суффиксом (…-FRONT-2), и наивное сравнение
    // с $new_base каждый раз давало бы новый суффикс.
    if ( $already !== '' && strpos( basename( $old_path ), $already . '.' ) === 0 ) {
        if ( ! $owner || ! $order_id || $owner === $order_id ) {
            return $att_id;
        }
        // Иначе файл принадлежит другому заказу → уходим в ветку копии ниже.
    }

    // Свободное имя в папке. Покрывает и два файла на одну сторону,
    // и совпадение номеров у разных заказов.
    $candidate = $new_base;
    $suffix    = 2;
    while ( file_exists( $dir_abs . '/' . $candidate . '.' . $ext ) ) {
        $candidate = $new_base . '-' . $suffix;
        $suffix++;
        if ( $suffix > 50 ) {
            return false;
        }
    }

    $new_name = $candidate . '.' . $ext;
    $new_path = $dir_abs . '/' . $new_name;
    $new_rel  = ltrim( $dir_rel . '/' . $new_name, '/' );

    $is_fork = ( $owner && $order_id && $owner !== $order_id );

    if ( $is_fork ) {
        /* ── Файл уже закреплён за другим заказом → делаем копию ──── */
        if ( ! @copy( $old_path, $new_path ) ) {
            return false;
        }
        @chmod( $new_path, 0644 );

        $src = get_post( $att_id );
        $new_id = wp_insert_post( array(
            'post_type'      => 'attachment',
            'post_mime_type' => $src ? $src->post_mime_type : 'application/octet-stream',
            'post_title'     => $candidate,
            'post_content'   => '',
            'post_status'    => 'inherit',
            'post_parent'    => $order_id,
            'guid'           => trailingslashit( $uploads['baseurl'] ) . $new_rel,
        ), true );

        if ( is_wp_error( $new_id ) || ! $new_id ) {
            @unlink( $new_path );
            return false;
        }

        update_post_meta( $new_id, '_wp_attached_file', $new_rel );

        $meta = get_post_meta( $att_id, '_wp_attachment_metadata', true );
        if ( ! is_array( $meta ) ) {
            $meta = array();
        }
        $meta['file']     = $new_rel;
        $meta['filesize'] = filesize( $new_path );
        update_post_meta( $new_id, '_wp_attachment_metadata', $meta );

        // Переносим служебные метки, иначе копия потеряет EWWW-байпас
        // и перестанет распознаваться как схема размещения.
        update_post_meta( $new_id, '_podval_print_upload', '1' );
        if ( get_post_meta( $att_id, '_podval_placement', true ) === '1' ) {
            update_post_meta( $new_id, '_podval_placement', '1' );
        }
        update_post_meta( $new_id, '_podval_print_renamed', $candidate );
        update_post_meta( $new_id, '_podval_print_order', $order_id );
        update_post_meta( $new_id, '_podval_print_copy_of', $att_id );

        // WooCommerce (woocommerce_checkout_create_order_line_item) уже
        // переписал post_parent оригинала на НОВЫЙ заказ. Возвращаем его
        // владельцу, иначе podval-print-cleanup будет считать оригинал
        // принадлежащим не тому заказу и может удалить его раньше времени.
        wp_update_post( array( 'ID' => $att_id, 'post_parent' => $owner ) );

        return $new_id;
    }

    /* ── Обычный путь: файл ещё ничей → переименовываем оригинал ──── */
    if ( ! @rename( $old_path, $new_path ) ) {
        return false;
    }

    update_post_meta( $att_id, '_wp_attached_file', $new_rel );

    $meta = get_post_meta( $att_id, '_wp_attachment_metadata', true );
    if ( is_array( $meta ) ) {
        $meta['file'] = $new_rel;
        update_post_meta( $att_id, '_wp_attachment_metadata', $meta );
    }

    wp_update_post( array(
        'ID'         => $att_id,
        'post_title' => $candidate,
        'guid'       => trailingslashit( $uploads['baseurl'] ) . $new_rel,
    ) );

    // Отметка, чтобы не переименовывать повторно и для диагностики.
    update_post_meta( $att_id, '_podval_print_renamed', $candidate );
    if ( $order_id ) {
        update_post_meta( $att_id, '_podval_print_order', $order_id );
    }

    return $att_id;
}

/**
 * Основной проход: переименовывает все файлы принта заказа по маске.
 *
 * @param int $order_id
 */
function podval_rename_order_print_files( $order_id ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }

    // Защита от повторного прохода (заказ сохраняется многократно).
    if ( $order->get_meta( '_podval_files_renamed' ) === '1' ) {
        return;
    }

    $number = $order->get_meta( '_podval_order_number' );
    if ( ! $number ) {
        $number = $order->get_order_number();
    }
    $number = preg_replace( '/[^0-9A-Za-z]/', '', (string) $number );
    if ( $number === '' ) {
        return; // без номера маска бессмысленна
    }

    $renamed_any = false;

    /* ── Позиции заказа (обычный заказ через корзину) ──────────────── */
    foreach ( $order->get_items() as $item ) {
        if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
            continue;
        }

        $type = podval_pf_normalize(
            podval_pf_get_attr( $item, array( 'модель', 'тип', 'фасон', 'model', 'type', 'size-type' ) ),
            'type',
            'NA'
        );

        $size_raw = podval_pf_get_attr( $item, array( 'размер', 'size' ) );
        $size     = $size_raw !== '' ? podval_pf_normalize( $size_raw, 'size', 'NA' ) : 'NA';

        $color = podval_pf_normalize(
            podval_pf_get_attr( $item, array( 'цвет', 'color', 'colour' ) ),
            'color',
            'NA'
        );

        // позиции конструктора шаблонов хранят изделие не
        // атрибутами WC, а в мете `_podval_tpl` (garment: type/size/color/
        // side). Без этого схема называлась 542-NA-NA-NA-FRONT-SCHEME,
        // хотя по умолчанию печать сзади. Атрибуты WC, если они есть,
        // по-прежнему в приоритете.
        $tpl_side = '';
        $tpl_meta = $item->get_meta( '_podval_tpl' );
        if ( is_array( $tpl_meta ) && ! empty( $tpl_meta['garment'] ) && is_array( $tpl_meta['garment'] ) ) {
            $g = $tpl_meta['garment'];
            if ( 'NA' === $type && ! empty( $g['type'] ) ) {
                $type = podval_pf_normalize( $g['type'], 'type', 'NA' );
            }
            if ( 'NA' === $size && ! empty( $g['size'] ) ) {
                $size = podval_pf_normalize( $g['size'], 'size', 'NA' );
            }
            if ( 'NA' === $color && ! empty( $g['color'] ) ) {
                $color = podval_pf_normalize( $g['color'], 'color', 'NA' );
            }
            if ( ! empty( $g['side'] ) ) {
                $tpl_side = podval_pf_normalize( $g['side'], 'side', 'FRONT' );
            }
        }

        // Карта attachment_id → слот, чтобы знать сторону печати.
        $slot_by_id = array();
        $aw_files   = $item->get_meta( '_artwork_files' );
        if ( is_array( $aw_files ) ) {
            foreach ( $aw_files as $slot => $fid ) {
                $slot_by_id[ (int) $fid ] = $slot;
            }
        }

        $targets = array();
        if ( is_array( $aw_files ) ) {
            foreach ( $aw_files as $fid ) {
                $targets[] = (int) $fid;
            }
        }
        // Печатный файл конструктора — своя мета позиции.
        $tpl_print_id = (int) $item->get_meta( '_podval_tpl_print_file' );
        if ( $tpl_print_id ) {
            $targets[] = $tpl_print_id;
        }

        $aw_extra       = $item->get_meta( '_artwork_extra_files' );
        $extra_ids_flat = array();
        if ( is_array( $aw_extra ) ) {
            foreach ( $aw_extra as $fid ) {
                $targets[]        = (int) $fid;
                $extra_ids_flat[] = (int) $fid;
            }
        }

        $id_map    = array();
        $extra_num = 0;

        foreach ( array_unique( array_filter( $targets ) ) as $att_id ) {
            $is_scheme = ( get_post_meta( $att_id, '_podval_placement', true ) === '1' );
            $is_print  = ( get_post_meta( $att_id, '_podval_tpl_print', true ) === '1' );

            // Является ли файл «доп. файлом» (не макет для конкретной стороны).
            // В _artwork_extra_files лежат и схемы размещения, и произвольные
            // референсы/логотипы/мокапы клиента.
            $is_extra = in_array( (int) $att_id, $extra_ids_flat, true ) && ! $is_scheme;

            if ( $is_extra ) {
                // У доп. файла нет стороны печати — маска с FRONT вводила бы
                // печатника в заблуждение (и раньше давала коллизию …-FRONT-2).
                $extra_num++;
                $base = $number . '-' . $type . '-' . $size . '-' . $color . '-EXTRA-' . $extra_num;
            } else {
                // Сторона из конструктора — если её нет в слотах _artwork_files.
                $side = ( $tpl_side && ! isset( $slot_by_id[ $att_id ] ) )
                    ? $tpl_side
                    : podval_pf_detect_side( $att_id, $slot_by_id );

                $base = $number . '-' . $type . '-' . $size . '-' . $color . '-' . $side;

                // Схемы размещения — это референс для печатника, а не макет.
                // Помечаем суффиксом, чтобы их не отправили в печать по ошибке.
                if ( $is_scheme ) {
                    $base .= '-SCHEME';
                } elseif ( $is_print ) {
                    // Печатный файл конструктора, 300 ppi.
                    $base .= '-PRINT';
                }
            }

            $result_id = podval_pf_rename_attachment( $att_id, $base, $order_id );
            if ( $result_id ) {
                $renamed_any = true;
                if ( (int) $result_id !== (int) $att_id ) {
                    // Файл форкнулся (принадлежал другому заказу) — этот заказ
                    // должен ссылаться на СВОЮ копию, иначе в админке и Telegram
                    // будет ссылка на файл с чужим номером.
                    $id_map[ (int) $att_id ] = (int) $result_id;
                }
            }
        }

        /* ── Перепривязка меты позиции на копии ────────────────────── */
        if ( ! empty( $id_map ) ) {
            if ( is_array( $aw_files ) ) {
                $updated = array();
                foreach ( $aw_files as $slot => $fid ) {
                    $fid = (int) $fid;
                    $updated[ $slot ] = isset( $id_map[ $fid ] ) ? $id_map[ $fid ] : $fid;
                }
                $item->update_meta_data( '_artwork_files', $updated );
            }

            if ( is_array( $aw_extra ) ) {
                $updated = array();
                foreach ( $aw_extra as $fid ) {
                    $fid = (int) $fid;
                    $updated[] = isset( $id_map[ $fid ] ) ? $id_map[ $fid ] : $fid;
                }
                $item->update_meta_data( '_artwork_extra_files', $updated );
            }

            if ( $tpl_print_id && isset( $id_map[ $tpl_print_id ] ) ) {
                $item->update_meta_data( '_podval_tpl_print_file', $id_map[ $tpl_print_id ] );
            }

            $item->save();

            // Order-level список (админка + cleanup) тоже должен смотреть на копии.
            $order_ids = $order->get_meta( '_podval_print_file_ids' );
            if ( is_array( $order_ids ) ) {
                $updated = array();
                foreach ( $order_ids as $fid ) {
                    $fid = (int) $fid;
                    $updated[] = isset( $id_map[ $fid ] ) ? $id_map[ $fid ] : $fid;
                }
                $order->update_meta_data( '_podval_print_file_ids', array_values( array_unique( $updated ) ) );
            }
        }
    }

    /* ── Заявки на услугу (order-level файлы, без вариаций) ────────── */
    if ( $order->get_meta( '_order_type' ) === 'service' ) {
        $ids = $order->get_meta( '_podval_print_file_ids' );
        if ( is_array( $ids ) ) {
            $n       = 1;
            $updated = array();
            foreach ( $ids as $att_id ) {
                $att_id = (int) $att_id;
                if ( ! $att_id ) {
                    continue;
                }
                // У заявки нет размера/цвета/типа — маска вырождается,
                // поэтому используем читаемый вариант с SERVICE.
                $base      = $number . '-SERVICE-' . $n;
                $result_id = podval_pf_rename_attachment( $att_id, $base, $order_id );
                if ( $result_id ) {
                    $renamed_any = true;
                    $updated[]   = (int) $result_id;
                } else {
                    $updated[] = $att_id;
                }
                $n++;
            }
            $order->update_meta_data( '_podval_print_file_ids', array_values( array_unique( $updated ) ) );
        }
    }

    if ( $renamed_any ) {
        $order->update_meta_data( '_podval_files_renamed', '1' );
        $order->save();
    }
}

/**
 * Хуки запуска.
 *
 * checkout_order_processed — обычный заказ через корзину.
 * Приоритет 20: после woocommerce_checkout_create_order_line_item,
 * когда _artwork_files уже записаны, и после присвоения номера.
 */
add_action( 'woocommerce_checkout_order_processed', 'podval_rename_order_print_files', 20 );

/**
 * Заявки на услугу создаются напрямую через wc_create_order() и не
 * проходят checkout, поэтому вешаемся ещё и на смену статуса.
 */
add_action( 'woocommerce_order_status_on-hold', 'podval_rename_order_print_files', 5 );
add_action( 'woocommerce_order_status_processing', 'podval_rename_order_print_files', 5 );

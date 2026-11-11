<?php
/**
 * Данные заказа → HTML для Telegram.
 *
 * Визуально всё как в 1.5.3: менеджеры читают эти карточки каждый день,
 * структура «↳», эмодзи и порядок блоков сохранены. Меняется только
 * разметка: Markdown → HTML.
 *
 * Почему HTML. Прежняя ptg_escape_md() экранировала четыре символа и
 * ломалась на имени клиента вроде «A & B» или названии товара со
 * скобками; а в Markdown без V2 нет зачёркивания, которое нужно баннеру
 * Фазы 1.
 *
 * @package podval-telegram-orders
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Порог, после которого сообщение схлопывается. Лимит Telegram — 4096. */
const PTG_TG_LIMIT = 4000;

/**
 * Экранирование текста для Telegram HTML.
 *
 * Нельзя использовать esc_html(): она дополнительно превращает кавычки в
 * &#039;, а Telegram такие сущности не разворачивает и покажет буквально.
 *
 * @param string $text Текст.
 * @return string
 */
function ptg_esc( $text ) {
    return str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), (string) $text );
}

/**
 * Ссылка.
 *
 * esc_url() в контексте display превращает & в &#038; — Telegram отдаст
 * такую ссылку битой. Поэтому esc_url_raw() + собственное экранирование.
 * Кириллица в пути кодируется посегментно: после print-filenames.php имена
 * ASCII, но исходники до переименования гарантий не дают.
 *
 * @param string $url   Адрес.
 * @param string $label Подпись.
 * @return string
 */
function ptg_link( $url, $label ) {
    $url = ptg_safe_url( $url );
    if ( '' === $url ) {
        return ptg_esc( $label );
    }
    return '<a href="' . ptg_esc( $url ) . '">' . ptg_esc( $label ) . '</a>';
}

/**
 * Приводит URL к безопасному для Telegram виду.
 *
 * @param string $url Адрес.
 * @return string
 */
function ptg_safe_url( $url ) {
    $url = (string) $url;
    if ( '' === $url ) {
        return '';
    }

    $parts = wp_parse_url( $url );
    if ( ! empty( $parts['path'] ) && preg_match( '/[^\x20-\x7E]/', $parts['path'] ) ) {
        $segments = array_map( 'rawurlencode', explode( '/', $parts['path'] ) );
        $path     = implode( '/', $segments );
        $url      = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' )
            . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' )
            . $path
            . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
    }

    return esc_url_raw( $url );
}

/**
 * Длина строки в единицах UTF-16 — именно так Telegram считает лимит.
 *
 * mb_strlen() даст меньше: эмодзи вне BMP занимают две единицы, а их в
 * карточке заказа много.
 *
 * @param string $s Строка.
 * @return int
 */
function ptg_tg_len( $s ) {
    $u16 = mb_convert_encoding( (string) $s, 'UTF-16LE', 'UTF-8' );
    return (int) ( strlen( $u16 ) / 2 );
}

/**
 * Главный рендер.
 *
 * @param array $d Данные заказа.
 * @return string HTML.
 */
function ptg_render_order_html( $d ) {
    $html = ptg_render_order_body( $d, false );

    if ( ptg_tg_len( $html ) > PTG_TG_LIMIT ) {
        $html = ptg_render_order_body( $d, true );
        ptg_log( 'warn', 'Сообщение усечено по лимиту Telegram', array(
            'order' => $d['id'],
            'len'   => ptg_tg_len( $html ),
        ) );
    }

    return $html;
}

/**
 * Тело сообщения.
 *
 * @param array $d       Данные заказа.
 * @param bool  $compact Схлопнуть details и files.
 * @return string
 */
function ptg_render_order_body( $d, $compact = false ) {
    $lines = array();

    $banner = ptg_render_banner_lines( $d );
    if ( $banner ) {
        foreach ( $banner as $b ) {
            $lines[] = $b;
        }
        $lines[] = '';
    }

    $number = ptg_esc( $d['number'] );
    $st     = $d['status_info'];

    if ( 'service' === $d['type'] ) {
        $lines[] = "🔢 <b>Заявка #{$number}</b> · " . $st['emoji'] . ' ' . ptg_esc( $st['label'] );
    } else {
        $lines[] = ptg_render_preview_anchor( $d ) . "🔢 <b>Заказ #{$number}</b> · " . $st['emoji'] . ' ' . ptg_esc( $st['label'] );
    }

    if ( ! empty( $d['verified']['by'] ) ) {
        $lines[] = '✅ Параметры сверил: <b>' . ptg_esc( $d['verified']['by'] ) . '</b>';
    }

    if ( ! empty( $d['assignee']['name'] ) && ptg_show_assignee() ) {
        $lines[] = '🧑‍💼 Ответственный: <b>' . ptg_esc( $d['assignee']['name'] ) . '</b>';
    }

    $lines[] = '';
    $lines[] = '👤 ' . ptg_esc( $d['customer']['name'] );

    if ( $d['customer']['phone'] ) {
        $lines[] = '📱 ' . ptg_esc( $d['customer']['phone'] );
    }
    if ( $d['customer']['messenger'] ) {
        $lines[] = '💬 ' . ptg_esc( $d['customer']['messenger'] );
    }
    if ( $d['customer']['email'] ) {
        $lines[] = '📧 ' . ptg_esc( $d['customer']['email'] );
    }

    $client_line = ptg_render_client_line( $d['client'] );
    if ( '' !== $client_line ) {
        $lines[] = $client_line;
    }

    $preview_line = ptg_render_preview_line( $d );
    if ( '' !== $preview_line ) {
        $lines[] = $preview_line;
    }

    if ( ! empty( $d['client']['files'] ) ) {
        $lines[] = '📎 Файлов из переписки: ' . (int) $d['client']['files'];
    }

    if ( ! empty( $d['greeting']['sent'] ) ) {
        $lines[] = '👋 Приветствие отправлено'
            . ( $d['greeting']['by'] ? ' · ' . ptg_esc( $d['greeting']['by'] ) : '' );
    }

    $lines[] = '';

    if ( $d['shipping']['label'] ) {
        $lines[] = '🚚 ' . ptg_esc( $d['shipping']['label'] );
    }
    if ( ! $d['shipping']['is_pickup'] && $d['shipping']['address'] ) {
        $lines[] = '📍 ' . ptg_esc( $d['shipping']['address'] );
    }

    if ( 'service' === $d['type'] ) {
        $lines[] = '';
        foreach ( $d['items'] as $item ) {
            $lines[] = '🔧 ' . ptg_esc( $item['name'] );
        }
        if ( $d['note'] ) {
            $lines[] = '';
            $lines[] = '📝 ' . ptg_esc( $d['note'] );
        }
        if ( ! empty( $d['files'] ) ) {
            $lines[] = '';
            $lines[] = '📎 <b>Прикреплённые файлы:</b>';
            foreach ( ptg_render_files( $d['files'], $compact ) as $l ) {
                $lines[] = $l;
            }
        }
    } else {
        $lines[] = '';

        $items_text = '';
        foreach ( $d['items'] as $item ) {
            $items_text .= ptg_render_item( $item, $compact ) . "\n";
        }
        $lines[] = rtrim( $items_text );

        if ( $d['payment']['title'] ) {
            $lines[] = '💳 ' . ptg_esc( $d['payment']['title'] )
                . ( ! empty( $d['payment']['state_label'] ) ? ' · ' . ptg_esc( $d['payment']['state_label'] ) : '' );
        }
        $lines[] = '💰 ' . ptg_esc( $d['payment']['total'] ) . ' Br';

        if ( 'pending' === $d['status'] ) {
            $lines[] = '';
            $lines[] = '⚠️ <i>Заказ ожидает оплату. Свяжитесь с клиентом, если оплата не поступит.</i>';
        }

        if ( $d['note'] ) {
            $lines[] = '';
            $lines[] = '💬 ' . ptg_esc( $d['note'] );
        }

        if ( ! empty( $d['files'] ) ) {
            $lines[] = '';
            $lines[] = '📎 <b>Доп. файлы:</b>';
            foreach ( ptg_render_files( $d['files'], $compact ) as $l ) {
                $lines[] = $l;
            }
        }
    }

    if ( $compact ) {
        $lines[] = '';
        $lines[] = '… полный список — в админке';
    }

    // Ссылка на админку — кнопкой под карточкой (2.9.2), в тексте дубль.
    return rtrim( implode( "\n", $lines ) );
}

/**
 * Строка про чат с клиентом.
 *
 * Пока чат не привязан, менеджер ищет заказ по нику руками — это одна из
 * трёх исходных проблем, ради которых всё затевалось. Поэтому строка
 * присутствует всегда: либо привязка, либо явная подсказка, как её
 * сделать.
 *
 * @param array $client Данные привязки.
 * @return string
 */
function ptg_render_client_line( $client ) {
    if ( empty( $client['linked'] ) ) {
        return '🔗 <i>Чат клиента не привязан</i>';
    }

    $who = $client['username']
        ? '@' . $client['username']
        : ( $client['name'] ? $client['name'] : 'ID ' . $client['chat_id'] );

    $line = '🔗 ' . ptg_esc( $who );
    if ( $client['messages'] ) {
        $line .= ' · ' . (int) $client['messages'] . ' '
            . ptg_ru_plural( (int) $client['messages'], 'сообщение', 'сообщения', 'сообщений' );
    }
    return $line;
}

/**
 * Строки блока «Доп. файлы».
 *
 * @param array $files   Файлы.
 * @param bool  $compact Схлопнуть.
 * @return array
 */
function ptg_render_files( $files, $compact ) {
    if ( $compact ) {
        return array( '  ' . count( $files ) . ' шт. — в админке' );
    }
    $out = array();
    $i   = 0;
    foreach ( $files as $f ) {
        $i++;
        $out[] = '  ' . $i . '. ' . ptg_link( $f['url'], $f['label'] );
    }
    return $out;
}

/**
 * Одна позиция.
 *
 * @param array $item    Строка позиции.
 * @param bool  $compact Схлопнуть details.
 * @return string
 */
function ptg_render_item( $item, $compact = false ) {
    if ( 'bundle' === $item['kind'] ) {
        return ptg_render_bundle( $item['bundle'] );
    }

    $out  = '• <b>' . ptg_esc( $item['name'] ) . '</b> × ' . (int) $item['qty'] . ' — ' . (int) $item['subtotal'] . " Br\n";
    $out .= ptg_render_garment( $item['garment'] );

    if ( 'tpl' === $item['kind'] ) {
        $out .= "    🎨 <b>Конструктор макета</b>\n";
        $out .= ptg_render_notices( $item['notices'], 'tpl' );
        if ( ! $compact ) {
            foreach ( $item['details'] as $row ) {
                $out .= '    ↳ ' . ptg_esc( $row['label'] ) . ': ' . ptg_esc( $row['value'] ) . "\n";
            }
        }
    } elseif ( ! $compact ) {
        foreach ( $item['details'] as $row ) {
            $out .= '    ↳ ' . ptg_esc( $row['label'] ) . ': ' . ptg_esc( $row['value'] ) . "\n";
        }
    }

    $icons = array(
        'preview'   => '🖼',
        'print'     => '🖨',
        'photo'     => '📎',
        'artwork'   => '🖼',
        'placement' => '📐',
    );

    foreach ( array( 'preview', 'print', 'photo', 'artwork' ) as $role ) {
        foreach ( $item['links'] as $link ) {
            if ( $link['role'] !== $role ) {
                continue;
            }
            $out .= '    ' . $icons[ $role ] . ' ' . ptg_link( $link['url'], $link['label'] ) . "\n";
        }
    }

    $out .= ptg_render_notices( $item['notices'], 'artwork' );

    foreach ( $item['texts'] as $t ) {
        $out .= '    ✏️ ' . ptg_esc( $t['label'] ) . ': «' . ptg_esc( $t['value'] ) . "»\n";
    }

    if ( $item['comment'] ) {
        $out .= '    💬 ' . ptg_esc( $item['comment'] ) . "\n";
    }

    foreach ( $item['links'] as $link ) {
        if ( 'placement' !== $link['role'] ) {
            continue;
        }
        $out .= '    📐 ' . ptg_link( $link['url'], $link['label'] ) . "\n";
    }

    return $out;
}

/**
 * Строки параметров изделия. Изменённые показываются зачёркиванием.
 *
 * @param array $garment Параметры.
 * @return string
 */
function ptg_render_garment( $garment ) {
    $out = '';
    foreach ( $garment as $g ) {
        $value = $g['emoji'] . ptg_esc( $g['value'] );
        if ( ! empty( $g['overridden'] ) ) {
            $value = '<s>' . ptg_esc( $g['from_value'] ) . '</s> <b>' . $value . '</b>';
        }
        $out .= '    ↳ ' . ptg_esc( $g['label'] ) . ': ' . $value . "\n";
    }
    return $out;
}

/**
 * Предупреждения позиции.
 *
 * @param array  $notices Предупреждения.
 * @param string $slot    Какие выводить.
 * @return string
 */
function ptg_render_notices( $notices, $slot ) {
    $out = '';
    foreach ( (array) $notices as $n ) {
        if ( ( $n['slot'] ?? 'artwork' ) !== $slot ) {
            continue;
        }
        $out .= '    ⏳ ' . $n['text'] . "\n";
    }
    return $out;
}

/**
 * Бандл.
 *
 * @param array $b Нормализованный бандл.
 * @return string
 */
function ptg_render_bundle( $b ) {
    if ( empty( $b['name'] ) ) {
        return '';
    }

    $out = '• <b>' . ptg_esc( 'Бандл: ' . $b['name'] ) . '</b> — ' . (int) $b['total'] . " Br\n";

    if ( $b['discount_percent'] > 0 && $b['saved'] > 0 ) {
        $out .= '    💰 Скидка: −' . $b['discount_percent'] . '% (экономия ' . $b['saved'] . " Br)\n";
    } elseif ( $b['discount_percent'] > 0 ) {
        $out .= '    💰 Скидка: −' . $b['discount_percent'] . "%\n";
    }

    if ( ! empty( $b['shirts'] ) ) {
        $count = count( $b['shirts'] );
        $word  = ptg_ru_plural( $count, 'футболка', 'футболки', 'футболок' );
        $out  .= "    📦 Состав ({$count} {$word}):\n";

        foreach ( $b['shirts'] as $i => $sh ) {
            $out .= '      ' . ( $i + 1 ) . '. <b>' . ptg_esc( $sh['name'] ) . "</b>\n";
            if ( $sh['color'] ) {
                $out .= '         ↳ Цвет: ' . ptg_color_emoji( $sh['color'] ) . ptg_esc( $sh['color'] ) . "\n";
            }
            if ( $sh['size'] ) {
                $out .= '         ↳ Размер: ' . ptg_esc( $sh['size'] ) . "\n";
            }
            if ( $sh['type'] ) {
                $out .= '         ↳ Модель: ' . ptg_esc( $sh['type'] ) . "\n";
            }
        }
    }

    return $out;
}

/**
 * Баннер поправок — первой строкой, выше номера заказа.
 *
 * @param array $d Данные заказа.
 * @return array Строки или пустой массив.
 */
function ptg_render_banner_lines( $d ) {
    if ( empty( $d['overrides'] ) ) {
        return array();
    }

    $multi = ! empty( $d['flags']['multi_item'] );
    $lines = array( '🛑 <b>ИЗМЕНЕНО ПОСЛЕ ОФОРМЛЕНИЯ</b>' );

    // Нумерация позиций та же, что в кнопках выбора позиции.
    $pos = array();
    $n   = 0;
    foreach ( ptg_overridable_items( $d ) as $item ) {
        $n++;
        $pos[ (int) $item['item_id'] ] = $n;
    }

    foreach ( $d['overrides'] as $ovr ) {
        $prefix = ( $multi && isset( $pos[ $ovr['item_id'] ] ) )
            ? 'Поз. ' . $pos[ $ovr['item_id'] ] . ' · '
            : '';

        $line = $prefix . ptg_esc( $ovr['label'] ) . ': <s>' . ptg_esc( $ovr['from_label'] )
            . '</s> → <b>' . ptg_esc( $ovr['to_label'] ) . '</b>';

        if ( null !== $ovr['delta'] ) {
            $sign  = ( $ovr['delta'] > 0 ) ? '+' : '−';
            $line .= ' · доплата ' . $sign . ptg_esc( ptg_fmt_amount( abs( $ovr['delta'] ) ) ) . ' Br';
        }

        $lines[] = $line;
    }

    if ( ! empty( $d['flags']['mockup_stale'] ) ) {
        $lines[] = '🚫 <b>Макет требует перерисовки</b> — в печать не отдавать';
    }

    // Строку «имя файла и параметры ниже — старые» убрали в 2.15.2: сам
    // блок «ИЗМЕНЕНО ПОСЛЕ ОФОРМЛЕНИЯ» с перечёркнутыми значениями уже
    // всё объясняет, а место в карточке она занимала заметное. Вернуть —
    // фильтром ptg_banner_file_warning.
    if ( apply_filters( 'ptg_banner_file_warning', false, $d ) ) {
        $lines[] = '⚠️ Имя файла и параметры ниже — старые. Наносить по этому блоку.';
    }

    return $lines;
}

/**
 * Есть ли у заказа файлы, имя которых собрано по производственной маске.
 *
 * @param array $d Данные заказа.
 * @return bool
 */
function ptg_has_masked_files( $d ) {
    foreach ( (array) $d['items'] as $item ) {
        foreach ( (array) $item['links'] as $link ) {
            if ( in_array( $link['role'], array( 'preview', 'artwork', 'placement' ), true ) ) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Сумма без лишних нулей.
 *
 * @param float $amount Сумма.
 * @return string
 */
function ptg_fmt_amount( $amount ) {
    $amount = (float) $amount;
    if ( abs( $amount - round( $amount ) ) < 0.01 ) {
        return (string) (int) round( $amount );
    }
    return number_format( $amount, 2, '.', '' );
}

/**
 * Переписка одним сообщением для группы.
 *
 * Передача заказа другому человеку — главный сценарий: личные чаты в
 * Telegram не расшариваются, и без выгрузки новый менеджер не увидит ни
 * согласованный эскиз, ни присланное клиентом фото.
 *
 * @param WC_Order $order Заказ.
 * @param int      $limit Сколько последних сообщений.
 * @return string HTML.
 */
function ptg_render_transcript( $order, $limit = 25 ) {
    $log   = $order->get_meta( PTG_META_CHAT_LOG );
    $log   = is_array( $log ) ? $log : array();
    $files = ptg_chat_files( $order );

    if ( empty( $log ) && empty( $files ) ) {
        return '📜 По заказу #' . ptg_esc( $order->get_order_number() ) . ' переписки пока нет';
    }

    $lines = array( '📜 <b>Переписка по заказу #' . ptg_esc( $order->get_order_number() ) . '</b>' );
    $shown = array_slice( $log, - (int) $limit );

    if ( count( $log ) > count( $shown ) ) {
        $lines[] = '<i>показаны последние ' . count( $shown ) . ' из ' . count( $log ) . '</i>';
    }
    $lines[] = '';

    foreach ( $shown as $entry ) {
        $who  = ( 'out' === ( $entry['d'] ?? '' ) ) ? '▶️' : '◀️';
        $time = gmdate( 'd.m H:i', (int) ( $entry['t'] ?? 0 ) + 3 * 3600 );
        $text = (string) ( $entry['x'] ?? '' );

        if ( ! empty( $entry['f'] ) ) {
            $text = '[' . $entry['f'] . ']' . ( $text ? ' ' . $text : '' );
        }

        $body = ptg_esc( $text );
        if ( ! empty( $entry['del'] ) ) {
            $body = '<s>' . $body . '</s>';
        }
        if ( ! empty( $entry['u'] ) ) {
            $body .= ' ' . ptg_link( $entry['u'], 'файл' );
        }

        $lines[] = $who . ' <i>' . ptg_esc( $time ) . '</i> ' . $body;
    }

    if ( $files ) {
        $lines[] = '';
        $lines[] = '📎 <b>Файлы:</b>';
        foreach ( $files as $i => $f ) {
            $lines[] = '  ' . ( $i + 1 ) . '. ' . ptg_link( $f['url'], ( 'out' === $f['dir'] ? 'нам → ' : 'от клиента: ' ) . $f['name'] );
        }
    }

    $html = implode( "\n", $lines );

    if ( ptg_tg_len( $html ) > PTG_TG_LIMIT ) {
        $html = mb_substr( $html, 0, 3800 ) . "\n…\n" . ptg_link( ptg_get_order_admin_edit_url( $order ), 'Полная переписка в админке' );
    }

    return $html;
}

<?php
/**
 * Фаза 4. Превью макета в карточке.
 *
 * Задача — чтобы печатник видел, что печатать, не открывая ссылки.
 *
 * Почему не sendPhoto с подписью: подпись ограничена 1024 символами, а
 * карточка заказа обычно длиннее. Пришлось бы либо резать данные, либо
 * держать два сообщения на заказ — и тогда ломается вся модель Фазы 0,
 * где у заказа ровно одно живое редактируемое сообщение.
 *
 * Решение — `link_preview_options` с `show_above_text`: Telegram
 * показывает картинку над текстом того же сообщения. Ограничений на
 * длину текста нет, сообщение остаётся одно и редактируется как прежде.
 *
 * Запасной режим — отдельное фото реплаем, файл заливается с сервера
 * multipart. Нужен на случай, если Telegram не сможет сам забрать
 * картинку с сайта: превью по ссылке требует, чтобы краулер Telegram
 * достучался до сайта, а WAF на этом хостинге уже показывал
 * характер.
 *
 * @package podval-telegram-orders
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Мета отдельного сообщения с фото (запасной режим). */
const PTG_META_PHOTO_MESSAGE = '_ptg_photo_message_id';
const PTG_META_PHOTO_KEY     = '_ptg_photo_key';

/**
 * Режим показа превью.
 *
 * @return string link|photo|off
 */
function ptg_preview_mode() {
    $mode = (string) get_option( 'ptg_preview_mode', 'link' );
    return in_array( $mode, array( 'link', 'photo', 'off' ), true ) ? $mode : 'link';
}

/**
 * Картинка заказа.
 *
 * Приоритет: собранный макет конструктора → артворк клиента → картинка
 * товара из каталога. Последнее выключается отдельно: для позиции
 * конструктора фото каталога бесполезно, а для готового дизайна —
 * наоборот, показывает ровно то, что будут наносить.
 *
 * @param array    $data  Данные заказа.
 * @param WC_Order $order Заказ.
 * @return array|null [ url, att_id, source ].
 */
function ptg_order_preview( $data, $order ) {
    foreach ( array( 'preview', 'artwork' ) as $role ) {
        foreach ( $data['items'] as $item ) {
            foreach ( $item['links'] as $link ) {
                if ( $link['role'] !== $role ) {
                    continue;
                }
                if ( ! ptg_is_image_url( $link['url'] ) ) {
                    continue;
                }
                $ready = ptg_preview_variant( $link['url'] );
                return array(
                    'url'      => $ready,
                    'att_id'   => 0,
                    'source'   => $role,
                    'origin'   => (string) $link['url'],
                    'prepared' => ( $ready !== $link['url'] ),
                );
            }
        }
    }

    // Заказ, оформленный вручную из приложения, обычно без макетов:
    // показываем то, что отправили клиенту сами (эскиз), — это и есть
    // картинка заказа для глаз оператора.
    $chat = ptg_chat_preview( $order );
    if ( $chat ) {
        return $chat;
    }

    if ( ! get_option( 'ptg_preview_product_fallback', 1 ) ) {
        return null;
    }

    foreach ( $order->get_items() as $item ) {
        $product = $item->get_product();
        if ( ! $product ) {
            continue;
        }
        $img_id = (int) $product->get_image_id();
        if ( ! $img_id ) {
            continue;
        }
        $url = wp_get_attachment_url( $img_id );
        if ( $url ) {
            return array(
                'url'    => $url,
                'att_id' => $img_id,
                'source' => 'product',
            );
        }
    }

    return null;
}

/**
 * Похоже ли на картинку.
 *
 * @param string $url Адрес.
 * @return bool
 */
function ptg_is_image_url( $url ) {
    $path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
    $ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
    return in_array( $ext, array( 'jpg', 'jpeg', 'png', 'webp', 'gif' ), true );
}

/**
 * Адрес картинки для Telegram — с меткой ревизии (2.17.1).
 *
 * Telegram кэширует превью по адресу у себя на сервере, причём и
 * неудачу тоже: если в момент первой отправки он не смог забрать
 * картинку (сайт был недоступен), то при каждой следующей правке
 * сообщения с той же ссылкой он отдаёт закэшированное «ничего», сколько
 * ни меняй статус. Новый адрес — единственный способ заставить его
 * сходить заново. Метка `tg=` файлу не мешает: nginx отдаёт статику без
 * оглядки на query string.
 *
 * Ревизия общая; «Обновить превью» в настройках поднимает её и
 * пересобирает карточки открытых заказов.
 *
 * @param string $url Адрес.
 * @return string
 */
function ptg_preview_tg_url( $url ) {
    // По умолчанию 1, а не 0: сразу после обновления плагина адреса уже
    // новые, и старые карточки подхватят картинку при первой же правке.
    $rev = (int) get_option( 'ptg_preview_rev', 1 );
    if ( $rev <= 0 || '' === (string) $url ) {
        return (string) $url;
    }
    $sep = false === strpos( (string) $url, '?' ) ? '?' : '&';
    return $url . $sep . 'tg=' . $rev;
}

/**
 * Поднять ревизию превью и пересобрать карточки открытых заказов.
 *
 * Правки уходят по крону с шагом в 4 секунды: Telegram режет больше
 * ~20 правок в минуту в одной группе, а пачка из сорока подряд упрётся
 * в 429 на середине.
 *
 * @param int $days За сколько дней брать заказы.
 * @return int Сколько заказов поставлено в очередь.
 */
function ptg_preview_refresh_all( $days = 21 ) {
    update_option( 'ptg_preview_rev', (int) get_option( 'ptg_preview_rev', 1 ) + 1, false );

    $ids = wc_get_orders( array(
        'limit'        => 60,
        'return'       => 'ids',
        'type'         => 'shop_order',
        'status'       => array_map( function ( $s ) { return 'wc-' . $s; }, ptg_refusable_statuses() ),
        'date_created' => '>' . ( time() - $days * DAY_IN_SECONDS ),
        'orderby'      => 'date',
        'order'        => 'DESC',
    ) );

    $n = 0;
    foreach ( (array) $ids as $id ) {
        $order = wc_get_order( $id );
        if ( ! $order || ! (int) $order->get_meta( PTG_META_MESSAGE ) ) {
            continue;
        }
        wp_schedule_single_event( time() + 5 + $n * 4, 'ptg_preview_refresh_one', array( (int) $id ) );
        $n++;
    }
    ptg_log( 'info', 'Превью: обновление карточек', array( 'rev' => get_option( 'ptg_preview_rev' ), 'orders' => $n ) );
    return $n;
}

/**
 * Пересборка одной карточки из очереди.
 *
 * @param int $order_id Заказ.
 * @return void
 */
function ptg_preview_refresh_one( $order_id ) {
    $order = wc_get_order( (int) $order_id );
    if ( ! $order ) {
        return;
    }
    ptg_force_next_sync( $order );
    ptg_sync_order_message( $order, 'preview_refresh' );
}
add_action( 'ptg_preview_refresh_one', 'ptg_preview_refresh_one', 10, 1 );

/**
 * Параметры превью для sendMessage/editMessageText.
 *
 * @param array $data Данные заказа.
 * @return array
 */
function ptg_link_preview_args( $data ) {
    $preview = $data['preview'] ?? null;

    if ( 'link' !== ptg_preview_mode() || ! $preview ) {
        return array( 'link_preview_options' => array( 'is_disabled' => true ) );
    }

    return array(
        'link_preview_options' => array(
            'is_disabled'        => false,
            'url'                => ptg_safe_url( ptg_preview_tg_url( $preview['url'] ) ),
            'prefer_large_media' => true,
            // Картинка над текстом: печатник видит макет первым, не
            // прокручивая карточку.
            'show_above_text'    => true,
        ),
    );
}

/**
 * Запасной режим: отдельное сообщение с фото реплаем на карточку.
 *
 * @param WC_Order $order Заказ.
 * @param array    $data  Данные заказа.
 * @return void
 */
function ptg_sync_photo_message( $order, $data ) {
    if ( 'photo' !== ptg_preview_mode() ) {
        return;
    }

    $preview = $data['preview'] ?? null;
    $key     = $preview ? md5( (string) $preview['url'] ) : '';
    $old_key = (string) $order->get_meta( PTG_META_PHOTO_KEY );
    $old_mid = (int) $order->get_meta( PTG_META_PHOTO_MESSAGE );

    if ( $key === $old_key ) {
        return;
    }

    $chat_id = (string) $order->get_meta( PTG_META_CHAT );
    if ( '' === $chat_id ) {
        $chat_id = (string) get_option( 'ptg_chat_id', '' );
    }

    // Макет поменялся — старое фото вводит в заблуждение.
    if ( $old_mid ) {
        ptg_api( 'deleteMessage', array( 'chat_id' => $chat_id, 'message_id' => $old_mid ) );
        $order->delete_meta_data( PTG_META_PHOTO_MESSAGE );
    }

    if ( ! $preview ) {
        $order->update_meta_data( PTG_META_PHOTO_KEY, '' );
        $order->save();
        return;
    }

    $path = ptg_local_path_for_url( $preview['url'] );
    $mid  = $path
        ? ptg_tg_send_photo_file( $chat_id, $path, '', array(
            'reply_to_message_id' => (int) $order->get_meta( PTG_META_MESSAGE ),
        ) )
        : ptg_tg_send_photo( $chat_id, $preview['url'], '', array(
            'reply_to_message_id' => (int) $order->get_meta( PTG_META_MESSAGE ),
        ) );

    if ( is_wp_error( $mid ) ) {
        ptg_log( 'warn', 'Фото макета не отправлено', array(
            'order' => $order->get_id(),
            'err'   => $mid->get_error_message(),
        ) );
        return;
    }

    $order->update_meta_data( PTG_META_PHOTO_MESSAGE, (int) $mid );
    $order->update_meta_data( PTG_META_PHOTO_KEY, $key );
    $order->save();
}

/**
 * Локальный путь к файлу по его URL.
 *
 * Заливка файлом надёжнее ссылки: Telegram не ходит на сайт, а значит
 * WAF, приватность папки и медленный ответ уже не при чём.
 *
 * @param string $url Адрес.
 * @return string Путь или ''.
 */
function ptg_local_path_for_url( $url ) {
    $att_id = attachment_url_to_postid( $url );
    if ( ! $att_id ) {
        return '';
    }
    $path = get_attached_file( $att_id );
    return ( $path && file_exists( $path ) ) ? $path : '';
}

/**
 * sendPhoto с заливкой файла (multipart).
 *
 * @param string|int $chat_id Чат.
 * @param string     $path    Путь к файлу.
 * @param string     $caption Подпись.
 * @param array      $args    Доп. параметры.
 * @return int|WP_Error message_id.
 */
function ptg_tg_send_photo_file( $chat_id, $path, $caption = '', $args = array() ) {
    $token = trim( (string) get_option( 'ptg_bot_token', '' ) );
    if ( '' === $token ) {
        return new WP_Error( 'ptg_no_token', 'Не указан Bot Token' );
    }

    $bytes = file_get_contents( $path );
    if ( false === $bytes ) {
        return new WP_Error( 'ptg_read_failed', 'Не удалось прочитать файл' );
    }

    $fields = array_merge( array(
        'chat_id' => (string) $chat_id,
        'caption' => $caption,
    ), array_map( 'strval', $args ) );

    $boundary = 'ptg' . wp_generate_password( 24, false );
    $body     = '';

    foreach ( $fields as $name => $value ) {
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n";
        $body .= $value . "\r\n";
    }

    $body .= "--{$boundary}\r\n";
    $body .= "Content-Disposition: form-data; name=\"photo\"; filename=\"" . basename( $path ) . "\"\r\n";
    $body .= "Content-Type: application/octet-stream\r\n\r\n";
    $body .= $bytes . "\r\n";
    $body .= "--{$boundary}--\r\n";

    $response = wp_remote_post( 'https://api.telegram.org/bot' . $token . '/sendPhoto', array(
        'timeout' => 30,
        'headers' => array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ),
        'body'    => $body,
    ) );

    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'ptg_http', $response->get_error_message() );
    }

    $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( empty( $decoded['ok'] ) ) {
        return new WP_Error( 'ptg_api', (string) ( $decoded['description'] ?? 'Ошибка sendPhoto' ) );
    }

    return (int) ( $decoded['result']['message_id'] ?? 0 );
}

/**
 * Невидимая ссылка на картинку превью (2.17.2).
 *
 * Превью задаётся через link_preview_options.url, но ссылку на тот же
 * адрес держим и в тексте: так Telegram гарантированно рисует картинку
 * (без неё бывали пустые карточки). Видимая строка «🖼 Превью макета»
 * при этом только мешала — ссылки на принты есть в позициях, а картинка
 * и так над текстом. Поэтому ссылка невидимая: текст из одного символа
 * нулевой ширины, ставится в начало заголовка карточки.
 *
 * @param array $data Данные заказа.
 * @return string HTML или ''.
 */
function ptg_render_preview_anchor( $data ) {
    $preview = $data['preview'] ?? null;
    if ( ! $preview || 'link' !== ptg_preview_mode() || 'product' === ( $preview['source'] ?? '' ) ) {
        return '';
    }
    return ptg_link( ptg_preview_tg_url( $preview['url'] ), "\u{200B}" );
}

/**
 * Строка о макете для карточки.
 *
 * @param array $data Данные заказа.
 * @return string
 */
function ptg_render_preview_line( $data ) {
    $preview = $data['preview'] ?? null;
    if ( ! $preview || 'off' === ptg_preview_mode() ) {
        return '';
    }

    if ( 'product' === $preview['source'] ) {
        // Честно помечаем: это не макет, а фото товара из каталога.
        return '🖼 <i>На картинке — фото товара, не макет</i>';
    }

    return '';
}

/**
 * Последнее изображение из переписки: сначала наше исходящее (эскиз),
 * потом любое.
 *
 * @param WC_Order $order Заказ.
 * @return array|null [ url, att_id, source ].
 */
function ptg_chat_preview( $order ) {
    if ( ! function_exists( 'ptg_chat_files' ) ) {
        return null;
    }
    $out = null;
    $any = null;
    foreach ( ptg_chat_files( $order ) as $f ) {
        $url = (string) ( $f['url'] ?? '' );
        if ( 'photo' !== ( $f['kind'] ?? '' ) && ! ptg_is_image_url( $url ) ) {
            continue;
        }
        if ( '' === $url ) {
            continue;
        }
        $rec = array( 'url' => $url, 'att_id' => (int) ( $f['id'] ?? 0 ), 'source' => 'chat' );
        $any = $rec;
        if ( 'out' === ( $f['dir'] ?? '' ) ) {
            $out = $rec;
        }
    }
    return $out ?: $any;
}

/* ── Подготовка картинки для показа (2.15.0) ───────────────────── */

/** Максимальная сторона подготовленной картинки. */
const PTG_PREVIEW_MAX_SIDE = 1600;

/** Дальше этого размера Telegram превью по ссылке не показывает. */
const PTG_PREVIEW_MAX_BYTES = 4 * MB_IN_BYTES;

/** Больше этого числа пикселей не разворачиваем: съест память. */
const PTG_PREVIEW_MAX_PIXELS = 40000000;

/**
 * Картинка, пригодная для показа в карточке и в приложении.
 *
 * Два повода не отдавать исходник:
 * - тяжёлый файл (макет на спину бывает 7 МБ) — Telegram молча не
 *   покажет превью по ссылке, и в карточке не будет вообще ничего;
 * - прозрачный фон — белый принт на прозрачном фоне Telegram кладёт на
 *   белое, и остаётся пустой квадрат.
 *
 * Поэтому делаем свой вариант: уменьшаем и подкладываем шахматку, по
 * которой одинаково видно и белый, и чёрный принт. Результат кэшируется
 * рядом в uploads и переиспользуется.
 *
 * @param string $url Адрес исходника.
 * @return string Адрес подготовленной картинки или исходник.
 */
function ptg_preview_variant( $url ) {
    $path = ptg_url_to_path( $url );
    if ( ! $path || ! is_readable( $path ) ) {
        return $url;
    }

    $size = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
    if ( ! $size ) {
        return $url;
    }
    $bytes = (int) filesize( $path );
    $alpha = ptg_image_has_alpha( $path, (int) $size[2] );
    $big   = $bytes > PTG_PREVIEW_MAX_BYTES || $size[0] > PTG_PREVIEW_MAX_SIDE || $size[1] > PTG_PREVIEW_MAX_SIDE;

    if ( ! $alpha && ! $big ) {
        return $url;
    }


    $uploads = wp_upload_dir();
    $dir     = trailingslashit( $uploads['basedir'] ) . 'ptg-previews';
    $name    = md5( $path . '|' . $bytes . '|' . filemtime( $path ) . '|v1' ) . '.jpg';
    $out     = $dir . '/' . $name;
    $out_url = trailingslashit( $uploads['baseurl'] ) . 'ptg-previews/' . $name;

    if ( file_exists( $out ) ) {
        return $out_url;
    }
    if ( ! wp_mkdir_p( $dir ) ) {
        return $url;
    }

    $scale = min( 1.0, PTG_PREVIEW_MAX_SIDE / max( $size[0], $size[1] ) );
    $w     = max( 1, (int) round( $size[0] * $scale ) );
    $h     = max( 1, (int) round( $size[1] * $scale ) );

    // Imagick держит картинку вне памяти PHP, поэтому макет на 40 Мп ему
    // по силам там, где GD упирается в memory_limit.
    $done = ptg_preview_imagick( $path, $out, $w, $h )
        || ptg_preview_gd( $path, (int) $size[2], $out, $size[0], $size[1], $w, $h );

    if ( ! $done ) {
        ptg_log( 'warn', 'Превью подготовить не удалось — отдаём исходник', array(
            'file' => basename( $path ),
            'px'   => $size[0] . 'x' . $size[1],
            'мб'   => round( $bytes / MB_IN_BYTES, 1 ),
        ) );
        return $url;
    }

    return file_exists( $out ) ? $out_url : $url;
}

/**
 * Хватит ли памяти развернуть картинку в GD.
 *
 * GD держит пиксель в 4 байтах плюс накладные расходы; если памяти
 * впритык, процесс падает фатально и обрывает всё, что делалось вокруг
 * (вплоть до записи истории заказа). Лучше отдать исходник.
 *
 * @param int $w Ширина.
 * @param int $h Высота.
 * @return bool
 */
function ptg_memory_for_image( $w, $h ) {
    if ( function_exists( 'wp_raise_memory_limit' ) ) {
        wp_raise_memory_limit( 'image' );
    }

    $limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
    if ( $limit <= 0 ) {
        return true; // без ограничения
    }
    $need = (int) ( $w * $h * 4 * 1.8 ) + ( PTG_PREVIEW_MAX_SIDE * PTG_PREVIEW_MAX_SIDE * 4 );
    return ( memory_get_usage( true ) + $need ) < $limit;
}

/**
 * Сборка через Imagick.
 *
 * @param string $path Исходник.
 * @param string $out  Куда писать.
 * @param int    $w    Ширина результата.
 * @param int    $h    Высота результата.
 * @return bool
 */
function ptg_preview_imagick( $path, $out, $w, $h ) {
    if ( ! class_exists( 'Imagick' ) ) {
        return false;
    }
    try {
        $im = new Imagick();
        $im->setResourceLimit( Imagick::RESOURCETYPE_MEMORY, 256 * MB_IN_BYTES );
        $im->setResourceLimit( Imagick::RESOURCETYPE_MAP, 512 * MB_IN_BYTES );

        // Подсказка декодеру: JPEG разворачивается сразу уменьшенным, а
        // не целиком. Для макета 12000×15000 это разница между парой
        // секунд и минутой с гигабайтом памяти.
        $im->setOption( 'jpeg:size', $w . 'x' . $h );
        $im->readImage( $path );
        $im->setImageColorspace( Imagick::COLORSPACE_SRGB );
        $im->thumbnailImage( $w, $h, true );

        $bg = new Imagick();
        $bg->newPseudoImage( $w, $h, 'canvas:white' );
        ptg_imagick_checkerboard( $bg, $w, $h );
        $bg->compositeImage( $im, Imagick::COMPOSITE_OVER, 0, 0 );
        $bg->setImageFormat( 'jpeg' );
        $bg->setImageCompressionQuality( 86 );
        $bg->writeImage( $out );

        $im->clear();
        $bg->clear();
        return file_exists( $out );
    } catch ( Throwable $e ) {
        ptg_log( 'warn', 'Imagick не справился с превью', array(
            'file'  => basename( $path ),
            'error' => $e->getMessage(),
        ) );
        return false;
    }
}

/**
 * Шахматка на холсте Imagick.
 *
 * @param Imagick $img Холст.
 * @param int     $w   Ширина.
 * @param int     $h   Высота.
 * @return void
 */
function ptg_imagick_checkerboard( $img, $w, $h ) {
    $cell = max( 8, (int) round( min( $w, $h ) / 24 ) );
    $draw = new ImagickDraw();
    $draw->setFillColor( new ImagickPixel( 'rgb(222,222,222)' ) );
    $draw->rectangle( 0, 0, $w, $h );
    $draw->setFillColor( new ImagickPixel( 'rgb(176,176,176)' ) );
    for ( $y = 0; $y < $h; $y += $cell ) {
        for ( $x = 0; $x < $w; $x += $cell ) {
            if ( 0 === ( ( (int) ( $x / $cell ) + (int) ( $y / $cell ) ) % 2 ) ) {
                continue;
            }
            $draw->rectangle( $x, $y, min( $x + $cell, $w ), min( $y + $cell, $h ) );
        }
    }
    $img->drawImage( $draw );
    $draw->destroy();
}

/**
 * Сборка через GD — когда Imagick недоступен.
 *
 * @param string $path Исходник.
 * @param int    $type IMAGETYPE_*.
 * @param string $out  Куда писать.
 * @param int    $sw   Ширина исходника.
 * @param int    $sh   Высота исходника.
 * @param int    $w    Ширина результата.
 * @param int    $h    Высота результата.
 * @return bool
 */
function ptg_preview_gd( $path, $type, $out, $sw, $sh, $w, $h ) {
    // GD разворачивает картинку целиком, поэтому гигантские макеты ему
    // не отдаём: лучше оставить исходник, чем уронить процесс.
    if ( $sw * $sh > PTG_PREVIEW_MAX_PIXELS ) {
        return false;
    }
    if ( ! function_exists( 'imagecreatetruecolor' ) || ! ptg_memory_for_image( $sw, $sh ) ) {
        return false;
    }
    $src = ptg_image_load( $path, $type );
    if ( ! $src ) {
        return false;
    }
    $canvas = imagecreatetruecolor( $w, $h );
    ptg_fill_checkerboard( $canvas, $w, $h );
    imagecopyresampled( $canvas, $src, 0, 0, 0, 0, $w, $h, $sw, $sh );
    imagejpeg( $canvas, $out, 86 );
    imagedestroy( $canvas );
    imagedestroy( $src );
    return file_exists( $out );
}

/**
 * Шахматка под прозрачные принты: белый виден на сером, чёрный на белом.
 *
 * @param resource|GdImage $img Холст.
 * @param int              $w   Ширина.
 * @param int              $h   Высота.
 * @return void
 */
function ptg_fill_checkerboard( $img, $w, $h ) {
    // Не белое с серым: на белой клетке белый принт исчезал. Обе клетки
    // серые, но заметно разной светлоты — видно и белое, и чёрное.
    $light = imagecolorallocate( $img, 222, 222, 222 );
    $dark  = imagecolorallocate( $img, 176, 176, 176 );
    $cell  = max( 8, (int) round( min( $w, $h ) / 24 ) );
    imagefilledrectangle( $img, 0, 0, $w, $h, $light );
    for ( $y = 0; $y < $h; $y += $cell ) {
        for ( $x = 0; $x < $w; $x += $cell ) {
            if ( 0 === ( ( (int) ( $x / $cell ) + (int) ( $y / $cell ) ) % 2 ) ) {
                continue;
            }
            imagefilledrectangle( $img, $x, $y, min( $x + $cell, $w ), min( $y + $cell, $h ), $dark );
        }
    }
}

/**
 * Есть ли в файле прозрачность.
 *
 * Для PNG читаем цветовой тип из заголовка (байт 25): 4 и 6 — с
 * альфа-каналом. Плюс блок tRNS у палитровых.
 *
 * @param string $path Путь.
 * @param int    $type IMAGETYPE_*.
 * @return bool
 */
function ptg_image_has_alpha( $path, $type ) {
    if ( IMAGETYPE_WEBP === $type ) {
        return true; // дешевле пересобрать, чем разбирать контейнер
    }
    if ( IMAGETYPE_PNG !== $type ) {
        return false;
    }
    $head = (string) file_get_contents( $path, false, null, 0, 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
    if ( strlen( $head ) < 26 ) {
        return false;
    }
    $color_type = ord( $head[25] );
    return in_array( $color_type, array( 4, 6 ), true ) || false !== strpos( $head, 'tRNS' );
}

/**
 * Загружает изображение в GD.
 *
 * @param string $path Путь.
 * @param int    $type IMAGETYPE_*.
 * @return resource|GdImage|null
 */
function ptg_image_load( $path, $type ) {
    switch ( $type ) {
        case IMAGETYPE_PNG:
            return function_exists( 'imagecreatefrompng' ) ? imagecreatefrompng( $path ) : null;
        case IMAGETYPE_JPEG:
            return function_exists( 'imagecreatefromjpeg' ) ? imagecreatefromjpeg( $path ) : null;
        case IMAGETYPE_GIF:
            return function_exists( 'imagecreatefromgif' ) ? imagecreatefromgif( $path ) : null;
        case IMAGETYPE_WEBP:
            return function_exists( 'imagecreatefromwebp' ) ? imagecreatefromwebp( $path ) : null;
    }
    return null;
}

/**
 * Путь к файлу по адресу внутри uploads.
 *
 * @param string $url Адрес.
 * @return string '' если файл не наш.
 */
function ptg_url_to_path( $url ) {
    $uploads = wp_upload_dir();
    $base    = trailingslashit( $uploads['baseurl'] );
    $url     = strtok( (string) $url, '?' );

    // Сайт может отдавать и http, и https — сравниваем без схемы.
    $strip = function ( $u ) {
        return preg_replace( '#^https?://#', '', (string) $u );
    };
    $base_s = $strip( $base );
    $url_s  = $strip( $url );
    if ( 0 !== strpos( $url_s, $base_s ) ) {
        return '';
    }
    $rel = substr( $url_s, strlen( $base_s ) );
    if ( false !== strpos( $rel, '..' ) ) {
        return '';
    }
    return trailingslashit( $uploads['basedir'] ) . $rel;
}

<?php
/**
 * Файлы из переписки с клиентом.
 *
 * Зачем. Эскизы уходят клиенту, а клиент досылает фото — и всё это живёт
 * в личке одного менеджера. Передать заказ другому человеку в Telegram
 * нельзя: личные чаты не расшаривают. Значит единственный способ не
 * просить клиента прислать всё заново — складывать файлы на сайт в момент
 * получения.
 *
 * @package podval-telegram-orders
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Мета со списком файлов переписки. */
const PTG_META_CHAT_FILES = '_ptg_chat_files';

/** Потолок Bot API на скачивание файла — 20 МБ. */
const PTG_FILE_MAX_BYTES = 20 * 1024 * 1024;

/**
 * Достаёт из сообщения описание вложения.
 *
 * Стикеры и кружки намеренно не скачиваем: производственной ценности в
 * них нет, а медиатеку они засоряют.
 *
 * @param array $msg Сообщение.
 * @return array|null [ file_id, kind, name, size ].
 */
function ptg_extract_attachment( $msg ) {
    if ( ! empty( $msg['photo'] ) && is_array( $msg['photo'] ) ) {
        // Массив размеров, последний — самый крупный.
        $best = end( $msg['photo'] );
        return array(
            'file_id' => (string) ( $best['file_id'] ?? '' ),
            'kind'    => 'photo',
            'name'    => 'photo.jpg',
            'size'    => (int) ( $best['file_size'] ?? 0 ),
        );
    }

    if ( ! empty( $msg['document'] ) ) {
        $doc = $msg['document'];
        return array(
            'file_id' => (string) ( $doc['file_id'] ?? '' ),
            'kind'    => 'document',
            'name'    => (string) ( $doc['file_name'] ?? 'file' ),
            'size'    => (int) ( $doc['file_size'] ?? 0 ),
        );
    }

    if ( ! empty( $msg['video'] ) ) {
        return array(
            'file_id' => (string) ( $msg['video']['file_id'] ?? '' ),
            'kind'    => 'video',
            'name'    => (string) ( $msg['video']['file_name'] ?? 'video.mp4' ),
            'size'    => (int) ( $msg['video']['file_size'] ?? 0 ),
        );
    }

    if ( ! empty( $msg['voice'] ) ) {
        return array(
            'file_id' => (string) ( $msg['voice']['file_id'] ?? '' ),
            'kind'    => 'voice',
            'name'    => 'voice.ogg',
            'size'    => (int) ( $msg['voice']['file_size'] ?? 0 ),
        );
    }

    return null;
}

/**
 * Скачивает вложение и кладёт в медиатеку.
 *
 * @param array    $att       Описание из ptg_extract_attachment().
 * @param WC_Order $order     Заказ.
 * @param string   $direction in|out.
 * @return array|WP_Error Запись файла.
 */
function ptg_ingest_attachment( $att, $order, $direction = 'in' ) {
    if ( empty( $att['file_id'] ) ) {
        return new WP_Error( 'ptg_no_file_id', 'Нет file_id' );
    }

    if ( $att['size'] > PTG_FILE_MAX_BYTES ) {
        return new WP_Error( 'ptg_file_too_big', 'Файл больше 20 МБ — Bot API его не отдаёт' );
    }

    $info = ptg_api( 'getFile', array( 'file_id' => $att['file_id'] ) );
    if ( is_wp_error( $info ) ) {
        return $info;
    }

    $path = (string) ( $info['file_path'] ?? '' );
    if ( '' === $path ) {
        return new WP_Error( 'ptg_no_path', 'Telegram не вернул file_path' );
    }

    $token = trim( (string) get_option( 'ptg_bot_token', '' ) );
    $url   = 'https://api.telegram.org/file/bot' . $token . '/' . $path;

    $response = wp_remote_get( $url, array( 'timeout' => 30 ) );
    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $bytes = wp_remote_retrieve_body( $response );
    if ( '' === $bytes ) {
        return new WP_Error( 'ptg_empty_file', 'Пустой ответ при скачивании' );
    }

    // Имя по маске заказа: файл потом ищут глазами в медиатеке.
    $ext      = pathinfo( $path, PATHINFO_EXTENSION );
    $ext      = $ext ? $ext : pathinfo( $att['name'], PATHINFO_EXTENSION );
    $ext      = $ext ? $ext : 'bin';
    $filename = sprintf(
        '%s-chat-%s-%s.%s',
        $order->get_order_number(),
        $direction,
        gmdate( 'Ymd-His' ),
        strtolower( $ext )
    );

    $upload = wp_upload_bits( $filename, null, $bytes );
    if ( ! empty( $upload['error'] ) ) {
        return new WP_Error( 'ptg_upload_failed', (string) $upload['error'] );
    }

    $filetype = wp_check_filetype( $upload['file'], null );
    $att_id   = wp_insert_attachment( array(
        'post_mime_type' => $filetype['type'] ? $filetype['type'] : 'application/octet-stream',
        'post_title'     => sprintf( 'Заказ %s — файл из переписки', $order->get_order_number() ),
        'post_content'   => '',
        'post_status'    => 'inherit',
    ), $upload['file'] );

    if ( is_wp_error( $att_id ) || ! $att_id ) {
        return new WP_Error( 'ptg_attach_failed', 'Не удалось создать вложение' );
    }

    if ( function_exists( 'wp_generate_attachment_metadata' ) ) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_update_attachment_metadata( $att_id, wp_generate_attachment_metadata( $att_id, $upload['file'] ) );
    }

    return array(
        'id'   => (int) $att_id,
        'url'  => (string) $upload['url'],
        'kind' => (string) $att['kind'],
        'name' => (string) $att['name'],
        'dir'  => $direction,
        't'    => time(),
    );
}

/**
 * Добавляет файл в список файлов заказа.
 *
 * @param WC_Order $order Заказ.
 * @param array    $file  Запись файла.
 * @return void
 */
function ptg_chat_files_add( $order, $file ) {
    $files = $order->get_meta( PTG_META_CHAT_FILES );
    if ( ! is_array( $files ) ) {
        $files = array();
    }
    $files[] = $file;
    $order->update_meta_data( PTG_META_CHAT_FILES, $files );
    $order->save();
}

/**
 * Файлы переписки заказа.
 *
 * @param WC_Order $order Заказ.
 * @return array
 */
function ptg_chat_files( $order ) {
    $files = $order->get_meta( PTG_META_CHAT_FILES );
    return is_array( $files ) ? $files : array();
}

/**
 * Забирает вложение сообщения, если оно есть.
 *
 * @param array    $msg       Сообщение.
 * @param WC_Order $order     Заказ.
 * @param string   $direction in|out.
 * @return array|null Запись файла.
 */
function ptg_maybe_ingest( $msg, $order, $direction ) {
    if ( ! get_option( 'ptg_save_chat_files', 1 ) ) {
        return null;
    }

    $att = ptg_extract_attachment( $msg );
    if ( ! $att ) {
        return null;
    }

    $file = ptg_ingest_attachment( $att, $order, $direction );
    if ( is_wp_error( $file ) ) {
        ptg_log( 'warn', 'Файл из переписки не сохранён', array(
            'order' => $order->get_id(),
            'err'   => $file->get_error_message(),
        ) );
        return null;
    }

    ptg_chat_files_add( $order, $file );
    ptg_log( 'info', 'Файл из переписки сохранён', array(
        'order' => $order->get_id(),
        'att'   => $file['id'],
        'kind'  => $file['kind'],
    ) );

    return $file;
}

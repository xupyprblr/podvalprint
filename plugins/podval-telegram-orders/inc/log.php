<?php
/**
 * Кольцевой журнал плагина.
 *
 * Опция, а не файл: nginx, .htaccess не работает, класть логи в доступную
 * из веба директорию нельзя.
 *
 * Пишется всегда, не только под WP_DEBUG: на проде WP_DEBUG выключен, а
 * журнал нужен именно там — диагностировать вебхук больше нечем.
 *
 * @package podval-telegram-orders
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Сколько записей держим. */
const PTG_LOG_MAX = 200;

/** Предел на сериализованный контекст одной записи. */
const PTG_LOG_CTX_MAX = 1024;

/**
 * Пишет строку в журнал.
 *
 * @param string $level info|warn|error.
 * @param string $msg   Сообщение.
 * @param array  $ctx   Контекст (маскируется и обрезается).
 * @return void
 */
function ptg_log( $level, $msg, $ctx = array() ) {
    $entry = array(
        't' => time(),
        'l' => in_array( $level, array( 'info', 'warn', 'error' ), true ) ? $level : 'info',
        'm' => mb_substr( (string) $msg, 0, 500 ),
        'c' => ptg_log_prepare_ctx( $ctx ),
    );

    $log = get_option( 'ptg_log', array() );
    if ( ! is_array( $log ) ) {
        $log = array();
    }

    $log[] = $entry;
    if ( count( $log ) > PTG_LOG_MAX ) {
        $log = array_slice( $log, - PTG_LOG_MAX );
    }

    update_option( 'ptg_log', $log, false );
}

/**
 * Маскирует секреты и обрезает контекст.
 *
 * Токен бота и secret_token в журнал попадать не должны: страницу настроек
 * видит любой администратор, а экспорт опций — ещё и бэкапы.
 *
 * @param array $ctx Контекст.
 * @return array
 */
function ptg_log_prepare_ctx( $ctx ) {
    if ( ! is_array( $ctx ) ) {
        $ctx = array( 'value' => $ctx );
    }

    $secrets = array_filter( array(
        (string) get_option( 'ptg_bot_token', '' ),
        (string) get_option( 'ptg_webhook_secret', '' ),
    ) );

    $ctx = ptg_log_mask_deep( $ctx, $secrets );

    $json = wp_json_encode( $ctx );
    if ( is_string( $json ) && strlen( $json ) > PTG_LOG_CTX_MAX ) {
        return array( 'truncated' => mb_substr( $json, 0, PTG_LOG_CTX_MAX ) . '…' );
    }

    return $ctx;
}

/**
 * Рекурсивная маскировка.
 *
 * @param mixed $value   Значение.
 * @param array $secrets Строки, которые надо скрыть.
 * @return mixed
 */
function ptg_log_mask_deep( $value, $secrets ) {
    if ( is_array( $value ) ) {
        $out = array();
        foreach ( $value as $k => $v ) {
            $key_l = mb_strtolower( (string) $k );
            if ( false !== mb_strpos( $key_l, 'token' ) || false !== mb_strpos( $key_l, 'secret' ) ) {
                $out[ $k ] = '***';
                continue;
            }
            $out[ $k ] = ptg_log_mask_deep( $v, $secrets );
        }
        return $out;
    }

    if ( is_string( $value ) ) {
        foreach ( $secrets as $secret ) {
            if ( '' !== $secret && false !== strpos( $value, $secret ) ) {
                $value = str_replace( $secret, '***', $value );
            }
        }
        return $value;
    }

    return $value;
}

/**
 * Последние записи журнала, свежие сверху.
 *
 * @param int $limit Сколько вернуть.
 * @return array
 */
function ptg_log_tail( $limit = 50 ) {
    $log = get_option( 'ptg_log', array() );
    if ( ! is_array( $log ) ) {
        return array();
    }
    $log = array_slice( $log, - (int) $limit );
    return array_reverse( $log );
}

/**
 * Очистка журнала.
 *
 * @return void
 */
function ptg_log_clear() {
    update_option( 'ptg_log', array(), false );
}

/**
 * Ловушка фатальных ошибок (2.16.3).
 *
 * Смена статуса тянет за собой пересборку карточки: разбор заказа,
 * подготовку превью, запросы в Telegram. Нехватка памяти или таймаут —
 * не исключение, их не поймать через try/catch, и обрыв выглядит как
 * «статус сменился, а в истории и в журнале пусто». Поэтому на время
 * операции вешаем обработчик завершения: если PHP упал, причина
 * попадёт в журнал.
 *
 * @param array $context Что делали.
 * @return void
 */
function ptg_watch_fatal( $context = array() ) {
    static $hooked = false;

    $GLOBALS['ptg_fatal_context'] = $context;

    if ( $hooked ) {
        return;
    }
    $hooked = true;

    register_shutdown_function( function () {
        if ( empty( $GLOBALS['ptg_fatal_context'] ) ) {
            return;
        }
        $e = error_get_last();
        if ( ! $e || ! in_array( $e['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
            return;
        }
        ptg_log( 'error', 'Фатальная ошибка во время операции', array_merge(
            (array) $GLOBALS['ptg_fatal_context'],
            array(
                'error' => $e['message'],
                'where' => basename( $e['file'] ) . ':' . $e['line'],
                'mem'   => size_format( memory_get_peak_usage( true ) ),
            )
        ) );
    } );
}

/**
 * Операция завершилась штатно.
 *
 * @return void
 */
function ptg_watch_fatal_done() {
    $GLOBALS['ptg_fatal_context'] = array();
}

/**
 * Запись в историю заказа (2.16.4).
 *
 * Эмодзи вне базовой плоскости Unicode (🎨 👍 🔥 ✅ 🆕 — четыре байта в
 * UTF-8) не влезают в таблицу комментариев с кодировкой utf8: MySQL
 * отбивает вставку целиком, и запись молча теряется. Трёхбайтовые
 * (например ❤️) проходят — поэтому в истории оставались только переходы
 * в «Клиент ответил», а остальные пропадали.
 *
 * Чинить кодировку базы — отдельная история; здесь просто убираем
 * четырёхбайтовые символы: текст записи от этого не страдает.
 *
 * @param WC_Order $order Заказ.
 * @param string   $text  Текст.
 * @return int|false
 */
function ptg_add_note( $order, $text ) {
    $text = preg_replace( '/[\x{10000}-\x{10FFFF}]/u', '', (string) $text );
    $text = trim( preg_replace( '/\s{2,}/u', ' ', (string) $text ) );
    if ( '' === $text ) {
        return false;
    }
    return $order->add_order_note( $text );
}

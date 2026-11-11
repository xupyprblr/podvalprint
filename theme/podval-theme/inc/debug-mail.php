<?php
/**
 * Podval Print — Mail Debug Module прежней версии
 *
 * Логирует в debug.log всю активность wp_mail() и WooCommerce emails.
 * Помогает диагностировать проблемы с доставкой писем.
 *
 * Активация: в wp-config.php добавить
 *   define( 'PODVAL_DEBUG_MAIL', true );
 *
 * Все записи в debug.log с префиксом [mail].
 * Не забудь выключить в продакшене после диагностики.
 */

defined( 'ABSPATH' ) || exit;

// Активируем только если флаг установлен
if ( ! defined( 'PODVAL_DEBUG_MAIL' ) || ! PODVAL_DEBUG_MAIL ) {
    return;
}


/* ─────────────────────────────────────────────
   Helper: короткий лог
   ───────────────────────────────────────────── */
if ( ! function_exists( 'podval_mail_log' ) ) {
    function podval_mail_log( $msg ) {
        error_log( '[mail] ' . $msg );
    }
}


/* ─────────────────────────────────────────────
   1. wp_mail — перехват до отправки
   Видим все параметры: to / subject / размер тела
   ───────────────────────────────────────────── */
add_filter( 'wp_mail', 'podval_mail_debug_wp_mail', 1 );

function podval_mail_debug_wp_mail( $args ) {

    $to      = is_array( $args['to'] ) ? implode( ',', $args['to'] ) : (string) $args['to'];
    $subject = (string) ( $args['subject'] ?? '' );
    $msg_len = isset( $args['message'] ) ? strlen( $args['message'] ) : 0;

    // Откуда вызвано — берём несколько кадров из backtrace
    $trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 15 );
    $caller_chain = [];
    foreach ( $trace as $frame ) {
        if ( empty( $frame['function'] ) ) continue;
        $fn = ( $frame['class'] ?? '' ) . ( $frame['type'] ?? '' ) . $frame['function'];
        // Пропускаем сам wp_mail и сам наш debug-колбэк
        if ( in_array( $fn, [ 'wp_mail', 'podval_mail_debug_wp_mail', 'apply_filters', 'call_user_func_array' ], true ) ) continue;
        $caller_chain[] = $fn;
        if ( count( $caller_chain ) >= 4 ) break;
    }
    $caller = implode( ' ← ', $caller_chain );

    podval_mail_log( sprintf(
        'wp_mail() to=%s subj="%s" len=%d caller=%s',
        $to,
        mb_substr( $subject, 0, 100 ),
        $msg_len,
        $caller
    ) );

    return $args; // ничего не меняем, только наблюдаем
}


/* ─────────────────────────────────────────────
   2. wp_mail_failed — ошибка PHPMailer
   Ловим wp_error с полным текстом проблемы
   ───────────────────────────────────────────── */
add_action( 'wp_mail_failed', 'podval_mail_debug_wp_mail_failed' );

function podval_mail_debug_wp_mail_failed( $wp_error ) {

    if ( ! is_wp_error( $wp_error ) ) return;

    $err_msg = $wp_error->get_error_message();
    $err_data = $wp_error->get_error_data();

    $to = '';
    $subject = '';
    if ( is_array( $err_data ) ) {
        $to      = isset( $err_data['to'] ) ? ( is_array( $err_data['to'] ) ? implode( ',', $err_data['to'] ) : $err_data['to'] ) : '';
        $subject = $err_data['subject'] ?? '';
    }

    podval_mail_log( sprintf(
        'FAILED to=%s subj="%s" error="%s"',
        $to,
        mb_substr( (string) $subject, 0, 100 ),
        $err_msg
    ) );
}


/* ─────────────────────────────────────────────
   3. phpmailer_init — настройки транспорта
   Логируем один раз за запрос: From / Sender / Host
   ───────────────────────────────────────────── */
add_action( 'phpmailer_init', 'podval_mail_debug_phpmailer_init', 999 );

function podval_mail_debug_phpmailer_init( $phpmailer ) {

    static $logged = false;
    if ( $logged ) return; // один раз за request
    $logged = true;

    $mailer = property_exists( $phpmailer, 'Mailer' ) ? $phpmailer->Mailer : 'mail';
    $host   = property_exists( $phpmailer, 'Host' )   ? $phpmailer->Host   : '';
    $port   = property_exists( $phpmailer, 'Port' )   ? $phpmailer->Port   : '';
    $from   = property_exists( $phpmailer, 'From' )   ? $phpmailer->From   : '';
    $sender = property_exists( $phpmailer, 'Sender' ) ? $phpmailer->Sender : '';

    podval_mail_log( sprintf(
        'phpmailer_init mailer=%s host=%s port=%s from=%s return-path=%s',
        $mailer,
        $host,
        $port,
        $from,
        $sender
    ) );
}


/* ─────────────────────────────────────────────
   4. WooCommerce — какой класс email отправляется
   Ловим до формирования recipient'а
   ───────────────────────────────────────────── */
add_action( 'woocommerce_email_sent', 'podval_mail_debug_wc_email_sent', 10, 3 );

function podval_mail_debug_wc_email_sent( $return, $email_id, $email ) {

    $order_id = '';
    if ( is_object( $email ) && method_exists( $email, 'object' ) ) {
        $obj = $email->object;
        if ( $obj instanceof WC_Order ) {
            $order_id = $obj->get_id();
        }
    }

    $class = is_object( $email ) ? get_class( $email ) : 'unknown';

    podval_mail_log( sprintf(
        'WC email_id=%s class=%s order=%s returned=%s',
        $email_id,
        $class,
        $order_id,
        $return ? 'true' : 'false'
    ) );
}


/* ─────────────────────────────────────────────
   5. WC recipients — кому собирается отправить
   Для каждого типа WC email-письма логируем адресатов
   ───────────────────────────────────────────── */
$podval_mail_debug_recipient_filters = [
    'woocommerce_email_recipient_new_order',
    'woocommerce_email_recipient_cancelled_order',
    'woocommerce_email_recipient_failed_order',
    'woocommerce_email_recipient_customer_on_hold_order',
    'woocommerce_email_recipient_customer_processing_order',
    'woocommerce_email_recipient_customer_completed_order',
    'woocommerce_email_recipient_customer_invoice',
    'woocommerce_email_recipient_customer_note',
];

foreach ( $podval_mail_debug_recipient_filters as $hook ) {
    add_filter( $hook, function( $recipient, $order, $email = null ) use ( $hook ) {
        $order_id = ( $order instanceof WC_Order ) ? $order->get_id() : '-';
        $email_id = $email && is_object( $email ) && property_exists( $email, 'id' ) ? $email->id : str_replace( 'woocommerce_email_recipient_', '', $hook );
        podval_mail_log( sprintf(
            'WC recipient email_id=%s order=%s → "%s"',
            $email_id,
            $order_id,
            $recipient
        ) );
        return $recipient;
    }, 999, 3 );
}


/* ─────────────────────────────────────────────
   6. WC send() disable check — если письмо отключено в настройках
   Ловим когда is_enabled() = false, чтобы не искать молча
   ───────────────────────────────────────────── */
add_action( 'woocommerce_order_status_changed', 'podval_mail_debug_order_status_change', 5, 4 );

function podval_mail_debug_order_status_change( $order_id, $from, $to, $order ) {
    if ( ! class_exists( 'WC_Emails' ) ) return;

    $mailer = WC()->mailer();
    if ( ! $mailer ) return;

    $emails = $mailer->get_emails();
    $relevant = [];
    foreach ( $emails as $id => $email ) {
        $relevant[ $id ] = $email->is_enabled() ? 'ON' : 'OFF';
    }

    podval_mail_log( sprintf(
        'order #%s status %s → %s | WC emails state: %s',
        $order_id,
        $from,
        $to,
        wp_json_encode( $relevant, JSON_UNESCAPED_UNICODE )
    ) );
}

<?php
/**
 * Фаза 5. Авторизация Mini App.
 *
 * Единственный источник личности — подписанный initData из заголовка
 * X-TG-Init-Data. telegram_id из тела запроса, query-параметров или
 * localStorage не читается никогда.
 *
 * Права строже, чем у кнопок бота: только привязанный WP-юзер с
 * edit_shop_orders, без легаси-фоллбэка на ptg_allowed_user_ids и без
 * режима «не настроено — пускаем всех». Mini App открывается по ссылке,
 * которую можно переслать кому угодно.
 *
 * @package podval-telegram-orders
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
if ( ! defined( 'PTG_APP_CLOCK_SKEW' ) ) {
	define( 'PTG_APP_CLOCK_SKEW', 60 ); // допуск на рассинхрон часов, сек
}

/**
 * Разбор строки initData без parse_str(): parse_str() переименовывает ключи
 * с точками/пробелами и молча склеивает повторы, нам нужен сырой разбор.
 *
 * @return array<string,string>|null  null — если есть повторяющийся ключ.
 */
function ptg_app_parse_init_data( string $raw ): ?array {
	$out = array();
	foreach ( explode( '&', $raw ) as $pair ) {
		if ( '' === $pair ) {
			continue;
		}
		$eq  = strpos( $pair, '=' );
		$key = urldecode( false === $eq ? $pair : substr( $pair, 0, $eq ) );
		$val = false === $eq ? '' : urldecode( substr( $pair, $eq + 1 ) );
		if ( array_key_exists( $key, $out ) ) {
			return null; // повтор ключа — отвергаем
		}
		$out[ $key ] = $val;
	}
	return $out;
}

/**
 * @param string   $raw       initData как есть из Telegram.WebApp.initData
 * @param string   $bot_token токен бота
 * @param int      $max_age   максимальный возраст auth_date, сек
 * @param int|null $now       для тестов
 * @return array{ok:bool,reason:string,age:?int,user:?array,fields:array}
 */
function ptg_app_validate_init_data( string $raw, string $bot_token, int $max_age, ?int $now = null ): array {
	$now  = $now ?? time();
	$fail = static function ( string $reason, ?int $age = null ): array {
		return array( 'ok' => false, 'reason' => $reason, 'age' => $age, 'user' => null, 'fields' => array() );
	};

	if ( '' === $bot_token ) {
		return $fail( 'no_token' );
	}
	if ( '' === trim( $raw ) ) {
		return $fail( 'empty' );
	}
	if ( strlen( $raw ) > 8192 ) {
		return $fail( 'too_long' );
	}

	$fields = ptg_app_parse_init_data( $raw );
	if ( null === $fields ) {
		return $fail( 'duplicate_key' );
	}
	if ( empty( $fields['hash'] ) || ! preg_match( '/^[0-9a-f]{64}$/', $fields['hash'] ) ) {
		return $fail( 'no_hash' );
	}

	$hash = $fields['hash'];
	unset( $fields['hash'] );
	ksort( $fields, SORT_STRING );

	$lines = array();
	foreach ( $fields as $k => $v ) {
		$lines[] = $k . '=' . $v;
	}
	$secret   = hash_hmac( 'sha256', $bot_token, 'WebAppData', true );
	$expected = hash_hmac( 'sha256', implode( "\n", $lines ), $secret );

	if ( ! hash_equals( $expected, $hash ) ) {
		return $fail( 'bad_hash' );
	}

	// Свежесть — только после подписи: неподписанный auth_date ничего не значит.
	if ( ! isset( $fields['auth_date'] ) || ! ctype_digit( $fields['auth_date'] ) ) {
		return $fail( 'no_auth_date' );
	}
	$age = $now - (int) $fields['auth_date'];
	if ( $age < -PTG_APP_CLOCK_SKEW ) {
		return $fail( 'future', $age );
	}
	if ( $age > $max_age ) {
		return $fail( 'stale', $age );
	}

	$user = isset( $fields['user'] ) ? json_decode( $fields['user'], true ) : null;
	if ( ! is_array( $user ) || empty( $user['id'] ) || ! is_int( $user['id'] ) ) {
		return $fail( 'no_user', $age );
	}

	return array( 'ok' => true, 'reason' => 'ok', 'age' => $age, 'user' => $user, 'fields' => $fields );
}

/**
 * Окно свежести initData, сек. Подпись ставится один раз при открытии
 * приложения; на выдаче его держат открытым весь день.
 *
 * @return int
 */
function ptg_app_max_age() {
    $hours = (int) get_option( 'ptg_app_max_age_hours', 24 );
    $hours = max( 1, min( 168, $hours ) );
    return $hours * HOUR_IN_SECONDS;
}

/**
 * Проверка запроса Mini App.
 *
 * @param WP_REST_Request $request Запрос.
 * @return array|WP_Error [ tg_id, user(WP_User), tg(array), start_param ].
 */
function ptg_app_auth( $request ) {
    static $memo = array();

    $raw = (string) $request->get_header( 'x_tg_init_data' );
    $key = md5( $raw );
    if ( isset( $memo[ $key ] ) ) {
        return $memo[ $key ];
    }

    $v = ptg_app_validate_init_data( $raw, (string) get_option( 'ptg_bot_token', '' ), ptg_app_max_age() );
    if ( ! $v['ok'] ) {
        $code = in_array( $v['reason'], array( 'stale', 'future' ), true ) ? 'ptg_app_stale' : 'ptg_app_unauthorized';
        ptg_log( 'warn', 'Mini App: отказ в доступе', array( 'reason' => $v['reason'] ) );
        return $memo[ $key ] = new WP_Error( $code, $v['reason'], array( 'status' => 401 ) );
    }

    $tg_id = (int) $v['user']['id'];
    $user  = ptg_user_by_telegram_id( $tg_id );
    if ( ! $user || ! user_can( $user, 'edit_shop_orders' ) ) {
        ptg_log( 'warn', 'Mini App: нет прав', array( 'tg' => $tg_id ) );
        return $memo[ $key ] = new WP_Error( 'ptg_app_forbidden', 'forbidden', array(
            'status' => 403,
            'tg_id'  => $tg_id,
        ) );
    }

    return $memo[ $key ] = array(
        'tg_id'       => $tg_id,
        'user'        => $user,
        'tg'          => $v['user'],
        'start_param' => (string) ( $v['fields']['start_param'] ?? '' ),
    );
}

/**
 * permission_callback для всех маршрутов /app/*.
 *
 * @param WP_REST_Request $request Запрос.
 * @return true|WP_Error
 */
function ptg_app_permission( $request ) {
    $a = ptg_app_auth( $request );
    return is_wp_error( $a ) ? $a : true;
}

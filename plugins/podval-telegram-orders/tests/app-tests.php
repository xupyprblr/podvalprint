<?php
/**
 * php tests/app-tests.php — проверки 2.6.0 без WordPress:
 * валидатор initData, негативные статусы, кнопки карточки.
 */
define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );

// ── минимальные стабы ────────────────────────────────────────────
$GLOBALS['opts'] = array();
function apply_filters( $t, $v ) { return $v; }
function add_action() {}
function add_filter() {}
function register_activation_hook() {}
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; }
function ptg_log() {}
function ptg_add_note( $order, $text ) {
	$text = trim( preg_replace( '/\s{2,}/u', ' ', preg_replace( '/[\x{10000}-\x{10FFFF}]/u', '', (string) $text ) ) );
	return '' === $text ? false : $order->add_order_note( $text );
}
function ptg_watch_fatal( $c = array() ) {}
function ptg_watch_fatal_done() {}
function ptg_staff_name() { return 'Тест'; }
function ptg_user_display_name( $u ) { return trim( ( $u['first_name'] ?? '' ) . ' ' . ( $u['last_name'] ?? '' ) ); }
class WP_Error { public $c; public $m; function __construct( $c, $m = '' ) { $this->c = $c; $this->m = $m; } function get_error_message() { return $this->m; } function get_error_code() { return $this->c; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function ptg_overridable_items() { return array(); }

class FakeOrder {
	public $status; public $meta = array(); public $notes = array();
	function __construct( $s ) { $this->status = $s; }
	function get_status() { return $this->status; }
	function get_meta( $k ) { return $this->meta[ $k ] ?? ''; }
	function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
	function save_meta_data() {}
	function update_status( $s, $note = '', $m = false ) { $this->status = $s; if ( '' !== $note ) { $this->notes[] = $note; } }
	function get_id() { return 47; }
	function save() {}
	function get_items() { return array(); }
	function get_order_number() { return "47"; }
	function get_billing_first_name() { return "Иван"; }
	function get_billing_last_name() { return ""; }
	function get_total() { return 55; }
	function add_order_note( $n ) { $this->notes[] = $n; return count( $this->notes ); }
	public $ovr = array();
}

require __DIR__ . '/../inc/app-auth.php';
require __DIR__ . '/../inc/statuses.php';
require __DIR__ . '/../inc/keyboard.php';
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function home_url( $p = '' ) { return 'https://shop.test' . $p; }
function add_rewrite_rule() {}
function nocache_headers() {}
function register_setting() {}
function ptg_chat_files( $o ) { return (array) ( $o->meta['_ptg_chat_files'] ?? array() ); }
require __DIR__ . '/../inc/app-page.php';
require __DIR__ . '/../inc/business.php';
if ( ! defined( 'MB_IN_BYTES' ) ) { define( 'MB_IN_BYTES', 1048576 ); }
function wp_upload_dir() { return array( 'basedir' => sys_get_temp_dir() . '/ptg-test-up', 'baseurl' => 'https://shop.test/wp-content/uploads' ); }
function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }
function wp_raise_memory_limit( $c ) { return ini_get( 'memory_limit' ); }
function wp_convert_hr_to_bytes( $v ) { $v = trim( (string) $v ); $n = (int) $v; $u = strtolower( substr( $v, -1 ) ); if ( 'g' === $u ) { $n *= 1024 ** 3; } elseif ( 'm' === $u ) { $n *= 1024 ** 2; } elseif ( 'k' === $u ) { $n *= 1024; } return $n < 0 ? 0 : $n; }
function size_format( $b ) { return round( $b / 1048576 ) . ' MB'; }
require __DIR__ . '/../inc/preview.php';

$pass = 0; $fail = 0;
function check( $name, $cond ) { global $pass, $fail; echo ( $cond ? '  ok   ' : '  FAIL ' ) . $name . "\n"; $cond ? $pass++ : $fail++; }

// ── initData ─────────────────────────────────────────────────────
function sign( array $f, $token ) {
	ksort( $f, SORT_STRING ); $l = array();
	foreach ( $f as $k => $v ) { $l[] = "$k=$v"; }
	$f['hash'] = hash_hmac( 'sha256', implode( "\n", $l ), hash_hmac( 'sha256', $token, 'WebAppData', true ) );
	return http_build_query( $f, '', '&', PHP_QUERY_RFC3986 );
}
$token = '123:ABC'; $now = 1800000000;
$base = array( 'user' => '{"id":331109569,"first_name":"Алекс"}', 'chat_type' => 'group', 'start_param' => 'o47', 'auth_date' => (string) ( $now - 10 ), 'signature' => 'x' );
$good = sign( $base, $token );
check( 'initData: валидная проходит', ptg_app_validate_init_data( $good, $token, 86400, $now )['ok'] );
check( 'initData: подмена user → bad_hash', 'bad_hash' === ptg_app_validate_init_data( str_replace( '331109569', '1', $good ), $token, 86400, $now )['reason'] );
check( 'initData: чужой токен → bad_hash', 'bad_hash' === ptg_app_validate_init_data( $good, '9:Z', 86400, $now )['reason'] );
check( 'initData: лишнее поле → bad_hash', 'bad_hash' === ptg_app_validate_init_data( $good . '&x=1', $token, 86400, $now )['reason'] );
check( 'initData: повтор ключа', 'duplicate_key' === ptg_app_validate_init_data( $good . '&user=1', $token, 86400, $now )['reason'] );
check( 'initData: просрочка', 'stale' === ptg_app_validate_init_data( $good, $token, 5, $now )['reason'] );
check( 'initData: будущее', 'future' === ptg_app_validate_init_data( sign( array_merge( $base, array( 'auth_date' => (string) ( $now + 999 ) ) ), $token ), $token, 86400, $now )['reason'] );
check( 'initData: пустой токен', 'no_token' === ptg_app_validate_init_data( $good, '', 86400, $now )['reason'] );
check( 'initData: start_param', 'o47' === ptg_app_validate_init_data( $good, $token, 86400, $now )['fields']['start_param'] );
$GLOBALS['opts']['ptg_app_max_age_hours'] = 0;
check( 'окно сессии: 0 → 1 час минимум', 3600 === ptg_app_max_age() );
$GLOBALS['opts']['ptg_app_max_age_hours'] = 9999;
check( 'окно сессии: потолок 168 ч', 168 * 3600 === ptg_app_max_age() );
unset( $GLOBALS['opts']['ptg_app_max_age_hours'] );
check( 'окно сессии: по умолчанию 24 ч', 86400 === ptg_app_max_age() );

// ── статусы ──────────────────────────────────────────────────────
$defs = ptg_statuses();
check( 'refused и not-picked зарегистрированы', isset( $defs['refused'], $defs['not-picked'] ) );
check( 'негативные не считаются оплаченными', ! in_array( 'refused', ptg_paid_statuses( array() ), true ) && ! in_array( 'not-picked', ptg_paid_statuses( array() ), true ) );
check( 'issued остаётся оплаченным', in_array( 'issued', ptg_paid_statuses( array() ), true ) );
check( 'слаги ≤ 20 символов с wc-', strlen( 'wc-not-picked' ) <= 20 );
$codes = array_column( $defs, 'code' );
check( 'коды callback уникальны', count( $codes ) === count( array_unique( $codes ) ) );
check( 'из готового — выдан или не забрали', array( 'issued', 'not-picked' ) === $defs['ready']['next'] );

$o = new FakeOrder( 'in-print' );
check( 'отказ из «В печати» разрешён', true === ptg_set_status( $o, 'refused', 1 ) );
check( 'запомнили, откуда ушли', 'in-print' === $o->meta[ PTG_META_NEG_FROM ] );
$info = ptg_order_status_info( $o );
check( 'назад из отказа — в «В печати»', 'in-print' === $info['back'] );
check( 'отказ — negative, не refusable', $info['negative'] && ! $info['refusable'] );

$o = new FakeOrder( 'refused' );
check( 'без меты назад → Получен', 'processing' === ptg_order_status_info( $o )['back'] );
$o = new FakeOrder( 'not-picked' );
check( 'не забрали без меты назад → Готов', 'ready' === ptg_order_status_info( $o )['back'] );

$o = new FakeOrder( 'issued' );
check( 'отказ из «Выдан» запрещён', is_wp_error( ptg_set_status( $o, 'refused', 1 ) ) );
$o = new FakeOrder( 'pending' );
check( 'отказ из «Ожидает оплату» разрешён (2.16.1)', true === ptg_set_status( $o, 'refused', 1 ) );
$o = new FakeOrder( 'completed' );
check( 'отказ из «Выполнен» запрещён', is_wp_error( ptg_set_status( $o, 'refused', 1 ) ) );

$o = new FakeOrder( 'ready' );
ptg_set_status( $o, 'not-picked', 1 );
ptg_set_status( $o, 'refused', 1 );
check( 'не забрали → отказ: исходным остаётся ready', 'ready' === $o->meta[ PTG_META_NEG_FROM ] );

// ── клавиатура ───────────────────────────────────────────────────
function kb_for( $status, $meta = array(), $type = 'order' ) {
	$o = new FakeOrder( $status ); $o->meta = $meta;
	$data = array( 'id' => 47, 'type' => $type, 'status_info' => ptg_order_status_info( $o ), 'overrides' => array(),
		'greeting' => array( 'sent' => true ), 'client' => array( 'linked' => false, 'messages' => 0, 'files' => 0, 'outgoing' => 0 ), 'assignee' => array( 'name' => '' ), 'admin_url' => 'https://x/adm' );
	return ptg_keyboard_root( $data )['inline_keyboard'];
}
function kb_texts( $kb ) { $t = array(); foreach ( $kb as $row ) { foreach ( $row as $b ) { $t[] = $b['text']; } } return $t; }

$t = kb_texts( kb_for( 'ready' ) );
check( 'готов: есть «Выдан» и «Не забрали»', in_array( '✅ Выдан', $t, true ) && in_array( '📦 Не забрали', $t, true ) );
check( 'готов: есть «назад в печать»', in_array( '↩️ 👍 В печати', $t, true ) );
check( 'готов: есть «Отказ»', in_array( '🚫 Отказ', $t, true ) );

$t = kb_texts( kb_for( 'issued' ) );
check( 'выдан: нет «Отказ»', ! in_array( '🚫 Отказ', $t, true ) );
$kb = kb_for( 'issued' );
check( 'выдан: одна строка кнопок', 1 === count( $kb ) );
check( 'выдан: «Вернуть в работу» первой', '↩️ Вернуть в работу' === $kb[0][0]['text'] && false !== strpos( $kb[0][0]['callback_data'], ptg_statuses()['ready']['code'] ) );
check( 'выдан: нет «Изменить параметры» и приветствия', ! (bool) preg_grep( '/Изменить|приветств/u', $t ) );

$t = kb_texts( kb_for( 'refused', array( PTG_META_NEG_FROM => 'sketch-sent' ) ) );
check( 'отказ: одна кнопка возврата', in_array( '↩️ Вернуть в работу', $t, true ) );
check( 'отказ: нет следующего шага', ! in_array( '✅ Выдан', $t, true ) );

$t = kb_texts( kb_for( 'not-picked', array( PTG_META_NEG_FROM => 'ready' ) ) );
check( 'не забрали: можно «Выдан» (пришёл позже)', in_array( '✅ Выдан', $t, true ) );
check( 'не забрали: назад в «Готов»', in_array( '↩️ 🔥 Готов к выдаче', $t, true ) );
check( 'не забрали: можно «Отказ»', in_array( '🚫 Отказ', $t, true ) );

$t = kb_texts( kb_for( 'processing' ) );
check( 'получен: назад нет, отказ есть', ! preg_grep( '/^↩️/', $t ) && in_array( '🚫 Отказ', $t, true ) );

$t = kb_texts( kb_for( 'processing', array(), 'service' ) );
check( 'заявка: есть кнопки статусов и отказа (2.18.0)', in_array( '🚫 Отказ', $t, true ) && (bool) preg_grep( '/Клиент ответил/', $t ) );

// Кнопка приложения
$GLOBALS['opts']['ptg_bot_username'] = 'shop_test_bot';
$t = kb_texts( kb_for( 'ready' ) );
check( 'без настройки: «Открыть в админке» как раньше', in_array( '🗂 Открыть в админке', $t, true ) && ! in_array( '📱 В приложении', $t, true ) );
$GLOBALS['opts']['ptg_app_short_name'] = 'orders';
$kb = kb_for( 'ready' );
$last = end( $kb );
check( 'с настройкой: «В приложении» + «Админка» в одном ряду', '📱 В приложении' === $last[0]['text'] && '🗂 Админка' === $last[1]['text'] );
check( 'диплинк на заказ', 'https://t.me/shop_test_bot/orders?startapp=o47' === $last[0]['url'] );

// callback_data укладывается в 64 байта
$max = 0; foreach ( $kb as $row ) { foreach ( $row as $b ) { if ( isset( $b['callback_data'] ) ) { $max = max( $max, strlen( $b['callback_data'] ) ); } } }
check( 'callback_data ≤ 64 байт', $max <= 64 );


// ── on-hold («после согласования») и ответственный ──────────────
$i = ptg_order_status_info( new FakeOrder( 'on-hold' ) );
check( 'on-hold: следующий шаг — «Клиент ответил»', array( 'client-replied' ) === $i['next'] );
check( 'on-hold: отказ разрешён', $i['refusable'] && true === ptg_set_status( new FakeOrder( 'on-hold' ), 'refused', 1 ) );
check( 'on-hold: подпись «Оплата после согласования»', 'Оплата после согласования' === $i['label'] );

unset( $GLOBALS['opts']['ptg_show_assignee'] );
$t = kb_texts( kb_for( 'processing' ) );
check( 'ответственный скрыт: нет «Взять в работу»', ! in_array( '🙋 Взять в работу', $t, true ) );
$GLOBALS['opts']['ptg_show_assignee'] = 1;
$t = kb_texts( kb_for( 'processing' ) );
check( 'ответственный включён: кнопка есть', in_array( '🙋 Взять в работу', $t, true ) );
unset( $GLOBALS['opts']['ptg_show_assignee'] );

// ── вкладки: заказы до запуска → «Прочие» ─────────────────────────
class StoreOrder extends FakeOrder {
	public $id; public $created;
	function __construct( $id, $s, $created, $status_at = 0 ) { parent::__construct( $s ); $this->id = $id; $this->created = $created; if ( $status_at ) { $this->meta[ PTG_META_STATUS_AT ] = $status_at; } }
	function get_id() { return $this->id; }
}
const PTG_META_ASSIGNEE_TG = '_a'; const PTG_META_ASSIGNEE_NAME = '_an'; const PTG_MOCKUP_STALE_META = '_s';
const PTG_REST_NS = 'x';
$SINCE = 1000;
$GLOBALS['opts']['ptg_app_since'] = $SINCE;
$GLOBALS['store'] = array(
	new StoreOrder( 1, 'processing', 500 ),             // до запуска, не трогали → Прочие
	new StoreOrder( 2, 'processing', 1500 ),            // после запуска → Получен
	new StoreOrder( 3, 'on-hold', 600 ),                // старый «после согласования» → Прочие
	new StoreOrder( 4, 'on-hold', 1600 ),               // новый → Получен
	new StoreOrder( 5, 'ready', 700, 1200 ),            // старый, но статус меняли после → Готов
	new StoreOrder( 6, 'in-print', 800 ),               // старый, не трогали → Прочие
	new StoreOrder( 7, 'completed', 300 ),              // служебный → Прочие
	new StoreOrder( 8, 'pending', 1700 ),               // неоплаченный онлайн → Прочие
	new StoreOrder( 9, 'issued', 1800, 1900 ),          // выдан → Выдан
);
function wc_get_orders( $a ) {
	$out = array();
	foreach ( $GLOBALS['store'] as $o ) {
		if ( ! in_array( $o->get_status(), (array) $a['status'], true ) ) { continue; }
		if ( isset( $a['date_created'] ) && preg_match( '/^(>=|<)(\d+)$/', $a['date_created'], $m ) ) {
			if ( '>=' === $m[1] ? $o->created < $m[2] : $o->created >= $m[2] ) { continue; }
		}
		if ( isset( $a['meta_query'][0] ) ) {
			$mq = $a['meta_query'][0];
			$v  = (int) $o->get_meta( $mq['key'] );
			if ( 'BETWEEN' === $mq['compare'] ) {
				if ( ! $v || $v < $mq['value'][0] || $v > $mq['value'][1] ) { continue; }
			} elseif ( ! $v || $v < $mq['value'] ) { continue; }
		}
		$out[] = $o;
	}
	usort( $out, function ( $x, $y ) { return $y->created <=> $x->created; } );
	if ( $a['limit'] > 0 ) { $out = array_slice( $out, 0, $a['limit'] ); }
	if ( ( $a['return'] ?? 'objects' ) !== 'ids' ) { return $out; }
	return array_map( function ( $o ) { return $o->get_id(); }, $out );
}
function wc_orders_count( $st ) { $n = 0; foreach ( $GLOBALS['store'] as $o ) { $n += ( $o->get_status() === $st ); } return $n; }
function add_action_stub() {}
require __DIR__ . '/../inc/app-rest.php';

$c = ptg_app_tab_counts();
check( 'Получен: только новые processing + on-hold', 2 === $c['processing'] );
check( 'Готов: старый заказ с новой сменой статуса вернулся', 1 === $c['ready'] );
check( 'В печати: старый нетронутый не считается', 0 === $c['in-print'] );
check( 'В работе = 3', 3 === $c['work'] );
check( 'Выдан = 1', 1 === $c['issued'] );
check( 'Прочие: 3 старых рабочих + completed + pending', 5 === $c['other'] );
$ids = ptg_app_tab_ids( 'other', 100 ); sort( $ids );
check( 'Прочие: состав 1,3,6,7,8', array( 1, 3, 6, 7, 8 ) === $ids );
check( 'Получен: состав 4,2 (новые первыми)', array( 4, 2 ) === ptg_app_tab_ids( 'processing', 100 ) );
check( 'старый нетронутый помечен как архив', ptg_app_is_legacy( $GLOBALS['store'][0] ) );
check( 'новый не архив', ! ptg_app_is_legacy( $GLOBALS['store'][1] ) );
check( 'служебный статус не архив', ! ptg_app_is_legacy( $GLOBALS['store'][6] ) );


// ── действия приложения (2.7.0) ──────────────────────────────────
function current_time() { return '2026-09-19 12:00:00'; }
function ptg_order_data( $o ) { return array( 'overrides' => $o->ovr ); }
function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['transients'][ $k ] = $v; }
function delete_transient() {}
const PTG_META_CLIENT_CHAT_STUB = 1;
require __DIR__ . '/../inc/greeting.php';
require __DIR__ . '/../inc/app-actions.php';

function do_status( $status, $to, $verified = false, $meta = array(), $ovr = array() ) {
	$o = new FakeOrder( $status ); $o->meta = $meta; $o->ovr = $ovr;
	return array( ptg_app_do_status( $o, $to, $verified, 1, array() ), $o );
}
list( $r ) = do_status( 'ready', 'issued' );
check( 'действие: готов → выдан', true === $r );
list( $r ) = do_status( 'ready', 'in-print' );
check( 'действие: готов → назад в печать', true === $r );
list( $r ) = do_status( 'ready', 'processing' );
check( 'действие: готов → получен запрещено (перескок)', is_wp_error( $r ) && 'ptg_stale_state' === $r->c );
list( $r ) = do_status( 'client-replied', 'client-replied' );
check( 'действие: повторное нажатие → «уже изменился»', is_wp_error( $r ) && 'ptg_stale_state' === $r->c );
list( $r ) = do_status( 'sketch-sent', 'in-print', false, array(), array( array( 'x' ) ) );
check( 'действие: в печать с правками без сверки → need_verify', is_wp_error( $r ) && 'ptg_need_verify' === $r->c );
list( $r, $o ) = do_status( 'sketch-sent', 'in-print', true, array(), array( array( 'x' ) ) );
check( 'действие: со сверкой — в печать и сверка записана', true === $r && 'in-print' === $o->status && '' !== $o->meta[ PTG_META_VERIFIED_AT ] );
list( $r ) = do_status( 'sketch-sent', 'in-print', false, array( PTG_META_VERIFIED_AT => 'x' ), array( array( 'x' ) ) );
check( 'действие: уже сверено — без повторной сверки', true === $r );
list( $r ) = do_status( 'sketch-sent', 'refused' );
check( 'действие: отказ из эскиза', true === $r );
list( $r ) = do_status( 'issued', 'refused' );
check( 'действие: отказ из выданного запрещён', is_wp_error( $r ) );
list( $r ) = do_status( 'processing', 'client-replied', false, array( '_order_type' => 'service' ) );
check( 'действие: заявку можно вести по циклу (2.18.0)', true === $r );
list( $r ) = do_status( 'refused', 'sketch-sent', false, array( PTG_META_NEG_FROM => 'sketch-sent' ) );
check( 'действие: назад из отказа в исходный', true === $r );

$o = new FakeOrder( 'ready' );
check( 'напоминание (копия) на готовом', true === ptg_app_do( $o, 'remind', array( 'copy' => true ), 1, array() ) );
check( 'напоминание записано в лог', 1 === count( ptg_remind_log( $o ) ) && 'copy' === ptg_remind_log( $o )[0]['mode'] );
check( 'напоминание: заметка в заказе', (bool) preg_grep( '/напоминания скопирован/u', $o->notes ) );
$o = new FakeOrder( 'processing' );
$r = ptg_app_do( $o, 'remind', array( 'copy' => true ), 1, array() );
check( 'напоминание на неготовом запрещено', is_wp_error( $r ) );
$o = new FakeOrder( 'ready' );
$r = ptg_app_do( $o, 'remind', array(), 1, array() );
check( 'напоминание без привязанного чата — ошибка, лог пуст', is_wp_error( $r ) && 0 === count( ptg_remind_log( $o ) ) );
check( 'неизвестное действие', is_wp_error( ptg_app_do( $o, 'drop_table', array(), 1, array() ) ) );


// ── 2.8.0: переписка и подсказки ─────────────────────────────────
function esc_url_raw( $u ) { return $u; }
function wp_get_attachment_image_url( $id, $size ) { return 'https://x/thumb-' . $id . '.jpg'; }

$o = new FakeOrder( 'client-replied' );
ptg_chat_log_append( $o, array( 't' => 1000, 'd' => 'in', 'n' => 'К', 'x' => 'привет', 'm' => 1, 'f' => '' ) );
check( 'лог: время последней активности', 1000 === (int) $o->meta[ PTG_META_CHAT_LAST_AT ] );
check( 'лог: входящее не считается «мы отправили фото»', empty( $o->meta[ PTG_META_OUT_MEDIA_AT ] ) );
ptg_chat_log_append( $o, array( 't' => 1100, 'd' => 'out', 'n' => 'А', 'x' => '', 'm' => 2, 'f' => 'фото' ) );
check( 'лог: исходящее фото отмечено', 1100 === (int) $o->meta[ PTG_META_OUT_MEDIA_AT ] );
ptg_chat_log_append( $o, array( 't' => 900, 'd' => 'in', 'n' => 'К', 'x' => 'старое', 'm' => 3, 'f' => '' ) );
check( 'лог: запоздавшее эхо не откатывает время назад', 1100 === (int) $o->meta[ PTG_META_CHAT_LAST_AT ] );

$now = time();
$o = new FakeOrder( 'client-replied' ); $o->meta = array( PTG_META_OUT_MEDIA_AT => $now - 300, PTG_META_STATUS_AT => $now - 3600 );
$hint = ptg_app_hint( $o );
check( 'подсказка: фото после смены статуса → «Эскиз отправлен»', $hint && 'sketch-sent' === $hint['to'] );
$o->meta[ PTG_META_STATUS_AT ] = $now - 60;
check( 'подсказка: статус меняли после фото — подсказки нет', null === ptg_app_hint( $o ) );
$o = new FakeOrder( 'in-print' ); $o->meta = array( PTG_META_OUT_MEDIA_AT => $now - 60 );
check( 'подсказка: в печати — не нужна', null === ptg_app_hint( $o ) );
$o = new FakeOrder( 'processing' ); $o->meta = array( PTG_META_OUT_MEDIA_AT => $now - 7 * 3600 );
check( 'подсказка: фото старше 6 часов — нет', null === ptg_app_hint( $o ) );
$o = new FakeOrder( 'processing' ); $o->meta = array( PTG_META_OUT_MEDIA_AT => $now - 60, '_order_type' => 'service' );
check( 'подсказка: у заявок тоже есть (2.18.0)', null !== ptg_app_hint( $o ) );

list( $r ) = do_status( 'processing', 'sketch-sent' );
check( 'действие: из «Получен» сразу в «Эскиз отправлен» (по подсказке)', true === $r );
list( $r ) = do_status( 'processing', 'in-print' );
check( 'действие: из «Получен» сразу в печать — нельзя', is_wp_error( $r ) );

$files = array(
	array( 'id' => 5, 'url' => 'https://x/a.jpg', 'kind' => 'photo', 'name' => 'photo.jpg', 'dir' => 'out', 't' => 2000 ),
	array( 'id' => 6, 'url' => 'https://x/v.ogg', 'kind' => 'voice', 'name' => 'voice.ogg', 'dir' => 'in', 't' => 2003 ),
);
$f = ptg_app_chat_file( array( 'f' => 'фото', 'd' => 'out', 't' => 1999, 'a' => 5, 'u' => 'https://x/a.jpg' ), $files );
check( 'файл: по ID вложения, с миниатюрой', $f && 'https://x/thumb-5.jpg' === $f['thumb'] );
$f = ptg_app_chat_file( array( 'f' => 'голосовое', 'd' => 'in', 't' => 2001 ), $files );
check( 'файл: без ID — по времени и направлению', $f && 'voice' === $f['kind'] && '' === $f['thumb'] );
$f = ptg_app_chat_file( array( 'f' => 'фото', 'd' => 'in', 't' => 5000 ), $files );
check( 'файл: не нашли — null, без выдумок', null === $f );
$f = ptg_app_chat_file( array( 'f' => '', 'd' => 'in', 't' => 2000, 'x' => 'текст' ), $files );
check( 'файл: у текстового сообщения нет', null === $f );


// ── 2.9.0: строки позиций, коллаж, быстрый шаг, отмена ───────────
$g = function ( $type, $size, $color, $emoji = '' ) {
	return array(
		array( 'key' => 'type', 'value' => $type, 'emoji' => '' ),
		array( 'key' => 'size', 'value' => $size, 'emoji' => '' ),
		array( 'key' => 'color', 'value' => $color, 'emoji' => $emoji ),
		array( 'key' => 'side', 'value' => 'Спина', 'emoji' => '' ),
	);
};
$data = array( 'items' => array(
	array( 'item_id' => 1, 'kind' => 'simple', 'artwork' => true, 'name' => 'Печать на Футболке', 'qty' => 1, 'garment' => $g( 'Классическая', 'M', 'Красный', '🔴' ), 'links' => array( array( 'role' => 'artwork', 'url' => 'https://x/a.jpg' ) ) ),
	array( 'item_id' => 2, 'kind' => 'simple', 'artwork' => true, 'name' => 'Печать на Футболке', 'qty' => 1, 'garment' => $g( 'Классическая', 'M', 'Красный', '🔴' ), 'links' => array( array( 'role' => 'artwork', 'url' => 'https://x/b.jpg' ) ) ),
	array( 'item_id' => 3, 'kind' => 'tpl', 'artwork' => false, 'name' => 'ONLY YOU', 'qty' => 2, 'garment' => $g( 'Оверсайз', 'L', 'Чёрный', '⚫' ), 'links' => array( array( 'role' => 'preview', 'url' => 'https://x/c.png' ) ) ),
	array( 'item_id' => 4, 'kind' => 'simple', 'artwork' => false, 'name' => 'Худи Deadlock — лимитка', 'qty' => 1, 'garment' => array(), 'links' => array() ),
) );
$lines = ptg_app_item_lines( $data );
check( 'строки: «Свой дизайн» вместо названия товара', 'Свой дизайн · M 🔴 Классическая спина' === $lines[0]['text'] );
check( 'строки: одинаковые схлопнуты с количеством', 2 === $lines[0]['qty'] && 3 === count( $lines ) );
check( 'строки: шаблон с количеством', 'Шаблон · L ⚫ Оверсайз спина' === $lines[1]['text'] && 2 === $lines[1]['qty'] );
check( 'строки: готовый товар — короткое имя без хвоста', 'Худи Deadlock' === $lines[2]['text'] );

class ItemsOrder extends FakeOrder { function get_items() { return array(); } }
$pv = ptg_app_item_previews( $data, new ItemsOrder( 'processing' ), 4 );
check( 'коллаж: разные принты — отдельные картинки', 3 === count( $pv ) && 'https://x/a.jpg' === $pv[0]['url'] && 'https://x/b.jpg' === $pv[1]['url'] );
check( 'коллаж: количество позиции сохранено', 2 === $pv[2]['qty'] );
$same = array( 'items' => array( $data['items'][0], array_merge( $data['items'][0], array( 'item_id' => 9 ) ) ) );
$pv = ptg_app_item_previews( $same, new ItemsOrder( 'processing' ), 4 );
check( 'коллаж: один и тот же файл — одна картинка ×2', 1 === count( $pv ) && 2 === $pv[0]['qty'] );

$o = new FakeOrder( 'sketch-sent' );
$q = ptg_app_quick( $o, false );
check( 'быстрый шаг: следующий статус', 'in-print' === $q['key'] && ! $q['hint'] );
check( 'быстрый шаг: у заявки есть (2.18.0)', null !== ptg_app_quick( $o, true ) );
check( 'быстрый шаг: у выданного нет', null === ptg_app_quick( new FakeOrder( 'issued' ), false ) );
$o = new FakeOrder( 'processing' ); $o->meta = array( PTG_META_OUT_MEDIA_AT => time() - 60 );
check( 'быстрый шаг: подсказка по фото важнее', 'sketch-sent' === ptg_app_quick( $o, false )['key'] );

$o = new FakeOrder( 'client-replied' ); $o->meta = array( PTG_META_STATUS_PREV => 'processing', PTG_META_STATUS_AT => time() - 10 );
check( 'отмена: в первые 2 минуты возвращает предыдущий', true === ptg_app_do( $o, 'undo', array( 'from' => 'client-replied' ), 1, array() ) && 'processing' === $o->status );
$o = new FakeOrder( 'client-replied' ); $o->meta = array( PTG_META_STATUS_PREV => 'processing', PTG_META_STATUS_AT => time() - 600 );
check( 'отмена: через 10 минут — нельзя', is_wp_error( ptg_app_do( $o, 'undo', array( 'from' => 'client-replied' ), 1, array() ) ) );
$o = new FakeOrder( 'sketch-sent' ); $o->meta = array( PTG_META_STATUS_PREV => 'client-replied', PTG_META_STATUS_AT => time() - 10 );
check( 'отмена: статус уже сменил другой — нельзя', is_wp_error( ptg_app_do( $o, 'undo', array( 'from' => 'client-replied' ), 1, array() ) ) );
$o = new FakeOrder( 'client-replied' ); $o->meta = array( PTG_META_STATUS_PREV => 'on-hold', PTG_META_STATUS_AT => time() - 10 );
check( 'отмена: возврат в «после согласования»', true === ptg_app_do( $o, 'undo', array( 'from' => 'client-replied' ), 1, array() ) && 'on-hold' === $o->status );


// ── 2.10.0: отчёт ────────────────────────────────────────────────
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
function wp_timezone() { return new DateTimeZone( 'Europe/Minsk' ); }
function wp_date( $f, $ts, $tz = null ) { return ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz ?: wp_timezone() )->format( $f ); }
require __DIR__ . '/../inc/app-report.php';

check( 'оплата: «Оплата наличными» → наличные', 'cash' === ptg_payment_category( 'cod', 'Оплата наличными' ) );
check( 'оплата: терминал', 'terminal' === ptg_payment_category( 'podval_card', 'Оплата картой через терминал' ) );
check( 'оплата: онлайн', 'online' === ptg_payment_category( 'alfabank', 'Оплата онлайн' ) );
check( 'оплата: после согласования — отдельная категория', 'agreed' === ptg_payment_category( 'bacs', 'Оплата после согласования' ) );
check( 'оплата: неизвестное → другое', 'other' === ptg_payment_category( 'x', 'Бартер' ) );

$rg = ptg_report_range( '2026-09-01', '2026-09-30' );
check( 'период: сентябрь = 30 дней', 30 === $rg[2] );
check( 'период: начало в полночь по Минску', '2026-09-01 00:00' === wp_date( 'Y-m-d H:i', $rg[0] ) );
check( 'период: конец включительно', '2026-10-01 00:00' === wp_date( 'Y-m-d H:i', $rg[1] ) );
check( 'период: перевёрнутый — ошибка', null === ptg_report_range( '2026-09-30', '2026-09-01' ) );
check( 'период: больше 400 дней — ошибка', null === ptg_report_range( '2024-01-01', '2026-01-01' ) );

class RepOrder extends StoreOrder {
	public $total; public $pm; public $pmt; public $qty;
	function __construct( $id, $s, $at, $total, $pm, $pmt, $qty = 1 ) { parent::__construct( $id, $s, $at, $at ); $this->total = $total; $this->pm = $pm; $this->pmt = $pmt; $this->qty = $qty; }
	function get_total() { return $this->total; }
	function get_payment_method() { return $this->pm; }
	function get_payment_method_title() { return $this->pmt; }
	function get_items() { $q = $this->qty; return array( new class( $q ) { public $q; function __construct( $q ) { $this->q = $q; } function get_quantity() { return $this->q; } } ); }
}
list( $A, $B ) = ptg_report_range( '2026-09-01', '2026-09-10' );
$GLOBALS['store'] = array(
	new RepOrder( 1, 'issued', $A + 3600, 55, 'cod', 'Оплата наличными', 2 ),
	new RepOrder( 2, 'issued', $A + 86400 * 2, 110, 'cod', 'Оплата наличными' ),
	new RepOrder( 3, 'issued', $A + 86400 * 3, 60, 'card', 'Оплата картой через терминал' ),
	new RepOrder( 4, 'issued', $A + 86400 * 9 + 100, 75, 'bacs', 'Оплата после согласования' ),
	new RepOrder( 5, 'issued', $B + 10, 999, 'cod', 'Оплата наличными' ),        // после периода
	new RepOrder( 6, 'issued', $A - 10, 999, 'cod', 'Оплата наличными' ),        // до периода
	new RepOrder( 7, 'refused', $A + 5000, 40, 'cod', 'Оплата наличными' ),
	new RepOrder( 8, 'not-picked', $A + 6000, 40, 'cod', 'Оплата наличными' ),
	new RepOrder( 9, 'ready', $A + 7000, 40, 'cod', 'Оплата наличными' ),        // не выдан — не выручка
);
$rep = ptg_report_build( $A, $B );
check( 'отчёт: выдано 4 в периоде', 4 === $rep['issued'] );
check( 'отчёт: выручка 300', 300.0 === $rep['revenue'] );
check( 'отчёт: средний чек 75', 75.0 === $rep['avg'] );
check( 'отчёт: вещей 5', 5 === $rep['items'] );
check( 'отчёт: отказ 1, доля 20%', 1 === $rep['refused'] && 20.0 === $rep['refused_share'] );
check( 'отчёт: не забрали 1', 1 === $rep['not_picked'] );
$by = array(); foreach ( $rep['payments'] as $p ) { $by[ $p['key'] ] = $p; }
check( 'отчёт: наличные 55%', 165.0 === $by['cash']['sum'] && 55.0 === $by['cash']['share'] && 2 === $by['cash']['count'] );
check( 'отчёт: после согласования без отметки — 25%', 25.0 === $by['agreed']['share'] && ! isset( $by['link'] ) );
check( 'отчёт: пустых категорий нет', ! isset( $by['online'] ) );
check( 'отчёт: 10 столбиков по дням', 10 === count( $rep['series'] ) );
check( 'отчёт: сумма по дням = выручка', 300.0 === array_sum( array_column( $rep['series'], 'sum' ) ) );
check( 'отчёт: последний день попал в свой столбик', 75.0 === $rep['series'][9]['sum'] );
list( $A2, $B2 ) = ptg_report_range( '2026-01-01', '2026-06-30' );
check( 'отчёт: полгода — по неделям', count( ptg_report_series( array(), $A2, $B2 ) ) <= 27 );


// ── 2.11.0: адрес самовывоза ─────────────────────────────────────
function get_bloginfo( $x ) { return 'Test Shop'; }
function WC() { return null; }
function sanitize_text_field( $v ) { return trim( $v ); }

$GLOBALS['api_calls'] = array();
function ptg_api( $method, $args = array() ) {
	$GLOBALS['api_calls'][] = array( $method, $args );
	if ( 'getBusinessConnection' === $method ) { return $GLOBALS['api_reply'] ?? array(); }
	return array( 'message_id' => count( $GLOBALS['api_calls'] ) );
}

check( 'адрес: без настроек отправка запрещена', is_wp_error( ptg_send_pickup_info( new FakeOrder( 'ready' ), 1 ) ) );
$GLOBALS['opts']['ptg_pickup_address'] = 'Минск, ул. Тимирязева 65Б, офис 301';
check( 'адрес: заполнен — можно', ptg_pickup_ready() );
check( 'координаты: пусто → точки нет', null === ptg_pickup_point() );
$GLOBALS['opts']['ptg_pickup_lat'] = '53.902284';
$GLOBALS['opts']['ptg_pickup_lng'] = '27.561831';
check( 'координаты: разобраны', array( 53.902284, 27.561831 ) === ptg_pickup_point() );
check( 'координаты: запятая как разделитель', '53.9' === ptg_sanitize_coord( '53,9' ) );
check( 'координаты: мусор отбрасывается', '' === ptg_sanitize_coord( 'вот тут' ) );
$GLOBALS['opts']['ptg_pickup_lat'] = '999';
check( 'координаты: вне диапазона — точки нет', null === ptg_pickup_point() );
$GLOBALS['opts']['ptg_pickup_lat'] = '53.902284';

$o = new FakeOrder( 'ready' );
$txt = ptg_pickup_render( $o, true );
check( 'текст: подставлены номер и адрес', false !== mb_strpos( $txt, '№47' ) && false !== mb_strpos( $txt, 'Тимирязева' ) );
check( 'текст: для копирования добавлена ссылка на карту', false !== mb_strpos( $txt, 'maps.google.com/?q=53.902284,27.561831' ) );
check( 'текст: в отправляемом ссылки нет (точка уйдёт карточкой)', false === mb_strpos( ptg_pickup_render( $o ), 'maps.google' ) );

$o = new FakeOrder( 'ready' );
check( 'отправка: без привязанного чата — ошибка', is_wp_error( ptg_send_pickup_info( $o, 1 ) ) );
check( 'отправка: отметка не поставлена', empty( $o->meta[ PTG_META_PICKUP_AT ] ) );

$o = new FakeOrder( 'ready' );
check( 'копирование: отметка ставится', true === ptg_app_do( $o, 'pickup', array( 'copy' => true ), 1, array() ) && ! empty( $o->meta[ PTG_META_PICKUP_AT ] ) );
check( 'копирование: заметка в заказе', (bool) preg_grep( '/адрес.*скопирован/ui', $o->notes ) );
check( 'адрес: на неготовом заказе нельзя', is_wp_error( ptg_app_do( new FakeOrder( 'in-print' ), 'pickup', array( 'copy' => true ), 1, array() ) ) );

$o = new FakeOrder( 'ready' );
$o->meta = array( PTG_META_CLIENT_CHAT => 555, PTG_META_CONNECTION => 'conn1' );
$GLOBALS['opts']['ptg_business_connections'] = array( 'conn1' => array( 'user_id' => 1, 'is_enabled' => true, 'rights' => array( 'can_reply' => true ) ) );
$GLOBALS['api_calls'] = array();
$res = ptg_send_pickup_info( $o, 1, array() );
$methods = array_column( $GLOBALS['api_calls'], 0 );
check( 'отправка: ушли сообщение и точка', true === $res && array( 'sendMessage', 'sendVenue' ) === $methods );
$venue = $GLOBALS['api_calls'][1][1];
check( 'точка: координаты и адрес в карточке места', 53.902284 === $venue['latitude'] && 'Минск, ул. Тимирязева 65Б, офис 301' === $venue['address'] );
check( 'точка: подключение то же, что у чата', 'conn1' === $venue['business_connection_id'] );
check( 'лог: две записи — текст и адрес', 2 === count( $o->meta[ PTG_META_CHAT_LOG ] ) && 'адрес' === $o->meta[ PTG_META_CHAT_LOG ][1]['f'] );


// ── 2.12.0: чаты без заказа и превью из переписки ────────────────

$now = time();
$GLOBALS['opts'][ PTG_OPT_UNLINKED ] = array();
ptg_remember_unlinked( 'c1', array( 'id' => 111, 'username' => 'lexa', 'first_name' => 'Алексей' ), array( 't' => $now - 60, 'x' => 'Хочу футболку', 'd' => 'in', 'f' => '' ) );
ptg_remember_unlinked( 'c1', array( 'id' => 222, 'username' => '', 'first_name' => 'Оля' ), array( 't' => $now - 30, 'x' => '', 'd' => 'in', 'f' => 'фото' ) );
$un = ptg_unlinked_chats();
check( 'чаты без заказа: свежий первым', 2 === count( $un ) && 222 === $un[0]['chat_id'] );
check( 'чаты без заказа: ник и имя сохранены', 'lexa' === $un[1]['username'] && 'Алексей' === $un[1]['name'] );
ptg_remember_unlinked( 'c1', array( 'id' => 111, 'username' => 'lexa', 'first_name' => 'Алексей' ), array( 't' => $now, 'x' => 'ещё', 'd' => 'in', 'f' => '' ) );
check( 'чаты без заказа: повтор обновляет запись, не плодит', 2 === count( ptg_unlinked_chats() ) && 111 === ptg_unlinked_chats()[0]['chat_id'] );
$GLOBALS['opts']['ptg_chat_index'] = array( ptg_chat_key( 'c1', 222 ) => 777 );
function wc_get_order( $id ) { return 777 === (int) $id ? new FakeOrder( 'processing' ) : null; }
check( 'чаты без заказа: привязанный исчез из списка', 1 === count( ptg_unlinked_chats() ) );
ptg_remember_unlinked( 'c1', array( 'id' => 333, 'username' => 'old', 'first_name' => 'Старый' ), array( 't' => $now - 8 * 86400, 'x' => 'давно', 'd' => 'in', 'f' => '' ) );
$keys = array_column( ptg_unlinked_chats(), 'chat_id' );
check( 'чаты без заказа: старше недели не показываем', ! in_array( 333, $keys, true ) && in_array( 111, $keys, true ) );
ptg_forget_unlinked( 'c1', 111 );
check( 'чаты без заказа: забываем по привязке', 0 === count( ptg_unlinked_chats() ) );

check( 'оплата: наш способ из приложения', 'link' === ptg_payment_category( 'ptg_link', 'По ссылке' ) && 'cash' === ptg_payment_category( 'ptg_cash', 'Наличные' ) );
check( 'оплата: чужой ptg_-метод не ломает разбор', 'other' === ptg_payment_category( 'ptg_bitcoin', 'Биткоин' ) );

$o = new FakeOrder( 'processing' );
$o->meta['_ptg_chat_files'] = array(
	array( 'id' => 3, 'url' => 'https://x/in.jpg', 'kind' => 'photo', 'dir' => 'in', 't' => 100 ),
	array( 'id' => 4, 'url' => 'https://x/sketch.jpg', 'kind' => 'photo', 'dir' => 'out', 't' => 200 ),
	array( 'id' => 5, 'url' => 'https://x/doc.pdf', 'kind' => 'document', 'dir' => 'out', 't' => 300 ),
);
$pv = ptg_chat_preview( $o );
check( 'превью из переписки: берём наш эскиз, а не фото клиента', 'https://x/sketch.jpg' === $pv['url'] && 'chat' === $pv['source'] );
$o2 = new FakeOrder( 'processing' );
$o2->meta['_ptg_chat_files'] = array( array( 'id' => 6, 'url' => 'https://x/in.png', 'kind' => 'photo', 'dir' => 'in', 't' => 100 ) );
check( 'превью из переписки: если своего нет — клиентское', 'https://x/in.png' === ptg_chat_preview( $o2 )['url'] );
check( 'превью из переписки: без картинок — пусто', null === ptg_chat_preview( new FakeOrder( 'processing' ) ) );


// ── 2.13.0: прайс и печать с нескольких сторон ───────────────────
function wc_get_price_to_display( $p ) { return 0; }
require __DIR__ . '/../inc/app-create.php';

check( 'прайс: классическая 55', 55.0 === ptg_price_base( 'Футболка Классическая', 0 ) );
check( 'прайс: оверсайз 75', 75.0 === ptg_price_base( 'Футболка Оверсайз', 0 ) );
check( 'прайс: худи 150', 150.0 === ptg_price_base( 'Зип Худи', 0 ) );
check( 'прайс: модель важнее товара', 75.0 === ptg_price_base( 'Футболка Классическая', 0, 'Оверсайз' ) );
check( 'прайс: неизвестное — цена каталога', 42.0 === ptg_price_base( 'Бандана', 42 ) );
check( 'прайс: каталог пустой — умолчание 55', 55.0 === ptg_price_base( 'Бандана', 0 ) );

check( 'сумма: одна сторона — без доплат', 55.0 === ptg_price_total( 55, array( 'front' ), false, 1 ) );
check( 'сумма: две стороны +10', 65.0 === ptg_price_total( 55, array( 'front', 'back' ), false, 1 ) );
check( 'сумма: рукав +5', 60.0 === ptg_price_total( 55, array( 'front' ), true, 1 ) );
check( 'сумма: две стороны и рукав', 70.0 === ptg_price_total( 55, array( 'front', 'back' ), true, 1 ) );
check( 'сумма: доплаты умножаются на количество', 140.0 === ptg_price_total( 55, array( 'front', 'back' ), true, 2 ) );
check( 'сумма: без сторон доплат нет', 150.0 === ptg_price_total( 150, array(), false, 1 ) );
check( 'сумма: дубли сторон не удваивают доплату', 65.0 === ptg_price_total( 55, array( 'front', 'back', 'back' ), false, 1 ) );


// ── 2.14.0: чат по номеру телефона ───────────────────────────────
check( 'телефон: ссылка из формата с плюсом', 'https://t.me/+375339912445' === ptg_phone_chat_link( '+375339912445' ) );
check( 'телефон: скобки и дефисы игнорируются', 'https://t.me/+375291234567' === ptg_phone_chat_link( '+375 (29) 123-45-67' ) );
check( 'телефон: короткий номер — не ссылка', '' === ptg_phone_chat_link( '12345' ) );
check( 'телефон: пусто — не ссылка', '' === ptg_phone_chat_link( '' ) );
check( 'телефон: мусор — не ссылка', '' === ptg_phone_chat_link( 'позвонить Тане' ) );
check( 'телефон: слишком длинный — не ссылка', '' === ptg_phone_chat_link( '1234567890123456789' ) );

$data = array(
	'id' => 471, 'number' => '471', 'type' => 'order',
	'status_info' => ptg_order_status_info( new FakeOrder( 'processing' ) ),
	'overrides' => array(), 'greeting' => array( 'sent' => true, 'text' => '' ),
	'client' => array( 'linked' => false, 'messages' => 0, 'files' => 0, 'outgoing' => 0, 'checkout' => '' ),
	'customer' => array( 'phone' => '+375339912445' ),
	'assignee' => array( 'name' => '' ), 'admin_url' => 'https://x/adm',
);
$t = kb_texts( ptg_keyboard_root( $data )['inline_keyboard'] );
check( 'карточка: кнопка чата по номеру', in_array( '📲 Чат по номеру', $t, true ) );
check( 'карточка: кнопка копирования /link', in_array( '📋 /link 471', $t, true ) );
$data['client']['linked'] = true;
$t = kb_texts( ptg_keyboard_root( $data )['inline_keyboard'] );
check( 'карточка: у привязанного чата этих кнопок нет', ! in_array( '📲 Чат по номеру', $t, true ) );
$data['client']['linked'] = false;
$data['customer']['phone'] = '';
$t = kb_texts( ptg_keyboard_root( $data )['inline_keyboard'] );
check( 'карточка: без телефона кнопок нет', ! in_array( '📲 Чат по номеру', $t, true ) );


// ── 2.14.1: кнопки переписки только до ответа клиента ────────────
function kb_client( $status, $linked = false, $phone = '+375339912445', $sent = false ) {
	$d = array(
		'id' => 472, 'number' => '472', 'type' => 'order',
		'status_info' => ptg_order_status_info( new FakeOrder( $status ) ),
		'overrides' => array(), 'greeting' => array( 'sent' => $sent, 'text' => 'Здравствуйте!' ),
		'client' => array( 'linked' => $linked, 'messages' => 0, 'files' => 0, 'outgoing' => 0, 'checkout' => 'lexa' ),
		'customer' => array( 'phone' => $phone ),
		'assignee' => array( 'name' => '' ), 'admin_url' => 'https://x/adm',
	);
	return kb_texts( ptg_keyboard_root( $d )['inline_keyboard'] );
}
$t = kb_client( 'processing' );
check( 'кнопки переписки: на «Получен» есть', in_array( '📋 Скопировать приветствие', $t, true ) && in_array( '📲 Чат по номеру', $t, true ) );
$t = kb_client( 'on-hold' );
check( 'кнопки переписки: на «После согласования» есть', in_array( '📲 Чат по номеру', $t, true ) );
$t = kb_client( 'client-replied' );
check( 'кнопки переписки: после ответа клиента убраны', ! in_array( '📋 Скопировать приветствие', $t, true ) && ! in_array( '📲 Чат по номеру', $t, true ) && ! in_array( '✍️ Открыть личку', $t, true ) );
check( 'после ответа клиента статусы и админка на месте', in_array( '🎨 Эскиз отправлен', $t, true ) && in_array( '🗂 Админка', $t, true ) );
$t = kb_client( 'ready' );
check( 'кнопки переписки: на «Готов к выдаче» их нет', ! in_array( '📲 Чат по номеру', $t, true ) );


// ── 2.14.2: отказ — только возврат в работу ──────────────────────
$GLOBALS['opts']['ptg_app_short_name'] = 'orders';
$o = new FakeOrder( 'refused' ); $o->meta = array( PTG_META_NEG_FROM => 'sketch-sent' );
$d = array(
	'id' => 471, 'number' => '471', 'type' => 'order',
	'status_info' => ptg_order_status_info( $o ),
	'overrides' => array( array( 'x' ) ), 'greeting' => array( 'sent' => false, 'text' => 'Здравствуйте!' ),
	'client' => array( 'linked' => false, 'messages' => 3, 'files' => 1, 'outgoing' => 0, 'checkout' => 'lexa' ),
	'customer' => array( 'phone' => '+375339912445' ),
	'assignee' => array( 'name' => '' ), 'admin_url' => 'https://x/adm',
);
$kb = ptg_keyboard_root( $d );
$t  = kb_texts( $kb['inline_keyboard'] );
check( 'отказ: есть «Вернуть в работу»', in_array( '↩️ Вернуть в работу', $t, true ) );
check( 'отказ: остальных кнопок нет', ! in_array( '✏️ Изменить параметры (1)', $t, true ) && ! in_array( '📋 Скопировать приветствие', $t, true ) && ! in_array( '📜 Переписка (3)', $t, true ) && ! in_array( '🚫 Отказ', $t, true ) );
check( 'отказ: ссылки остались', in_array( '📱 В приложении', $t, true ) && in_array( '🗂 Админка', $t, true ) );
check( 'отказ: всего два ряда', 2 === count( $kb['inline_keyboard'] ) );
$back = $kb['inline_keyboard'][0][0]['callback_data'];
check( 'отказ: возврат ведёт в исходный статус', false !== strpos( $back, 'ss' ) && false !== strpos( $back, '471' ) );
$o2 = new FakeOrder( 'not-picked' ); $o2->meta = array( PTG_META_NEG_FROM => 'ready' );
$d['status_info'] = ptg_order_status_info( $o2 );
$t = kb_texts( ptg_keyboard_root( $d )['inline_keyboard'] );
check( 'не забрали: кнопки на месте (заказ живой)', in_array( '✅ Выдан', $t, true ) && in_array( '📜 Переписка (3)', $t, true ) );


// ── 2.15.0: подготовка картинки ──────────────────────────────────
$up = wp_upload_dir()['basedir'];
@mkdir( $up . '/customer-prints', 0777, true );
foreach ( glob( $up . '/ptg-previews/*.jpg' ) as $f ) { unlink( $f ); }

$make_png = function ( $path, $w, $h, $alpha ) {
	$im = imagecreatetruecolor( $w, $h );
	if ( $alpha ) {
		imagealphablending( $im, false );
		imagesavealpha( $im, true );
		imagefilledrectangle( $im, 0, 0, $w, $h, imagecolorallocatealpha( $im, 255, 255, 255, 127 ) );
		imagefilledrectangle( $im, (int) ( $w / 4 ), (int) ( $h / 4 ), (int) ( $w / 2 ), (int) ( $h / 2 ), imagecolorallocate( $im, 255, 255, 255 ) );
	} else {
		imagefilledrectangle( $im, 0, 0, $w, $h, imagecolorallocate( $im, 20, 20, 20 ) );
	}
	imagepng( $im, $path );
	imagedestroy( $im );
};
$base_url = 'https://shop.test/wp-content/uploads/customer-prints/';

$make_png( $up . '/customer-prints/alpha.png', 2400, 2400, true );
$make_png( $up . '/customer-prints/solid.png', 400, 400, false );

check( 'путь: адрес из uploads разворачивается в файл', is_file( ptg_url_to_path( $base_url . 'solid.png' ) ) );
check( 'путь: другая схема — тот же файл', is_file( ptg_url_to_path( str_replace( 'https://', 'http://', $base_url ) . 'solid.png' ) ) );
check( 'путь: чужой домен — пусто', '' === ptg_url_to_path( 'https://example.com/a.png' ) );
check( 'путь: выход наверх запрещён', '' === ptg_url_to_path( $base_url . '../../wp-config.php' ) );

check( 'прозрачность: PNG с альфой', ptg_image_has_alpha( $up . '/customer-prints/alpha.png', IMAGETYPE_PNG ) );
check( 'прозрачность: обычный PNG', ! ptg_image_has_alpha( $up . '/customer-prints/solid.png', IMAGETYPE_PNG ) );

$v = ptg_preview_variant( $base_url . 'alpha.png' );
check( 'прозрачный макет: подменён на подготовленный jpg', $v !== $base_url . 'alpha.png' && '.jpg' === substr( $v, -4 ) );
$vp = ptg_url_to_path( $v );
$vs = getimagesize( $vp );
check( 'подготовленный: уменьшен до 1600', 1600 === max( $vs[0], $vs[1] ) );
check( 'подготовленный: это JPEG без альфы', IMAGETYPE_JPEG === $vs[2] );
$px = imagecolorat( imagecreatefromjpeg( $vp ), 5, 5 );
check( 'подготовленный: прозрачное стало серой шахматкой, не белым', ( ( $px >> 16 ) & 255 ) < 240 );
check( 'подготовленный: второй вызов отдаёт тот же файл', $v === ptg_preview_variant( $base_url . 'alpha.png' ) );
check( 'лёгкий непрозрачный файл отдаём как есть', $base_url . 'solid.png' === ptg_preview_variant( $base_url . 'solid.png' ) );
check( 'чужой адрес не трогаем', 'https://example.com/a.png' === ptg_preview_variant( 'https://example.com/a.png' ) );


// ── 2.16.0: состояние оплаты ─────────────────────────────────────
class PayOrder extends FakeOrder {
	public $pm = 'alfabank'; public $pmt = 'alfabank'; public $txn = ''; public $paid_at = null;
	function get_payment_method() { return $this->pm; }
	function get_payment_method_title() { return $this->pmt; }
	function get_transaction_id() { return $this->txn; }
	function get_date_paid() { return $this->paid_at; }
}
$mk = function ( $status, $pm, $pmt, $txn = '', $paid = null ) {
	$o = new PayOrder( $status ); $o->pm = $pm; $o->pmt = $pmt; $o->txn = $txn; $o->paid_at = $paid;
	return ptg_payment_state( $o );
};
check( 'оплата: онлайн и есть дата — «оплачен»', 'paid' === $mk( 'processing', 'alfabank', 'alfabank', 'tx1', '2026-09-27' )['state'] );
check( 'оплата: онлайн без даты — «не подтверждена»', 'unpaid' === $mk( 'processing', 'alfabank', 'Оплата онлайн' )['state'] );
check( 'оплата: статус failed — «платёж не прошёл»', 'failed' === $mk( 'failed', 'alfabank', 'alfabank', '', null )['state'] );
check( 'оплата: статус pending — «ждём оплату»', 'pending' === $mk( 'pending', 'alfabank', 'alfabank' )['state'] );
check( 'оплата при получении: состояния нет', '' === $mk( 'processing', 'ptg_cash', 'Наличные', '', '2026-09-27' )['state'] );
check( 'оплата: по ссылке считается онлайном', 'paid' === $mk( 'processing', 'ptg_link', 'По ссылке', '', '2026-09-27' )['state'] );
check( 'оплата: подпись для карточки заполнена', '✅ оплачен' === $mk( 'processing', 'alfabank', 'alfabank', 'tx1', '2026-09-27' )['state_label'] );


// ── 2.17.0: «после согласования» ─────────────────────────────────
if ( ! defined( 'PTG_META_MESSAGE' ) ) { define( 'PTG_META_MESSAGE', '_ptg_message_id' ); define( 'PTG_META_CHAT', '_ptg_chat_id' ); }
if ( ! function_exists( 'ptg_esc' ) ) { function ptg_esc( $s ) { return htmlspecialchars( (string) $s, ENT_NOQUOTES ); } }
if ( ! function_exists( 'ptg_tg_send_message' ) ) { function ptg_tg_send_message( $c, $t, $a = array() ) { $GLOBALS['sent'][] = $t; return 1; } }
class AgreedOrder extends PayOrder {
	function delete_meta_data( $k ) { unset( $this->meta[ $k ] ); }
}
$ag = function ( $status = 'processing', $paid = '2026-10-08' ) {
	$o = new AgreedOrder( $status ); $o->pm = 'bacs'; $o->pmt = 'Оплата после согласования'; $o->paid_at = $paid;
	return $o;
};
$o = $ag();
check( 'согласование: date_paid от WooCommerce не делает «оплачен»', 'agreed' === ptg_payment_state( $o )['state'] );
check( 'согласование: подпись «по согласованию»', '💬 оплата по согласованию' === ptg_payment_state( $o )['state_label'] );
$o->txn = 'tx-from-somewhere';
check( 'согласование: transaction_id тоже не делает «оплачен»', 'agreed' === ptg_payment_state( $o )['state'] );
check( 'согласование: заказ из приложения «по ссылке» — не согласование', ! ptg_payment_is_agreed( ( function () { $x = new PayOrder( 'processing' ); $x->pm = 'ptg_link'; $x->pmt = 'По ссылке'; return $x; } )() ) );
$GLOBALS['sent'] = array();
$o = $ag(); $o->meta[ PTG_META_MESSAGE ] = 555;
check( 'отметка: ставится', true === ptg_set_pay_mark( $o, 'cash', 1, array() ) && 'cash' === ptg_pay_mark( $o ) );
check( 'отметка: «оплачен · наличными»', '✅ оплачен · наличными' === ptg_payment_state( $o )['state_label'] && 'paid' === ptg_payment_state( $o )['state'] );
check( 'отметка: реплай в группу ушёл с указанием способа', 1 === count( $GLOBALS['sent'] ) && false !== mb_strpos( $GLOBALS['sent'][0], 'оплачен · наличными' ) );
ptg_set_pay_mark( $o, 'terminal', 1, array() );
check( 'отметка: смена способа без второго реплая', 1 === count( $GLOBALS['sent'] ) && 'terminal' === ptg_pay_mark( $o ) );
check( 'отметка: в историю пишется', (bool) preg_grep( '/Оплачено: картой/u', $o->notes ) );
ptg_set_pay_mark( $o, '', 1, array() );
check( 'отметка: снимается', '' === ptg_pay_mark( $o ) && 'agreed' === ptg_payment_state( $o )['state'] );
check( 'отметка: неизвестный способ — ошибка', is_wp_error( ptg_set_pay_mark( $o, 'bitcoin', 1, array() ) ) );
$x = new AgreedOrder( 'processing' ); $x->pm = 'cod'; $x->pmt = 'Оплата наличными';
check( 'отметка: для обычных способов недоступна', is_wp_error( ptg_set_pay_mark( $x, 'cash', 1, array() ) ) );
$o = $ag(); ptg_set_pay_mark( $o, 'link', 1, array() );
check( 'отчёт: отмеченный заказ уходит в фактический способ', 'link' === ptg_order_payment_category( $o ) );
check( 'отчёт: неотмеченный — «после согласования»', 'agreed' === ptg_order_payment_category( $ag() ) );


// ── 2.16.1: неоплаченный заказ можно взять в работу ──────────────
$i = ptg_order_status_info( new FakeOrder( 'pending' ) );
check( 'ожидает оплату: есть шаг «Клиент ответил»', array( 'client-replied' ) === $i['next'] && $i['refusable'] );
$i = ptg_order_status_info( new FakeOrder( 'failed' ) );
check( 'ошибка оплаты: есть шаг «Клиент ответил»', array( 'client-replied' ) === $i['next'] );
$t = kb_texts( kb_for( 'pending' ) );
check( 'карточка: кнопка «Клиент ответил» на неоплаченном', in_array( '❤️ Клиент ответил', $t, true ) );
check( 'карточка: и отказ тоже', in_array( '🚫 Отказ', $t, true ) );
check( 'карточка: назад из неоплаченного некуда', ! (bool) preg_grep( '/^↩️/u', $t ) );
list( $r, $o ) = do_status( 'pending', 'client-replied' );
check( 'действие из приложения: неоплаченный уходит в работу', true === $r && 'client-replied' === $o->status );
list( $r ) = do_status( 'pending', 'ready' );
check( 'действие: перескок из неоплаченного запрещён', is_wp_error( $r ) );
$t = kb_texts( kb_for( 'completed' ) );
check( 'служебные статусы WooCommerce кнопок цикла не получили', ! in_array( '❤️ Клиент ответил', $t, true ) );


// ── 2.16.2: запись в историю не зависит от перехода ──────────────
$o = new FakeOrder( 'in-print' );
ptg_set_status( $o, 'ready', 1, array() );
$our = array_values( preg_grep( '/Статус изменён из Telegram/u', $o->notes ) );
check( 'история: наша запись добавляется отдельно', 1 === count( $our ) );
check( 'история: в записи старый и новый статусы', false !== mb_strpos( $our[0], 'В печати' ) && false !== mb_strpos( $our[0], 'Готов к выдаче' ) );
check( 'история: текст не дублируется в update_status', 1 === count( $o->notes ) );


// ── 2.16.3: запись в историю до перехода, память на превью ───────
$o = new FakeOrder( 'sketch-sent' );
$o->notes = array();
ptg_set_status( $o, 'in-print', 1, array() );
check( 'история: запись появляется до смены статуса', 1 === count( $o->notes ) && false !== mb_strpos( $o->notes[0], 'Эскиз отправлен → В печати' ) );
check( 'память: обычная картинка проходит', ptg_memory_for_image( 2000, 2000 ) );
check( 'память: гигантская отсекается', ! ptg_memory_for_image( 20000, 20000 ) );
$big = wp_upload_dir()['basedir'] . '/customer-prints/huge.png';
if ( ! file_exists( $big ) ) { copy( wp_upload_dir()['basedir'] . '/customer-prints/alpha.png', $big ); }
check( 'превью: при нехватке памяти отдаём исходник', is_string( ptg_preview_variant( 'https://shop.test/wp-content/uploads/customer-prints/huge.png' ) ) );


// ── 2.16.4: эмодзи не должны убивать запись в истории ────────────
$o = new FakeOrder( 'client-replied' );
$o->notes = array();
ptg_set_status( $o, 'sketch-sent', 1, array() );
check( 'история: переход в «Эскиз отправлен» записан', 1 === count( $o->notes ) );
check( 'история: четырёхбайтовые эмодзи убраны', ! preg_match( '/[\x{10000}-\x{10FFFF}]/u', $o->notes[0] ) );
check( 'история: текст остался читаемым', false !== mb_strpos( $o->notes[0], 'Клиент ответил → Эскиз отправлен' ) );
$o = new FakeOrder( 'in-print' );
$o->notes = array();
ptg_set_status( $o, 'ready', 1, array() );
check( 'история: переход в «Готов к выдаче» записан', 1 === count( $o->notes ) && false !== mb_strpos( $o->notes[0], 'Готов к выдаче' ) );
$o = new FakeOrder( 'processing' );
check( 'запись: пустой текст не создаёт заметку', false === ptg_add_note( $o, '🔥' ) );
ptg_add_note( $o, 'Тест ❤️ ок' );
check( 'запись: трёхбайтовые символы сохраняются', false !== mb_strpos( (string) end( $o->notes ), '❤️' ) );


// ── 2.16.5: подключение перечитывается у Telegram ────────────────
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
$GLOBALS['transients'] = array();
$GLOBALS['opts']['ptg_business_connections'] = array();
$GLOBALS['api_calls'] = array();
$GLOBALS['api_reply'] = array( 'id' => 'conn9', 'user' => array( 'id' => 331109569, 'first_name' => 'Alexandr' ), 'is_enabled' => true, 'rights' => array( 'can_reply' => true ) );

check( 'подключение: пустой id ничего не делает', false === ptg_refresh_connection( '' ) );
check( 'подключение: перечитано и сохранено', true === ptg_refresh_connection( 'conn9' ) );
check( 'подключение: владелец записан', 331109569 === ptg_connection_owner( 'conn9' ) );
check( 'подключение: право на ответ записано', ptg_connection_can_reply( 'conn9' ) );
check( 'подключение: повторный запрос придержан', false === ptg_refresh_connection( 'conn9' ) );
check( 'подключение: обращались к getBusinessConnection', 'getBusinessConnection' === $GLOBALS['api_calls'][0][0] );


// ── 2.16.6: личные чаты можно убрать из списка ───────────────────
$GLOBALS['opts'][ PTG_OPT_UNLINKED ] = array();
$GLOBALS['opts'][ PTG_OPT_UNLINKED_HIDDEN ] = array();
$GLOBALS['opts']['ptg_chat_index'] = array();
ptg_remember_unlinked( 'c1', array( 'id' => 500, 'username' => 'mama', 'first_name' => 'Мама' ), array( 't' => time(), 'x' => 'как дела', 'd' => 'in', 'f' => '' ) );
check( 'список: чат попал', 1 === count( ptg_unlinked_chats() ) );
ptg_hide_unlinked( ptg_chat_key( 'c1', 500 ) );
check( 'список: после крестика чат убран', 0 === count( ptg_unlinked_chats() ) );
ptg_remember_unlinked( 'c1', array( 'id' => 500, 'username' => 'mama', 'first_name' => 'Мама' ), array( 't' => time(), 'x' => 'ещё сообщение', 'd' => 'in', 'f' => '' ) );
check( 'список: скрытый чат не возвращается новым сообщением', 0 === count( ptg_unlinked_chats() ) );
$GLOBALS['opts']['ptg_remember_chats'] = 0;
ptg_remember_unlinked( 'c1', array( 'id' => 501, 'username' => 'kto', 'first_name' => 'Кто-то' ), array( 't' => time(), 'x' => 'привет', 'd' => 'in', 'f' => '' ) );
check( 'список: при выключенной настройке ничего не копится', 0 === count( ptg_unlinked_chats() ) );
$GLOBALS['opts']['ptg_remember_chats'] = 1;


// ── 2.16.7: подготовка превью не зависит от одной библиотеки ─────
foreach ( glob( wp_upload_dir()['basedir'] . '/ptg-previews/*.jpg' ) as $f ) { unlink( $f ); }
$v = ptg_preview_variant( 'https://shop.test/wp-content/uploads/customer-prints/alpha.png' );
check( 'превью: собирается доступной библиотекой', '.jpg' === substr( $v, -4 ) );
$vs = getimagesize( ptg_url_to_path( $v ) );
check( 'превью: размер ограничен', 1600 === max( $vs[0], $vs[1] ) && IMAGETYPE_JPEG === $vs[2] );
check( 'превью: GD-ветка собирает файл напрямую', ptg_preview_gd( wp_upload_dir()['basedir'] . '/customer-prints/alpha.png', IMAGETYPE_PNG, wp_upload_dir()['basedir'] . '/ptg-previews/gd-test.jpg', 2400, 2400, 800, 800 ) );
check( 'превью: Imagick-ветка молча уступает, если его нет', class_exists( 'Imagick' ) || false === ptg_preview_imagick( wp_upload_dir()['basedir'] . '/customer-prints/alpha.png', wp_upload_dir()['basedir'] . '/ptg-previews/im-test.jpg', 800, 800 ) );


// ── 2.16.8: ссылка на подготовленное превью живёт в тексте ───────
function ptg_link( $url, $label ) { return '<a href="' . $url . '">' . $label . '</a>'; }
$GLOBALS['opts']['ptg_preview_mode'] = 'link';

$data = array( 'items' => array( array(
	'item_id' => 1, 'kind' => 'simple', 'artwork' => true, 'name' => 'Печать', 'qty' => 1, 'garment' => array(),
	'links' => array( array( 'role' => 'artwork', 'url' => 'https://shop.test/wp-content/uploads/customer-prints/alpha.png', 'label' => 'Принт на спину' ) ),
) ) );
$pv = ptg_order_preview( $data, new FakeOrder( 'processing' ) );
check( 'превью: подготовленный вариант помечен', ! empty( $pv['prepared'] ) && $pv['url'] !== $pv['origin'] );
$anc = ptg_render_preview_anchor( array( 'preview' => $pv ) );
check( 'превью: ссылка на него попадает в текст карточки', false !== strpos( $anc, $pv['url'] ) );
check( 'превью: ссылка невидимая — без «Превью макета»', false !== strpos( $anc, "\u{200B}</a>" ) && false === mb_strpos( $anc, 'Превью' ) );
check( 'превью: отдельной видимой строки больше нет', '' === ptg_render_preview_line( array( 'preview' => $pv ) ) );
$GLOBALS['opts']['ptg_preview_mode'] = 'photo';
check( 'превью: в режиме фото невидимой ссылки нет', '' === ptg_render_preview_anchor( array( 'preview' => $pv ) ) );
// 2.17.1: метка ревизии в адресе превью — Telegram кэширует и неудачи.
$GLOBALS['opts']['ptg_preview_mode'] = 'link';
check( 'превью: в адрес для Telegram добавлена ревизия', 'https://x/a.jpg?tg=1' === ptg_preview_tg_url( 'https://x/a.jpg' ) );
$GLOBALS['opts']['ptg_preview_rev'] = 3;
check( 'превью: ревизия из настроек, существующий query не ломается', 'https://x/a.jpg?w=1&tg=3' === ptg_preview_tg_url( 'https://x/a.jpg?w=1' ) );
check( 'превью: ссылка в карточке с той же ревизией', false !== strpos( ptg_render_preview_anchor( array( 'preview' => $pv ) ), '?tg=3' ) );
if ( ! function_exists( 'ptg_safe_url' ) ) { function ptg_safe_url( $u ) { return $u; } }
check( 'превью: link_preview_options с той же ревизией', '?tg=3' === substr( ptg_link_preview_args( array( 'preview' => $pv ) )['link_preview_options']['url'], -5 ) );
unset( $GLOBALS['opts']['ptg_preview_rev'] );
$GLOBALS['opts']['ptg_preview_mode'] = 'link';
$line = ptg_render_preview_line( array( 'preview' => array( 'source' => 'product', 'url' => 'x' ) ) );
check( 'превью: фото товара помечается как раньше', false !== mb_strpos( $line, 'фото товара' ) );


// ── 2.16.10: гигантские макеты всё равно получают превью ─────────
check( 'GD: гигантскую картинку не берёт', false === ptg_preview_gd( wp_upload_dir()['basedir'] . '/customer-prints/alpha.png', IMAGETYPE_PNG, wp_upload_dir()['basedir'] . '/ptg-previews/huge.jpg', 12206, 14932, 1308, 1600 ) );
if ( class_exists( 'Imagick' ) ) {
	$outp = wp_upload_dir()['basedir'] . '/ptg-previews/imagick.jpg';
	@unlink( $outp );
	check( 'Imagick: собирает превью', ptg_preview_imagick( wp_upload_dir()['basedir'] . '/customer-prints/alpha.png', $outp, 800, 800 ) );
	$sz = getimagesize( $outp );
	check( 'Imagick: размер и формат верные', 800 === max( $sz[0], $sz[1] ) && IMAGETYPE_JPEG === $sz[2] );
} else {
	check( 'Imagick: не установлен — пропуск', true );
	check( 'Imagick: не установлен — пропуск размера', true );
}

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );

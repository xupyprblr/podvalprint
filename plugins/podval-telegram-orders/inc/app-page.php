<?php
/**
 * Фаза 5. Страница Mini App /tg-app/ и диплинки.
 *
 * Страница — пустая оболочка без темы: разметку рисует app.js после
 * проверки initData на сервере. Поэтому в HTML нет ни одного заказа, и
 * её можно не бояться отдать постороннему.
 *
 * @package podval-telegram-orders
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const PTG_APP_PATH = 'tg-app';

/**
 * Правило перезаписи. Флаш — по смене версии плагина: при обновлении
 * ZIP-ом хук активации не срабатывает, а без флаша /tg-app/ отдаёт 404.
 */
function ptg_app_rewrite() {
    add_rewrite_rule( '^' . PTG_APP_PATH . '/?$', 'index.php?ptg_app=1', 'top' );
    if ( get_option( 'ptg_app_rewrite_ver' ) !== PTG_VERSION ) {
        flush_rewrite_rules( false );
        update_option( 'ptg_app_rewrite_ver', PTG_VERSION, true );
    }
}
add_action( 'init', 'ptg_app_rewrite', 20 );

add_filter( 'query_vars', function ( $vars ) {
    $vars[] = 'ptg_app';
    return $vars;
} );

/**
 * Адрес страницы — его вписывают в BotFather.
 *
 * @return string
 */
function ptg_app_url() {
    return home_url( '/' . PTG_APP_PATH . '/' );
}

/**
 * Прямая ссылка в заказ: t.me/<бот>/<приложение>?startapp=o<ID>.
 *
 * Инлайн-кнопка web_app в группе не работает (Bot API: только личка с
 * ботом), поэтому на карточке — обычная url-кнопка с этой ссылкой.
 *
 * @param int $order_id Заказ.
 * @return string '' если приложение не настроено.
 */
function ptg_app_order_link( $order_id ) {
    $short = (string) get_option( 'ptg_app_short_name', '' );
    $bot   = (string) get_option( 'ptg_bot_username', '' );
    if ( '' === $short || '' === $bot ) {
        return '';
    }
    return 'https://t.me/' . rawurlencode( $bot ) . '/' . rawurlencode( $short ) . '?startapp=o' . (int) $order_id;
}

/**
 * Ссылка на главный экран приложения (без заказа).
 *
 * @return string
 */
function ptg_app_main_link() {
    $short = (string) get_option( 'ptg_app_short_name', '' );
    $bot   = (string) get_option( 'ptg_bot_username', '' );
    return ( '' === $short || '' === $bot ) ? '' : 'https://t.me/' . rawurlencode( $bot ) . '/' . rawurlencode( $short );
}

/**
 * Отдача оболочки.
 */
function ptg_app_serve() {
    if ( ! get_query_var( 'ptg_app' ) && ! isset( $_GET['ptg_app'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
        return;
    }
    if ( ! defined( 'DONOTCACHEPAGE' ) ) {
        define( 'DONOTCACHEPAGE', true );
    }
    nocache_headers();
    header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
    header( 'Content-Type: text/html; charset=utf-8' );
    header( 'X-Robots-Tag: noindex, nofollow' );

    $cfg = array(
        'rest'    => esc_url_raw( rest_url( PTG_REST_NS . '/app/' ) ),
        'ver'     => PTG_VERSION,
        'currency'=> 'Br',
    );
    $base = PTG_URL . 'assets/app/';
    ?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="dark light">
<title>Заказы · <?php echo esc_html( get_bloginfo( 'name' ) ); ?></title>
<link rel="icon" type="image/png" sizes="32x32" href="<?php echo esc_url( $base . 'favicon-32.png?ver=' . PTG_VERSION ); ?>">
<link rel="apple-touch-icon" sizes="180x180" href="<?php echo esc_url( $base . 'apple-touch-icon.png?ver=' . PTG_VERSION ); ?>">
<link rel="manifest" href="<?php echo esc_url( $base . 'manifest.json?ver=' . PTG_VERSION ); ?>">
<meta name="theme-color" content="#000000">
<script src="https://telegram.org/js/telegram-web-app.js"></script>
<link rel="stylesheet" href="<?php echo esc_url( $base . 'app.css?ver=' . PTG_VERSION ); ?>">
</head>
<body>
<div id="app" class="app"><div class="boot">Загрузка…</div></div>
<script>window.PTG_APP = <?php echo wp_json_encode( $cfg ); ?>;</script>
<script src="<?php echo esc_url( $base . 'app.js?ver=' . PTG_VERSION ); ?>"></script>
</body>
</html>
    <?php
    exit;
}
add_action( 'template_redirect', 'ptg_app_serve', 0 );

/* ── Настройки ──────────────────────────────────────────────────── */

add_action( 'admin_init', function () {
    register_setting( 'ptg_settings', 'ptg_app_short_name', array(
        'sanitize_callback' => function ( $v ) {
            return preg_replace( '/[^A-Za-z0-9_]/', '', (string) $v );
        },
    ) );
    register_setting( 'ptg_settings', 'ptg_app_max_age_hours', array( 'sanitize_callback' => 'absint' ) );
    register_setting( 'ptg_settings', 'ptg_show_assignee', array( 'sanitize_callback' => 'absint' ) );
    register_setting( 'ptg_settings', 'ptg_remember_chats', array( 'sanitize_callback' => 'absint' ) );
    register_setting( 'ptg_settings', 'ptg_remind_template', array( 'sanitize_callback' => 'sanitize_textarea_field' ) );
    register_setting( 'ptg_settings', 'ptg_pickup_template', array( 'sanitize_callback' => 'sanitize_textarea_field' ) );
    register_setting( 'ptg_settings', 'ptg_pickup_address', array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting( 'ptg_settings', 'ptg_pickup_title', array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting( 'ptg_settings', 'ptg_pickup_lat', array( 'sanitize_callback' => 'ptg_sanitize_coord' ) );
    register_setting( 'ptg_settings', 'ptg_pickup_lng', array( 'sanitize_callback' => 'ptg_sanitize_coord' ) );
    register_setting( 'ptg_settings', 'ptg_app_since', array(
        'sanitize_callback' => function ( $v ) {
            $v = trim( (string) $v );
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ) {
                return (int) get_option( 'ptg_app_since', 0 );
            }
            $d = date_create_immutable( $v . ' 00:00:00', wp_timezone() );
            return $d ? $d->getTimestamp() : (int) get_option( 'ptg_app_since', 0 );
        },
    ) );
}, 11 );

/**
 * Координата: и «53.9», и «53,9» — люди копируют из карт по-разному.
 *
 * @param mixed $v Значение.
 * @return string
 */
function ptg_sanitize_coord( $v ) {
    $v = str_replace( ',', '.', trim( (string) $v ) );
    return is_numeric( $v ) ? $v : '';
}

/**
 * Поля настроек Mini App. Выводятся внутри формы настроек плагина.
 */
function ptg_app_settings_rows() {
    $short = (string) get_option( 'ptg_app_short_name', '' );
    $hours = (int) get_option( 'ptg_app_max_age_hours', 24 );
    ?>
    <tr>
        <th scope="row"><label for="ptg_app_short_name">Mini App</label></th>
        <td>
            <p>URL для BotFather (<code>/newapp</code> или <code>/myapps</code> → Edit Web App URL):</p>
            <input type="text" class="large-text code" readonly value="<?php echo esc_attr( ptg_app_url() ); ?>" onclick="this.select()" />
            <p style="margin-top:10px;">Короткое имя приложения из BotFather:</p>
            <input type="text" id="ptg_app_short_name" name="ptg_app_short_name" class="regular-text code"
                   value="<?php echo esc_attr( $short ); ?>" placeholder="orders" />
            <p class="description">
                Пока пусто — кнопки «📱 В приложении» на карточках нет.
                <?php if ( $short && get_option( 'ptg_bot_username' ) ) : ?>
                    Главный экран: <code><?php echo esc_html( ptg_app_main_link() ); ?></code> — закрепите в группе.
                    Ссылка на заказ: <code><?php echo esc_html( ptg_app_order_link( 47 ) ); ?></code>
                <?php elseif ( $short ) : ?>
                    <b>Не известен ник бота</b> — нажмите «Самодиагностика» ниже.
                <?php endif; ?>
            </p>
            <p style="margin-top:10px;">
                Сессия приложения, часов:
                <input type="number" name="ptg_app_max_age_hours" min="1" max="168" style="width:70px;"
                       value="<?php echo esc_attr( $hours ?: 24 ); ?>" />
            </p>
            <p class="description">Сколько живёт вход после открытия. Потом приложение попросит переоткрыть себя.</p>
            <p style="margin-top:10px;">
                Начало работы в приложении:
                <input type="date" name="ptg_app_since"
                       value="<?php echo esc_attr( wp_date( 'Y-m-d', ptg_app_since() ) ); ?>" />
            </p>
            <p class="description">
                Заказы, созданные раньше и с тех пор не менявшие статус, лежат во вкладке «Прочие».
                Как только такому заказу меняют статус, он возвращается в рабочие вкладки.
            </p>
            <p style="margin-top:10px;">
                <label>
                    <input type="checkbox" name="ptg_show_assignee" value="1" <?php checked( ptg_show_assignee() ); ?> />
                    Показывать ответственного
                </label>
            </p>
            <p style="margin-top:10px;">
                <label>
                    <input type="checkbox" name="ptg_remember_chats" value="1" <?php checked( get_option( 'ptg_remember_chats', 1 ) ); ?> />
                    Запоминать чаты без заказа
                </label>
            </p>
            <p class="description">
                Список «кому оформить заказ» в форме нового заказа. Бот видит всю личку менеджера,
                поэтому туда попадают и личные переписки — лишние убираются крестиком в приложении.
            </p>

            <p class="description" style="margin-top:14px;">
                В карточке, кнопка «Взять в работу» и в приложении. Назначение пишется в заказ всегда,
                выключается только показ.
            </p>
            <p style="margin-top:14px;">Напоминание забрать заказ:</p>
            <textarea name="ptg_remind_template" rows="3" class="large-text"
                      placeholder="<?php echo esc_attr( ptg_remind_template() ); ?>"><?php echo esc_textarea( (string) get_option( 'ptg_remind_template', '' ) ); ?></textarea>
            <p class="description">
                Кнопка «🔔 Напомнить забрать» в приложении, для заказов «Готов к выдаче» и «Не забрали».
                Подстановки как в приветствии: {номер}, {имя}, {фамилия}, {сумма}, {позиции}. Пусто — текст по умолчанию.
            </p>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="ptg_pickup_address">Самовывоз</label></th>
        <td>
            <p>Адрес пункта выдачи:</p>
            <input type="text" id="ptg_pickup_address" name="ptg_pickup_address" class="large-text"
                   value="<?php echo esc_attr( (string) get_option( 'ptg_pickup_address', '' ) ); ?>"
                   placeholder="<?php echo esc_attr( ptg_pickup_address() ); ?>" />
            <p class="description">Пусто — берётся адрес магазина из настроек WooCommerce.</p>

            <p style="margin-top:10px;">Название точки на карте:</p>
            <input type="text" name="ptg_pickup_title" class="regular-text"
                   value="<?php echo esc_attr( (string) get_option( 'ptg_pickup_title', '' ) ); ?>"
                   placeholder="<?php echo esc_attr( ptg_pickup_title() ); ?>" />

            <p style="margin-top:10px;">
                Координаты:
                <input type="text" name="ptg_pickup_lat" style="width:120px;" placeholder="53.902284"
                       value="<?php echo esc_attr( (string) get_option( 'ptg_pickup_lat', '' ) ); ?>" />
                <input type="text" name="ptg_pickup_lng" style="width:120px;" placeholder="27.561831"
                       value="<?php echo esc_attr( (string) get_option( 'ptg_pickup_lng', '' ) ); ?>" />
            </p>
            <p class="description">
                В Google Maps правый клик по точке копирует широту и долготу.
                Без координат уйдёт только текст с адресом, без точки на карте.
            </p>

            <p style="margin-top:10px;">Текст сообщения:</p>
            <textarea name="ptg_pickup_template" rows="3" class="large-text"
                      placeholder="<?php echo esc_attr( ptg_pickup_template() ); ?>"><?php echo esc_textarea( (string) get_option( 'ptg_pickup_template', '' ) ); ?></textarea>
            <p class="description">
                Кнопка «📍 Отправить адрес» в приложении, для заказов «Готов к выдаче» и «Не забрали».
                Подстановки: {адрес}, {номер}, {имя}, {фамилия}, {сумма}, {позиции}.
            </p>
        </td>
    </tr>
    <?php
}

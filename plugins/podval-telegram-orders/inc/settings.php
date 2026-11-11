<?php
/**
 * Страница настроек, кнопки управления вебхуком, самодиагностика, журнал.
 *
 * @package podval-telegram-orders
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Пункт меню.
 *
 * @return void
 */
function ptg_add_settings_page() {
    add_options_page(
        'Telegram уведомления',
        'Telegram заказы',
        'manage_options',
        'podval-telegram-orders',
        'ptg_settings_page_html'
    );
}
add_action( 'admin_menu', 'ptg_add_settings_page' );

/**
 * Регистрация опций.
 *
 * @return void
 */
function ptg_register_settings() {
    register_setting( 'ptg_settings', 'ptg_bot_token', array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting( 'ptg_settings', 'ptg_chat_id', array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting( 'ptg_settings', 'ptg_send_on_status', array( 'sanitize_callback' => 'ptg_sanitize_statuses' ) );
    register_setting( 'ptg_settings', 'ptg_webhook_secret', array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting( 'ptg_settings', 'ptg_allowed_user_ids', array( 'sanitize_callback' => 'sanitize_textarea_field' ) );
    register_setting( 'ptg_settings', 'ptg_greeting_template', array( 'sanitize_callback' => 'sanitize_textarea_field' ) );
    register_setting( 'ptg_settings', 'ptg_save_chat_files', array( 'sanitize_callback' => 'absint' ) );
    register_setting( 'ptg_settings', 'ptg_group_notices', array( 'sanitize_callback' => 'absint' ) );
    register_setting( 'ptg_settings', 'ptg_preview_mode', array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting( 'ptg_settings', 'ptg_preview_product_fallback', array( 'sanitize_callback' => 'absint' ) );
    register_setting( 'ptg_settings', 'ptg_auto_client_replied', array( 'sanitize_callback' => 'absint' ) );
}
add_action( 'admin_init', 'ptg_register_settings' );

/**
 * @param mixed $input Значение.
 * @return array
 */
function ptg_sanitize_statuses( $input ) {
    if ( ! is_array( $input ) ) {
        return array( 'processing' );
    }
    return array_map( 'sanitize_text_field', $input );
}

/**
 * Разметка страницы.
 *
 * @return void
 */
function ptg_settings_page_html() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $bot_token = get_option( 'ptg_bot_token', '' );
    $chat_id   = get_option( 'ptg_chat_id', '' );
    $statuses  = (array) get_option( 'ptg_send_on_status', array( 'processing' ) );
    $secret    = get_option( 'ptg_webhook_secret', '' );
    $user_ids  = get_option( 'ptg_allowed_user_ids', '' );

    $all_statuses = array(
        'pending'    => 'Ожидание оплаты',
        'processing' => 'В обработке',
        'on-hold'    => 'На удержании',
        'completed'  => 'Завершён',
    );
    ?>
    <div class="wrap">
        <h1>Telegram заказы <span style="font-size:13px;color:#666;">v<?php echo esc_html( PTG_VERSION ); ?></span></h1>

        <form method="post" action="options.php">
            <?php settings_fields( 'ptg_settings' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="ptg_bot_token">Bot Token</label></th>
                    <td>
                        <input type="text" id="ptg_bot_token" name="ptg_bot_token"
                               value="<?php echo esc_attr( $bot_token ); ?>"
                               class="regular-text" placeholder="123456:ABC-DEF1234..." />
                        <p class="description">Токен от @BotFather</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ptg_chat_id">Chat ID</label></th>
                    <td>
                        <input type="text" id="ptg_chat_id" name="ptg_chat_id"
                               value="<?php echo esc_attr( $chat_id ); ?>"
                               class="regular-text" placeholder="-1001234567890" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">Создавать сообщение при статусе</th>
                    <td>
                        <?php foreach ( $all_statuses as $key => $label ) : ?>
                            <label style="display:block;margin-bottom:5px;">
                                <input type="checkbox" name="ptg_send_on_status[]"
                                       value="<?php echo esc_attr( $key ); ?>"
                                       <?php checked( in_array( $key, $statuses, true ) ); ?> />
                                <?php echo esc_html( $label ); ?>
                            </label>
                        <?php endforeach; ?>
                        <p class="description">
                            Дальше сообщение живёт само: при любых изменениях заказа оно
                            редактируется, а не отправляется заново.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ptg_webhook_secret">Secret token вебхука</label></th>
                    <td>
                        <input type="text" id="ptg_webhook_secret" name="ptg_webhook_secret"
                               value="<?php echo esc_attr( $secret ); ?>" class="regular-text code" />
                        <button type="button" class="button" id="ptg-gen-secret">Сгенерировать</button>
                        <p class="description">
                            Telegram присылает его в заголовке X-Telegram-Bot-Api-Secret-Token.
                            После смены — переустановить вебхук.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ptg_allowed_user_ids">Кому можно нажимать кнопки</label></th>
                    <td>
                        <textarea id="ptg_allowed_user_ids" name="ptg_allowed_user_ids" rows="3"
                                  class="regular-text code"><?php echo esc_textarea( $user_ids ); ?></textarea>
                        <p class="description">
                            Telegram user_id через запятую или с новой строки. Пусто — кнопки доступны всем
                            участникам чата.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ptg_greeting_template">Текст приветствия</label></th>
                    <td>
                        <textarea id="ptg_greeting_template" name="ptg_greeting_template" rows="3"
                                  class="large-text"><?php echo esc_textarea( ptg_greeting_template() ); ?></textarea>
                        <p class="description">
                            Первое сообщение клиенту. Подстановки:
                            <code>{номер}</code>, <code>{имя}</code>, <code>{фамилия}</code>,
                            <code>{сумма}</code>, <code>{позиции}</code>.<br />
                            Если чат клиента уже привязан, кнопка на карточке отправит текст сама
                            от имени менеджера. Если нет — скопирует в буфер: писать первым тому,
                            кто не начинал диалог, Telegram боту не разрешает.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Автостатусы</th>
                    <td>
                        <label>
                            <input type="checkbox" name="ptg_auto_client_replied" value="1"
                                   <?php checked( (int) get_option( 'ptg_auto_client_replied', 1 ), 1 ); ?> />
                            Переводить в «❤️ Клиент ответил», когда клиент пишет в привязанном чате
                        </label>
                        <p class="description">
                            Срабатывает только если мы писали клиенту первыми и заказ ещё в статусе
                            «Получен». Заказ, уехавший в эскиз или печать, новое сообщение клиента
                            назад не откатывает.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Превью макета</th>
                    <td>
                        <?php $mode = ptg_preview_mode(); ?>
                        <label style="display:block;margin-bottom:5px;">
                            <input type="radio" name="ptg_preview_mode" value="link" <?php checked( $mode, 'link' ); ?> />
                            Картинкой над текстом карточки
                        </label>
                        <label style="display:block;margin-bottom:5px;">
                            <input type="radio" name="ptg_preview_mode" value="photo" <?php checked( $mode, 'photo' ); ?> />
                            Отдельным фото под карточкой
                        </label>
                        <label style="display:block;margin-bottom:5px;">
                            <input type="radio" name="ptg_preview_mode" value="off" <?php checked( $mode, 'off' ); ?> />
                            Без картинки
                        </label>
                        <p class="description">
                            Первый режим не добавляет сообщений в ленту, но требует, чтобы Telegram
                            сам забрал картинку с сайта. Если превью не появляется — вероятно, её
                            режет WAF; переключитесь на второй, там файл заливается с сервера.
                        </p>
                        <label style="display:block;margin-top:8px;">
                            <input type="checkbox" name="ptg_preview_product_fallback" value="1"
                                   <?php checked( (int) get_option( 'ptg_preview_product_fallback', 1 ), 1 ); ?> />
                            Если макета нет — показывать фото товара из каталога
                        </label>
                        <p class="description">
                            Для позиции конструктора фото каталога бесполезно, для готового дизайна —
                            наоборот, показывает то, что будут наносить. В карточке такая картинка
                            помечается явно.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Файлы из переписки</th>
                    <td>
                        <label>
                            <input type="checkbox" name="ptg_save_chat_files" value="1"
                                   <?php checked( (int) get_option( 'ptg_save_chat_files', 1 ), 1 ); ?> />
                            Сохранять фото и документы из чатов клиентов в медиатеку
                        </label>
                        <p class="description">
                            Эскизы и досланные клиентом файлы оседают в личке одного менеджера.
                            Личные чаты в Telegram не расшариваются, поэтому при передаче заказа
                            это единственный способ не просить клиента прислать всё заново.
                            Лимит Bot API на скачивание — 20 МБ. Стикеры и кружки не сохраняются.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Служебные сообщения</th>
                    <td>
                        <label>
                            <input type="checkbox" name="ptg_group_notices" value="1"
                                   <?php checked( (int) get_option( 'ptg_group_notices', 0 ), 1 ); ?> />
                            Писать в группу подтверждения привязки чатов
                        </label>
                        <p class="description">
                            По умолчанию выключено: факт привязки и так виден в карточке заказа,
                            а лента заказов — рабочий инструмент. Сообщения, на которые нужно
                            отреагировать (несколько заказов на один ник, неудачная привязка),
                            приходят всегда.
                        </p>
                    </td>
                </tr>
                <?php ptg_app_settings_rows(); ?>
                <tr>
                    <th scope="row">URL вебхука</th>
                    <td>
                        <input type="text" class="large-text code" readonly
                               value="<?php echo esc_attr( ptg_webhook_url() ); ?>"
                               onclick="this.select()" />
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Сохранить настройки' ); ?>
        </form>

        <hr />
        <h2>Вебхук и диагностика</h2>
        <p>
            <button type="button" class="button button-primary" data-ptg-op="set_webhook">Установить вебхук</button>
            <button type="button" class="button" data-ptg-op="check_webhook">Проверить вебхук</button>
            <button type="button" class="button" data-ptg-op="delete_webhook">Удалить вебхук</button>
            <button type="button" class="button" data-ptg-op="selftest">Самодиагностика</button>
            <button type="button" class="button" data-ptg-op="test_message">Тестовое сообщение</button>
            <button type="button" class="button" data-ptg-op="preview_test">Тест превью</button>
            <button type="button" class="button" data-ptg-op="preview_refresh" title="Telegram запоминает, что картинку не удалось загрузить. Кнопка меняет адрес превью и пересобирает карточки открытых заказов за 3 недели.">Обновить превью в карточках</button>
            <button type="button" class="button" data-ptg-op="clear_log">Очистить журнал</button>
        </p>
        <pre id="ptg-result" style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-height:340px;overflow:auto;white-space:pre-wrap;"></pre>

        <h2>Business-подключения</h2>
        <p class="description" style="max-width:640px;">
            Клиент пишет менеджеру в личку, а не боту, поэтому переписка видна плагину
            только через Telegram Business. Каждый менеджер подключает бота сам:
            <b>Настройки Telegram → Telegram Business → Чат-боты</b>, указать
            <code><?php echo esc_html( ptg_bot_username_hint() ); ?></code> и выдать права на
            чтение, ответ и удаление сообщений. Нужен Telegram Premium.
        </p>
        <table class="widefat striped" style="max-width:840px;">
            <thead><tr><th>Менеджер</th><th style="width:90px;">user_id</th><th style="width:90px;">Статус</th><th>Права</th><th style="width:140px;">Обновлено</th></tr></thead>
            <tbody>
            <?php
            $connections = ptg_business_connections();
            if ( empty( $connections ) ) {
                echo '<tr><td colspan="5">Подключений нет. После подключения бота строка появится автоматически.</td></tr>';
            }
            foreach ( $connections as $conn_id => $conn ) :
                $rights = array();
                foreach ( (array) $conn['rights'] as $rk => $rv ) {
                    if ( $rv ) {
                        $rights[] = $rk;
                    }
                }
                $wp_user = ptg_user_by_telegram_id( $conn['user_id'] );
                ?>
                <tr>
                    <td>
                        <?php echo esc_html( $conn['name'] ); ?>
                        <?php if ( $wp_user ) : ?>
                            <br /><small style="color:#646970;">→ <?php echo esc_html( $wp_user->display_name ); ?></small>
                        <?php else : ?>
                            <br /><small style="color:#d63638;">не привязан к WP-юзеру</small>
                        <?php endif; ?>
                    </td>
                    <td><code><?php echo (int) $conn['user_id']; ?></code></td>
                    <td><?php echo $conn['is_enabled'] ? '✅ вкл' : '⛔ выкл'; ?></td>
                    <td><small><?php echo esc_html( $rights ? implode( ', ', $rights ) : '—' ); ?></small></td>
                    <td><small><?php echo esc_html( $conn['updated'] ); ?></small></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h2>Журнал</h2>
        <table class="widefat striped">
            <thead><tr><th style="width:150px;">Время</th><th style="width:70px;">Уровень</th><th>Событие</th></tr></thead>
            <tbody>
            <?php
            $rows = ptg_log_tail( 50 );
            if ( empty( $rows ) ) {
                echo '<tr><td colspan="3">Пусто</td></tr>';
            }
            foreach ( $rows as $row ) :
                $ctx = ! empty( $row['c'] ) ? wp_json_encode( $row['c'], JSON_UNESCAPED_UNICODE ) : '';
                ?>
                <tr>
                    <td><?php echo esc_html( gmdate( 'd.m.Y H:i:s', (int) $row['t'] + 3 * 3600 ) ); ?></td>
                    <td><?php echo esc_html( $row['l'] ); ?></td>
                    <td>
                        <?php echo esc_html( $row['m'] ); ?>
                        <?php if ( $ctx ) : ?>
                            <br /><code style="font-size:11px;color:#666;"><?php echo esc_html( $ctx ); ?></code>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <script>
        (function () {
            var nonce  = '<?php echo esc_js( wp_create_nonce( 'ptg_admin' ) ); ?>';
            var result = document.getElementById('ptg-result');

            document.getElementById('ptg-gen-secret').addEventListener('click', function () {
                var a = new Uint8Array(24);
                crypto.getRandomValues(a);
                document.getElementById('ptg_webhook_secret').value =
                    Array.from(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
            });

            document.querySelectorAll('[data-ptg-op]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var op = btn.getAttribute('data-ptg-op');
                    result.textContent = 'Выполняю: ' + op + '…';
                    fetch(ajaxurl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'action=ptg_admin_op&op=' + encodeURIComponent(op) + '&_wpnonce=' + nonce
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        result.textContent = typeof d.data === 'string'
                            ? d.data
                            : JSON.stringify(d.data, null, 2);
                    })
                    .catch(function () { result.textContent = 'Ошибка запроса'; });
                });
            });
        })();
        </script>
    </div>
    <?php
}

/**
 * Имя бота для подсказки в настройках.
 *
 * @return string
 */
function ptg_bot_username_hint() {
    $cached = get_option( 'ptg_bot_username', '' );
    return $cached ? '@' . $cached : '@ваш_бот';
}

/**
 * AJAX-операции страницы настроек.
 *
 * @return void
 */
function ptg_admin_op() {
    check_ajax_referer( 'ptg_admin' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Нет доступа' );
    }

    $op = isset( $_POST['op'] ) ? sanitize_text_field( wp_unslash( $_POST['op'] ) ) : '';

    switch ( $op ) {

        case 'set_webhook':
            $secret = (string) get_option( 'ptg_webhook_secret', '' );
            if ( '' === $secret ) {
                $secret = wp_generate_password( 48, false );
                update_option( 'ptg_webhook_secret', $secret );
            }
            $res = ptg_api( 'setWebhook', array(
                'url'                  => ptg_webhook_url(),
                'secret_token'         => $secret,
                'drop_pending_updates' => true,
                'allowed_updates'      => ptg_allowed_updates(),
                'max_connections'      => 10,
            ) );
            if ( is_wp_error( $res ) ) {
                wp_send_json_error( 'Ошибка: ' . $res->get_error_message() );
            }
            wp_send_json_success( "Вебхук установлен:\n" . ptg_webhook_url() );
            break;

        case 'check_webhook':
            $res = ptg_api( 'getWebhookInfo' );
            if ( is_wp_error( $res ) ) {
                wp_send_json_error( 'Ошибка: ' . $res->get_error_message() );
            }
            $out = array(
                'url'                  => $res['url'] ?? '',
                'pending_update_count' => $res['pending_update_count'] ?? 0,
                'last_error_date'      => ! empty( $res['last_error_date'] )
                    ? gmdate( 'd.m.Y H:i:s', (int) $res['last_error_date'] + 3 * 3600 )
                    : '',
                'last_error_message'   => $res['last_error_message'] ?? '',
                'allowed_updates'      => $res['allowed_updates'] ?? '(по умолчанию — business-события НЕ придут)',
            );
            wp_send_json_success( $out );
            break;

        case 'delete_webhook':
            $res = ptg_api( 'deleteWebhook', array( 'drop_pending_updates' => true ) );
            if ( is_wp_error( $res ) ) {
                wp_send_json_error( 'Ошибка: ' . $res->get_error_message() );
            }
            wp_send_json_success( 'Вебхук удалён' );
            break;

        case 'test_message':
            $chat_id = (string) get_option( 'ptg_chat_id', '' );
            $res     = ptg_tg_send_message( $chat_id, "✅ <b>Тест</b>\nПлагин v" . PTG_VERSION . " на связи." );
            if ( is_wp_error( $res ) ) {
                wp_send_json_error( 'Ошибка: ' . $res->get_error_message() );
            }
            wp_send_json_success( 'Отправлено, message_id=' . (int) $res );
            break;

        case 'preview_test':
            // Отладка превью: берём последний заказ с макетом и шлём в
            // группу сообщение с той же ссылкой, что уходит в карточке.
            // Видно сразу: если здесь картинка есть, значит дело не в
            // файле и не в Telegram, а в сборке карточки.
            $orders = wc_get_orders( array( 'limit' => 10, 'orderby' => 'date', 'order' => 'DESC', 'type' => 'shop_order' ) );
            $found  = null;
            foreach ( (array) $orders as $o ) {
                $pv = ptg_order_preview( ptg_order_data( $o ), $o );
                if ( $pv ) {
                    $found = array( 'order' => $o, 'preview' => $pv );
                    break;
                }
            }
            if ( ! $found ) {
                wp_send_json_error( 'Среди последних 10 заказов не нашлось ни одного с картинкой' );
            }

            $url = $found['preview']['url'];
            $res = ptg_tg_send_message(
                (string) get_option( 'ptg_chat_id', '' ),
                'Проверка превью: заказ #' . $found['order']->get_order_number() . "\n" . ptg_link( ptg_preview_tg_url( $url ), 'картинка' ),
                array(
                    'link_preview_options' => array(
                        'is_disabled'        => false,
                        'url'                => ptg_safe_url( ptg_preview_tg_url( $url ) ),
                        'prefer_large_media' => true,
                        'show_above_text'    => true,
                    ),
                )
            );
            if ( is_wp_error( $res ) ) {
                wp_send_json_error( 'Ошибка: ' . $res->get_error_message() );
            }
            wp_send_json_success( sprintf(
                'Отправлено. Источник: %s. Ссылка превью: %s (%s)',
                $found['preview']['source'],
                $url,
                ! empty( $found['preview']['prepared'] ) ? 'подготовленный вариант' : 'исходник'
            ) );
            break;

        case 'preview_refresh':
            $n = ptg_preview_refresh_all();
            wp_send_json_success( $n
                ? sprintf( 'В очереди %d карточек, по одной каждые 4 секунды — займёт около %d мин. Новые заказы сразу идут с обновлённым адресом.', $n, max( 1, (int) ceil( $n * 4 / 60 ) ) )
                : 'Открытых заказов с карточкой за 3 недели нет. Адрес превью для новых заказов обновлён.' );
            break;

        case 'clear_log':
            ptg_log_clear();
            wp_send_json_success( 'Журнал очищен' );
            break;

        case 'selftest':
            wp_send_json_success( ptg_selftest() );
            break;
    }

    wp_send_json_error( 'Неизвестная операция' );
}
add_action( 'wp_ajax_ptg_admin_op', 'ptg_admin_op' );

/**
 * Самодиагностика: пять проверок, каждая — зелёная или красная.
 *
 * @return string
 */
function ptg_selftest() {
    $lines = array();

    // 1. Токен.
    $me = ptg_api( 'getMe' );
    if ( is_wp_error( $me ) ) {
        $lines[] = '❌ getMe: ' . $me->get_error_message();
    } else {
        $lines[] = '✅ getMe: @' . ( $me['username'] ?? '?' );
        if ( ! empty( $me['username'] ) ) {
            update_option( 'ptg_bot_username', sanitize_text_field( $me['username'] ), false );
        }
    }

    // 2. Доступ к целевому чату.
    $chat_id = (string) get_option( 'ptg_chat_id', '' );
    if ( '' === $chat_id ) {
        $lines[] = '❌ getChat: Chat ID не указан';
    } else {
        $chat = ptg_api( 'getChat', array( 'chat_id' => $chat_id ) );
        $lines[] = is_wp_error( $chat )
            ? '❌ getChat: ' . $chat->get_error_message()
            : '✅ getChat: ' . ( $chat['title'] ?? $chat['type'] ?? 'ok' );
    }

    // 3. Вебхук. last_error_message — прямой индикатор того, что запросы
    //    Telegram до WP не доезжают (на этом хостинге обычно BitNinja).
    $info = ptg_api( 'getWebhookInfo' );
    if ( is_wp_error( $info ) ) {
        $lines[] = '❌ getWebhookInfo: ' . $info->get_error_message();
    } elseif ( empty( $info['url'] ) ) {
        $lines[] = '⚠️ Вебхук не установлен';
    } else {
        $lines[] = '✅ Вебхук: ' . $info['url'];
        if ( ! empty( $info['last_error_message'] ) ) {
            $lines[] = '❌ Последняя ошибка доставки: ' . $info['last_error_message']
                . ' — проверьте, не режет ли запросы WAF';
        }
        $need = ptg_allowed_updates();
        $have = (array) ( $info['allowed_updates'] ?? array() );
        $miss = array_diff( $need, $have );
        $lines[] = empty( $miss )
            ? '✅ allowed_updates: все нужные типы включены'
            : '❌ allowed_updates: не хватает ' . implode( ', ', $miss ) . ' — переустановите вебхук';
    }

    // 4. Кэширование REST.
    $url = ptg_webhook_url() . '/health';
    $t1  = ptg_fetch_health( $url );
    sleep( 1 );
    $t2 = ptg_fetch_health( $url );
    if ( null === $t1 || null === $t2 ) {
        $lines[] = '❌ /health недоступен снаружи (' . $url . ')';
    } elseif ( $t1 === $t2 ) {
        $lines[] = '❌ REST кэшируется: два запроса вернули одинаковый t. Исключите путь в WP-Optimize';
    } else {
        $lines[] = '✅ REST не кэшируется';
    }

    // 5. Тема на месте.
    $lines[] = function_exists( 'podval_tpl_summary' )
        ? '✅ podval_tpl_summary() найдена (тема активна)'
        : '❌ podval_tpl_summary() не найдена — данные конструктора отрендерятся урезанно';

    // 6. Business-подключения.
    $connections = ptg_business_connections();
    if ( empty( $connections ) ) {
        $lines[] = '⚠️ Business-подключений нет — переписка с клиентами собираться не будет';
    } else {
        foreach ( $connections as $conn ) {
            $ok = ! empty( $conn['is_enabled'] );
            $lines[] = ( $ok ? '✅' : '❌' ) . ' Business: ' . $conn['name']
                . ( $ok ? '' : ' — подключение выключено' );
            if ( $ok && ! ptg_user_by_telegram_id( $conn['user_id'] ) ) {
                $lines[] = '   ⚠️ ' . $conn['name'] . ' не привязан к WP-юзеру (профиль → Telegram user_id)';
            }
        }
    }

    $lines[] = function_exists( 'podval_tpl_garment_options' )
        ? '✅ podval_tpl_garment_options() найдена (кнопки значений будут работать)'
        : '❌ podval_tpl_garment_options() не найдена — кнопок значений не будет';

    return implode( "\n", $lines );
}

/**
 * Запрос к /health.
 *
 * @param string $url Адрес.
 * @return int|null Значение t.
 */
function ptg_fetch_health( $url ) {
    $res = wp_remote_get( $url, array( 'timeout' => 10 ) );
    if ( is_wp_error( $res ) ) {
        return null;
    }
    $body = json_decode( wp_remote_retrieve_body( $res ), true );
    return isset( $body['t'] ) ? (int) $body['t'] : null;
}

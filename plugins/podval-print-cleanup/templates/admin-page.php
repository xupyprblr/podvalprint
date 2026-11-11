<?php
/**
 * Admin page template.
 *
 * Available vars: $settings, $folder, $atts, $cron, $last, $log
 */

if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="wrap pfc-wrap">
    <h1>🗑 Очистка клиентских принтов</h1>
    <p class="description" style="max-width:800px;">
        Управление файлами, загруженными клиентами через страницу «Свой Дизайн». Автоочистка по cron + ручные операции.
    </p>

    <?php if ( ! empty( $_GET['saved'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p>Настройки сохранены</p></div>
    <?php endif; ?>

    <!-- ═══════════════ СТАТУС ═══════════════ -->
    <h2 class="title">Статус</h2>
    <div class="pfc-grid">

        <div class="pfc-card">
            <h3>Cron</h3>
            <?php if ( $cron['disabled'] ) : ?>
                <p class="pfc-bad">🔴 WP-Cron отключён</p>
                <p class="description">В <code>wp-config.php</code> задан <code>DISABLE_WP_CRON</code>. Нужен системный cron на <code>wp-cron.php</code>.</p>
            <?php elseif ( $cron['next'] ) : ?>
                <p class="pfc-good">🟢 Активен</p>
                <p class="description">
                    Следующий запуск: <strong><?php echo esc_html( date_i18n( 'd.m.Y H:i', $cron['next'] ) ); ?></strong><br>
                    (через <?php echo esc_html( human_time_diff( time(), $cron['next'] ) ); ?>)
                </p>
            <?php else : ?>
                <p class="pfc-bad">🔴 Не зарегистрирован</p>
                <p class="description">Переактивируй плагин.</p>
            <?php endif; ?>
        </div>

        <div class="pfc-card">
            <h3>Последний запуск</h3>
            <?php if ( $last ) : ?>
                <p><strong><?php echo esc_html( date_i18n( 'd.m.Y H:i', (int) $last['time'] ) ); ?></strong></p>
                <p class="description">
                    <?php echo $last['trigger'] === 'manual' ? '👤 вручную' : '⏰ автомат'; ?><br>
                    удалено файлов: <strong><?php echo (int) $last['result']['files_deleted']; ?></strong>
                    (<?php echo esc_html( PFC_Stats::format_bytes( $last['result']['bytes_deleted'] ) ); ?>)<br>
                    сирот: <strong><?php echo (int) $last['result']['orphans_deleted']; ?></strong>
                </p>
            <?php else : ?>
                <p class="description">Ещё не запускалось</p>
            <?php endif; ?>
        </div>

        <div class="pfc-card">
            <h3>Папка на диске</h3>
            <?php if ( $folder['exists'] ) : ?>
                <p><strong><?php echo (int) $folder['files']; ?></strong> файлов</p>
                <p><strong><?php echo esc_html( PFC_Stats::format_bytes( $folder['size'] ) ); ?></strong> занято</p>
                <p class="description"><code>uploads/customer-prints/</code></p>
            <?php else : ?>
                <p class="description">Папка не существует</p>
            <?php endif; ?>
        </div>

        <div class="pfc-card">
            <h3>Attachment'ы в БД</h3>
            <p>Всего: <strong><?php echo (int) $atts['total']; ?></strong></p>
            <p>Привязано к заказам: <?php echo (int) $atts['attached']; ?></p>
            <p>Сирот (без заказа): <strong><?php echo (int) $atts['orphans']; ?></strong></p>
        </div>
    </div>

    <!-- ═══════════════ НАСТРОЙКИ ═══════════════ -->
    <hr>
    <h2 class="title">Настройки</h2>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <input type="hidden" name="action" value="pfc_save_settings">
        <?php wp_nonce_field( 'pfc_save_settings' ); ?>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="enabled">Автоочистка</label></th>
                <td>
                    <label>
                        <input type="checkbox" name="enabled" id="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>>
                        включить автозапуск раз в сутки
                    </label>
                    <p class="description">Даже если выключено — ручной запуск работает.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="days">Из заказов старше</label></th>
                <td>
                    <input type="number" name="days" id="days" value="<?php echo (int) $settings['days']; ?>" min="1" max="365" class="small-text"> дней
                </td>
            </tr>
            <tr>
                <th scope="row">Статусы заказов</th>
                <td>
                    <?php
                    $status_labels = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
                    $all_statuses  = array(
                        'wc-pending'    => 'Ожидает оплаты',
                        'wc-on-hold'    => 'На удержании',
                        'wc-processing' => 'Обработка',
                        'wc-completed'  => 'Выполнен',
                        'wc-cancelled'  => 'Отменён',
                        'wc-refunded'   => 'Возврат',
                        'wc-failed'     => 'Не удался',
                    );
                    foreach ( $all_statuses as $key => $fallback ) :
                        $label = isset( $status_labels[ $key ] ) ? $status_labels[ $key ] : $fallback;
                        $checked = in_array( $key, (array) $settings['statuses'], true );
                        $risky   = in_array( $key, array( 'wc-pending', 'wc-on-hold', 'wc-processing' ), true );
                    ?>
                        <label class="pfc-status-chip <?php echo $risky ? 'is-risky' : ''; ?>">
                            <input type="checkbox" name="statuses[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $checked ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </label>
                    <?php endforeach; ?>
                    <p class="description">
                        ⚠ Включение <em>processing / on-hold / pending</em> может удалить файлы из ещё активных заказов.
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="date_field">Считать возраст по</label></th>
                <td>
                    <select name="date_field" id="date_field">
                        <option value="date_created" <?php selected( $settings['date_field'], 'date_created' ); ?>>дате создания заказа</option>
                        <option value="date_modified" <?php selected( $settings['date_field'], 'date_modified' ); ?>>дате последней модификации</option>
                    </select>
                    <p class="description">«По модификации» = отсчёт с момента перевода заказа в конечный статус (рекомендуется).</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="orphan_days">Сирот старше</label></th>
                <td>
                    <input type="number" name="orphan_days" id="orphan_days" value="<?php echo (int) $settings['orphan_days']; ?>" min="1" max="90" class="small-text"> дней
                    <p class="description">Сироты — файлы, загруженные но не привязанные ни к одному заказу (клиент бросил корзину до checkout).</p>
                </td>
            </tr>
        </table>
        <?php submit_button( 'Сохранить настройки' ); ?>
    </form>

    <!-- ═══════════════ РУЧНЫЕ ОПЕРАЦИИ ═══════════════ -->
    <hr>
    <h2 class="title">Ручные операции</h2>

    <div class="pfc-actions">
        <div class="pfc-action-row">
            <button type="button" class="button button-secondary" id="pfc-preview">🔍 Предпросмотр</button>
            <span class="description">Посчитать что попадёт под текущие настройки, без удаления</span>
        </div>
        <div id="pfc-preview-result"></div>

        <div class="pfc-action-row">
            <button type="button" class="button button-primary" id="pfc-run-now">▶ Запустить очистку сейчас</button>
            <span class="description">Выполнить очистку немедленно (с удалением)</span>
        </div>
        <div id="pfc-run-result"></div>

        <div class="pfc-action-row">
            <button type="button" class="button button-secondary" id="pfc-scan-ghosts">👻 Найти файлы-призраки</button>
            <span class="description">Файлы физически в папке, но в БД нет attachment-записи</span>
        </div>
        <div id="pfc-ghosts-result"></div>
    </div>

    <!-- ═══════════════ ИСТОРИЯ ═══════════════ -->
    <hr>
    <h2 class="title" style="display:flex;justify-content:space-between;align-items:center;">
        <span>История запусков</span>
        <?php if ( ! empty( $log ) ) : ?>
            <button type="button" class="button-link pfc-clear-log-btn" id="pfc-clear-log">очистить историю</button>
        <?php endif; ?>
    </h2>

    <?php if ( empty( $log ) ) : ?>
        <p class="description">Ещё не запускалось.</p>
    <?php else : ?>
        <table class="widefat striped pfc-log">
            <thead>
                <tr>
                    <th>Дата</th>
                    <th>Триггер</th>
                    <th>Заказов</th>
                    <th>Файлов</th>
                    <th>Размер</th>
                    <th>Сирот</th>
                    <th>Длительность</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $log as $entry ) : ?>
                    <tr>
                        <td><?php echo esc_html( date_i18n( 'd.m.Y H:i', (int) $entry['time'] ) ); ?></td>
                        <td>
                            <?php
                            if ( $entry['trigger'] === 'manual' ) echo '👤 вручную';
                            elseif ( $entry['trigger'] === 'cron' ) echo '⏰ cron';
                            else echo esc_html( $entry['trigger'] );
                            ?>
                        </td>
                        <td><?php echo (int) ( $entry['orders_processed'] ?? 0 ); ?></td>
                        <td><?php echo (int) ( $entry['files_deleted'] ?? 0 ); ?></td>
                        <td><?php echo esc_html( PFC_Stats::format_bytes( (int) ( $entry['bytes_deleted'] ?? 0 ) ) ); ?></td>
                        <td><?php echo (int) ( $entry['orphans_deleted'] ?? 0 ); ?></td>
                        <td><?php echo (int) ( $entry['duration_ms'] ?? 0 ); ?> мс</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

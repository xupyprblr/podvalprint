<?php
/**
 * Фаза 2. Кто есть кто: Telegram user_id ↔ WP-пользователь.
 *
 * До этого права были плоским списком user_id в настройках. Список не
 * знает, кто стоит за номером, не пишет имя в историю заказа и не
 * переживает найм человека. Теперь номер привязывается к WP-юзеру, а
 * право нажимать кнопки берётся из капабилити `edit_shop_orders`.
 *
 * @package podval-telegram-orders
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Мета WP-пользователя с его Telegram user_id. */
const PTG_USER_META_TG = 'ptg_telegram_id';

/** Меты ответственного за заказ. */
const PTG_META_ASSIGNEE_TG   = '_ptg_assignee_tg_id';
const PTG_META_ASSIGNEE_NAME = '_ptg_assignee_name';
const PTG_META_ASSIGNEE_WP   = '_ptg_assignee_user_id';
const PTG_META_ASSIGNEE_AT   = '_ptg_assignee_at';

/**
 * Роль печатника: доступ к заказам, но не ко всей админке.
 *
 * Заводится при активации плагина. Полноценно пригодится в Фазе 5
 * (Mini App), но капабилити нужна уже сейчас — по ней проверяются права
 * на кнопки.
 *
 * @return void
 */
function ptg_register_roles() {
    if ( get_role( 'print_operator' ) ) {
        return;
    }
    add_role( 'print_operator', 'Печатник', array(
        'read'              => true,
        'edit_shop_orders'  => true,
        'read_shop_orders'  => true,
    ) );
}
register_activation_hook( PTG_PATH . 'podval-telegram-orders.php', 'ptg_register_roles' );

/**
 * WP-пользователь по Telegram user_id.
 *
 * @param int $tg_id Telegram user_id.
 * @return WP_User|null
 */
function ptg_user_by_telegram_id( $tg_id ) {
    $tg_id = (int) $tg_id;
    if ( ! $tg_id ) {
        return null;
    }

    $users = get_users( array(
        'meta_key'   => PTG_USER_META_TG,
        'meta_value' => (string) $tg_id,
        'number'     => 1,
        'fields'     => 'all',
    ) );

    return $users ? $users[0] : null;
}

/**
 * Может ли этот Telegram-пользователь управлять заказами.
 *
 * Порядок проверки:
 * 1. Привязанный WP-юзер с `edit_shop_orders` — основной путь.
 * 2. Легаси-список `ptg_allowed_user_ids` — чтобы обновление с 2.0.x не
 *    отключило кнопки до того, как привязки будут проставлены.
 * 3. Если не настроено ни то, ни другое — разрешаем (поведение 2.0.x).
 *
 * @param int $tg_id Telegram user_id.
 * @return bool
 */
function ptg_user_can_manage( $tg_id ) {
    $user = ptg_user_by_telegram_id( $tg_id );
    if ( $user && user_can( $user, 'edit_shop_orders' ) ) {
        return true;
    }

    $allowed = ptg_allowed_user_ids();
    if ( ! empty( $allowed ) ) {
        return in_array( (int) $tg_id, $allowed, true );
    }

    // Ни привязок, ни списка — плагин ещё не настроен, не блокируем работу.
    $map = ptg_telegram_id_map();
    return empty( $map );
}

/**
 * Все привязки telegram_id → WP user_id.
 *
 * @return array tg_id => user_id.
 */
function ptg_telegram_id_map() {
    $users = get_users( array(
        'meta_key' => PTG_USER_META_TG,
        'fields'   => array( 'ID' ),
    ) );

    $map = array();
    foreach ( $users as $u ) {
        $tg = (int) get_user_meta( $u->ID, PTG_USER_META_TG, true );
        if ( $tg ) {
            $map[ $tg ] = (int) $u->ID;
        }
    }
    return $map;
}

/**
 * Имя сотрудника для карточки и истории заказа.
 *
 * @param int   $tg_id Telegram user_id.
 * @param array $from  Объект from из апдейта (фоллбэк).
 * @return string
 */
function ptg_staff_name( $tg_id, $from = array() ) {
    $user = ptg_user_by_telegram_id( $tg_id );
    if ( $user ) {
        return $user->display_name;
    }
    return ptg_user_display_name( $from );
}

/**
 * Назначает ответственного, если он ещё не назначен.
 *
 * Первое действие по заказу = «взял в работу». Именно этого не хватало
 * в схеме с эмодзи: реакция не говорит, кто её поставил.
 *
 * @param WC_Order $order Заказ.
 * @param int      $tg_id Telegram user_id.
 * @param array    $from  Объект from.
 * @param bool     $force Переназначить, даже если ответственный есть.
 * @return bool Назначение изменилось.
 */
function ptg_maybe_assign( $order, $tg_id, $from = array(), $force = false ) {
    $tg_id = (int) $tg_id;
    if ( ! $tg_id ) {
        return false;
    }

    $current = (int) $order->get_meta( PTG_META_ASSIGNEE_TG );
    if ( $current === $tg_id ) {
        return false;
    }
    if ( $current && ! $force ) {
        return false;
    }

    $name = ptg_staff_name( $tg_id, $from );
    $user = ptg_user_by_telegram_id( $tg_id );

    $order->update_meta_data( PTG_META_ASSIGNEE_TG, $tg_id );
    $order->update_meta_data( PTG_META_ASSIGNEE_NAME, $name );
    $order->update_meta_data( PTG_META_ASSIGNEE_WP, $user ? (int) $user->ID : 0 );
    $order->update_meta_data( PTG_META_ASSIGNEE_AT, current_time( 'mysql' ) );
    $order->save();

    ptg_add_note( $order, $current
        ? sprintf( 'Ответственный изменён: %s (Telegram)', $name )
        : sprintf( 'Заказ взят в работу: %s (Telegram)', $name )
    );

    ptg_log( 'info', 'Ответственный назначен', array(
        'order' => $order->get_id(),
        'tg'    => $tg_id,
        'name'  => $name,
    ) );

    return true;
}

/**
 * Поле Telegram ID в профиле WP-пользователя.
 *
 * @param WP_User $user Пользователь.
 * @return void
 */
function ptg_user_profile_field( $user ) {
    if ( ! current_user_can( 'edit_users' ) && get_current_user_id() !== $user->ID ) {
        return;
    }
    $value = get_user_meta( $user->ID, PTG_USER_META_TG, true );
    ?>
    <h2>Telegram</h2>
    <table class="form-table">
        <tr>
            <th><label for="ptg_telegram_id">Telegram user_id</label></th>
            <td>
                <input type="text" name="ptg_telegram_id" id="ptg_telegram_id"
                       value="<?php echo esc_attr( $value ); ?>" class="regular-text code" />
                <p class="description">
                    Числовой ID, не юзернейм. Узнать: написать боту в личку команду
                    <code>/whoami</code> — он ответит в группу заказов.
                    Привязка даёт право нажимать кнопки на карточках заказов.
                </p>
            </td>
        </tr>
    </table>
    <?php
}
add_action( 'show_user_profile', 'ptg_user_profile_field' );
add_action( 'edit_user_profile', 'ptg_user_profile_field' );

/**
 * Сохранение поля профиля.
 *
 * @param int $user_id Пользователь.
 * @return void
 */
function ptg_save_user_profile_field( $user_id ) {
    if ( ! current_user_can( 'edit_user', $user_id ) ) {
        return;
    }
    if ( ! isset( $_POST['ptg_telegram_id'] ) ) {
        return;
    }

    $raw = preg_replace( '/[^0-9]/', '', (string) wp_unslash( $_POST['ptg_telegram_id'] ) );

    if ( '' === $raw ) {
        delete_user_meta( $user_id, PTG_USER_META_TG );
        return;
    }

    // Один Telegram-аккаунт — один WP-юзер, иначе непонятно, кого писать
    // ответственным.
    $existing = ptg_user_by_telegram_id( $raw );
    if ( $existing && (int) $existing->ID !== (int) $user_id ) {
        ptg_log( 'warn', 'Попытка привязать занятый Telegram ID', array(
            'tg'    => (int) $raw,
            'busy'  => (int) $existing->ID,
            'tried' => (int) $user_id,
        ) );
        return;
    }

    update_user_meta( $user_id, PTG_USER_META_TG, $raw );
}
add_action( 'personal_options_update', 'ptg_save_user_profile_field' );
add_action( 'edit_user_profile_update', 'ptg_save_user_profile_field' );

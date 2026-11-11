<?php
/**
 * Plugin Name: Podval Telegram Orders
 * Description: Заказы WooCommerce в Telegram: живое сообщение-карточка, редактирование параметров изделия кнопками, вебхук.
 * Version: 0.1.0
 * Author: Podval Print
 * Text Domain: podval-telegram-orders
 *
 * Фаза 0 — каркас: плагин умеет редактировать ранее отправленное сообщение
 * и принимать апдейты от Telegram.
 * Фаза 1 — оверрайды параметров изделия поверх заказа, без правки позиций WC.
 * Фаза 2 — привязка клиента через Telegram Business, лог переписки,
 * маппинг telegram_id → WP-юзер, ответственный за заказ.
 * Фаза 3 — статусы производственного цикла, контекстные кнопки,
 * блокировка параметров после отправки в печать.
 * Фаза 4 — превью макета в карточке, сверка параметров перед печатью.
 * Фаза 5 — Mini App: список заказов, фильтры, поиск, заказ целиком.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'PTG_VERSION', '0.1.0' );
define( 'PTG_PATH', plugin_dir_path( __FILE__ ) );
define( 'PTG_URL', plugin_dir_url( __FILE__ ) );

require_once PTG_PATH . 'inc/log.php';
require_once PTG_PATH . 'inc/api.php';
require_once PTG_PATH . 'inc/identity.php';
require_once PTG_PATH . 'inc/statuses.php';
require_once PTG_PATH . 'inc/order-data.php';
require_once PTG_PATH . 'inc/overrides.php';
require_once PTG_PATH . 'inc/keyboard.php';
require_once PTG_PATH . 'inc/render-tg.php';
require_once PTG_PATH . 'inc/hooks.php';
require_once PTG_PATH . 'inc/callbacks.php';
require_once PTG_PATH . 'inc/business.php';
require_once PTG_PATH . 'inc/greeting.php';
require_once PTG_PATH . 'inc/media.php';
require_once PTG_PATH . 'inc/preview.php';
require_once PTG_PATH . 'inc/webhook.php';
require_once PTG_PATH . 'inc/settings.php';
require_once PTG_PATH . 'inc/app-auth.php';
require_once PTG_PATH . 'inc/app-rest.php';
require_once PTG_PATH . 'inc/app-actions.php';
require_once PTG_PATH . 'inc/app-report.php';
require_once PTG_PATH . 'inc/app-create.php';
require_once PTG_PATH . 'inc/app-page.php';

/**
 * Совместимость с HPOS: заказы не CPT, объявляем поддержку явно.
 */
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
} );

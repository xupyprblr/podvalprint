<?php
/**
 * Раздел сайта в админке.
 *
 * Все кастомные страницы настроек и инструментов собраны в одном меню.
 *
 * 1) Страницы темы регистрируются сразу с родителем PODVAL_ADMIN_MENU
 *    (Конструктор шаблонов, Google Feed, Лендинги услуг, Индекс бандлов).
 *
 * 2) Страницы наших плагинов (podval-cdek, podval-print-cleanup,
 *    podval-telegram-orders) тема не трогает в коде плагинов — вместо этого
 *    «переносчик» ищет их пункты в меню и показывает ссылкой в разделе сайта,
 *    а исходный пункт прячет. Сама страница остаётся по своему адресу
 *    со своими хуками (load-*, admin_enqueue_scripts, screen id),
 *    поэтому ничего в плагинах не ломается.
 *
 *    Почему прячем на admin_head, а не на admin_menu: WordPress вычисляет
 *    хук страницы плагина по $submenu (get_admin_page_parent). Если убрать
 *    пункт раньше — прямой адрес начнёт отдавать «нет прав». К admin_head
 *    хук уже найден, а меню ещё не отрисовано.
 *
 *    Что считать «нашим»: фильтр podval_admin_relocate_rules (slug/title regex).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined( 'PODVAL_ADMIN_MENU' ) ) {
	define( 'PODVAL_ADMIN_MENU', 'podval' );
}

/* ── 1. Верхний пункт + страница-обзор ─────────────────────────────── */
add_action( 'admin_menu', function () {
	add_menu_page(
		podval_brand( 'name' ),
		podval_brand( 'name' ),
		'manage_woocommerce',
		PODVAL_ADMIN_MENU,
		'podval_admin_hub_page',
		'dashicons-art',
		'55.7' // сразу под WooCommerce / Товары
	);
	// Первый подпункт дублирует верхний — переименовываем в «Обзор».
	add_submenu_page( PODVAL_ADMIN_MENU, podval_brand( 'name' ), 'Обзор', 'manage_woocommerce', PODVAL_ADMIN_MENU, 'podval_admin_hub_page' );
}, 5 );

/* Ссылка на Customizer — настройки темы тоже «наше». В самом конце списка. */
add_action( 'admin_menu', function () {
	add_submenu_page( PODVAL_ADMIN_MENU, 'Настройки темы', 'Настройки темы', 'edit_theme_options', 'customize.php', '' );
}, 10000 );

/* ── 2. Переносчик пунктов плагинов ───────────────────────────────── */

/**
 * Правила: пункт переносится, если slug ИЛИ видимое название совпали.
 * Совпавший верхний пункт плагина переносится вместе со всеми подпунктами.
 */
function podval_admin_relocate_rules() {
	return (array) apply_filters( 'podval_admin_relocate_rules', array(
		'slug'  => '/cdek|print[-_]?cleanup|telegram|ptg[-_]|pfc[-_]/i',
		'title' => '/СДЭК|CDEK|Очистка принтов|Telegram|Телеграм/iu',
		// Никогда не трогаем эти slug'и (точное совпадение).
		'skip'  => array(),
	) );
}

function podval_admin_item_matches( $slug, $title, $rules ) {
	$slug  = (string) $slug;
	$title = trim( wp_strip_all_tags( (string) $title ) );
	if ( in_array( $slug, (array) $rules['skip'], true ) ) return false;
	// Ядро (customize.php?return=…, themes.php и т.п.) не трогаем никогда.
	if ( preg_match( '/^(customize|themes|options-[a-z]+|tools|edit|post-new|upload|users|profile|plugins|update-core|index)\.php/', $slug )
		&& strpos( $slug, 'page=' ) === false ) {
		return false;
	}
	// Для «файл.php?…» сверяем только page=…, а не весь query (там бывает ?return=<текущий URL>).
	$key = $slug;
	if ( strpos( $slug, '.php' ) !== false ) {
		$q = array();
		parse_str( (string) wp_parse_url( $slug, PHP_URL_QUERY ), $q );
		$key = isset( $q['page'] ) ? (string) $q['page'] : basename( (string) wp_parse_url( $slug, PHP_URL_PATH ) );
	}
	if ( ! empty( $rules['slug'] ) && preg_match( $rules['slug'], $key ) ) return true;
	if ( ! empty( $rules['title'] ) && $title !== '' && preg_match( $rules['title'], $title ) ) return true;
	return false;
}

/** URL пункта меню так, как его строит сам WordPress. */
function podval_admin_item_url( $slug, $parent ) {
	if ( strpos( $slug, '.php' ) !== false || strpos( $slug, '://' ) !== false ) {
		return $slug; // прямой файл/URL
	}
	if ( $parent && strpos( $parent, '.php' ) !== false ) {
		return $parent . ( strpos( $parent, '?' ) !== false ? '&' : '?' ) . 'page=' . $slug;
	}
	return 'admin.php?page=' . $slug;
}

/** Найденные пункты: [ url => [ 'title', 'cap', 'parent', 'slug', 'top' ] ]. */
$GLOBALS['podval_admin_moved'] = array();

add_action( 'admin_menu', function () {
	global $menu, $submenu;
	if ( ! is_array( $submenu ) ) return;

	$rules = podval_admin_relocate_rules();
	$moved = array();

	// a) Верхние пункты плагинов — целиком.
	foreach ( (array) $menu as $pos => $item ) {
		if ( empty( $item[2] ) || $item[2] === PODVAL_ADMIN_MENU ) continue;
		if ( strpos( (string) $item[4], 'wp-menu-separator' ) !== false ) continue;
		if ( ! podval_admin_item_matches( $item[2], $item[0], $rules ) ) continue;

		$top  = $item[2];
		$subs = isset( $submenu[ $top ] ) ? $submenu[ $top ] : array();
		if ( ! $subs ) {
			$subs = array( array( $item[0], $item[1], $top ) );
		}
		$top_title = trim( wp_strip_all_tags( preg_replace( '#<span.*$#s', '', (string) $item[0] ) ) );
		foreach ( array_values( $subs ) as $n => $sub ) {
			$url = podval_admin_item_url( $sub[2], $top );
			// Первый подпункт = сам раздел плагина; остальные подписываем «Раздел → Подпункт».
			$title = 0 === $n ? $top_title : $top_title . ' → ' . $sub[0];
			$moved[ $url ] = array( 'title' => $title, 'cap' => $sub[1], 'parent' => $top, 'slug' => $sub[2], 'top' => true, 'menu_pos' => $pos );
		}
	}

	// b) Отдельные подпункты в чужих разделах (WooCommerce, Настройки, Инструменты…).
	foreach ( $submenu as $parent => $items ) {
		if ( $parent === PODVAL_ADMIN_MENU ) continue;
		foreach ( (array) $items as $sub ) {
			if ( empty( $sub[2] ) ) continue;
			$url = podval_admin_item_url( $sub[2], $parent );
			if ( isset( $moved[ $url ] ) ) continue;
			if ( ! podval_admin_item_matches( $sub[2], $sub[0], $rules ) ) continue;
			$moved[ $url ] = array( 'title' => $sub[0], 'cap' => $sub[1], 'parent' => $parent, 'slug' => $sub[2], 'top' => false );
		}
	}

	foreach ( $moved as $url => $m ) {
		add_submenu_page( PODVAL_ADMIN_MENU, wp_strip_all_tags( $m['title'] ), $m['title'], $m['cap'], $url, '' );
	}
	$GLOBALS['podval_admin_moved'] = $moved;
}, 9999 );

/* Прячем оригиналы — после того, как WP нашёл хук страницы. */
add_action( 'admin_head', function () {
	global $menu, $submenu;
	$moved = $GLOBALS['podval_admin_moved'];
	if ( ! $moved ) return;

	foreach ( $moved as $m ) {
		if ( $m['top'] ) {
			if ( isset( $m['menu_pos'] ) ) unset( $menu[ $m['menu_pos'] ] );
			unset( $submenu[ $m['parent'] ] );
			continue;
		}
		if ( empty( $submenu[ $m['parent'] ] ) ) continue;
		foreach ( $submenu[ $m['parent'] ] as $i => $sub ) {
			if ( isset( $sub[2] ) && $sub[2] === $m['slug'] ) {
				unset( $submenu[ $m['parent'] ][ $i ] );
			}
		}
	}
}, 1 );

/* Подсветка раздела, когда открыта перенесённая страница плагина. */
function podval_admin_current_moved_url() {
	global $plugin_page, $pagenow;
	$moved = $GLOBALS['podval_admin_moved'];
	if ( ! $moved || empty( $plugin_page ) ) return '';
	foreach ( $moved as $url => $m ) {
		if ( $m['slug'] === $plugin_page ) return $url;
	}
	return '';
}
add_filter( 'parent_file', function ( $parent_file ) {
	return podval_admin_current_moved_url() ? PODVAL_ADMIN_MENU : $parent_file;
}, 99 );
add_filter( 'submenu_file', function ( $submenu_file ) {
	$url = podval_admin_current_moved_url();
	return $url ? $url : $submenu_file;
}, 99 );
/* menu-header.php после фильтров зовёт get_admin_page_parent(), и для страниц
   под options-general.php / tools.php раздел снова становится «Настройки».
   Догоняем подсветку на клиенте — только на перенесённых страницах. */
add_action( 'admin_footer', function () {
	if ( ! podval_admin_current_moved_url() ) return;
	?>
	<script>
	(function(){
		var hub = document.getElementById('toplevel_page_<?php echo esc_js( PODVAL_ADMIN_MENU ); ?>');
		if (!hub) return;
		document.querySelectorAll('#adminmenu > li.wp-has-current-submenu, #adminmenu > li.current').forEach(function(li){
			if (li === hub) return;
			li.classList.remove('wp-has-current-submenu','wp-menu-open','current');
			li.classList.add('wp-not-current-submenu');
			var a = li.querySelector(':scope > a');
			if (a) a.classList.remove('wp-has-current-submenu','wp-menu-open','current');
		});
		hub.classList.remove('wp-not-current-submenu');
		hub.classList.add('wp-has-current-submenu','wp-menu-open');
		var ga = hub.querySelector(':scope > a');
		if (ga) { ga.classList.remove('wp-not-current-submenu'); ga.classList.add('wp-has-current-submenu','wp-menu-open'); }
	})();
	</script>
	<?php
} );

/* ── 3. Страница «Обзор» ──────────────────────────────────────────── */
function podval_admin_hub_page() {
	global $submenu;
	$items = isset( $submenu[ PODVAL_ADMIN_MENU ] ) ? $submenu[ PODVAL_ADMIN_MENU ] : array();
	?>
	<div class="wrap">
		<h1><?php echo esc_html( podval_brand( 'name' ) ); ?></h1>
		<p>Все настройки и инструменты сайта и наших плагинов в одном месте.</p>
		<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px;margin-top:16px;max-width:1100px">
		<?php foreach ( $items as $sub ) :
			if ( $sub[2] === PODVAL_ADMIN_MENU ) continue;
			if ( ! current_user_can( $sub[1] ) ) continue;
			$href = podval_admin_item_url( $sub[2], PODVAL_ADMIN_MENU );
			?>
			<a href="<?php echo esc_url( admin_url( $href ) ); ?>" class="card" style="display:block;margin:0;padding:16px 18px;text-decoration:none;max-width:none">
				<strong style="font-size:14px"><?php echo esc_html( wp_strip_all_tags( $sub[0] ) ); ?></strong>
			</a>
		<?php endforeach; ?>
		</div>
		<?php if ( empty( $GLOBALS['podval_admin_moved'] ) ) : ?>
			<p style="margin-top:20px;color:#787c82">Страницы плагинов CDEK / Telegram / Очистка принтов не найдены — плагины выключены или их пункты называются иначе (фильтр <code>podval_admin_relocate_rules</code>).</p>
		<?php endif; ?>
	</div>
	<?php
}

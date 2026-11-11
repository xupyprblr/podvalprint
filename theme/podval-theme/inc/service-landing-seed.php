<?php
/**
 * Лендинги услуг — сидер черновиков.
 *
 * Раздел админки → «Лендинги услуг»: создаёт недостающие страницы черновиками.
 * Идемпотентен — страницу со слагом, который уже есть в любом статусе
 * (включая корзину), не трогает. Публикуются страницы вручную после проверки.
 *
 * Определения страниц лежат в inc/landings/<слаг>.php — по файлу на лендинг,
 * каждый возвращает массив [ num, title, order, kw, desc, sl, content ].
 * Текстов в этом файле нет: только механизм.
 *
 * Цифры в текстах — шорткодом [podval_price], ссылки на соседние лендинги —
 * [podval_sl_link]: соседняя страница может быть ещё черновиком.
 *
 * @package Podval Print
 */

defined( 'ABSPATH' ) || exit;


/**
 * Разметка блочного редактора из простого списка блоков.
 * [ 'h2', 'текст' ] · [ 'h3', 'текст' ] · [ 'p', 'текст' ] · [ 'ul', [ 'пункт', … ] ]
 */
function podval_sl_seed_blocks( array $blocks ): string {
	$out = array();
	foreach ( $blocks as $b ) {
		list( $type, $val ) = $b;
		switch ( $type ) {
			case 'h2':
				$out[] = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">{$val}</h2>\n<!-- /wp:heading -->";
				break;
			case 'h3':
				$out[] = "<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">{$val}</h3>\n<!-- /wp:heading -->";
				break;
			case 'ul':
				$items = '';
				foreach ( $val as $li ) {
					$items .= "<!-- wp:list-item -->\n<li>{$li}</li>\n<!-- /wp:list-item -->";
				}
				$out[] = "<!-- wp:list -->\n<ul class=\"wp-block-list\">{$items}</ul>\n<!-- /wp:list -->";
				break;
			default:
				$out[] = "<!-- wp:paragraph -->\n<p>{$val}</p>\n<!-- /wp:paragraph -->";
		}
	}
	return implode( "\n\n", $out );
}

/**
 * Определения лендингов из inc/landings/*.php.
 *
 * @return array slug => [ num, title, order, sl, content, yoast => [ title, desc, kw ] ]
 */
function podval_sl_seed_definitions(): array {
	$defs = array();
	foreach ( (array) glob( get_theme_file_path( 'inc/landings/*.php' ) ) as $file ) {
		$slug = basename( (string) $file, '.php' );
		if ( '' === $slug || '_' === $slug[0] ) {
			continue; // _example.php и прочие служебные файлы
		}
		$def = require $file;
		if ( is_array( $def ) && ! empty( $def['title'] ) ) {
			$defs[ $slug ] = $def;
		}
	}
	uasort( $defs, static function ( $x, $y ) {
		return (int) ( $x['order'] ?? 0 ) <=> (int) ( $y['order'] ?? 0 );
	} );
	return (array) apply_filters( 'podval_sl_seed_definitions', $defs );
}

/** Подстановка ID товаров по слагам (product_slug → product_id) и финальная санитизация. */
function podval_sl_seed_resolve( array $sl ): array {
	foreach ( array( 'cta_primary' ) as $cta ) {
		if ( isset( $sl['hero'][ $cta ]['product_slug'] ) ) {
			$slug = (string) $sl['hero'][ $cta ]['product_slug'];
			$pid  = 0;
			if ( $slug !== '' ) {
				$p   = get_page_by_path( $slug, OBJECT, 'product' );
				$pid = $p ? (int) $p->ID : 0;
			}
			unset( $sl['hero'][ $cta ]['product_slug'] );
			$sl['hero'][ $cta ]['product_id'] = $pid;
		}
	}
	return podval_sl_sanitize( $sl );
}

/** Yoast title ≤ 60 знаков: «H1 — от X Br, срок | бренд»; без цены — без цены. */
function podval_sl_seed_yoast_title( array $def ): string {
	$from  = podval_price_from( $def['sl']['calc']['garments'] ?? array() );
	$lead  = $def['sl']['card']['lead_time'] ?? '';
	$brand = ' | ' . podval_brand( 'name' );
	$cands = array();
	if ( null !== $from ) {
		$cands[] = $def['title'] . ' — от ' . podval_price_num( $from ) . ' Br, ' . $lead . $brand;
		$cands[] = $def['title'] . ' — от ' . podval_price_num( $from ) . ' Br' . $brand;
	}
	$cands[] = $def['title'] . ( $lead !== '' ? ', ' . $lead : '' ) . $brand;
	$cands[] = $def['title'] . $brand;
	foreach ( $cands as $c ) {
		if ( mb_strlen( $c ) <= 60 ) {
			return $c;
		}
	}
	return $def['title'];
}

/** Существующая страница со слагом в любом статусе. */
function podval_sl_seed_find( string $slug ): ?WP_Post {
	$q = get_posts( array(
		'post_type'        => 'page',
		'name'             => $slug,
		'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
		'posts_per_page'   => 1,
		'suppress_filters' => true,
	) );
	return $q ? $q[0] : null;
}

/**
 * Создать недостающие черновики. Возвращает [ slug => 'created'|'exists'|'error' ].
 */
function podval_sl_seed_run(): array {
	$res = array();
	foreach ( podval_sl_seed_definitions() as $slug => $def ) {
		if ( podval_sl_seed_find( $slug ) ) {
			$res[ $slug ] = 'exists';
			continue;
		}
		$id = wp_insert_post( wp_slash( array(
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => $def['title'],
			'post_name'    => $slug,
			'post_content' => $def['content'],
			'menu_order'   => $def['order'],
		) ), true );
		if ( is_wp_error( $id ) || ! $id ) {
			$res[ $slug ] = 'error';
			continue;
		}
		update_post_meta( $id, '_wp_page_template', PODVAL_SL_TEMPLATE );
		update_post_meta( $id, '_podval_sl', podval_sl_seed_resolve( $def['sl'] ) );
		update_post_meta( $id, '_podval_sl_ver', PODVAL_SL_VER );

		$desc = str_replace( '%price%', podval_price_num( podval_price_from( $def['sl']['calc']['garments'] ?? array() ) ), $def['desc'] );
		update_post_meta( $id, '_yoast_wpseo_title', podval_sl_seed_yoast_title( $def ) );
		update_post_meta( $id, '_yoast_wpseo_focuskw', $def['kw'] );
		if ( $desc !== '' ) {
			update_post_meta( $id, '_yoast_wpseo_metadesc', $desc );
		}
		$res[ $slug ] = 'created';
	}
	if ( function_exists( 'podval_flush_all_cache' ) ) {
		podval_flush_all_cache( 'service-landings seed' );
	}
	return $res;
}

/* ═══════════════════════════════════════════
   ЭКРАН «ИНСТРУМЕНТЫ → ЛЕНДИНГИ УСЛУГ»
   ═══════════════════════════════════════════ */

add_action( 'admin_menu', function () {
	// раздел сайта в админке
	add_submenu_page( PODVAL_ADMIN_MENU, 'Лендинги услуг', 'Лендинги услуг', 'edit_pages', 'podval-sl-seed', 'podval_sl_seed_screen', 3 );
} );

add_action( 'admin_post_podval_sl_seed', function () {
	if ( ! current_user_can( 'edit_pages' ) ) {
		wp_die( 'Недостаточно прав.' );
	}
	check_admin_referer( 'podval_sl_seed' );
	$res = podval_sl_seed_run();
	set_transient( 'podval_sl_seed_result_' . get_current_user_id(), $res, 5 * MINUTE_IN_SECONDS );
	wp_safe_redirect( admin_url( 'admin.php?page=podval-sl-seed&done=1' ) );
	exit;
} );

function podval_sl_seed_screen(): void {
	$labels = array( 'created' => 'создан черновик', 'exists' => 'уже есть — не тронут', 'error' => 'ошибка' );
	$result = ! empty( $_GET['done'] ) ? get_transient( 'podval_sl_seed_result_' . get_current_user_id() ) : false;

	$sku_ok = function_exists( 'wc_get_product_id_by_sku' ) && wc_get_product_id_by_sku( 'svc-request' );
	$ph     = array();
	foreach ( podval_prices()['garments'] as $k => $g ) {
		if ( ! empty( $g['placeholder'] ) ) {
			$ph[] = $g['label'];
		}
	}
	?>
	<div class="wrap">
		<h1>Лендинги услуг</h1>
		<?php if ( is_array( $result ) ) : ?>
		<div class="notice notice-success"><p>
			<?php foreach ( $result as $slug => $st ) {
				echo esc_html( $slug . ': ' . ( $labels[ $st ] ?? $st ) ) . '<br>';
			} ?>
		</p></div>
		<?php endif; ?>

		<p>Создаёт недостающие страницы <strong>черновиками</strong> с шаблоном «Лендинг услуги». Существующие страницы с тем же адресом (в любом статусе, включая корзину) не трогаются — повторный запуск безопасен.</p>
		<p>№1 — полный контент, №8 и №9 — контент товаров-услуг, остальные — структура без FAQ и SEO-текста. Фото, отзывы и галерею добавьте в мета-боксе, затем опубликуйте.</p>

		<table class="widefat striped" style="max-width:960px">
			<thead><tr><th>№</th><th>Адрес</th><th>H1</th><th>Статус</th></tr></thead>
			<tbody>
			<?php foreach ( podval_sl_seed_definitions() as $slug => $def ) :
				$p = podval_sl_seed_find( $slug ); ?>
				<tr>
					<td><?php echo (int) $def['num']; ?></td>
					<td><code>/<?php echo esc_html( $slug ); ?>/</code></td>
					<td><?php echo esc_html( $def['title'] ); ?></td>
					<td><?php
					if ( ! $p ) {
						echo '—';
					} else {
						$st = get_post_status_object( $p->post_status );
						echo '<a href="' . esc_url( get_edit_post_link( $p->ID ) ) . '">' . esc_html( $st ? $st->label : $p->post_status ) . '</a>';
						if ( PODVAL_SL_TEMPLATE !== get_page_template_slug( $p ) ) {
							echo ' <span style="color:#b32d2e">· другой шаблон</span>';
						}
					}
					?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px">
			<input type="hidden" name="action" value="podval_sl_seed">
			<?php wp_nonce_field( 'podval_sl_seed' ); ?>
			<?php submit_button( 'Создать недостающие черновики', 'primary', 'submit', false ); ?>
		</form>

		<h2 style="margin-top:32px">Проверки перед публикацией</h2>
		<ul style="list-style:disc;padding-left:20px">
			<li>Товар «Заявка на печать» (SKU <code>svc-request</code>): <?php echo $sku_ok ? '<strong style="color:#008a20">найден</strong>' : '<strong style="color:#b32d2e">не найден</strong> — заявки с лендингов будут без позиции'; ?>.</li>
			<li>Цены-заглушки в реестре: <?php echo $ph ? '<strong style="color:#b32d2e">' . esc_html( implode( ', ', $ph ) ) . '</strong> — на проде эти изделия пойдут как «цена по запросу»' : '<strong style="color:#008a20">нет</strong>'; ?>.</li>
			<li>Этот хост: <?php echo podval_is_dev_host() ? '<strong>дев</strong> (noindex, заглушки видны)' : '<strong>прод</strong>'; ?>.</li>
		</ul>
	</div>
	<?php
}

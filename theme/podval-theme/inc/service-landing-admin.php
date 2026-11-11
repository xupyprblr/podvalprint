<?php
/**
 * Лендинги услуг — мета-бокс «Лендинг услуги».
 *
 * Нативный WP без ACF. Образец — inc/cpt-bundle.php: скрытый input с JSON →
 * json_decode при сохранении → очищенный массив в мету. Разметка полей строится
 * в assets/js/admin-service-landing.js по той же схеме podval_sl_schema(),
 * которой пользуется санитизация, — описание полей в одном месте.
 *
 * @package Podval Print
 */

defined( 'ABSPATH' ) || exit;

add_action( 'add_meta_boxes_page', function ( $post ) {
	add_meta_box( 'podval_sl_box', 'Лендинг услуги', 'podval_sl_meta_box', 'page', 'normal', 'high' );
} );

function podval_sl_meta_box( $post ): void {
	wp_nonce_field( 'podval_sl_save', 'podval_sl_nonce' );

	$is_tpl = PODVAL_SL_TEMPLATE === get_page_template_slug( $post );
	$data   = metadata_exists( 'post', $post->ID, '_podval_sl' ) ? podval_sl_get( $post->ID ) : podval_sl_defaults();

	// Плашка «цена-заглушка» (§4.1): видно, что на проде этих цен не будет.
	$ph = array();
	foreach ( $data['calc']['garments'] as $k ) {
		if ( podval_price_garment_is_placeholder( $k ) ) {
			$ph[] = podval_prices()['garments'][ $k ]['label'];
		}
	}
	?>
	<div class="msl-box" data-is-template="<?php echo $is_tpl ? '1' : '0'; ?>">
		<p class="msl-inactive"<?php echo $is_tpl ? ' hidden' : ''; ?>>
			Поля применяются, когда у страницы выбран шаблон <strong>«Лендинг услуги»</strong>. Смените шаблон и сохраните страницу.
		</p>
		<?php if ( $ph ) : ?>
		<div class="msl-placeholder-notice">
			<strong>Цена-заглушка:</strong> <?php echo esc_html( implode( ', ', $ph ) ); ?>.
			На деве цена видна, на проде такое изделие считается изделием без цены: калькулятор уходит в «Своя вещь», в hero — «цена по запросу».
		</div>
		<?php endif; ?>
		<div class="msl-toolbar">
			<button type="button" class="button msl-example">Заполнить пример (№1 «Футболки»)</button>
			<button type="button" class="button-link msl-expand">Развернуть все</button>
			<button type="button" class="button-link msl-collapse">Свернуть все</button>
		</div>
		<div class="msl-editor" id="msl-editor"></div>
		<input type="hidden" name="podval_sl_json" id="podval_sl_json" value="<?php echo esc_attr( wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) ); ?>">
		<p class="description">SEO-текст (секция 11) — это обычный контент страницы в редакторе выше: его видит анализ Yoast. Цифры в текстах — только через шорткод <code>[podval_price key="garments.classic"]</code>.</p>
	</div>
	<?php
}

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
		return;
	}
	$screen = get_current_screen();
	if ( ! $screen || 'page' !== $screen->post_type ) {
		return;
	}
	$ver = wp_get_theme()->get( 'Version' );
	$uri = get_template_directory_uri();

	wp_enqueue_media();
	wp_enqueue_style( 'podval-admin-sl', $uri . '/assets/css/admin-service-landing.css', array(), $ver );
	wp_enqueue_script( 'podval-admin-sl', $uri . '/assets/js/admin-service-landing.js', array(), $ver, true );

	$example = function_exists( 'podval_sl_seed_definitions' ) ? podval_sl_seed_definitions() : array();
	$sections = array();
	foreach ( podval_sl_section_labels() as $key => $label ) {
		$sections[] = array( 'key' => $key, 'label' => $label, 'pinned' => PODVAL_SL_SECTION_PINNED === $key );
	}

	wp_localize_script( 'podval-admin-sl', 'PodvalSLAdmin', array(
		'schema'   => podval_sl_schema(),
		'sections' => $sections,
		'template' => PODVAL_SL_TEMPLATE,
		'example'  => isset( $example['pechat-na-futbolkah'] ) ? podval_sl_seed_resolve( $example['pechat-na-futbolkah']['sl'] ) : null,
	) );
} );

/**
 * Сохранение. Мету пишем только при шаблоне лендинга: иначе любая сохранённая
 * страница получила бы _podval_sl, и страховка шаблона превратила бы её в лендинг.
 */
add_action( 'save_post_page', function ( $post_id ) {
	if ( ! isset( $_POST['podval_sl_nonce'], $_POST['podval_sl_json'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['podval_sl_nonce'] ) ), 'podval_sl_save' ) ) {
		return;
	}
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_page', $post_id ) ) {
		return;
	}
	// Классический редактор присылает шаблон в том же POST, блочный сохраняет его
	// через REST раньше, чем приходит POST мета-боксов.
	$tpl = isset( $_POST['page_template'] ) ? sanitize_text_field( wp_unslash( $_POST['page_template'] ) ) : get_page_template_slug( $post_id );
	if ( PODVAL_SL_TEMPLATE !== $tpl ) {
		return;
	}

	$raw  = json_decode( wp_unslash( $_POST['podval_sl_json'] ), true );
	$data = podval_sl_sanitize( is_array( $raw ) ? $raw : array() );

	update_post_meta( $post_id, '_podval_sl', $data );
	update_post_meta( $post_id, '_podval_sl_ver', PODVAL_SL_VER );
	// Сброс кэша — хук save_post_page с приоритетом 20 в inc/service-landing.php,
	// он срабатывает уже после записи меты.
}, 10 );

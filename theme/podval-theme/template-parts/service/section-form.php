<?php
/**
 * Лендинг · 12 ФОРМА ЗАЯВКИ.
 *
 * База — форма single-product-service.php; обслуживают service.js (загрузка,
 * валидация, токен P1, свежие nonce P2) и service-landing.js (fetch-отправка).
 * Без JS форма уходит обычным POST на admin-post.php и редиректит на thank-you.
 *
 * @var array $args post_id, sl, h1, calc, from
 */
defined( 'ABSPATH' ) || exit;

$form = $args['sl']['form'];
if ( empty( $form['enabled'] ) ) {
	return;
}
$tg = podval_sl_tg_url();
?>
<section class="sl-sec sl-sec--form" id="zayavka">
	<div class="sl-wrap sl-wrap--form">
		<header class="sl-head">
			<h2 class="sl-h2"><?php echo esc_html( $form['title'] ); ?></h2>
			<?php if ( $form['subtitle'] !== '' ) : ?>
			<p class="sl-lead"><?php echo esc_html( $form['subtitle'] ); ?></p>
			<?php endif; ?>
		</header>

		<form class="podval-svc-form sl-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" data-tg="<?php echo esc_attr( $tg ); ?>">
			<input type="hidden" name="action" value="podval_service_order">
			<input type="hidden" name="service_product_id" value="">
			<input type="hidden" name="sl_landing" value="<?php echo (int) $args['post_id']; ?>">
			<input type="hidden" name="sl_calc" value="">
			<input type="hidden" name="svc_token" value="">
			<input type="hidden" name="svc_ajax" value="">
			<?php wp_nonce_field( 'podval_service_nonce', '_podval_svc_nonce', false ); ?>

			<div class="sl-hp" aria-hidden="true">
				<label>Сайт <input type="text" name="svc_website" value="" tabindex="-1" autocomplete="off"></label>
			</div>

			<div class="podval-svc-form__row">
				<div class="podval-svc-form__field">
					<label for="sl-f-name">Имя <abbr class="required" title="обязательно">*</abbr></label>
					<input type="text" id="sl-f-name" name="svc_name" required placeholder="Ваше имя" autocomplete="name">
				</div>
				<div class="podval-svc-form__field">
					<label for="sl-f-phone">Телефон <abbr class="required" title="обязательно">*</abbr></label>
					<input type="tel" id="sl-f-phone" name="svc_phone" required placeholder="+375 (__) ___-__-__" autocomplete="tel">
				</div>
			</div>

			<div class="podval-svc-form__row">
				<div class="podval-svc-form__field">
					<label for="sl-f-tg">Telegram <abbr class="required" title="обязательно">*</abbr></label>
					<input type="text" id="sl-f-tg" name="svc_messenger" required placeholder="@username или номер">
				</div>
				<div class="podval-svc-form__field">
					<label for="sl-f-qty">Тираж, шт</label>
					<input type="text" id="sl-f-qty" name="svc_qty" inputmode="numeric" pattern="[0-9]*" placeholder="Например, 10">
				</div>
			</div>

			<div class="podval-svc-form__field">
				<label for="sl-f-email">E-mail</label>
				<input type="email" id="sl-f-email" name="svc_email" placeholder="email@example.com" autocomplete="email">
			</div>

			<div class="podval-svc-form__field">
				<label for="sl-f-comment">Комментарий</label>
				<textarea id="sl-f-comment" name="svc_comment" rows="4" placeholder="Изделие, размер принта, цвет, сроки…"></textarea>
			</div>

			<div class="podval-svc-form__field podval-co__upload-field">
				<label>Файлы для печати</label>
				<div class="podval-co__upload-zone" id="podval-svc-upload-zone">
					<input type="file" id="podval-svc-file-input" multiple accept=".jpg,.jpeg,.png,.webp,.tiff,.tif,.svg,.pdf,.ai,.eps,.psd" style="display:none">
					<div class="podval-co__upload-placeholder" id="podval-svc-upload-placeholder">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="podval-co__upload-icon" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
						<span class="podval-co__upload-text">Нажмите или перетащите файлы</span>
						<span class="podval-co__upload-formats">JPG, PNG, SVG, PDF, PSD, AI, EPS — до 25 МБ</span>
					</div>
					<div class="podval-co__upload-list" id="podval-svc-upload-list"></div>
				</div>
			</div>

			<div class="podval-svc-form__field">
				<label>Получение</label>
				<div class="podval-svc-form__delivery-options">
					<label class="podval-svc-form__radio"><input type="radio" name="svc_delivery" value="discuss" checked> Обсудим позже</label>
					<label class="podval-svc-form__radio"><input type="radio" name="svc_delivery" value="pickup"> Самовывоз в Минске (бесплатно)</label>
					<label class="podval-svc-form__radio"><input type="radio" name="svc_delivery" value="delivery"> Доставка по Беларуси</label>
				</div>
				<div class="podval-svc-form__delivery-addr" id="svc-delivery-addr" style="display:none;margin-top:10px">
					<input type="text" name="svc_delivery_address" placeholder="Город, адрес или отделение Евро-Почты">
				</div>
			</div>

			<div class="sl-form__error" role="alert" hidden></div>

			<button type="submit" class="sl-btn sl-btn--primary sl-btn--block">Отправить заявку</button>

			<p class="podval-svc-form__note">Нажимая кнопку, вы соглашаетесь с <a href="<?php echo esc_url( podval_url( 'privacy' ) ); ?>">политикой конфиденциальности</a></p>
		</form>
	</div>
</section>

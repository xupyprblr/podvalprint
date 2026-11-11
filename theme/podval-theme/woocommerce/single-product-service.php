<?php
/**
 * Podval Print — Single Product (Service) Template
 * Used for products in category "uslugi" and its children
 * $product is already set from single-product.php
 */
defined( 'ABSPATH' ) || exit;

$product_id  = $product->get_id();
$name        = $product->get_name();
$desc        = $product->get_description();
$short_desc  = $product->get_short_description();
$images      = $product->get_gallery_image_ids();
$main_img    = $product->get_image_id();

// Telegram link
$tg = get_theme_mod( 'podval_footer_tg', '' );

// Breadcrumbs
$cats = wc_get_product_cat_ids( $product_id );
?>

<article class="podval-svc-single">

  <!-- Breadcrumbs -->
  <nav class="podval-svc-single__breadcrumbs">
    <a href="<?php echo esc_url( home_url('/') ); ?>">Главная</a>
    <span>—</span>
    <a href="<?php echo esc_url( get_term_link( 'uslugi', 'product_cat' ) ); ?>">Услуги</a>
    <span>—</span>
    <span><?php echo esc_html( $name ); ?></span>
  </nav>

  <!-- Hero -->
  <section class="podval-svc-single__hero">
    <?php if ( $main_img ) : ?>
    <div class="podval-svc-single__hero-img" style="background-image:url(<?php echo esc_url( wp_get_attachment_url( $main_img ) ); ?>)"></div>
    <?php endif; ?>
    <div class="podval-svc-single__hero-overlay"></div>
    <div class="podval-svc-single__hero-content">
      <h1 class="podval-svc-single__title"><?php echo esc_html( $name ); ?></h1>

      <?php if ( $short_desc ) : ?>
        <p class="podval-svc-single__subtitle"><?php echo esc_html( wp_strip_all_tags( $short_desc ) ); ?></p>
      <?php endif; ?>

      <div class="podval-svc-single__hero-btns">
        <a href="#order-form" class="podval-svc__btn-primary">Оставить заявку</a>
      </div>
    </div>
  </section>

  <!-- Общее описание из WC -->
  <?php if ( $desc ) : ?>
  <section class="podval-svc__section">
    <div class="podval-svc__container">
      <div class="podval-svc-single__text-block"><?php echo wp_kses_post( $desc ); ?></div>
    </div>
  </section>
  <?php endif; ?>


  <!-- ФОРМА ЗАЯВКИ (shared) -->
  <section class="podval-svc__section" id="order-form">
    <div class="podval-svc__container" style="max-width:700px;">
      <h2 class="podval-svc__section-title">Оставить заявку</h2>
      <p class="podval-svc__section-desc">Опишите задачу и прикрепите файлы — мы свяжемся с вами для обсуждения деталей и расчёта стоимости.</p>

      <form class="podval-svc-form" method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" enctype="multipart/form-data">
        <input type="hidden" name="action" value="podval_service_order">
        <input type="hidden" name="service_product_id" value="<?php echo esc_attr( $product_id ); ?>">
        <?php /* прежней версии (P1): токен генерирует service.js в браузере. В кэшированном
                 HTML он был один на всех посетителей. */ ?>
        <input type="hidden" name="svc_token" value="">
        <?php wp_nonce_field( 'podval_service_nonce', '_podval_svc_nonce' ); ?>

        <div class="podval-svc-form__row">
          <div class="podval-svc-form__field">
            <label>Имя <abbr class="required">*</abbr></label>
            <input type="text" name="svc_name" required placeholder="Ваше имя">
          </div>
          <div class="podval-svc-form__field">
            <label>Телефон <abbr class="required">*</abbr></label>
            <input type="tel" name="svc_phone" required placeholder="+375 (__) ___-__-__">
          </div>
        </div>

        <div class="podval-svc-form__row">
          <div class="podval-svc-form__field">
            <label>Telegram <abbr class="required">*</abbr></label>
            <input type="text" name="svc_messenger" required placeholder="@username или номер">
          </div>
          <div class="podval-svc-form__field">
            <label>E-mail</label>
            <input type="email" name="svc_email" placeholder="email@example.com">
          </div>
        </div>

        <div class="podval-svc-form__field">
          <label>Описание задачи</label>
          <textarea name="svc_comment" rows="4" placeholder="Опишите что нужно: тираж, размеры принта, тип изделия..."></textarea>
        </div>

        <!-- Файлы -->
        <div class="podval-svc-form__field podval-co__upload-field">
          <label>Файлы для печати</label>
          <div class="podval-co__upload-zone" id="podval-svc-upload-zone">
            <input type="file" id="podval-svc-file-input" multiple
                   accept=".jpg,.jpeg,.png,.webp,.tiff,.tif,.svg,.pdf,.ai,.eps,.psd"
                   style="display:none">
            <div class="podval-co__upload-placeholder" id="podval-svc-upload-placeholder">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="podval-co__upload-icon">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="17 8 12 3 7 8"/>
                <line x1="12" y1="3" x2="12" y2="15"/>
              </svg>
              <span class="podval-co__upload-text">Нажмите или перетащите файлы</span>
              <span class="podval-co__upload-formats">JPG, PNG, SVG, PDF, PSD, AI, EPS — до 25 МБ</span>
            </div>
            <div class="podval-co__upload-list" id="podval-svc-upload-list"></div>
          </div>
        </div>

        <!-- Доставка (опционально) -->
        <div class="podval-svc-form__field">
          <label>Нужна доставка?</label>
          <div class="podval-svc-form__delivery-options">
            <label class="podval-svc-form__radio"><input type="radio" name="svc_delivery" value="discuss" checked> Обсудим позже</label>
            <label class="podval-svc-form__radio"><input type="radio" name="svc_delivery" value="pickup"> Самовывоз (бесплатно)</label>
            <label class="podval-svc-form__radio"><input type="radio" name="svc_delivery" value="delivery"> Нужна доставка</label>
          </div>
          <div class="podval-svc-form__delivery-addr" id="svc-delivery-addr" style="display:none; margin-top:10px;">
            <input type="text" name="svc_delivery_address" placeholder="Город, адрес или отделение Евро-Почты">
          </div>
        </div>

        <button type="submit" class="podval-svc__btn-primary" style="width:100%;justify-content:center;padding:18px 24px;margin-top:8px;">
          Оставить заявку
        </button>

        <p class="podval-svc-form__note">Нажимая кнопку, вы соглашаетесь с <a href="<?php echo esc_url( podval_url( 'privacy' ) ); ?>">политикой конфиденциальности</a></p>
      </form>
    </div>
  </section>

</article>

<?php get_footer(); ?>

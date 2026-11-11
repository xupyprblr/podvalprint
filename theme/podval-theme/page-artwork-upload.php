<?php
/**
 * Template Name: Загрузка дизайна
 * 
 * Multi-step artwork upload page for custom-design products.
 * Receives product config from GET params, shows upload zones
 * based on selected options (sides, sleeve), then adds to cart.
 *
 * @package Podval Print
 *
 */
defined( 'ABSPATH' ) || exit;

/**
 * Render the "Выбрать примерное расположение" button + saved-thumb stub for a slot.
 * Placement editor — schematic reference of where the print goes.
 *
 * @param string $slot front|back|sleeve
 */
if ( ! function_exists( 'podval_aw_placement_button' ) ) {
    function podval_aw_placement_button( $slot, $enabled = true ) {
        if ( ! $enabled ) return; // товар-шаблон: редактор размещения отключён
        $slot = sanitize_key( $slot );
        ?>
        <div class="aw-placement" data-slot="<?php echo esc_attr( $slot ); ?>">
            <button type="button" class="aw-placement__btn" data-slot="<?php echo esc_attr( $slot ); ?>">
                <span class="aw-placement__btn-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="18" height="18"><path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2"/><rect x="8" y="8" width="8" height="8" rx="1"/></svg>
                </span>
                <span class="aw-placement__btn-text">Выбрать примерное расположение</span>
            </button>
            <div class="aw-placement__saved" data-slot="<?php echo esc_attr( $slot ); ?>" hidden>
                <img class="aw-placement__thumb" alt="Схема расположения принта">
                <div class="aw-placement__saved-meta">
                    <span class="aw-placement__saved-label">Расположение задано</span>
                    <span class="aw-placement__stale-note">Данные изменились — обновите расположение</span>
                    <div class="aw-placement__saved-actions">
                        <button type="button" class="aw-placement__edit" data-slot="<?php echo esc_attr( $slot ); ?>">Изменить</button>
                        <button type="button" class="aw-placement__clear" data-slot="<?php echo esc_attr( $slot ); ?>">Удалить</button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}

// ── Collect params from GET ──────────────────────────────
$product_id   = absint( $_GET['product_id'] ?? 0 );
$variation_id = absint( $_GET['variation_id'] ?? 0 );

if ( ! $product_id || ! function_exists( 'wc_get_product' ) ) {
    wp_redirect( home_url( '/' ) );
    exit;
}

$product = wc_get_product( $product_id );
if ( ! $product ) {
    wp_redirect( home_url( '/' ) );
    exit;
}

// Редактор «примерное расположение»: включён, если мета не равна явному '0'
// (legacy-товары без меты остаются с включённым редактором).
$placement_enabled = ( get_post_meta( $product_id, '_podval_placement_enabled', true ) !== '0' );

// Decode variation attributes from GET
$attrs_raw = array();
foreach ( $_GET as $k => $v ) {
    if ( strpos( $k, 'attr_' ) === 0 ) {
        $attr_key = sanitize_text_field( urldecode( substr( $k, 5 ) ) );
        $attrs_raw[ $attr_key ] = sanitize_text_field( urldecode( $v ) );
    }
}

// Determine print sides from attributes
// Look for "Где печатаем?" / "сторона" type attribute
$print_side    = ''; // спереди, сзади, с обеих сторон (lowercase Cyrillic, NOT sanitize_title!)
$has_sleeve    = false;
$is_dark_garment = false; // black/dark tee → dark mockup + white print text
$product_name  = $product->get_name();

foreach ( $attrs_raw as $key => $val ) {
    $key_lower = mb_strtolower( $key );
    $val_lower = mb_strtolower( $val );
    // Detect print side attribute
    if ( strpos( $key_lower, 'печата' ) !== false || strpos( $key_lower, 'сторон' ) !== false || strpos( $key_lower, 'где' ) !== false ) {
        $print_side = $val_lower; // keep as lowercase Cyrillic for strpos matching
    }
    // Detect sleeve print attribute
    if ( strpos( $key_lower, 'рукав' ) !== false ) {
        if ( $val_lower === 'да' || $val_lower === 'yes' ) {
            $has_sleeve = true;
        }
    }
    // Detect garment color → dark mockup for black/dark shades
    if ( strpos( $key_lower, 'цвет' ) !== false || strpos( $key_lower, 'color' ) !== false ) {
        if ( strpos( $val_lower, 'чёрн' ) !== false || strpos( $val_lower, 'черн' ) !== false
          || strpos( $val_lower, 'black' ) !== false || strpos( $val_lower, 'тёмн' ) !== false
          || strpos( $val_lower, 'темн' ) !== false ) {
            $is_dark_garment = true;
        }
    }
}

// Determine number of upload slots
$is_both_sides = ( strpos( $print_side, 'обеих' ) !== false || strpos( $print_side, 'обоих' ) !== false || strpos( $print_side, 'обе' ) !== false );
$is_front_only = ( strpos( $print_side, 'спереди' ) !== false || strpos( $print_side, 'перед' ) !== false );
$is_back_only  = ( strpos( $print_side, 'сзади' ) !== false || strpos( $print_side, 'зад' ) !== false );

// Variation display name for order summary
$var_display = array();
foreach ( $attrs_raw as $key => $val ) {
    $var_display[] = $val;
}

// Variation product for price
$display_price = '';
if ( $variation_id ) {
    $var_product = wc_get_product( $variation_id );
    if ( $var_product ) {
        $display_price = wc_price( $var_product->get_price() );
    }
} else {
    $display_price = $product->get_price_html();
}

// Thumbnail
$thumb_id  = $product->get_image_id();
$thumb_url = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'woocommerce_thumbnail' ) : wc_placeholder_img_src( 'woocommerce_thumbnail' );

get_header();
?>

<div class="aw-page" style="max-width:960px;margin-left:auto;margin-right:auto;">

  <!-- Breadcrumbs -->
  <nav class="aw-breadcrumbs">
    <a href="<?php echo esc_url( home_url( '/' ) ); ?>">Главная</a>
    <span class="aw-breadcrumbs__sep">—</span>
    <a href="<?php echo esc_url( get_permalink( $product_id ) ); ?>"><?php echo esc_html( $product_name ); ?></a>
    <span class="aw-breadcrumbs__sep">—</span>
    <span>Загрузка дизайна</span>
  </nav>

  <div class="aw-layout">

    <!-- LEFT: Upload area -->
    <div class="aw-main">

      <h1 class="aw-title">Загрузите ваш дизайн</h1>
      <p class="aw-subtitle">Прикрепите изображение или введите текст для печати. <strong>Загрузить файл прямо сейчас необязательно</strong> — макет можно отправить позже через Telegram.</p>

      <!-- Compact skip link at top (for mobile, so it's not hidden below fold) -->
      <div class="aw-skip-top">
        <button type="button" class="aw-skip-top__link" id="aw-skip-upload-top">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="14" height="14"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
          Пропустить и оформить заказ
        </button>
      </div>

      <!-- ═══ FRONT / SINGLE SIDE ═══ -->
      <?php
        if ( $is_both_sides ) {
            $primary_slot  = 'front';
            $primary_label = 'Печать на груди';
        } elseif ( $is_back_only ) {
            $primary_slot  = 'back';
            $primary_label = 'Печать на спине';
        } elseif ( $is_front_only ) {
            $primary_slot  = 'front';
            $primary_label = 'Печать на груди';
        } else {
            $primary_slot  = 'front';
            $primary_label = 'Файл для печати';
        }
      ?>
      <div class="aw-upload-section" data-slot="<?php echo esc_attr( $primary_slot ); ?>">
        <div class="aw-upload-section__header">
          <span class="aw-upload-section__icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><path d="M7 5H9C9 5 10 7 12 7C14 7 15 5 15 5H17L21 9V12H18V20H6V12H3V9L7 5Z"/><path d="M9 5.5C9 5.5 10 7.5 12 7.5C14 7.5 15 5.5 15 5.5" opacity="0.4"/></svg>
          </span>
          <h2 class="aw-upload-section__title"><?php echo esc_html( $primary_label ); ?></h2>
          <?php if ( $is_both_sides ) : ?>
          <span class="aw-upload-section__badge">Сторона 1</span>
          <?php endif; ?>
        </div>

        <div class="aw-upload-zone" id="aw-zone-<?php echo esc_attr( $primary_slot ); ?>">
          <input type="file" class="aw-upload-zone__input" id="aw-input-<?php echo esc_attr( $primary_slot ); ?>"
                 accept=".jpg,.jpeg,.png,.webp,.tiff,.tif,.bmp,.svg,.pdf,.ai,.eps,.psd"
                 style="display:none">
          <div class="aw-upload-zone__placeholder" id="aw-placeholder-<?php echo esc_attr( $primary_slot ); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="aw-upload-zone__icon">
              <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
              <polyline points="17 8 12 3 7 8"/>
              <line x1="12" y1="3" x2="12" y2="15"/>
            </svg>
            <span class="aw-upload-zone__text">Нажмите или перетащите файл</span>
            <span class="aw-upload-zone__formats">JPG, PNG, WebP, TIFF, SVG, PDF, PSD, AI, EPS — до 25 МБ</span>
          </div>
          <div class="aw-upload-zone__file-info" id="aw-file-<?php echo esc_attr( $primary_slot ); ?>" style="display:none"></div>
        </div>

        <?php podval_aw_placement_button( $primary_slot, $placement_enabled ); ?>

        <!-- Add text line toggle -->
        <div class="aw-textline" data-slot="<?php echo esc_attr( $primary_slot ); ?>">
          <label class="aw-textline__toggle">
            <span class="aw-textline__check">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="12" height="12" class="aw-textline__check-icon"><polyline points="20 6 9 17 4 12"/></svg>
            </span>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="15" height="15" class="aw-textline__icon"><path d="M4 7V4h16v3M9 20h6M12 4v16"/></svg>
            <span class="aw-textline__label">Добавить надпись</span>
            <span class="aw-textline__label-hint">текст для печати</span>
          </label>
          <div class="aw-textline__body">
            <input type="text" class="aw-textline__input" data-slot="<?php echo esc_attr( $primary_slot ); ?>" placeholder="Например: CREW, ваше имя, фраза..." maxlength="120">
            <span class="aw-textline__hint">До 120 символов. Шрифт и расположение согласуем в Telegram.</span>
          </div>
        </div>

      </div>

      <!-- ═══ BACK SIDE (only if both sides) ═══ -->
      <?php if ( $is_both_sides ) : ?>
      <div class="aw-upload-section" data-slot="back">
        <div class="aw-upload-section__header">
          <span class="aw-upload-section__icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><path d="M7 5H17L21 9V12H18V20H6V12H3V9L7 5Z"/><path d="M9 5C9 5 10 6 12 6C14 6 15 5 15 5"/></svg>
          </span>
          <h2 class="aw-upload-section__title">Печать на спине</h2>
          <span class="aw-upload-section__badge">Сторона 2</span>
        </div>

        <div class="aw-upload-zone" id="aw-zone-back">
          <input type="file" class="aw-upload-zone__input" id="aw-input-back"
                 accept=".jpg,.jpeg,.png,.webp,.tiff,.tif,.bmp,.svg,.pdf,.ai,.eps,.psd"
                 style="display:none">
          <div class="aw-upload-zone__placeholder" id="aw-placeholder-back">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="aw-upload-zone__icon">
              <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
              <polyline points="17 8 12 3 7 8"/>
              <line x1="12" y1="3" x2="12" y2="15"/>
            </svg>
            <span class="aw-upload-zone__text">Нажмите или перетащите файл</span>
            <span class="aw-upload-zone__formats">JPG, PNG, WebP, TIFF, SVG, PDF, PSD, AI, EPS — до 25 МБ</span>
          </div>
          <div class="aw-upload-zone__file-info" id="aw-file-back" style="display:none"></div>
        </div>

        <?php podval_aw_placement_button( 'back', $placement_enabled ); ?>

        <!-- Add text line toggle -->
        <div class="aw-textline" data-slot="back">
          <label class="aw-textline__toggle">
            <span class="aw-textline__check">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="12" height="12" class="aw-textline__check-icon"><polyline points="20 6 9 17 4 12"/></svg>
            </span>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="15" height="15" class="aw-textline__icon"><path d="M4 7V4h16v3M9 20h6M12 4v16"/></svg>
            <span class="aw-textline__label">Добавить надпись</span>
            <span class="aw-textline__label-hint">текст для печати</span>
          </label>
          <div class="aw-textline__body">
            <input type="text" class="aw-textline__input" data-slot="back" placeholder="Например: CREW, ваше имя, фраза..." maxlength="120">
            <span class="aw-textline__hint">До 120 символов. Шрифт и расположение согласуем в Telegram.</span>
          </div>
        </div>

      </div>
      <?php endif; ?>

      <!-- ═══ SLEEVE (if selected) ═══ -->
      <?php if ( $has_sleeve ) : ?>
      <div class="aw-upload-section" data-slot="sleeve">
        <div class="aw-upload-section__header">
          <span class="aw-upload-section__icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><path d="M12 5L19 8V17L12 20V5Z"/><path d="M12 20L5 17V8L12 5"/><path d="M12 5V20" opacity="0.3"/></svg>
          </span>
          <h2 class="aw-upload-section__title">Печать на рукаве</h2>
        </div>

        <p class="aw-sleeve-note">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" width="15" height="15" aria-hidden="true"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
          Нужен принт на двух рукавах? Загрузите один здесь, а второй обсудим в Telegram при подтверждении заказа.
        </p>

        <div class="aw-upload-zone" id="aw-zone-sleeve">
          <input type="file" class="aw-upload-zone__input" id="aw-input-sleeve"
                 accept=".jpg,.jpeg,.png,.webp,.tiff,.tif,.bmp,.svg,.pdf,.ai,.eps,.psd"
                 style="display:none">
          <div class="aw-upload-zone__placeholder" id="aw-placeholder-sleeve">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="aw-upload-zone__icon">
              <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
              <polyline points="17 8 12 3 7 8"/>
              <line x1="12" y1="3" x2="12" y2="15"/>
            </svg>
            <span class="aw-upload-zone__text">Нажмите или перетащите файл</span>
            <span class="aw-upload-zone__formats">JPG, PNG, WebP, TIFF, SVG, PDF, PSD, AI, EPS — до 25 МБ</span>
          </div>
          <div class="aw-upload-zone__file-info" id="aw-file-sleeve" style="display:none"></div>
        </div>

        <?php podval_aw_placement_button( 'sleeve', $placement_enabled ); ?>

        <!-- Add text line toggle -->
        <div class="aw-textline" data-slot="sleeve">
          <label class="aw-textline__toggle">
            <span class="aw-textline__check">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="12" height="12" class="aw-textline__check-icon"><polyline points="20 6 9 17 4 12"/></svg>
            </span>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="15" height="15" class="aw-textline__icon"><path d="M4 7V4h16v3M9 20h6M12 4v16"/></svg>
            <span class="aw-textline__label">Добавить надпись</span>
            <span class="aw-textline__label-hint">текст для печати</span>
          </label>
          <div class="aw-textline__body">
            <input type="text" class="aw-textline__input" data-slot="sleeve" placeholder="Например: CREW, ваше имя, фраза..." maxlength="120">
            <span class="aw-textline__hint">До 120 символов. Шрифт и расположение согласуем в Telegram.</span>
          </div>
        </div>

      </div>
      <?php endif; ?>

      <!-- ═══ EXTRA FILES (accordion) ═══ -->
      <div class="aw-extra">
        <label class="aw-extra__toggle" id="aw-extra-toggle">
          <span class="aw-extra__checkbox" id="aw-extra-check">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14" class="aw-extra__check-icon"><polyline points="20 6 9 17 4 12"/></svg>
          </span>
          <span class="aw-extra__toggle-text">Дополнительные файлы</span>
          <span class="aw-comment__optional">референсы, мокапы, логотипы...</span>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16" class="aw-extra__chevron"><path d="M6 9l6 6 6-6"/></svg>
        </label>
        <div class="aw-extra__body" id="aw-extra-body">
          <div class="aw-extra__zone" id="aw-extra-zone">
            <input type="file" class="aw-upload-zone__input" id="aw-input-extra" multiple
                   accept=".jpg,.jpeg,.png,.webp,.tiff,.tif,.bmp,.svg,.pdf,.ai,.eps,.psd"
                   style="display:none">
            <div class="aw-extra__placeholder" id="aw-extra-placeholder">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28" style="color:rgba(255,102,0,0.4)">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="17 8 12 3 7 8"/>
                <line x1="12" y1="3" x2="12" y2="15"/>
              </svg>
              <span class="aw-upload-zone__text" style="font-size:14px">Нажмите или перетащите файлы</span>
              <span class="aw-upload-zone__formats">Любое количество файлов — до 25 МБ каждый</span>
            </div>
            <div class="aw-extra__list" id="aw-extra-list"></div>
          </div>
        </div>
      </div>

      <!-- ═══ COMMENT ═══ -->
      <div class="aw-comment">
        <label class="aw-comment__label" for="aw-comment">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16" class="aw-comment__icon"><path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/></svg>
          Пожелания к печати <span class="aw-comment__optional">необязательно</span>
        </label>
        <textarea class="aw-comment__input" id="aw-comment" rows="3" placeholder="Расположение, цвета, размер принта, особые пожелания..."></textarea>
      </div>

      <!-- ═══ ACTIONS ═══ -->
      <div class="aw-actions">
        <button type="button" class="aw-btn aw-btn--primary" id="aw-checkout">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="20" height="20">
            <path d="M9 11l3 3L22 4"/>
            <path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/>
          </svg>
          Оформить заказ
        </button>

        <button type="button" class="aw-btn aw-btn--outline" id="aw-add-to-cart">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="20" height="20">
            <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
            <path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/>
          </svg>
          В корзину
        </button>

        <a href="<?php echo esc_url( get_permalink( $product_id ) ); ?>" class="aw-btn aw-btn--icon" id="aw-back" title="Назад к товару">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="20" height="20">
            <path d="M19 12H5M12 19l-7-7 7-7"/>
          </svg>
        </a>
      </div>

      <!-- Skip option -->
      <div class="aw-skip">
        <button type="button" class="aw-skip__link" id="aw-skip-upload">
          Пропустить — отправлю файлы позже
        </button>
        <p class="aw-skip__hint">Мы свяжемся с вами через Telegram для получения макета после оформления заказа</p>
      </div>

    </div><!-- /.aw-main -->

    <!-- RIGHT: Order summary sidebar -->
    <aside class="aw-sidebar">
      <div class="aw-summary">
        <h3 class="aw-summary__title">Ваш заказ</h3>

        <div class="aw-summary__product">
          <?php if ( $thumb_url ) : ?>
          <img src="<?php echo esc_url( $thumb_url ); ?>" alt="<?php echo esc_attr( $product_name ); ?>" class="aw-summary__img">
          <?php endif; ?>
          <div class="aw-summary__info">
            <span class="aw-summary__name"><?php echo esc_html( $product_name ); ?></span>
            <?php if ( ! empty( $var_display ) ) : ?>
            <span class="aw-summary__attrs"><?php echo esc_html( implode( ', ', $var_display ) ); ?></span>
            <?php endif; ?>
            <span class="aw-summary__price"><?php echo wp_kses_post( $display_price ); ?></span>
          </div>
        </div>

        <div class="aw-summary__files" id="aw-summary-files" style="display:none">
          <h4 class="aw-summary__files-title">Файлы</h4>
          <div id="aw-summary-files-list"></div>
        </div>
      </div>
    </aside>

  </div><!-- /.aw-layout -->

  <!-- ═══ ЧТО ДАЛЬШЕ — пошаговая инструкция (reuses cd-steps from custom-design) ═══ -->
  <div class="aw-howto">
    <h3 class="aw-howto__title">Что дальше?</h3>
    <div class="cd-steps">
      <div class="cd-step">
        <span class="cd-step__num">1</span>
        <div class="cd-step__arrow"><svg viewBox="0 0 40 12" fill="none"><path d="M0 6h36M32 1l5 5-5 5" stroke="currentColor" stroke-width="1.2"/></svg></div>
        <h3 class="cd-step__title">Загрузите принт</h3>
        <p class="cd-step__text">Прикрепите изображение выше — фото, арт, логотип. Можете добавить дополнительные файлы: референсы, мокапы</p>
      </div>
      <div class="cd-step">
        <span class="cd-step__num">2</span>
        <div class="cd-step__arrow"><svg viewBox="0 0 40 12" fill="none"><path d="M0 6h36M32 1l5 5-5 5" stroke="currentColor" stroke-width="1.2"/></svg></div>
        <h3 class="cd-step__title">Оформите заказ</h3>
        <p class="cd-step__text">Нажмите «Оформить заказ», заполните контактные данные и выберите способ доставки</p>
      </div>
      <div class="cd-step">
        <span class="cd-step__num">3</span>
        <div class="cd-step__arrow"><svg viewBox="0 0 40 12" fill="none"><path d="M0 6h36M32 1l5 5-5 5" stroke="currentColor" stroke-width="1.2"/></svg></div>
        <h3 class="cd-step__title">Обсудим детали</h3>
        <p class="cd-step__text">Свяжемся с вами в Telegram — уточним расположение принта, цвета и размер, отправим макет</p>
      </div>
      <div class="cd-step">
        <span class="cd-step__num">4</span>
        <div class="cd-step__arrow"><svg viewBox="0 0 40 12" fill="none"><path d="M0 6h36M32 1l5 5-5 5" stroke="currentColor" stroke-width="1.2"/></svg></div>
        <h3 class="cd-step__title">Оплатите</h3>
        <p class="cd-step__text">Онлайн картой, наличными или через терминал при самовывозе</p>
      </div>
      <div class="cd-step">
        <span class="cd-step__num">5</span>
        <h3 class="cd-step__title">Получите</h3>
        <p class="cd-step__text">Самовывоз в Минске, доставка Евро-Почтой, СДЭК или Яндекс Доставкой по всей Беларуси</p>
      </div>
    </div>
  </div>

  <!-- ═══ PLACEMENT EDITOR MODAL ═══ -->
  <?php if ( $placement_enabled ) : ?>
  <div class="awp-modal" id="awp-modal" hidden aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="awp-modal-title">
    <div class="awp-modal__backdrop" data-awp-close></div>
    <div class="awp-modal__dialog" role="document">
      <header class="awp-modal__head">
        <h2 class="awp-modal__title" id="awp-modal-title">Примерное расположение</h2>
        <button type="button" class="awp-modal__close" data-awp-close aria-label="Закрыть">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" width="22" height="22"><path d="M18 6 6 18M6 6l12 12"/></svg>
        </button>
      </header>

      <div class="awp-modal__body">
        <p class="awp-modal__hint">Перетащите изображение, потяните за угол, чтобы изменить размер. Это примерная схема — точное расположение согласуем в Telegram.</p>
        <p class="awp-modal__note" id="awp-note-stub" hidden>Предпросмотр для этого формата браузер не показывает. Обозначьте рамкой, где и какого размера должен быть принт — макет из файла ляжет в эту область.</p>

        <div class="awp-stage" id="awp-stage">
          <div class="awp-stage__garment" id="awp-stage-garment"></div>
          <div class="awp-layer awp-layer--img" id="awp-layer-img" hidden>
            <img class="awp-layer__img" id="awp-layer-img-el" alt="" draggable="false">
            <!-- PDF/AI/EPS/PSD браузер в <img> не рисует. Если сервер не
                 смог сделать превью, вместо битой картинки показываем рамку с
                 типом и именем файла — перетаскивать и масштабировать можно так же. -->
            <span class="awp-layer__stub" id="awp-layer-img-stub" hidden aria-hidden="true">
              <span class="awp-layer__stub-ext" id="awp-layer-img-stub-ext"></span>
              <span class="awp-layer__stub-name" id="awp-layer-img-stub-name"></span>
            </span>
            <span class="awp-layer__handle" data-handle="se" aria-hidden="true"></span>
          </div>
          <div class="awp-layer awp-layer--text" id="awp-layer-text" hidden>
            <span class="awp-layer__text-content" id="awp-layer-text-el"></span>
            <span class="awp-layer__handle awp-layer__handle--text" data-handle="text-se" aria-hidden="true"></span>
          </div>
          <div class="awp-stage__empty" id="awp-stage-empty">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" width="34" height="34"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            <span>Добавьте изображение или надпись</span>
          </div>
          <div class="awp-stage__uploading" id="awp-stage-uploading" hidden>
            <span class="awp-spinner" aria-hidden="true"></span>
            <span class="awp-stage__uploading-text">Загрузка изображения…</span>
          </div>
        </div>
        <input type="file" id="awp-file-input" accept="image/*,.pdf,.psd,.ai,.eps,.tiff,.svg" hidden>

        <div class="awp-textctl" id="awp-textctl">
          <div class="awp-textctl__head">
            <label class="awp-textctl__label" for="awp-text-input">Надпись</label>
            <div class="awp-textctl__align" role="group" aria-label="Выравнивание текста">
              <button type="button" class="awp-align" data-align="left" aria-label="По левому краю">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" width="16" height="16"><path d="M3 6h18M3 12h12M3 18h15"/></svg>
              </button>
              <button type="button" class="awp-align" data-align="center" aria-label="По центру">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" width="16" height="16"><path d="M3 6h18M6 12h12M5 18h14"/></svg>
              </button>
              <button type="button" class="awp-align" data-align="right" aria-label="По правому краю">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" width="16" height="16"><path d="M3 6h18M9 12h12M6 18h15"/></svg>
              </button>
            </div>
          </div>
          <textarea class="awp-textctl__input" id="awp-text-input" maxlength="200" rows="2" placeholder="Например: CREW, имя, фраза… (Enter — новая строка)"></textarea>
        </div>

        <div class="awp-tools">
          <button type="button" class="awp-tool awp-tool--upload" id="awp-empty-upload" hidden>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" width="16" height="16"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            Загрузить изображение
          </button>
          <button type="button" class="awp-tool" id="awp-tool-reset">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>
            Сбросить
          </button>
          <button type="button" class="awp-tool" id="awp-tool-center">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16"><path d="M12 3v18M3 12h18"/></svg>
            По центру
          </button>
        </div>
      </div>

      <footer class="awp-modal__foot">
        <button type="button" class="awp-btn awp-btn--ghost" data-awp-close>Отмена</button>
        <button type="button" class="awp-btn awp-btn--primary" id="awp-save">
          <span class="awp-btn__label">Сохранить</span>
        </button>
      </footer>
    </div>
  </div>
  <?php endif; // $placement_enabled ?>

</div><!-- /.aw-page -->

<!-- Pass config to JS -->
<script>
  window.awConfig = {
    productId:   <?php echo (int) $product_id; ?>,
    variationId: <?php echo (int) $variation_id; ?>,
    attrs:       <?php echo wp_json_encode( $attrs_raw, JSON_UNESCAPED_UNICODE ); ?>,
    isBothSides: <?php echo $is_both_sides ? 'true' : 'false'; ?>,
    hasSleeve:   <?php echo $has_sleeve ? 'true' : 'false'; ?>,
    cartUrl:     <?php echo wp_json_encode( wc_get_cart_url() ); ?>,
    checkoutUrl: <?php echo wp_json_encode( wc_get_checkout_url() ); ?>,
    productUrl:  <?php echo wp_json_encode( get_permalink( $product_id ) ); ?>,
    ajaxUrl:     <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
    nonce:       <?php echo wp_json_encode( wp_create_nonce( 'podval_nonce' ) ); ?>,
    garments:    {
      <?php $g_color = $is_dark_garment ? 'black' : 'white'; ?>
      front:  <?php echo wp_json_encode( get_template_directory_uri() . '/assets/img/garment/tee-' . $g_color . '-front.svg' ); ?>,
      back:   <?php echo wp_json_encode( get_template_directory_uri() . '/assets/img/garment/tee-' . $g_color . '-back.svg' ); ?>,
      sleeve: <?php echo wp_json_encode( get_template_directory_uri() . '/assets/img/garment/tee-' . $g_color . '-sleeve.svg' ); ?>
    },
    darkGarment: <?php echo $is_dark_garment ? 'true' : 'false'; ?>,
    placementEnabled: <?php echo $placement_enabled ? 'true' : 'false'; ?>
  };
</script>

<?php get_footer(); ?>

<?php
/**
 * Podval Print — Custom Checkout Page
 */
defined( 'ABSPATH' ) || exit;

// Подключаем WC checkout скрипты
if ( function_exists( 'wp_enqueue_script' ) ) {
    wp_enqueue_script( 'wc-checkout' );
}

get_header();

if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
    echo '<div class="podval-checkout"><div class="podval-checkout__empty">';
    echo '<p>Корзина пуста</p>';
    echo '<a href="' . esc_url( podval_get_catalog_url() ) . '" class="podval-co__submit-btn">Перейти в каталог</a>';
    echo '</div></div>';
    get_footer();
    return;
}

WC()->cart->calculate_totals();
$cart_items = WC()->cart->get_cart();
$checkout   = WC()->checkout();
?>

<div class="podval-checkout">

  <!-- ЗАГОЛОВОК -->
  <div class="podval-co__header">
    <h1 class="podval-co__title">Оформление заказа</h1>
  </div>

  <form name="checkout" method="post" class="podval-co__form checkout woocommerce-checkout" action="<?php echo esc_url( wc_get_checkout_url() ); ?>" enctype="multipart/form-data" id="podval-checkout-form">

    <div class="podval-co__layout">

      <!-- ====== ЛЕВАЯ КОЛОНКА ====== -->
      <div class="podval-co__main">

        <!-- 1. Контактные данные -->
        <section class="podval-co__section">
          <h2 class="podval-co__section-title">Контактные данные</h2>

          <div class="podval-co__fields-row">
            <div class="podval-co__field">
              <label for="billing_first_name" class="podval-co__label">Имя <abbr class="required">*</abbr></label>
              <input type="text" id="billing_first_name" name="billing_first_name"
                     class="podval-co__input" required autocomplete="given-name"
                     value="<?php echo esc_attr( (string) $checkout->get_value('billing_first_name') ); ?>"
                     placeholder="Ваше имя">
              <span class="podval-co__field-error" id="err-name"></span>
            </div>

            <div class="podval-co__field">
              <label for="billing_phone" class="podval-co__label">Номер телефона <abbr class="required">*</abbr></label>
              <input type="tel" id="billing_phone" name="billing_phone"
                     class="podval-co__input" required autocomplete="tel"
                     value="<?php echo esc_attr( (string) $checkout->get_value('billing_phone') ); ?>"
                     placeholder="+375 (__) ___-__-__">
              <span class="podval-co__field-error" id="err-phone"></span>
            </div>
          </div>

          <div class="podval-co__fields-row">
            <div class="podval-co__field">
              <label for="billing_messenger" class="podval-co__label">Telegram <abbr class="required">*</abbr></label>
              <input type="text" id="billing_messenger" name="billing_messenger"
                     class="podval-co__input" required
                     value="<?php echo esc_attr( (string) $checkout->get_value('billing_messenger') ); ?>"
                     placeholder="@username или +375...">
              <span class="podval-co__hint">Используется для связи по заказу</span>
              <span class="podval-co__field-error" id="err-messenger"></span>
            </div>

            <div class="podval-co__field">
              <label for="billing_email" class="podval-co__label">E-mail</label>
              <input type="email" id="billing_email" name="billing_email"
                     class="podval-co__input" autocomplete="email"
                     value="<?php echo esc_attr( (string) $checkout->get_value('billing_email') ); ?>"
                     placeholder="email@example.com">
              <span class="podval-co__hint">Отправим детали заказа на почту</span>
              <span class="podval-co__field-error" id="err-email"></span>
            </div>
          </div>

          <!-- Загрузка файлов принта -->
          <div class="podval-co__collapsible" id="podval-upload-collapse">
            <button type="button" class="podval-co__collapsible-toggle" id="podval-upload-toggle">
              <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3" width="14" height="14"><path d="M9 1H4a1 1 0 00-1 1v12a1 1 0 001 1h8a1 1 0 001-1V5L9 1z"/><path d="M9 1v4h4"/></svg>
              Прикрепить доп. файлы
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="14" height="14" class="podval-co__collapsible-chevron"><path d="M6 9l6 6 6-6"/></svg>
            </button>
            <div class="podval-co__collapsible-body" id="podval-upload-body">
              <div class="podval-co__field podval-co__upload-field">
                <div class="podval-co__upload-zone" id="podval-upload-zone">
                  <input type="file" id="podval-file-input" multiple
                         accept=".jpg,.jpeg,.png,.webp,.tiff,.tif,.bmp,.svg,.pdf,.ai,.eps,.psd"
                         style="display:none">
                  <div class="podval-co__upload-placeholder" id="podval-upload-placeholder">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="podval-co__upload-icon">
                      <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                      <polyline points="17 8 12 3 7 8"/>
                      <line x1="12" y1="3" x2="12" y2="15"/>
                    </svg>
                    <span class="podval-co__upload-text">Нажмите или перетащите файлы</span>
                    <span class="podval-co__upload-formats">JPG, PNG, WebP, TIFF, SVG, PDF, PSD, AI, EPS — до 25 МБ</span>
                  </div>
                  <div class="podval-co__upload-list" id="podval-upload-list"></div>
                </div>
              </div>
            </div>
          </div>
        </section>

        <!-- 2+3. Доставка и Оплата — в ряд на десктопе -->
        <div class="podval-co__shipping-payment-row">

        <!-- 2. Доставка -->
        <section class="podval-co__section podval-co__section--shipping">
          <button type="button" class="podval-co__section-title podval-co__section-toggle" id="podval-shipping-toggle">
            <span>Способ доставки</span>
            <span class="podval-co__section-preview" id="podval-shipping-preview">Самовывоз</span>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="14" height="14" class="podval-co__section-chevron"><path d="M6 9l6 6 6-6"/></svg>
          </button>
          <div class="podval-co__section-body" id="podval-shipping-body">

          <div class="podval-co__select-group" id="podval-shipping-methods">
            <label class="podval-co__select-card is-selected" data-value="pickup">
              <input type="radio" name="podval_shipping" value="pickup" checked>
              <div class="podval-co__select-content">
                <div class="podval-co__select-info">
                  <div class="podval-co__select-title">Самовывоз</div>
                  <div class="podval-co__select-desc">Бесплатно</div>
                </div>
              </div>
            </label>

            <label class="podval-co__select-card is-collapsed" data-value="yandex">
              <input type="radio" name="podval_shipping" value="yandex">
              <div class="podval-co__select-content">
                <div class="podval-co__select-info">
                  <div class="podval-co__select-title">Яндекс доставка</div>
                  <div class="podval-co__select-desc">Оплата клиентом</div>
                </div>
              </div>
            </label>

            <label class="podval-co__select-card is-collapsed" data-value="europost">
              <input type="radio" name="podval_shipping" value="europost">
              <div class="podval-co__select-content">
                <div class="podval-co__select-info">
                  <div class="podval-co__select-title">Евро-Почта до отделения</div>
                  <div class="podval-co__select-desc">Оплата посылки — 5 Br</div>
                </div>
              </div>
            </label>

            <label class="podval-co__select-card is-collapsed" data-value="cdek">
              <input type="radio" name="podval_shipping" value="cdek">
              <div class="podval-co__select-content">
                <div class="podval-co__select-info">
                  <div class="podval-co__select-title">СДЭК до ПВЗ</div>
                  <div class="podval-co__select-desc">Стоимость по тарифам перевозчика</div>
                </div>
              </div>
            </label>
          </div>

          </div><!-- /.podval-co__section-body (shipping) -->
        </section>

        <!-- 3. Оплата -->
        <section class="podval-co__section podval-co__section--payment">
          <button type="button" class="podval-co__section-title podval-co__section-toggle" id="podval-payment-toggle">
            <span>Способ оплаты</span>
            <span class="podval-co__section-preview" id="podval-payment-preview">Оплата наличными</span>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="14" height="14" class="podval-co__section-chevron"><path d="M6 9l6 6 6-6"/></svg>
          </button>
          <div class="podval-co__section-body" id="podval-payment-body">

          <div class="podval-co__select-group" id="podval-payment-methods">
            <label class="podval-co__select-card podval-co__payment-card is-selected" data-method="podval_cash" data-value="podval_cash">
              <input type="radio" name="payment_method" value="podval_cash" checked>
              <div class="podval-co__select-content">
                <div class="podval-co__select-info">
                  <div class="podval-co__select-title">Оплата наличными</div>
                  <div class="podval-co__select-desc">При самовывозе</div>
                </div>
              </div>
            </label>

            <label class="podval-co__select-card podval-co__payment-card is-collapsed" data-method="terminal" data-value="terminal">
              <input type="radio" name="payment_method" value="terminal">
              <div class="podval-co__select-content">
                <div class="podval-co__select-info">
                  <div class="podval-co__select-title">Оплата картой через терминал</div>
                  <div class="podval-co__select-desc">При самовывозе</div>
                </div>
              </div>
            </label>

            <label class="podval-co__select-card podval-co__payment-card is-collapsed" data-method="alfabankby" data-value="alfabankby">
              <input type="radio" name="payment_method" value="alfabankby">
              <div class="podval-co__select-content">
                <div class="podval-co__select-info">
                  <div class="podval-co__select-title">Оплата онлайн</div>
                  <div class="podval-co__select-desc">Банковской картой</div>
                </div>
              </div>
            </label>

            <label class="podval-co__select-card podval-co__payment-card is-collapsed" data-method="podval_pay_link" data-value="podval_pay_link">
              <input type="radio" name="payment_method" value="podval_pay_link">
              <div class="podval-co__select-content">
                <div class="podval-co__select-info">
                  <div class="podval-co__select-title">Оплата после согласования</div>
                  <div class="podval-co__select-desc">Пришлём ссылку на оплату</div>
                </div>
              </div>
            </label>
          </div>
          </div><!-- /.podval-co__section-body (payment) -->
        </section>

        </div><!-- /.podval-co__shipping-payment-row -->

        <!-- Адрес доставки — панель появляется при выборе Яндекс/Евро-Почты -->
        <!-- Структура полей переключается JS в зависимости от выбранной доставки -->
        <div class="podval-co__address-panel" id="podval-address-panel" style="display:none;" aria-hidden="true">
          <div class="podval-co__address-panel-title" id="podval-address-panel-title">Адрес доставки</div>

          <!-- Yandex: одна строка адреса -->
          <div class="podval-co__field podval-co__address-yandex" id="podval-address-yandex" style="display:none;">
            <label for="podval_address_yandex_full" class="podval-co__label">Адрес <abbr class="required">*</abbr></label>
            <input type="text" id="podval_address_yandex_full" class="podval-co__input"
                   placeholder="Улица, дом, квартира" autocomplete="street-address">
          </div>

          <!-- Europost: ФИО / Город / Улица / Телефон -->
          <div class="podval-co__address-europost" id="podval-address-europost" style="display:none;">
            <div class="podval-co__field">
              <label for="podval_address_eu_fio" class="podval-co__label">ФИО получателя <abbr class="required">*</abbr></label>
              <input type="text" id="podval_address_eu_fio" class="podval-co__input"
                     placeholder="Иванов Иван Иванович" autocomplete="name">
            </div>
            <div class="podval-co__field">
              <label for="podval_address_eu_city" class="podval-co__label">Город <abbr class="required">*</abbr></label>
              <input type="text" id="podval_address_eu_city" class="podval-co__input"
                     placeholder="Минск" autocomplete="address-level2">
            </div>
            <div class="podval-co__field">
              <label for="podval_address_eu_street" class="podval-co__label">Улица, дом <abbr class="required">*</abbr></label>
              <input type="text" id="podval_address_eu_street" class="podval-co__input"
                     placeholder="ул. Притыцкого, 12" autocomplete="street-address">
            </div>
            <div class="podval-co__field">
              <label for="podval_address_eu_phone" class="podval-co__label">Телефон получателя <abbr class="required">*</abbr></label>
              <input type="tel" id="podval_address_eu_phone" class="podval-co__input"
                     placeholder="+375 29 123-45-67" autocomplete="tel">
              <div class="podval-co__field-hint">Будет выслано SMS с трек-номером и кодом получения посылки</div>
            </div>
            <div class="podval-co__address-note">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16" aria-hidden="true">
                <circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/>
              </svg>
              <span>Подберём ближайший пункт выдачи Евро-Почты по указанному адресу</span>
            </div>
          </div>

          <!-- Скрытое поле, в которое JS пишет собранную итоговую строку адреса.
               Имя поля shipping_address_1 совпадает со старой версией — обработка
               в functions.php (строка ~1437) и thankyou/Telegram-плагин не ломаются. -->
          <input type="hidden" id="shipping_address" name="shipping_address_1"
                 value="<?php echo esc_attr( (string) $checkout->get_value('shipping_address_1') ); ?>">
        </div>

        <!-- Комментарий -->
        <div class="podval-co__collapsible" id="podval-comment-collapse">
          <button type="button" class="podval-co__collapsible-toggle" id="podval-comment-toggle">
            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3" width="14" height="14"><path d="M14 10.7a1.3 1.3 0 01-1.3 1.3H4.6L2 14V3.3A1.3 1.3 0 013.3 2h9.4A1.3 1.3 0 0114 3.3z"/></svg>
            Комментарий к заказу
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="14" height="14" class="podval-co__collapsible-chevron"><path d="M6 9l6 6 6-6"/></svg>
          </button>
          <div class="podval-co__collapsible-body" id="podval-comment-body">
            <div class="podval-co__field">
              <textarea id="order_comments" name="order_comments" class="podval-co__textarea"
                        rows="3" placeholder="Особые пожелания к заказу..."><?php echo esc_textarea( (string) $checkout->get_value('order_comments') ); ?></textarea>
            </div>
          </div>
        </div>

      </div><!-- /.podval-co__main -->

      <!-- ====== ПРАВАЯ КОЛОНКА: Итого ====== -->
      <div class="podval-co__sidebar">
        <div class="podval-co__summary">

          <button type="button" class="podval-co__summary-toggle" id="podval-summary-toggle">
            <span class="podval-co__summary-title">Ваш заказ</span>
            <span class="podval-co__summary-preview" id="podval-summary-preview">
              <?php echo count( $cart_items ); ?> <?php
                $cnt = count( $cart_items );
                echo $cnt === 1 ? 'товар' : ( $cnt < 5 ? 'товара' : 'товаров' );
              ?> · <?php echo WC()->cart->get_total(); ?>
            </span>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="14" height="14" class="podval-co__summary-chevron"><path d="M6 9l6 6 6-6"/></svg>
          </button>

          <div class="podval-co__summary-body" id="podval-summary-body">

          <div class="podval-co__summary-items">
            <?php foreach ( $cart_items as $cart_item_key => $cart_item ) :
              $product  = $cart_item['data'];
              $quantity = $cart_item['quantity'];
              if ( ! $product || ! $product->exists() || $quantity <= 0 ) continue;

              $name     = $product->get_name();
              $subtotal = WC()->cart->get_product_subtotal( $product, $quantity );

              // Атрибуты вариации
              $var_attrs = array();
              if ( $cart_item['variation_id'] && ! empty( $cart_item['variation'] ) ) {
                  foreach ( $cart_item['variation'] as $ak => $av ) {
                      if ( $av === '' ) continue;
                      $tax = str_replace( 'attribute_', '', $ak );
                      $tax = urldecode( $tax );
                      if ( taxonomy_exists( $tax ) ) {
                          $term = get_term_by( 'slug', $av, $tax );
                          if ( $term && ! is_wp_error($term) ) $av = $term->name;
                      }
                      $var_attrs[] = $av;
                  }
              }
            ?>
            <div class="podval-co__summary-item">
              <div class="podval-co__summary-item-info">
                <span class="podval-co__summary-item-name"><?php echo esc_html( $name ); ?></span>
                <?php if ( ! empty( $var_attrs ) ) : ?>
                <span class="podval-co__summary-item-attrs"><?php echo esc_html( implode( ', ', $var_attrs ) ); ?></span>
                <?php endif; ?>
                <span class="podval-co__summary-item-qty">× <?php echo esc_html( $quantity ); ?></span>
                <?php
                // Artwork files detail for checkout
                $aw = ! empty( $cart_item['podval_artwork'] ) ? $cart_item['podval_artwork'] : null;
                if ( $aw ) :
                  $slot_labels = array( 'front' => 'Перед', 'back' => 'Спина', 'sleeve' => 'Рукав' );
                ?>
                <div class="podval-co__summary-artwork">
                  <?php if ( ! empty( $aw['files'] ) ) :
                    $slot_labels_co = array( 'front' => 'перед', 'back' => 'спина', 'sleeve' => 'рукав' );
                    $slot_names_co  = array();
                    foreach ( $aw['files'] as $slot => $fid ) { $slot_names_co[] = $slot_labels_co[ $slot ] ?? $slot; }
                    $fc_co   = count( $aw['files'] );
                    $word_co = $fc_co === 1 ? 'принт' : ( $fc_co < 5 ? 'принта' : 'принтов' );
                  ?>
                    <span class="podval-cart__artwork-badge">
                      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3" width="14" height="14"><path d="M2.5 2h7l3.5 3.5V14a1 1 0 01-1 1H2.5A1 1 0 011.5 14V3a1 1 0 011-1z"/><path d="M9.5 2v4h4"/><path d="M5 8.5h6M5 11h4"/></svg>
                      <?php echo $fc_co . ' ' . $word_co . ' (' . implode( ', ', $slot_names_co ) . ')'; ?>
                    </span>
                  <?php elseif ( ! empty( $aw['skipped'] ) ) : ?>
                    <span class="podval-cart__artwork-badge podval-cart__artwork-badge--pending">Файл будет отправлен позже</span>
                  <?php endif; ?>
                  <?php
                  // Split placement schematics from real extra files.
                  $extra_co     = 0;
                  $placement_co = 0;
                  if ( ! empty( $aw['extra_files'] ) && is_array( $aw['extra_files'] ) ) {
                      foreach ( $aw['extra_files'] as $efid_co ) {
                          if ( get_post_meta( (int) $efid_co, '_podval_placement', true ) === '1' ) {
                              $placement_co++;
                          } else {
                              $extra_co++;
                          }
                      }
                  }
                  if ( $placement_co > 0 ) :
                  ?>
                    <span class="podval-cart__artwork-badge podval-cart__artwork-badge--placement">
                      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3" width="14" height="14"><path d="M2 4.5V3a1 1 0 011-1h1.5M11.5 2H13a1 1 0 011 1v1.5M14 11.5V13a1 1 0 01-1 1h-1.5M4.5 14H3a1 1 0 01-1-1v-1.5"/><rect x="5" y="5" width="6" height="6" rx="1"/></svg>
                      Расположение задано
                    </span>
                  <?php endif; ?>
                  <?php
                  if ( $extra_co > 0 ) :
                    $ef_word = $extra_co === 1 ? 'доп. файл' : ( $extra_co < 5 ? 'доп. файла' : 'доп. файлов' );
                  ?>
                    <span class="podval-cart__artwork-badge podval-cart__artwork-badge--extra">
                      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3" width="14" height="14"><path d="M9 1H4a1 1 0 00-1 1v12a1 1 0 001 1h8a1 1 0 001-1V5L9 1z"/><path d="M9 1v4h4"/></svg>
                      <?php echo $extra_co . ' ' . $ef_word; ?>
                    </span>
                  <?php endif; ?>
                  <?php if ( ! empty( $aw['comment'] ) ) : ?>
                    <span class="podval-cart__artwork-badge podval-cart__artwork-badge--comment">Комментарий к печати</span>
                  <?php endif; ?>
                </div>
                <?php endif; ?>
              </div>
              <div class="podval-co__summary-item-price"><?php echo $subtotal; ?></div>
            </div>
            <?php endforeach; ?>
          </div>

          <div class="podval-co__summary-divider"></div>

          <!-- Строка стоимости доставки (скрыта по умолчанию) -->
          <div class="podval-co__summary-row podval-co__summary-shipping" id="podval-shipping-cost-row" style="display:none;">
            <span id="podval-shipping-cost-label">Оплата посылки</span>
            <span id="podval-shipping-cost-value">5,00&nbsp;Br</span>
          </div>

          <div class="podval-co__summary-row podval-co__summary-total">
            <span>Итого</span>
            <span id="podval-total-display"><?php echo WC()->cart->get_total(); ?></span>
          </div>
          </div><!-- /.podval-co__summary-body -->

          <?php /* Base cart total for JS recalculation */ ?>
          <script>var podvalCartBase = <?php echo (float) WC()->cart->get_total('edit'); ?>;</script>

          <?php wp_nonce_field( 'woocommerce-process_checkout', 'woocommerce-process-checkout-nonce' ); ?>
          <input type="hidden" name="billing_last_name" value="">
          <input type="hidden" name="billing_country" value="BY">
          <input type="hidden" name="billing_city" value="">
          <input type="hidden" name="billing_address_1" value="">
          <input type="hidden" name="billing_postcode" value="">
          <input type="hidden" name="shipping_method[0]" value="flat_rate:1" id="podval-shipping-method-hidden">
          <input type="hidden" name="podval_cdek_cost" value="0" id="podval-cdek-cost-hidden">

          <button type="submit" class="podval-co__submit-btn" id="place_order" name="woocommerce_checkout_place_order" value="1">
            Сделать заказ
          </button>

          <p class="podval-co__agreement">
            Нажимая «Сделать заказ», вы соглашаетесь с
            <a href="<?php echo esc_url( podval_url( 'privacy' ) ); ?>">политикой конфиденциальности</a>
          </p>

        </div>
      </div><!-- /.podval-co__sidebar -->

    </div><!-- /.podval-co__layout -->

    <!-- WC notices container for AJAX errors -->
    <div class="woocommerce-notices-wrapper" id="wc-notices"></div>

  </form>

</div><!-- /.podval-checkout -->

<?php get_footer(); ?>

/* ═══════════════════════════════════════════
   КОРЗИНА — AJAX qty update / remove / clear
   ═══════════════════════════════════════════ */
(function() {
  if (!document.querySelector('.podval-cart')) return;

  // Nonce для будущих API-запросов
  var restNonce = (window.podvalData && window.podvalData.restNonce) ? window.podvalData.restNonce : '';



  function reloadCart() {
    window.location.reload();
  }

  var qtyTimers = {};

  function updateQty(key, qty) {
    if (qtyTimers[key]) clearTimeout(qtyTimers[key]);
    qtyTimers[key] = setTimeout(function() {
      submitQtyForm(key, qty);
    }, 800);
  }

  function submitQtyForm(key, qty) {
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = window.location.href.split('?')[0];
    form.style.display = 'none';

    function addField(name, val) {
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      input.value = val;
      form.appendChild(input);
    }

    addField('podval_update_qty', key);
    addField('podval_qty', qty);

    document.body.appendChild(form);
    form.submit();
  }

  function updateSidebarTotal() {
    var total = 0;
    document.querySelectorAll('.podval-cart__item').forEach(function(item) {
      var lineEl = item.querySelector('.podval-cart__item-total');
      if (!lineEl) return;
      // Используем data-orig-byn если есть (BYN значение), иначе парсим текст
      var val;
      if (lineEl.hasAttribute('data-orig-byn')) {
        val = parseFloat(lineEl.getAttribute('data-orig-byn')) || 0;
      } else {
        var raw = lineEl.textContent || lineEl.innerText || '';
        raw = raw.replace(/[^\d,\.]/g, '').replace(',', '.');
        val = parseFloat(raw) || 0;
      }
      total += val;
    });
    var totalEl = document.querySelector('.podval-cart__summary-price');
    if (totalEl) {
      totalEl.setAttribute('data-orig-byn', total);
      var formatted = total.toFixed(2).replace('.', ',') + ' Br';
      totalEl.textContent = formatted;
    }
  }

  function removeItem(key) {
    var url = window.location.href.split('?')[0];
    window.location.href = url + '?podval_remove=' + encodeURIComponent(key);
  }

  // Кнопки +/-
  document.querySelectorAll('.podval-cart__qty').forEach(function(block) {
    var key   = block.dataset.key;
    var numEl = block.querySelector('.podval-cart__qty-num');
    var item  = block.closest('.podval-cart__item');

    // Получаем unit price из элемента .podval-cart__item-unit
    function getUnitPrice() {
      var unitEl = item ? item.querySelector('.podval-cart__item-unit') : null;
      if (!unitEl) return 0;
      // Извлекаем число из строки вида "50,00 Br / шт."
      var text = unitEl.textContent.replace(/\s/g,'').replace(',','.');
      var match = text.match(/([\d.]+)/);
      return match ? parseFloat(match[1]) : 0;
    }

    function formatPrice(num) {
      return num.toFixed(2).replace('.', ',') + ' Br';
    }

    function updateTotalDisplay(q) {
      var unit     = getUnitPrice();
      var totalEl  = item ? item.querySelector('.podval-cart__item-total') : null;
      if (totalEl && unit > 0) {
        var lineTotal = unit * q;
        totalEl.setAttribute('data-orig-byn', lineTotal);
        totalEl.textContent = formatPrice(lineTotal);
      }
      // Сразу обновляем сайдбар — DOM уже изменён выше
      updateSidebarTotal();
    }

    block.querySelector('.podval-cart__qty-minus').addEventListener('click', function() {
      var q = parseInt(numEl.textContent) - 1;
      if (q <= 0) { removeItem(key); return; }
      numEl.textContent = q;
      updateTotalDisplay(q); // обновит строку + итого
      updateQty(key, q);
    });

    block.querySelector('.podval-cart__qty-plus').addEventListener('click', function() {
      var q = parseInt(numEl.textContent) + 1;
      numEl.textContent = q;
      updateTotalDisplay(q); // обновит строку + итого
      updateQty(key, q);
    });
  });

  // Удалить товар
  document.querySelectorAll('.podval-cart__remove').forEach(function(btn) {
    btn.addEventListener('click', function() {
      removeItem(btn.dataset.key);
    });
  });

  // Очистить корзину
  var clearBtn = document.getElementById('podval-cart-clear');
  if (clearBtn) {
    clearBtn.addEventListener('click', function() {
      var url = window.location.href.split('?')[0];
      window.location.href = url + '?podval_clear=1';
    });
  }

  // Промокод — через GET редирект (самый надёжный)
  var couponBtn = document.getElementById('podval-coupon-apply');
  if (couponBtn) {
    function applyCoupon() {
      var code = document.getElementById('podval-coupon-code').value.trim();
      if (!code) return;
      window.location.href = window.location.pathname + '?podval_coupon=' + encodeURIComponent(code);
    }
    couponBtn.addEventListener('click', applyCoupon);
    document.getElementById('podval-coupon-code').addEventListener('keydown', function(e) {
      if (e.key === 'Enter') applyCoupon();
    });
  }
})();

/* ═══════════════════════════════════════════
   CHECKOUT — accordion select + payment logic
   ═══════════════════════════════════════════ */
(function() {
  var shippingGroup = document.getElementById('podval-shipping-methods');
  var paymentGroup  = document.getElementById('podval-payment-methods');
  // Новая адресная панель (вынесена из секции shipping наружу,
  // чтобы не сворачиваться вместе с ней на мобиле — баг прежней версии)
  var addressPanel    = document.getElementById('podval-address-panel');
  var addressTitle    = document.getElementById('podval-address-panel-title');
  var addressYandex   = document.getElementById('podval-address-yandex');
  var addressEuropost = document.getElementById('podval-address-europost');
  var addressHidden   = document.getElementById('shipping_address');
  if (!shippingGroup || !paymentGroup) return;

  var pickupOnlyMethods = ['podval_cash', 'terminal'];

  // Accordion: выбранный раскрыт, остальные свёрнуты
  function updateSelectGroup(group) {
    var cards = group.querySelectorAll('.podval-co__select-card');
    cards.forEach(function(card) {
      var radio = card.querySelector('input[type="radio"]');
      if (radio && radio.checked) {
        card.classList.add('is-selected');
        card.classList.remove('is-collapsed');
      } else {
        card.classList.remove('is-selected');
        if (!card.classList.contains('is-disabled')) {
          card.classList.add('is-collapsed');
        }
      }
    });
  }

  function getSelectedShipping() {
    var checked = shippingGroup.querySelector('input[name="podval_shipping"]:checked');
    return checked ? checked.value : 'pickup';
  }

  // Обновление текста превью в свёрнутом заголовке "Способ оплаты"
  // Вынесено отдельно, чтобы синхронизировать превью и при автоселекте
  // (программный radio.checked = true НЕ запускает событие 'change')
  function updatePaymentPreview() {
    var sel = paymentGroup.querySelector('.podval-co__select-card.is-selected .podval-co__select-title');
    var prev = document.getElementById('podval-payment-preview');
    if (sel && prev) prev.textContent = sel.textContent;
  }

  function updatePaymentVisibility() {
    var shipping = getSelectedShipping();
    var isPickup = (shipping === 'pickup');
    var firstAvailable = null;

    paymentGroup.querySelectorAll('.podval-co__select-card').forEach(function(card) {
      var method = card.dataset.method;
      var radio  = card.querySelector('input[type="radio"]');
      var isPickupOnly = pickupOnlyMethods.indexOf(method) !== -1;

      if (isPickupOnly && !isPickup) {
        card.classList.add('is-disabled', 'is-collapsed');
        card.classList.remove('is-selected');
        if (radio && radio.checked) radio.checked = false;
      } else {
        card.classList.remove('is-disabled');
        if (!firstAvailable) firstAvailable = radio;
      }
    });

    // Если ни один не выбран — выбираем первый доступный
    var anyChecked = paymentGroup.querySelector('input[name="payment_method"]:checked');
    if (!anyChecked && firstAvailable) {
      firstAvailable.checked = true;
    }
    updateSelectGroup(paymentGroup);
    // Синхронизируем превью со свёрнутым состоянием — иначе на мобиле
    // в заголовке остаётся старый текст (напр. «Оплата наличными»),
    // хотя выбран уже другой метод.
    updatePaymentPreview();
  }

  // Заголовки панели для разных способов доставки
  var addressTitles = {
    yandex:   'Адрес для Яндекс доставки',
    europost: 'Адрес для Евро-Почты'
  };

  function setRequired(container, isRequired) {
    if (!container) return;
    container.querySelectorAll('input').forEach(function(inp) {
      inp.required = isRequired;
    });
  }

  // Сборка итоговой строки адреса в скрытое shipping_address_1.
  // Формат держим читабельным — он попадает в Telegram, в админку и в thankyou.
  function syncAddressHidden() {
    if (!addressHidden) return;
    var shipping = getSelectedShipping();
    var val = '';
    if (shipping === 'yandex') {
      var y = document.getElementById('podval_address_yandex_full');
      val = y ? y.value.trim() : '';
    } else if (shipping === 'europost') {
      var fio    = (document.getElementById('podval_address_eu_fio')    || {}).value || '';
      var city   = (document.getElementById('podval_address_eu_city')   || {}).value || '';
      var street = (document.getElementById('podval_address_eu_street') || {}).value || '';
      var phone  = (document.getElementById('podval_address_eu_phone')  || {}).value || '';
      // ФИО, г. Город, ул., тел. +375…
      var parts = [];
      if (fio.trim())    parts.push(fio.trim());
      if (city.trim())   parts.push('г. ' + city.trim());
      if (street.trim()) parts.push(street.trim());
      if (phone.trim())  parts.push('тел. ' + phone.trim());
      val = parts.join(', ');
    }
    addressHidden.value = val;
  }

  function updateAddressField() {
    if (!addressPanel) return;
    var shipping = getSelectedShipping();
    var needsAddress = (shipping === 'yandex' || shipping === 'europost');

    if (!needsAddress) {
      addressPanel.style.display = 'none';
      addressPanel.setAttribute('aria-hidden', 'true');
      setRequired(addressYandex, false);
      setRequired(addressEuropost, false);
      // На pickup/cdek чужие данные в скрытом поле не нужны — чистим,
      // CDEK сам кладёт ПВЗ в свои метаполя, pickup адреса не требует.
      if (addressHidden) addressHidden.value = '';
      return;
    }

    // Показываем панель и переключаем подформу
    addressPanel.style.display = 'block';
    addressPanel.setAttribute('aria-hidden', 'false');
    if (addressTitle && addressTitles[shipping]) {
      addressTitle.textContent = addressTitles[shipping];
    }

    if (shipping === 'yandex') {
      if (addressYandex)   addressYandex.style.display   = 'block';
      if (addressEuropost) addressEuropost.style.display = 'none';
      setRequired(addressYandex, true);
      setRequired(addressEuropost, false);
    } else {
      if (addressYandex)   addressYandex.style.display   = 'none';
      if (addressEuropost) addressEuropost.style.display = 'block';
      setRequired(addressYandex, false);
      setRequired(addressEuropost, true);
    }

    syncAddressHidden();

    // Скроллим на мобиле так, чтобы СВЕРХУ был блок «Способ оплаты»,
    // а адресная панель — следом под ним. Иначе пользователь, увидев
    // только адрес, теряет возможность поменять способ оплаты не
    // прокручивая обратно вверх.
    // Ждём окончание CSS-анимации max-height (0.3s) свёрнутой секции
    // shipping, чтобы координата считалась от уже схлопнутого положения.
    if (window.innerWidth <= 900) {
      setTimeout(function() {
        var paymentSection = document.querySelector('.podval-co__section--payment');
        var target = paymentSection || addressPanel;
        var rect = target.getBoundingClientRect();
        var top = window.pageYOffset + rect.top - 80; // отступ под шапку
        window.scrollTo({ top: top, behavior: 'smooth' });
      }, 320);
    }
  }

  // Любое изменение в полях адреса — пересобираем скрытое поле,
  // чтобы валидация и сабмит видели актуальное значение.
  if (addressPanel) {
    addressPanel.addEventListener('input', syncAddressHidden);
  }

  /* Shipping cost: Евро-Почта = +5 BYN, СДЭК = dynamic from plugin */
  var shippingCosts = { europost: 5 };
  function updateShippingCost() {
    var shipping = getSelectedShipping();
    var costRow = document.getElementById('podval-shipping-cost-row');
    var totalEl = document.getElementById('podval-total-display');
    var costValEl = document.getElementById('podval-shipping-cost-value');
    var costLabelEl = document.getElementById('podval-shipping-cost-label');

    // For CDEK, cost is managed by podval-cdek plugin JS — don't override
    if (shipping === 'cdek') {
      // Read cost from plugin hidden field if available
      var cdekCostInput = document.getElementById('podval-cdek-cost-input');
      var cdekCost = cdekCostInput ? parseFloat(cdekCostInput.value) || 0 : 0;

      // Mirror CDEK cost into our own hidden input with a known name so it goes to POST
      var cdekMirror = document.getElementById('podval-cdek-cost-hidden');
      if (cdekMirror) cdekMirror.value = cdekCost;

      if (costRow) costRow.style.display = cdekCost > 0 ? 'flex' : 'none';
      if (costLabelEl) costLabelEl.textContent = 'СДЭК доставка';
      if (costValEl && cdekCost > 0) {
        costValEl.setAttribute('data-orig-byn', cdekCost);
        costValEl.textContent = cdekCost.toFixed(2).replace('.', ',') + ' Br';
      }
      if (totalEl && typeof podvalCartBase === 'number') {
        var total = podvalCartBase + cdekCost;
        totalEl.setAttribute('data-orig-byn', total);
        totalEl.innerHTML = total.toFixed(2).replace('.', ',') + '&nbsp;Br';
      }
      return;
    }

    // Non-CDEK — reset mirror to 0 so stale value doesn't leak into POST
    var cdekMirrorReset = document.getElementById('podval-cdek-cost-hidden');
    if (cdekMirrorReset) cdekMirrorReset.value = 0;

    var extra = shippingCosts[shipping] || 0;

    if (costRow) costRow.style.display = extra > 0 ? 'flex' : 'none';
    if (costLabelEl) costLabelEl.textContent = 'Оплата посылки';

    // Update shipping cost value with data-orig-byn for currency switcher
    if (costValEl && extra > 0) {
      costValEl.setAttribute('data-orig-byn', extra);
      costValEl.textContent = extra.toFixed(2).replace('.', ',') + ' Br';
    }

    if (totalEl && typeof podvalCartBase === 'number') {
      var total = podvalCartBase + extra;
      // Store BYN value for currency switcher, then format
      totalEl.setAttribute('data-orig-byn', total);
      totalEl.innerHTML = total.toFixed(2).replace('.', ',') + '&nbsp;Br';
    }
  }

  // Click on shipping group
  shippingGroup.addEventListener('change', function() {
    updateSelectGroup(shippingGroup);
    updatePaymentVisibility();
    updateShippingCost();
    // Update preview text & auto-collapse on mobile
    var sel = shippingGroup.querySelector('.podval-co__select-card.is-selected .podval-co__select-title');
    var prev = document.getElementById('podval-shipping-preview');
    if (sel && prev) prev.textContent = sel.textContent;
    if (window.innerWidth <= 900) {
      var sec = document.querySelector('.podval-co__section--shipping');
      if (sec) sec.classList.remove('is-expanded');
    }
    // updateAddressField — после свёртывания секции, чтобы скролл к панели
    // считался от уже схлопнутого положения и не было «прыжка».
    updateAddressField();
  });

  // Click on payment group
  paymentGroup.addEventListener('change', function() {
    updateSelectGroup(paymentGroup);
    // Update preview text & auto-collapse on mobile
    updatePaymentPreview();
    if (window.innerWidth <= 900) {
      var sec = document.querySelector('.podval-co__section--payment');
      if (sec) sec.classList.remove('is-expanded');
    }
  });

  // Init
  updateSelectGroup(shippingGroup);
  updatePaymentVisibility();
  updateAddressField();
  updateShippingCost();

  // Expose updateShippingCost for CDEK plugin integration
  // When the CDEK plugin calculates delivery cost, it triggers this to update totals
  window.podvalUpdateShippingCost = updateShippingCost;

  // Observe CDEK cost hidden field changes (plugin updates it after calculation)
  var cdekCostInput = document.getElementById('podval-cdek-cost-input');
  if (cdekCostInput) {
    // MutationObserver on value attribute
    var cdekObserver = new MutationObserver(function() {
      if (getSelectedShipping() === 'cdek') updateShippingCost();
    });
    cdekObserver.observe(cdekCostInput, { attributes: true, attributeFilter: ['value'] });

    // Also listen for input events (programmatic .value = x won't trigger mutation, but dispatched events will)
    cdekCostInput.addEventListener('change', function() {
      if (getSelectedShipping() === 'cdek') updateShippingCost();
    });
  }

  // Last-chance sync on form submit: make sure podval-cdek-cost-hidden has the current value
  // (covers the case where plugin updated the cost but updateShippingCost was not re-triggered)
  var checkoutForm = document.querySelector('form.checkout, form#podval-checkout-form, form.woocommerce-checkout');
  if (checkoutForm) {
    checkoutForm.addEventListener('submit', function() {
      if (getSelectedShipping() !== 'cdek') return;
      var pluginInput = document.getElementById('podval-cdek-cost-input');
      var mirror      = document.getElementById('podval-cdek-cost-hidden');
      if (pluginInput && mirror) {
        mirror.value = parseFloat(pluginInput.value) || 0;
      }
    }, true);
  }
})();

/* ═══════════════════════════════════════════
   CHECKOUT — File upload (print images)
   ═══════════════════════════════════════════ */
(function() {
  var zone      = document.getElementById('podval-upload-zone');
  var input     = document.getElementById('podval-file-input');
  var list      = document.getElementById('podval-upload-list');
  var placeholder = document.getElementById('podval-upload-placeholder');
  if (!zone || !input || !list) return;

  var maxSize = 25 * 1024 * 1024; // 25 MB
  var allowedTypes = ['image/jpeg','image/png','image/webp','image/tiff','image/bmp','image/svg+xml',
                      'application/pdf','application/postscript','image/vnd.adobe.photoshop',
                      'application/x-photoshop','application/octet-stream'];
  var allowedExts = ['jpg','jpeg','png','webp','tiff','tif','bmp','svg','pdf','ai','eps','psd'];
  var uploadedIds = []; // WP attachment IDs

  // Click to open file picker
  zone.addEventListener('click', function(e) {
    if (e.target.closest('.podval-co__file-remove')) return;
    input.click();
  });

  // File input change
  input.addEventListener('change', function() {
    handleFiles(input.files);
    input.value = '';
  });

  // Drag & drop
  zone.addEventListener('dragover', function(e) { e.preventDefault(); zone.classList.add('is-dragover'); });
  zone.addEventListener('dragleave', function() { zone.classList.remove('is-dragover'); });
  zone.addEventListener('drop', function(e) {
    e.preventDefault();
    zone.classList.remove('is-dragover');
    handleFiles(e.dataTransfer.files);
  });

  function getExt(name) {
    return (name || '').split('.').pop().toLowerCase();
  }

  function formatSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' КБ';
    return (bytes / (1024 * 1024)).toFixed(1) + ' МБ';
  }

  function handleFiles(files) {
    for (var i = 0; i < files.length; i++) {
      var file = files[i];
      var ext = getExt(file.name);

      if (allowedExts.indexOf(ext) === -1) {
        alert('Формат .' + ext + ' не поддерживается');
        continue;
      }
      if (file.size > maxSize) {
        alert(file.name + ' превышает 25 МБ');
        continue;
      }

      addFileItem(file);
    }
  }

  function addFileItem(file) {
    var item = document.createElement('div');
    item.className = 'podval-co__file-item';

    // Thumbnail
    var thumbHtml = '';
    if (file.type.indexOf('image/') === 0 && file.type !== 'image/svg+xml') {
      thumbHtml = '<img class="podval-co__file-thumb" src="" alt="">';
    } else {
      var ext = getExt(file.name);
      thumbHtml = '<div class="podval-co__file-thumb-placeholder">' + ext + '</div>';
    }

    item.innerHTML = thumbHtml +
      '<div class="podval-co__file-info">' +
        '<span class="podval-co__file-name">' + escHtml(file.name) + '</span>' +
        '<span class="podval-co__file-size">' + formatSize(file.size) + '</span>' +
      '</div>' +
      '<span class="podval-co__file-status is-uploading">Загрузка...</span>' +
      '<button type="button" class="podval-co__file-remove" aria-label="Удалить">' +
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M18 6L6 18M6 6l12 12"/></svg>' +
      '</button>';

    list.appendChild(item);

    // Read thumbnail
    var thumbEl = item.querySelector('.podval-co__file-thumb');
    if (thumbEl && file.type.indexOf('image/') === 0) {
      var reader = new FileReader();
      reader.onload = function(e) { thumbEl.src = e.target.result; };
      reader.readAsDataURL(file);
    }

    // Remove button
    item.querySelector('.podval-co__file-remove').addEventListener('click', function(e) {
      e.stopPropagation();
      var hiddenInput = item.querySelector('input[type="hidden"]');
      if (hiddenInput) {
        var id = parseInt(hiddenInput.value);
        uploadedIds = uploadedIds.filter(function(x) { return x !== id; });
      }
      item.remove();
    });

    // Upload via AJAX
    uploadFile(file, item);
  }

  function uploadFile(file, item) {
    var formData = new FormData();
    formData.append('action', 'podval_upload_print');
    formData.append('podval_print_file', file);
    formData.append('_wpnonce', (window.PodvalStore && PodvalStore.nonce) || '');

    var xhr = new XMLHttpRequest();
    xhr.open('POST', (window.PodvalStore && PodvalStore.ajaxUrl) || '/wp-admin/admin-ajax.php');
    xhr.onload = function() {
      var status = item.querySelector('.podval-co__file-status');
      try {
        var resp = JSON.parse(xhr.responseText);
        if (resp.success && resp.data && resp.data.id) {
          status.textContent = '✓';
          status.className = 'podval-co__file-status is-done';
          uploadedIds.push(resp.data.id);
          // Add hidden input for form submission
          var hidden = document.createElement('input');
          hidden.type = 'hidden';
          hidden.name = 'podval_print_files[]';
          hidden.value = resp.data.id;
          item.appendChild(hidden);
        } else {
          status.textContent = 'Ошибка';
          status.className = 'podval-co__file-status is-error';
        }
      } catch(e) {
        status.textContent = 'Ошибка';
        status.className = 'podval-co__file-status is-error';
      }
    };
    xhr.onerror = function() {
      var status = item.querySelector('.podval-co__file-status');
      status.textContent = 'Ошибка сети';
      status.className = 'podval-co__file-status is-error';
    };
    xhr.send(formData);
  }

  function escHtml(str) {
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }
})();

/* ═══════════════════════════════════════════
   CHECKOUT — Field validation
   ═══════════════════════════════════════════ */
(function() {
  var form = document.querySelector('.woocommerce-checkout');
  if (!form) return;

  var nameInput      = document.getElementById('billing_first_name');
  var phoneInput     = document.getElementById('billing_phone');
  var messengerInput = document.getElementById('billing_messenger');
  var emailInput     = document.getElementById('billing_email');
  var commentInput   = document.getElementById('order_comments');

  var errName      = document.getElementById('err-name');
  var errPhone     = document.getElementById('err-phone');
  var errMessenger = document.getElementById('err-messenger');
  var errEmail     = document.getElementById('err-email');

  function showError(input, errEl, msg) {
    if (input) input.classList.add('is-invalid');
    if (errEl) { errEl.textContent = msg; errEl.classList.add('is-visible'); }
  }

  function clearError(input, errEl) {
    if (input) input.classList.remove('is-invalid');
    if (errEl) { errEl.textContent = ''; errEl.classList.remove('is-visible'); }
  }

  // Phone validation — BY: +375xxxxxxxxx, RU: +7xxxxxxxxxx (required)
  var phoneRegex = /^(\+375\d{9}|\+7\d{10}|375\d{9}|80\d{9})$/;

  function validatePhone() {
    if (!phoneInput) return true;
    var val = phoneInput.value.replace(/[\s\-\(\)]/g, '');
    if (val === '') {
      showError(phoneInput, errPhone, 'Введите номер телефона');
      return false;
    }
    if (!phoneRegex.test(val)) {
      showError(phoneInput, errPhone, 'Введите белорусский (+375) или российский (+7) номер');
      return false;
    }
    clearError(phoneInput, errPhone);
    return true;
  }

  // Messenger — no cyrillic
  var cyrillicRegex = /[\u0400-\u04FF]/;

  function validateMessenger() {
    if (!messengerInput) return true;
    var val = messengerInput.value.trim();
    if (val === '') {
      showError(messengerInput, errMessenger, 'Укажите Telegram');
      return false;
    }
    if (cyrillicRegex.test(val)) {
      showError(messengerInput, errMessenger, 'Используйте латиницу: @username или номер телефона');
      return false;
    }
    clearError(messengerInput, errMessenger);
    return true;
  }

  // Block cyrillic input in messenger field in real-time
  if (messengerInput) {
    messengerInput.addEventListener('input', function() {
      if (cyrillicRegex.test(this.value)) {
        this.value = this.value.replace(/[\u0400-\u04FF]/g, '');
        showError(messengerInput, errMessenger, 'Кириллица не допускается — используйте @username или номер');
      } else {
        clearError(messengerInput, errMessenger);
      }
    });
  }

  // Name validation
  function validateName() {
    if (!nameInput) return true;
    if (nameInput.value.trim() === '') {
      showError(nameInput, errName, 'Введите ваше имя');
      return false;
    }
    clearError(nameInput, errName);
    return true;
  }

  // Email validation
  var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  function validateEmail() {
    if (!emailInput) return true;
    var val = emailInput.value.trim();
    if (val === '') { clearError(emailInput, errEmail); return true; } // необязательное
    if (!emailRegex.test(val)) {
      showError(emailInput, errEmail, 'Введите корректный e-mail адрес');
      return false;
    }
    clearError(emailInput, errEmail);
    return true;
  }

  // Comment sanitization — strip HTML/script tags
  if (commentInput) {
    commentInput.addEventListener('input', function() {
      // Remove any HTML tags and potential injection patterns
      this.value = this.value
        .replace(/<[^>]*>/g, '')           // HTML tags
        .replace(/javascript:/gi, '')       // javascript: protocol
        .replace(/on\w+\s*=/gi, '')         // event handlers
        .replace(/\{\{.*?\}\}/g, '')        // template injections
        .replace(/\$\{.*?\}/g, '');         // template literals
    });
  }

  // Validate on blur
  if (nameInput)      nameInput.addEventListener('blur', validateName);
  if (phoneInput)     phoneInput.addEventListener('blur', validatePhone);
  if (messengerInput) messengerInput.addEventListener('blur', validateMessenger);
  if (emailInput)     emailInput.addEventListener('blur', validateEmail);

  // Validate on form submit (WC AJAX intercepts, but we validate before)
  form.addEventListener('submit', function(e) {
    var valid = true;
    if (!validateName()) valid = false;
    if (!validateMessenger()) valid = false;
    if (!validatePhone()) valid = false;
    if (!validateEmail()) valid = false;

    if (!valid) {
      e.preventDefault();
      e.stopImmediatePropagation();
      // Scroll to first error
      var firstErr = form.querySelector('.is-invalid');
      if (firstErr) firstErr.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  }, true); // capture phase — before WC JS
})();

/* ═══════════════════════════════════════════
   SPINNER — checkout & add-to-cart
   ═══════════════════════════════════════════ */
(function() {
  // Checkout spinner overlay
  var checkoutForm = document.querySelector('form.woocommerce-checkout');
  if (checkoutForm) {
    // WC fires checkout_place_order event before AJAX
    document.body.addEventListener('submit', function(e) {
      if (!e.target.closest('form.woocommerce-checkout')) return;
      showCheckoutSpinner();
    });

    // Also listen for WC AJAX events
    if (typeof jQuery !== 'undefined') {
      jQuery(document.body).on('checkout_error', hideCheckoutSpinner);
      jQuery(checkoutForm).on('checkout_place_order', function() {
        showCheckoutSpinner();
        return true;
      });
    }
  }

  function showCheckoutSpinner() {
    if (document.getElementById('podval-checkout-spinner')) return;
    var overlay = document.createElement('div');
    overlay.id = 'podval-checkout-spinner';
    overlay.className = 'podval-co__spinner-overlay';
    overlay.innerHTML = '<div class="podval-co__spinner"></div><div class="podval-co__spinner-text">Оформляем заказ...</div>';
    document.body.appendChild(overlay);
  }

  function hideCheckoutSpinner() {
    var el = document.getElementById('podval-checkout-spinner');
    if (el) el.remove();
  }

  // Hide spinner if WC shows errors (page scroll to notices)
  var observer = new MutationObserver(function(mutations) {
    mutations.forEach(function(m) {
      m.addedNodes.forEach(function(node) {
        if (node.nodeType === 1 && (node.classList.contains('woocommerce-error') || node.classList.contains('woocommerce-NoticeGroup'))) {
          hideCheckoutSpinner();
        }
      });
    });
  });
  if (checkoutForm) {
    observer.observe(document.body, { childList: true, subtree: true });
  }
})();

/* ═══════════════════════════════════════════
   COMPACT CHECKOUT — collapsibles & toggles
   ═══════════════════════════════════════════ */
(function() {
  // Generic collapsible toggle
  function initCollapsible(toggleId, collapseId) {
    var toggle = document.getElementById(toggleId);
    var wrap   = document.getElementById(collapseId);
    if (!toggle || !wrap) return;
    toggle.addEventListener('click', function() {
      wrap.classList.toggle('is-open');
    });
  }

  initCollapsible('podval-upload-toggle', 'podval-upload-collapse');
  initCollapsible('podval-comment-toggle', 'podval-comment-collapse');

  // Section toggles (shipping, payment) — mobile only behavior
  function initSectionToggle(toggleId, sectionSelector) {
    var toggle = document.getElementById(toggleId);
    var section = document.querySelector(sectionSelector);
    if (!toggle || !section) return;
    toggle.addEventListener('click', function() {
      if (window.innerWidth <= 900) {
        section.classList.toggle('is-expanded');
      }
    });
  }

  initSectionToggle('podval-shipping-toggle', '.podval-co__section--shipping');
  initSectionToggle('podval-payment-toggle', '.podval-co__section--payment');

  // Summary toggle — mobile only
  var summaryToggle = document.getElementById('podval-summary-toggle');
  var summary = summaryToggle ? summaryToggle.closest('.podval-co__summary') : null;
  if (summaryToggle && summary) {
    summaryToggle.addEventListener('click', function() {
      if (window.innerWidth <= 900) {
        summary.classList.toggle('is-expanded');
      }
    });
  }
})();

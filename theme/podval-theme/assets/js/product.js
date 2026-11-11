/* =============================================
   СТРАНИЦА ТОВАРА — галерея + аккордеон + размеры
   ============================================= */
(function() {
  // ── Галерея: переключение фото ──
  var mainPhoto = document.getElementById('sp-main-photo');
  if (mainPhoto) {
    document.querySelectorAll('.sp-thumb').forEach(function(thumb) {
      function activate() {
        var large = thumb.dataset.large;
        if (!large) return;
        // Не mainPhoto.src напрямую: EWWW заворачивает картинку в
        // <picture>, и тогда кадр диктует <source>, а не src.
        window.podvalSwapImage(mainPhoto, large);
        document.querySelectorAll('.sp-thumb').forEach(function(t) { t.classList.remove('is-active'); });
        thumb.classList.add('is-active');
      }
      thumb.addEventListener('click', activate);
      thumb.addEventListener('mouseenter', activate);
    });
  }

  // ── Аккордеон на странице товара ──
  document.querySelectorAll('.js-acc-trigger').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var body = btn.nextElementSibling;
      if (!body) return;
      var isOpen = body.classList.contains('is-open');
      // Закрываем все
      document.querySelectorAll('.sp-acc-body.is-open').forEach(function(b) { b.classList.remove('is-open'); });
      document.querySelectorAll('.sp-acc-trigger.is-open').forEach(function(b) { b.classList.remove('is-open'); });
      if (!isOpen) {
        body.classList.add('is-open');
        btn.classList.add('is-open');
      }
    });
  });

  // ── Выбор атрибутов вариаций + обновление цены ──
  // window.podvalVariations = { varId: {price_html, image, ...}, ... }
  // window.podvalVariationAttrs = { varId: {'attribute_pa_size': 's', 'attribute_pa_model': 'oversajz'}, ... }

  var priceEl = document.getElementById('sp-price');
  var variations     = window.podvalVariations     || {};
  var variationAttrs = window.podvalVariationAttrs || {};
  var selectedByGroup = {}; // { 'attribute_pa_model': 'oversajz-premium', 'attribute_pa_size': 'm' }

  // Декодируем все ключи в variationAttrs (убираем URL-encoding)
  function decodeAttrs(attrs) {
    var result = {};
    for (var k in attrs) {
      var dk = decodeURIComponent(k).toLowerCase();
      result[dk] = (attrs[k] || '').toLowerCase().trim();
      // Также добавляем sanitized версию ключа для кастомных атрибутов
      var sk = sanitizeKey(dk);
      if (sk !== dk) result[sk] = result[dk];
    }
    return result;
  }

  // Аналог WP sanitize_title: пробелы и спецсимволы → дефисы, lowercase
  function sanitizeKey(str) {
    return str
      .toLowerCase()
      .replace(/[^a-zа-яёa-z0-9_\-]/gi, '-')  // спецсимволы → дефис
      .replace(/-+/g, '-')                       // множественные дефисы → один
      .replace(/^-|-$/g, '');                     // убираем крайние дефисы
  }

  function findVariationBySelected() {
    var selKeys = Object.keys(selectedByGroup);
    if (!selKeys.length) return null;

    for (var varId in variationAttrs) {
      var decoded = decodeAttrs(variationAttrs[varId]);
      var match = true;

      for (var i = 0; i < selKeys.length; i++) {
        var selKey   = selKeys[i].toLowerCase();
        var selVal   = (selectedByGroup[selKeys[i]] || '').toLowerCase().trim();

        // Ищем значение по точному ключу или по sanitized версии
        var attrVal = decoded[selKey];
        if (attrVal === undefined) {
          attrVal = decoded[sanitizeKey(selKey)];
        }
        // Ещё fallback: ищем среди всех ключей decoded по sanitized совпадению
        if (attrVal === undefined) {
          var selSanitized = sanitizeKey(selKey);
          for (var dk in decoded) {
            if (sanitizeKey(dk) === selSanitized) {
              attrVal = decoded[dk];
              break;
            }
          }
        }

        if (attrVal === undefined) { match = false; break; }
        // '' в вариации = любое значение подходит (Any)
        if (attrVal !== '' && attrVal !== selVal && sanitizeKey(attrVal) !== sanitizeKey(selVal)) {
          match = false; break;
        }
      }

      if (match) return varId;
    }
    return null;
  }

  function updateVariationUI() {
    var varId = findVariationBySelected();
    var form  = document.querySelector('.podval-add-to-cart-form');
    var addBtn = form ? form.querySelector('.sp-add-btn') : null;
    var hiddenVar = form ? form.querySelector('.js-selected-variation') : null;
    var stickyPrice = document.getElementById('sp-sticky-price');

    if (varId && variations[varId]) {
      var v = variations[varId];
      // Цена
      if (priceEl) priceEl.innerHTML = v.price_html;
      if (stickyPrice) stickyPrice.innerHTML = v.price_html;
      // Фото
      if (v.image) {
        var mainPhoto = document.getElementById('sp-main-photo');
        if (mainPhoto) window.podvalSwapImage(mainPhoto, v.image);
      }
      // Кнопка
      if (hiddenVar) hiddenVar.value = varId;
      var isCustom = !!document.getElementById('sp-custom-hint');
      if (addBtn) { addBtn.textContent = isCustom ? 'Продолжить — загрузить изображения' : 'В корзину'; addBtn.disabled = false; }
    } else {
      // Не все атрибуты выбраны — сбрасываем кнопку
      var isCustom2 = !!document.getElementById('sp-custom-hint');
      if (addBtn) { addBtn.textContent = isCustom2 ? 'Выберите параметры' : 'Выберите размер'; addBtn.disabled = true; }
      if (hiddenVar) hiddenVar.value = '';
    }
  }

  document.querySelectorAll('.sp-size-btn').forEach(function(btn) {
    if (btn.disabled) return;
    // Выбор изделия для конструктора использует те же классы ради единого
    // вида, но вариаций WC у него нет: у кнопок нет data-attr-group.
    // Без этой проверки обработчик считал группой 'default', снять
    // выделение по несуществующему селектору не мог и подсвечивал все
    // нажатые кнопки разом. Их логику ведёт скрипт на самой карточке.
    if (!btn.dataset.attrGroup) return;
    btn.addEventListener('click', function() {
      var group   = btn.dataset.attrGroup || btn.dataset.attrKey || 'default';
      var attrKey = group.toLowerCase();
      if (attrKey.indexOf('attribute_') !== 0) attrKey = 'attribute_' + attrKey;

      // Снимаем выделение в своей группе
      document.querySelectorAll('.sp-size-btn[data-attr-group="' + group + '"]')
        .forEach(function(b) { b.classList.remove('is-selected'); });
      btn.classList.add('is-selected');

      // Сохраняем значение в нижнем регистре
      selectedByGroup[attrKey] = (btn.dataset.attrVal || '').toLowerCase().trim();

      // Color name display
      if (btn.dataset.colorName) {
        var nameEl = document.getElementById('sp-color-name');
        if (nameEl) nameEl.textContent = btn.dataset.colorName;
      }

      updateVariationUI();
    });
  });

  // ── Sticky price bar (mobile) ──
  (function() {
    var bar     = document.getElementById('sp-sticky-bar');
    var actions = document.querySelector('.sp-actions');
    var stickyBtn = document.getElementById('sp-sticky-btn');
    if (!bar || !actions) return;

    var actionsVisible = false;
    var labelContinue = stickyBtn ? (stickyBtn.getAttribute('data-label-continue') || stickyBtn.textContent.trim()) : '';
    var labelChoose   = stickyBtn ? (stickyBtn.getAttribute('data-label-choose')   || labelContinue)               : '';

    function updateStickyLabel() {
      if (!stickyBtn) return;
      var mainBtn = document.querySelector('.sp-actions .sp-add-btn');
      if (!mainBtn) return;
      // Label is "Choose" when user hasn't scrolled to params yet OR main button is disabled
      var needsChoose = !actionsVisible || mainBtn.disabled;
      stickyBtn.textContent = needsChoose ? labelChoose : labelContinue;
    }

    // Show/hide based on whether .sp-actions is visible
    if ('IntersectionObserver' in window) {
      var obs = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
          actionsVisible = entry.isIntersecting;
          if (entry.isIntersecting) {
            bar.classList.remove('is-visible');
          } else {
            bar.classList.add('is-visible');
          }
          updateStickyLabel();
        });
      }, { threshold: 0 });
      obs.observe(actions);
    }

    // Sticky bar button
    if (stickyBtn) {
      // Initial label + react to variation changes
      updateStickyLabel();
      document.addEventListener('click', function(e) {
        if (e.target.closest('.sp-attr-btn, .sp-color-btn, .sp-add-btn')) {
          setTimeout(updateStickyLabel, 50);
        }
      });

      stickyBtn.addEventListener('click', function() {
        var mainBtn = document.querySelector('.sp-actions .sp-add-btn');
        // If params block isn't in view — always scroll to it first (so user sees the choices).
        // Only bypass to main button when .sp-actions is already visible and variation is ready.
        if (actionsVisible && mainBtn && !mainBtn.disabled) {
          mainBtn.click();
          return;
        }
        // Scroll to title so price + params are visible under header
        var target = document.querySelector('.sp-title') || document.querySelector('.sp-details') || document.querySelector('.sp-section');
        if (!target) return;
        var headerH = 60;
        var rect = target.getBoundingClientRect();
        var top = window.pageYOffset + rect.top - headerH - 12;
        window.scrollTo({ top: top, behavior: 'smooth' });
      });
    }
  })();
})();


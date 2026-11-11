/* ═══════════════════════════════════════════
   SERVICE FORM — delivery toggle & upload
   ═══════════════════════════════════════════ */
(function() {
  // Delivery address toggle
  var radios = document.querySelectorAll('input[name="svc_delivery"]');
  var addrDiv = document.getElementById('svc-delivery-addr');
  if (radios.length && addrDiv) {
    radios.forEach(function(r) {
      r.addEventListener('change', function() {
        addrDiv.style.display = this.value === 'delivery' ? 'block' : 'none';
      });
    });
  }

  // Service upload zone (reuse checkout upload logic)
  var svcZone = document.getElementById('podval-svc-upload-zone');
  var svcInput = document.getElementById('podval-svc-file-input');
  var svcList = document.getElementById('podval-svc-upload-list');
  if (svcZone && svcInput) {
    svcZone.addEventListener('click', function(e) {
      if (e.target.closest('.podval-co__upload-file-remove')) return;
      svcInput.click();
    });
    svcZone.addEventListener('dragover', function(e) { e.preventDefault(); svcZone.classList.add('is-dragover'); });
    svcZone.addEventListener('dragleave', function() { svcZone.classList.remove('is-dragover'); });
    svcZone.addEventListener('drop', function(e) {
      e.preventDefault(); svcZone.classList.remove('is-dragover');
      if (e.dataTransfer.files.length) handleSvcFiles(e.dataTransfer.files);
    });
    svcInput.addEventListener('change', function() {
      if (this.files.length) handleSvcFiles(this.files);
    });
  }

  function svcEscHtml(str) {
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  function svcFormatSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' КБ';
    return (bytes / (1024 * 1024)).toFixed(1) + ' МБ';
  }

  function svcGetExt(name) {
    return (name || '').split('.').pop().toLowerCase();
  }

  function handleSvcFiles(files) {
    var ajaxUrl = (window.PodvalStore && PodvalStore.ajaxUrl) || '/wp-admin/admin-ajax.php';
    var nonce   = (window.PodvalStore && PodvalStore.nonce)   || '';

    Array.from(files).forEach(function(file) {
      if (file.size > 25 * 1024 * 1024) { alert('Файл слишком большой (макс 25 МБ)'); return; }

      var safeName = svcEscHtml(file.name);
      var ext = svcGetExt(file.name);
      var isImage = file.type && file.type.indexOf('image/') === 0 && file.type !== 'image/svg+xml';

      // Build file item with preview
      var item = document.createElement('div');
      item.className = 'podval-svc__file-item';

      var thumbHtml = isImage
        ? '<img class="podval-svc__file-thumb" src="" alt="">'
        : '<div class="podval-svc__file-thumb-placeholder">' + svcEscHtml(ext) + '</div>';

      item.innerHTML = thumbHtml +
        '<div class="podval-svc__file-info">' +
          '<span class="podval-svc__file-name">' + safeName + '</span>' +
          '<span class="podval-svc__file-size">' + svcFormatSize(file.size) + '</span>' +
        '</div>' +
        '<span class="podval-svc__file-status is-uploading">Загрузка...</span>' +
        '<button type="button" class="podval-svc__file-remove" aria-label="Удалить">' +
          '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="16" height="16"><path d="M18 6L6 18M6 6l12 12"/></svg>' +
        '</button>';

      if (svcList) svcList.appendChild(item);

      // Read local preview for images
      var thumbEl = item.querySelector('.podval-svc__file-thumb');
      if (thumbEl && isImage) {
        var reader = new FileReader();
        reader.onload = function(e) { thumbEl.src = e.target.result; };
        reader.readAsDataURL(file);
      }

      // Remove button
      item.querySelector('.podval-svc__file-remove').addEventListener('click', function(e) {
        e.stopPropagation();
        item.remove();
      });

      // Upload
      var formData = new FormData();
      formData.append('action', 'podval_upload_print');
      formData.append('podval_print_file', file);
      formData.append('_wpnonce', nonce);

      console.log('[Upload] Starting upload:', file.name, file.type, file.size, 'nonce:', nonce, 'url:', ajaxUrl);

      fetch(ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(function(r) {
          console.log('[Upload] Response status:', r.status, r.statusText);
          return r.text();
        })
        .then(function(text) {
          console.log('[Upload] Raw response:', text);
          var resp;
          try { resp = JSON.parse(text); } catch(e) {
            console.error('[Upload] JSON parse error, raw:', text);
            var statusEl = item.querySelector('.podval-svc__file-status');
            statusEl.textContent = 'Ошибка сервера';
            statusEl.className = 'podval-svc__file-status is-error';
            return;
          }
          var statusEl = item.querySelector('.podval-svc__file-status');
          if (resp.success && resp.data && resp.data.id) {
            console.log('[Upload] Success! ID:', resp.data.id);
            statusEl.textContent = '✓';
            statusEl.className = 'podval-svc__file-status is-done';
            // Add hidden input
            var hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'podval_print_files[]';
            hidden.value = resp.data.id;
            item.appendChild(hidden);
            // Update thumb from server if available
            if (resp.data.thumb && thumbEl && thumbEl.tagName === 'IMG') {
              thumbEl.src = resp.data.thumb;
            }
          } else {
            var msg = (resp.data && resp.data.message) ? resp.data.message : 'Ошибка';
            console.error('[Upload Error]', resp);
            statusEl.textContent = svcEscHtml(msg);
            statusEl.className = 'podval-svc__file-status is-error';
          }
        })
        .catch(function(err) {
          console.error('[Upload Network Error]', err);
          var statusEl = item.querySelector('.podval-svc__file-status');
          statusEl.textContent = 'Ошибка сети';
          statusEl.className = 'podval-svc__file-status is-error';
        });
    });
  }
})();

/* ═══════════════════════════════════════════
   SERVICE FORM — Validation
   ═══════════════════════════════════════════ */
(function() {
  var form = document.querySelector('.podval-svc-form');
  if (!form) return;

  var nameInput      = form.querySelector('input[name="svc_name"]');
  var phoneInput     = form.querySelector('input[name="svc_phone"]');
  var messengerInput = form.querySelector('input[name="svc_messenger"]');
  var emailInput     = form.querySelector('input[name="svc_email"]');
  var commentInput   = form.querySelector('textarea[name="svc_comment"]');

  var phoneRegex = /^(\+375\d{9}|\+7\d{10}|375\d{9}|80\d{9})$/;
  var cyrillicRegex = /[\u0400-\u04FF]/;
  var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

  function getOrCreateError(input) {
    var err = input.parentNode.querySelector('.podval-co__field-error');
    if (!err) {
      err = document.createElement('span');
      err.className = 'podval-co__field-error';
      input.parentNode.appendChild(err);
    }
    return err;
  }

  function showErr(input, msg) {
    input.classList.add('is-invalid');
    var err = getOrCreateError(input);
    err.textContent = msg;
    err.classList.add('is-visible');
  }

  function clearErr(input) {
    input.classList.remove('is-invalid');
    var err = input.parentNode.querySelector('.podval-co__field-error');
    if (err) { err.textContent = ''; err.classList.remove('is-visible'); }
  }

  function validateName() {
    if (!nameInput) return true;
    if (nameInput.value.trim() === '') { showErr(nameInput, 'Введите ваше имя'); return false; }
    clearErr(nameInput); return true;
  }

  function validatePhone() {
    if (!phoneInput) return true;
    var val = phoneInput.value.replace(/[\s\-\(\)]/g, '');
    if (val === '') { showErr(phoneInput, 'Введите номер телефона'); return false; }
    if (!phoneRegex.test(val)) { showErr(phoneInput, 'Введите белорусский (+375) или российский (+7) номер'); return false; }
    clearErr(phoneInput); return true;
  }

  function validateMessenger() {
    if (!messengerInput) return true;
    var val = messengerInput.value.trim();
    if (val === '') { showErr(messengerInput, 'Укажите Telegram'); return false; }
    if (cyrillicRegex.test(val)) { showErr(messengerInput, 'Используйте латиницу: @username или номер телефона'); return false; }
    clearErr(messengerInput); return true;
  }

  function validateEmail() {
    if (!emailInput) return true;
    var val = emailInput.value.trim();
    if (val === '') { clearErr(emailInput); return true; }
    if (!emailRegex.test(val)) { showErr(emailInput, 'Введите корректный e-mail адрес'); return false; }
    clearErr(emailInput); return true;
  }

  // Block cyrillic in messenger
  if (messengerInput) {
    messengerInput.addEventListener('input', function() {
      if (cyrillicRegex.test(this.value)) {
        this.value = this.value.replace(/[\u0400-\u04FF]/g, '');
        showErr(messengerInput, 'Кириллица не допускается — используйте @username или номер');
      } else {
        clearErr(messengerInput);
      }
    });
  }

  // Sanitize comment
  if (commentInput) {
    commentInput.addEventListener('input', function() {
      this.value = this.value
        .replace(/<[^>]*>/g, '')
        .replace(/javascript:/gi, '')
        .replace(/on\w+\s*=/gi, '')
        .replace(/\{\{.*?\}\}/g, '')
        .replace(/\$\{.*?\}/g, '');
    });
  }

  // Blur validation
  if (nameInput) nameInput.addEventListener('blur', validateName);
  if (phoneInput) phoneInput.addEventListener('blur', validatePhone);
  if (messengerInput) messengerInput.addEventListener('blur', validateMessenger);
  if (emailInput) emailInput.addEventListener('blur', validateEmail);

  // Submit validation
  form.addEventListener('submit', function(e) {
    var valid = true;
    if (!validateName()) valid = false;
    if (!validateMessenger()) valid = false;
    if (!validatePhone()) valid = false;
    if (!validateEmail()) valid = false;
    if (!valid) {
      e.preventDefault();
      var first = form.querySelector('.is-invalid');
      if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }
    // Defense-in-depth против двойного клика: блокируем кнопку после
    // первой валидной отправки. Серверная идемпотентность (svc_token)
    // — основная защита; это лишь UX-страховка.
    var btn = form.querySelector('button[type="submit"]');
    if (btn) {
      if (btn.dataset.submitting === '1') { e.preventDefault(); return; }
      btn.dataset.submitting = '1';
      btn.disabled = true;
      btn.style.opacity = '0.6';
      btn.style.pointerEvents = 'none';
      var orig = btn.textContent;
      btn.textContent = 'Отправляем...';
      // Страховка: если форма не ушла (ошибка), разблокируем через 8 сек.
      setTimeout(function() {
        if (btn.dataset.submitting === '1') {
          btn.disabled = false;
          btn.dataset.submitting = '';
          btn.style.opacity = '';
          btn.style.pointerEvents = '';
          btn.textContent = orig;
        }
      }, 8000);
    }
  });
})();

/* ═══════════════════════════════════════════
   SERVICE FORM — токен идемпотентности и свежие nonce

   P1. svc_token раньше печатался в PHP и попадал в кэш WP-Optimize одним
   на всех: второй посетитель в течение 15 минут получал thank-you первого.
   Теперь токен генерирует браузер при инициализации формы.

   P2. Nonce формы и PodvalStore.nonce (загрузка файлов) тоже лежат в кэше;
   гостевой nonce живёт 12–24 ч. При первом взаимодействии с формой берём
   свежие значения с admin-ajax.php?action=podval_sl_nonces (no-cache).
   ═══════════════════════════════════════════ */
(function() {
  var forms = document.querySelectorAll('.podval-svc-form');
  if (!forms.length) return;

  function makeToken() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
      return window.crypto.randomUUID().replace(/-/g, '');
    }
    var s = '';
    for (var i = 0; i < 4; i++) s += Math.random().toString(36).slice(2, 10);
    return s.slice(0, 32);
  }

  var refreshed = null; // Promise — один запрос на страницу

  function refreshNonces() {
    if (refreshed) return refreshed;
    var ajaxUrl = (window.PodvalStore && PodvalStore.ajaxUrl) || '/wp-admin/admin-ajax.php';
    refreshed = fetch(ajaxUrl + (ajaxUrl.indexOf('?') === -1 ? '?' : '&') + 'action=podval_sl_nonces&_=' + Date.now(), {
      credentials: 'same-origin',
      cache: 'no-store'
    })
      .then(function(r) { return r.json(); })
      .then(function(resp) {
        if (!resp || !resp.success || !resp.data) return false;
        forms.forEach(function(f) {
          var n = f.querySelector('input[name="_podval_svc_nonce"]');
          if (n && resp.data.svc) n.value = resp.data.svc;
        });
        if (resp.data.upload) {
          window.PodvalStore = window.PodvalStore || {};
          window.PodvalStore.nonce = resp.data.upload;
        }
        return true;
      })
      .catch(function() { return false; }); // WAF/сеть — остаёмся со старыми значениями
    return refreshed;
  }
  window.podvalRefreshServiceNonces = refreshNonces;

  forms.forEach(function(form) {
    var tok = form.querySelector('input[name="svc_token"]');
    if (tok && !tok.value) tok.value = makeToken();

    var once = function() {
      refreshNonces();
      form.removeEventListener('focusin', once);
      form.removeEventListener('pointerdown', once);
    };
    form.addEventListener('focusin', once);
    form.addEventListener('pointerdown', once);
  });

  // Зона загрузки файлов вне фокуса формы (drag-n-drop) — тоже освежаем.
  var zone = document.getElementById('podval-svc-upload-zone');
  if (zone) zone.addEventListener('dragenter', refreshNonces);
})();

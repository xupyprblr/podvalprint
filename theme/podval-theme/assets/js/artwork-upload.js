/* ═══════════════════════════════════════════
   ARTWORK UPLOAD — multi-slot file upload + extra files + add to cart / checkout
   added per-slot text input ("Добавить надпись") + top skip button
   dispatch 'podval:print_uploaded' CustomEvent on successful upload
             (listened in inc/analytics.php → Y.Metrika print_upload goal)
   multiline text, text alignment, in-modal image upload
              garment outline, save JPEG reference into extra_files + layout meta
   ═══════════════════════════════════════════ */
(function() {
  'use strict';

  var cfg = window.awConfig;
  if (!cfg) return;

  var maxSize    = 25 * 1024 * 1024;
  var allowedExts = ['jpg','jpeg','png','webp','tiff','tif','bmp','svg','pdf','ai','eps','psd'];

  // State: { front: {id, name}, back: {id, name}, sleeve: {id, name} }
  var uploads = {};

  // Text lines state: { front: 'text', back: 'text', sleeve: 'text' }
  var texts = {};

  // Extra files state: [ {id, name, url, tempId, size, progress, error}, ... ]
  var extraFiles = [];
  var extraCounter = 0;

  // ── Init each upload zone ──────────────────
  var slots = document.querySelectorAll('.aw-upload-zone');
  slots.forEach(function(zone) {
    var slotName = zone.id.replace('aw-zone-', '');
    initZone(zone, slotName);
  });

  // ── Init text-line toggles (one per slot) ──
  var textLines = document.querySelectorAll('.aw-textline');
  textLines.forEach(function(wrap) {
    initTextLine(wrap);
  });

  function initTextLine(wrap) {
    var slot   = wrap.getAttribute('data-slot');
    var toggle = wrap.querySelector('.aw-textline__toggle');
    var input  = wrap.querySelector('.aw-textline__input');
    if (!slot || !toggle || !input) return;

    toggle.addEventListener('click', function(e) {
      e.preventDefault();
      var isOpen = wrap.classList.toggle('is-open');
      if (isOpen) {
        // Focus input shortly after expand animation starts
        setTimeout(function() { input.focus(); }, 120);
      } else {
        // Clear text when collapsing (user unchecked the box → they don't want text)
        input.value = '';
        delete texts[slot];
        updateSummary();
      }
    });

    input.addEventListener('input', function() {
      var val = input.value.trim();
      if (val) {
        texts[slot] = val;
      } else {
        delete texts[slot];
      }
      updateSummary();
      // Flag the saved placement as stale if the live text now differs from the snapshot
      if (window.awPlacement && window.awPlacement.checkStale) window.awPlacement.checkStale(slot);
    });

    // Prevent click-bubbling from input field re-toggling the label
    input.addEventListener('click', function(e) { e.stopPropagation(); });
  }

  function initZone(zone, slot) {
    var input       = document.getElementById('aw-input-' + slot);
    var placeholder = document.getElementById('aw-placeholder-' + slot);
    var fileInfo    = document.getElementById('aw-file-' + slot);

    if (!input || !placeholder || !fileInfo) return;

    zone.addEventListener('click', function(e) {
      if (e.target.closest('.aw-file-remove')) return;
      input.click();
    });

    input.addEventListener('change', function() {
      if (input.files.length > 0) handleFile(input.files[0], slot);
      input.value = '';
    });

    zone.addEventListener('dragover', function(e) { e.preventDefault(); zone.classList.add('is-dragover'); });
    zone.addEventListener('dragleave', function() { zone.classList.remove('is-dragover'); });
    zone.addEventListener('drop', function(e) {
      e.preventDefault();
      zone.classList.remove('is-dragover');
      if (e.dataTransfer.files.length > 0) handleFile(e.dataTransfer.files[0], slot);
    });
  }

  function handleFile(file, slot) {
    var ext = getExt(file.name);
    if (allowedExts.indexOf(ext) === -1) {
      showToast('Формат .' + ext + ' не поддерживается');
      return;
    }
    if (file.size > maxSize) {
      showToast(file.name + ' превышает 25 МБ');
      return;
    }

    var zone        = document.getElementById('aw-zone-' + slot);
    var placeholder = document.getElementById('aw-placeholder-' + slot);
    var fileInfo    = document.getElementById('aw-file-' + slot);

    var thumbHtml = '';
    if (file.type.indexOf('image/') === 0 && file.type !== 'image/svg+xml') {
      thumbHtml = '<img class="aw-file__thumb" src="" alt="" id="aw-thumb-' + slot + '">';
    } else {
      thumbHtml = '<div class="aw-file__thumb-placeholder">' + escHtml(ext.toUpperCase()) + '</div>';
    }

    fileInfo.innerHTML =
      '<div class="aw-file__row">' +
        thumbHtml +
        '<div class="aw-file__details">' +
          '<span class="aw-file__name">' + escHtml(file.name) + '</span>' +
          '<span class="aw-file__size">' + formatSize(file.size) + '</span>' +
        '</div>' +
        '<span class="aw-file__status" id="aw-status-' + slot + '">Загрузка...</span>' +
        '<button type="button" class="aw-file-remove" data-slot="' + slot + '" aria-label="Удалить">' +
          '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M18 6L6 18M6 6l12 12"/></svg>' +
        '</button>' +
      '</div>';

    placeholder.style.display = 'none';
    fileInfo.style.display = 'block';
    zone.classList.add('has-file');

    var thumbEl = document.getElementById('aw-thumb-' + slot);
    if (thumbEl && file.type.indexOf('image/') === 0) {
      var reader = new FileReader();
      reader.onload = function(e) { thumbEl.src = e.target.result; };
      reader.readAsDataURL(file);
    }

    fileInfo.querySelector('.aw-file-remove').addEventListener('click', function(e) {
      e.stopPropagation();
      removeFile(slot);
    });

    uploadFile(file, slot);
  }

  function removeFile(slot) {
    delete uploads[slot];
    var zone        = document.getElementById('aw-zone-' + slot);
    var placeholder = document.getElementById('aw-placeholder-' + slot);
    var fileInfo    = document.getElementById('aw-file-' + slot);
    fileInfo.innerHTML = '';
    fileInfo.style.display = 'none';
    placeholder.style.display = '';
    zone.classList.remove('has-file');
    if (window.awPlacement && window.awPlacement.checkStale) window.awPlacement.checkStale(slot);
    updateSummary();
  }

  function uploadFile(file, slot) {
    var formData = new FormData();
    formData.append('action', 'podval_upload_print');
    formData.append('podval_print_file', file);
    formData.append('_wpnonce', cfg.nonce);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', cfg.ajaxUrl);

    xhr.upload.onprogress = function(e) {
      if (e.lengthComputable) {
        var pct = Math.round((e.loaded / e.total) * 100);
        var statusEl = document.getElementById('aw-status-' + slot);
        if (statusEl) statusEl.textContent = pct + '%';
      }
    };

    xhr.onload = function() {
      var statusEl = document.getElementById('aw-status-' + slot);
      try {
        var resp = JSON.parse(xhr.responseText);
        if (resp.success && resp.data && resp.data.id) {
          uploads[slot] = {
            id: resp.data.id,
            name: file.name,
            url: resp.data.url || '',
            // прежней версии. canPreview — браузер нарисует сам файл в <img>.
            // previewUrl — JPEG первой страницы, который сделал сервер для
            // PDF/AI/EPS/PSD/TIFF (пусто, если Imagick не смог).
            canPreview: !!resp.data.can_preview,
            previewUrl: resp.data.preview || ''
          };
          if (window.awPlacement && window.awPlacement.checkStale) window.awPlacement.checkStale(slot);
          if (statusEl) {
            statusEl.textContent = '✓';
            statusEl.className = 'aw-file__status is-done';
          }
          updateSummary();
          trackPrintUpload(slot, file);
        } else {
          if (statusEl) {
            statusEl.textContent = resp.data && resp.data.message ? resp.data.message : 'Ошибка';
            statusEl.className = 'aw-file__status is-error';
          }
        }
      } catch(e) {
        if (statusEl) { statusEl.textContent = 'Ошибка'; statusEl.className = 'aw-file__status is-error'; }
      }
    };

    xhr.onerror = function() {
      var statusEl = document.getElementById('aw-status-' + slot);
      if (statusEl) { statusEl.textContent = 'Ошибка сети'; statusEl.className = 'aw-file__status is-error'; }
    };

    xhr.send(formData);
  }

  // ── Extra files accordion ──────────────────
  var extraToggle  = document.getElementById('aw-extra-toggle');
  var extraCheck   = document.getElementById('aw-extra-check');
  var extraWrapper = extraToggle ? extraToggle.closest('.aw-extra') : null;
  var extraZone    = document.getElementById('aw-extra-zone');
  var extraInput   = document.getElementById('aw-input-extra');
  var extraList    = document.getElementById('aw-extra-list');
  var extraPlc     = document.getElementById('aw-extra-placeholder');

  if (extraToggle && extraWrapper) {
    extraToggle.addEventListener('click', function() {
      var isOpen = extraWrapper.classList.toggle('is-open');
      extraCheck.classList.toggle('is-checked', isOpen);
    });
  }

  if (extraZone && extraInput) {
    extraZone.addEventListener('click', function(e) {
      if (e.target.closest('.aw-extra__item-remove') || e.target.closest('.aw-extra__add-more')) return;
      extraInput.click();
    });

    extraInput.addEventListener('change', function() {
      for (var i = 0; i < extraInput.files.length; i++) {
        handleExtraFile(extraInput.files[i]);
      }
      extraInput.value = '';
    });

    extraZone.addEventListener('dragover', function(e) { e.preventDefault(); extraZone.classList.add('is-dragover'); });
    extraZone.addEventListener('dragleave', function() { extraZone.classList.remove('is-dragover'); });
    extraZone.addEventListener('drop', function(e) {
      e.preventDefault();
      extraZone.classList.remove('is-dragover');
      for (var i = 0; i < e.dataTransfer.files.length; i++) {
        handleExtraFile(e.dataTransfer.files[i]);
      }
    });
  }

  function handleExtraFile(file) {
    var ext = getExt(file.name);
    if (allowedExts.indexOf(ext) === -1) {
      showToast('Формат .' + ext + ' не поддерживается');
      return;
    }
    if (file.size > maxSize) {
      showToast(file.name + ' превышает 25 МБ');
      return;
    }

    var tempId = 'extra_' + (++extraCounter);
    extraFiles.push({ tempId: tempId, id: null, name: file.name, size: file.size, progress: null, error: false });
    renderExtraList();
    uploadExtraFile(file, tempId);
  }

  function renderExtraList() {
    if (!extraList || !extraPlc || !extraZone) return;

    if (extraFiles.length === 0) {
      extraList.innerHTML = '';
      extraPlc.style.display = '';
      extraZone.classList.remove('has-files');
      updateSummary();
      return;
    }

    extraPlc.style.display = 'none';
    extraZone.classList.add('has-files');

    var html = '';
    extraFiles.forEach(function(ef) {
      var statusClass = ef.id ? 'is-done' : (ef.error ? 'is-error' : '');
      var statusText  = ef.id ? '✓' : (ef.error ? 'Ошибка' : (ef.progress || 'Загрузка...'));
      html += '<div class="aw-extra__item" data-temp="' + ef.tempId + '">' +
        '<span class="aw-extra__item-name">' + escHtml(ef.name) + '</span>' +
        '<span class="aw-extra__item-size">' + formatSize(ef.size) + '</span>' +
        '<span class="aw-extra__item-status ' + statusClass + '">' + statusText + '</span>' +
        '<button type="button" class="aw-extra__item-remove" data-temp="' + ef.tempId + '" aria-label="Удалить">' +
          '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M18 6L6 18M6 6l12 12"/></svg>' +
        '</button>' +
      '</div>';
    });

    html += '<button type="button" class="aw-extra__add-more" id="aw-extra-add-more">' +
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="14" height="14"><path d="M12 5v14M5 12h14"/></svg>' +
      'Добавить ещё' +
    '</button>';

    extraList.innerHTML = html;

    extraList.querySelectorAll('.aw-extra__item-remove').forEach(function(btn) {
      btn.addEventListener('click', function(e) {
        e.stopPropagation();
        removeExtraFile(btn.getAttribute('data-temp'));
      });
    });

    var addMoreBtn = document.getElementById('aw-extra-add-more');
    if (addMoreBtn) {
      addMoreBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        extraInput.click();
      });
    }

    updateSummary();
  }

  function removeExtraFile(tempId) {
    extraFiles = extraFiles.filter(function(ef) { return ef.tempId !== tempId; });
    renderExtraList();
  }

  function uploadExtraFile(file, tempId) {
    var formData = new FormData();
    formData.append('action', 'podval_upload_print');
    formData.append('podval_print_file', file);
    formData.append('_wpnonce', cfg.nonce);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', cfg.ajaxUrl);

    xhr.upload.onprogress = function(e) {
      if (e.lengthComputable) {
        var pct = Math.round((e.loaded / e.total) * 100);
        var ef = findExtra(tempId);
        if (ef) { ef.progress = pct + '%'; renderExtraList(); }
      }
    };

    xhr.onload = function() {
      var ef = findExtra(tempId);
      if (!ef) return;
      try {
        var resp = JSON.parse(xhr.responseText);
        if (resp.success && resp.data && resp.data.id) {
          ef.id = resp.data.id;
          ef.url = resp.data.url || '';
          trackPrintUpload('extra', file);
        } else {
          ef.error = true;
        }
      } catch(e) {
        ef.error = true;
      }
      renderExtraList();
    };

    xhr.onerror = function() {
      var ef = findExtra(tempId);
      if (ef) { ef.error = true; renderExtraList(); }
    };

    xhr.send(formData);
  }

  function findExtra(tempId) {
    for (var i = 0; i < extraFiles.length; i++) {
      if (extraFiles[i].tempId === tempId) return extraFiles[i];
    }
    return null;
  }

  // ── Summary sidebar updates ────────────────
  function updateSummary() {
    var container = document.getElementById('aw-summary-files');
    var list      = document.getElementById('aw-summary-files-list');
    var keys = Object.keys(uploads);
    var textKeys = Object.keys(texts);
    var hasExtra = extraFiles.length > 0;

    if (keys.length === 0 && textKeys.length === 0 && !hasExtra) {
      container.style.display = 'none';
      return;
    }

    container.style.display = '';
    var slotLabels = { front: 'Перед', back: 'Спина', sleeve: 'Рукав' };
    var html = '';
    keys.forEach(function(key) {
      html += '<div class="aw-summary__file">' +
        '<span class="aw-summary__file-slot">' + (slotLabels[key] || key) + ':</span> ' +
        '<span class="aw-summary__file-name">' + escHtml(uploads[key].name) + '</span>' +
      '</div>';
    });
    textKeys.forEach(function(key) {
      var preview = texts[key];
      if (preview.length > 32) preview = preview.substring(0, 30) + '…';
      html += '<div class="aw-summary__file">' +
        '<span class="aw-summary__file-slot">' + (slotLabels[key] || key) + ' (надпись):</span> ' +
        '<span class="aw-summary__file-name">«' + escHtml(preview) + '»</span>' +
      '</div>';
    });
    if (hasExtra) {
      extraFiles.forEach(function(ef) {
        html += '<div class="aw-summary__file">' +
          '<span class="aw-summary__file-slot">Доп.:</span> ' +
          '<span class="aw-summary__file-name">' + escHtml(ef.name) + '</span>' +
        '</div>';
      });
    }
    list.innerHTML = html;
  }

  // ── Add to cart / Checkout ────────────────
  var checkoutBtn = document.getElementById('aw-checkout');
  var addBtn      = document.getElementById('aw-add-to-cart');
  var skipBtn     = document.getElementById('aw-skip-upload');
  var skipTopBtn  = document.getElementById('aw-skip-upload-top');

  if (checkoutBtn) {
    checkoutBtn.addEventListener('click', function() { doAddToCart(false, 'checkout'); });
  }
  if (addBtn) {
    addBtn.addEventListener('click', function() { doAddToCart(false, 'cart'); });
  }
  if (skipBtn) {
    skipBtn.addEventListener('click', function() { doAddToCart(true, 'checkout'); });
  }
  if (skipTopBtn) {
    skipTopBtn.addEventListener('click', function() { doAddToCart(true, 'checkout'); });
  }

  function doAddToCart(skipped, redirect) {
    var fileIds = {};
    ['front', 'back', 'sleeve'].forEach(function(slot) {
      if (uploads[slot]) fileIds[slot] = uploads[slot].id;
    });

    // Collect texts for submission
    var textSubmit = {};
    ['front', 'back', 'sleeve'].forEach(function(slot) {
      if (texts[slot] && texts[slot].trim()) {
        textSubmit[slot] = texts[slot].trim();
      }
    });

    var comment = (document.getElementById('aw-comment') || {}).value || '';

    // Validate: at least one file OR one text unless skipped or just adding to cart
    if (!skipped && redirect !== 'cart' && Object.keys(fileIds).length === 0 && Object.keys(textSubmit).length === 0) {
      showToast('Прикрепите файл или введите надпись для печати');
      return;
    }

    // Check if any extra files are still uploading
    var extraUploading = extraFiles.some(function(ef) { return !ef.id && !ef.error; });
    if (extraUploading) {
      showToast('Дождитесь загрузки всех файлов');
      return;
    }

    // Disable active button
    var activeBtn;
    if (redirect === 'checkout') {
      activeBtn = skipped ? (skipTopBtn || skipBtn) : checkoutBtn;
    } else {
      activeBtn = addBtn;
    }
    if (activeBtn) { activeBtn.classList.add('is-loading'); activeBtn.disabled = true; }

    var formData = new FormData();
    formData.append('action', 'podval_artwork_add_to_cart');
    formData.append('_wpnonce', cfg.nonce);
    formData.append('product_id', cfg.productId);
    formData.append('variation_id', cfg.variationId);
    formData.append('artwork_comment', comment);
    // Mark as skipped if user explicitly skipped OR if adding to cart without any files/text
    var isEffectivelySkipped = skipped || (redirect === 'cart' && Object.keys(fileIds).length === 0 && Object.keys(textSubmit).length === 0);
    formData.append('artwork_skipped', isEffectivelySkipped ? '1' : '0');

    Object.keys(cfg.attrs).forEach(function(k) {
      formData.append('attrs[' + k + ']', cfg.attrs[k]);
    });

    Object.keys(fileIds).forEach(function(slot) {
      formData.append('artwork_files[' + slot + ']', fileIds[slot]);
    });

    // Text lines per slot
    Object.keys(textSubmit).forEach(function(slot) {
      formData.append('artwork_texts[' + slot + ']', textSubmit[slot]);
    });

    // Extra files — send IDs as JSON array
    var extraIds = [];
    extraFiles.forEach(function(ef) {
      if (ef.id) extraIds.push(ef.id);
    });

    // Placement editor: schematic reference previews + layout meta.
    // Preview attachment IDs piggyback on extra_files so they flow into the
    // order with zero extra server storage logic; layout carries coordinates.
    if (window.awPlacement) {
      var pl = window.awPlacement.collect(); // { layout:{slot:{...}}, previewIds:{slot:id} }
      Object.keys(pl.previewIds).forEach(function(slot) {
        var pid = pl.previewIds[slot];
        if (pid && extraIds.indexOf(pid) === -1) extraIds.push(pid);
      });
      if (Object.keys(pl.layout).length) {
        formData.append('artwork_layout', JSON.stringify(pl.layout));
      }
    }

    if (extraIds.length > 0) {
      formData.append('artwork_extra_files', JSON.stringify(extraIds));
    }

    fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: formData })
    .then(function(r) { return r.json(); })
    .then(function(resp) {
      if (resp.success) {
        if (resp.data && resp.data.cart_count) {
          var countEls = document.querySelectorAll('.cart-count, .js-cart-count');
          countEls.forEach(function(el) { el.textContent = resp.data.cart_count; el.style.display = ''; });
        }
        // Fire add_to_cart analytics before redirect
        if (window.podvalAnalytics && window.podvalAnalytics.fireAddToCart) {
          window.podvalAnalytics.fireAddToCart();
        }
        window.location.href = (redirect === 'checkout') ? cfg.checkoutUrl : cfg.cartUrl;
      } else {
        showToast(resp.data && resp.data.message ? resp.data.message : 'Ошибка добавления в корзину');
        if (activeBtn) { activeBtn.classList.remove('is-loading'); activeBtn.disabled = false; }
      }
    })
    .catch(function(err) {
      showToast('Ошибка сети');
      if (activeBtn) { activeBtn.classList.remove('is-loading'); activeBtn.disabled = false; }
    });
  }

  // ── Helpers ────────────────────────────────
  function getExt(name) { return (name || '').split('.').pop().toLowerCase(); }

  function formatSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' КБ';
    return (bytes / (1024 * 1024)).toFixed(1) + ' МБ';
  }

  function escHtml(s) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(s));
    return d.innerHTML;
  }

  function showToast(msg) {
    var existing = document.querySelector('.aw-toast');
    if (existing) existing.remove();

    var toast = document.createElement('div');
    toast.className = 'aw-toast';
    toast.textContent = msg;
    document.body.appendChild(toast);
    requestAnimationFrame(function() { toast.classList.add('is-visible'); });
    setTimeout(function() {
      toast.classList.remove('is-visible');
      setTimeout(function() { toast.remove(); }, 300);
    }, 3500);
  }

  // ── Analytics: dispatch event for successful print upload ──
  // Listened in inc/analytics.php → reachGoal 'print_upload'
  function trackPrintUpload(slot, file) {
    try {
      var name = (file && file.name) ? String(file.name) : '';
      var dot  = name.lastIndexOf('.');
      var ext  = dot > -1 ? name.substring(dot + 1).toLowerCase() : '';
      var sizeKb = (file && file.size) ? Math.round(file.size / 1024) : 0;

      document.dispatchEvent(new CustomEvent('podval:print_uploaded', {
        detail: {
          slot:         slot,
          file_ext:     ext,
          file_size_kb: sizeKb
        }
      }));
    } catch(e) { /* no-op: analytics must never break upload flow */ }
  }


  /* ═══════════════════════════════════════════════════════════
     PLACEMENT EDITOR
     Schematic "примерное расположение" — drag + corner-resize an
     uploaded image over a garment outline, save a JPEG reference.
     No dependencies: native Pointer Events. Coordinates stored
     normalized (0..1 of stage) so they're resolution-independent.
     Exposes window.awPlacement: { open, clear, collect }.
     ═══════════════════════════════════════════════════════════ */
  /* ═══════════════════════════════════════════════════════════
     PLACEMENT EDITOR
     Drag + corner-resize an uploaded image AND a text line over a
     garment outline, save a JPEG reference. Native Pointer Events.
     Coordinates normalized (0..1 of stage) → resolution-independent.
     work = {
       img:  { x, y, w, h, present },
       text: { x, y, size, value, present }   // size = font px at render width
     }
     window.awPlacement: { open, clear, collect }
     ═══════════════════════════════════════════════════════════ */
  (function initPlacement() {
    var modal = document.getElementById('awp-modal');
    if (!modal) return;

    var stage      = document.getElementById('awp-stage');
    var garmentBox = document.getElementById('awp-stage-garment');
    var imgLayer   = document.getElementById('awp-layer-img');
    var imgEl      = document.getElementById('awp-layer-img-el');
    var imgHandle  = imgLayer ? imgLayer.querySelector('.awp-layer__handle') : null;
    var textLayer  = document.getElementById('awp-layer-text');
    var textEl     = document.getElementById('awp-layer-text-el');
    var textHandle = textLayer ? textLayer.querySelector('.awp-layer__handle--text') : null;
    var emptyEl    = document.getElementById('awp-stage-empty');
    var stubEl     = document.getElementById('awp-layer-img-stub');
    var stubExtEl  = document.getElementById('awp-layer-img-stub-ext');
    var stubNameEl = document.getElementById('awp-layer-img-stub-name');
    var noteEl     = document.getElementById('awp-note-stub');
    var textInput  = document.getElementById('awp-text-input');
    var fileInput  = document.getElementById('awp-file-input');
    var emptyUpload= document.getElementById('awp-empty-upload');
    var uploadingEl= document.getElementById('awp-stage-uploading');
    var saveBtn    = document.getElementById('awp-save');
    var resetBtn   = document.getElementById('awp-tool-reset');
    var centerBtn  = document.getElementById('awp-tool-center');

    var store = {};         // slot -> { layout, previewId, previewUrl }
    var garmentCache = {};
    var activeSlot = null;
    var work = null;
    var imgRatio = 1;       // natural h/w of source image
    // режим заглушки — файл, который ни браузер, ни сервер показать
    // не смогли (AI/EPS/PSD, или PDF без Ghostscript на хосте). Рамку всё
    // равно двигаем и масштабируем: печатнику нужны место и размер.
    var imgStub = false;
    var stubExt = '';
    var stubName = '';
    var RENDERABLE_RE = /\.(jpe?g|png|webp|gif|bmp|svg)$/i;

    var MIN_W = 0.08, MAX_W = 1.4;
    var MIN_TEXT = 0.03, MAX_TEXT = 0.30;  // text height as fraction of stage
    var RENDER_W = 760;                    // canvas width for JPEG
    var RENDER_H = Math.round(RENDER_W / 0.9337);
    var isDark = !!(cfg && cfg.darkGarment);
    if (isDark) {
      modal.classList.add('awp-dark');
      stage.classList.add('is-dark');
    }

    // ── Garment loading ───────────────────────────────────────
    function loadGarment(slot, cb) {
      var url = (cfg.garments && cfg.garments[slot]) || (cfg.garments && cfg.garments.front);
      if (!url) { garmentBox.innerHTML = ''; cb && cb(); return; }
      if (garmentCache[url]) { garmentBox.innerHTML = garmentCache[url]; cb && cb(); return; }
      fetch(url, { credentials: 'same-origin' })
        .then(function(r){ return r.text(); })
        .then(function(svg){ garmentCache[url] = svg; garmentBox.innerHTML = svg; cb && cb(); })
        .catch(function(){
          garmentBox.innerHTML = '<img src="' + url + '" alt="" style="width:100%;height:100%;object-fit:contain">';
          cb && cb();
        });
    }

    // ── Источник картинки для сцены ──────────────────
    // Сам файл — только для форматов, которые рисует браузер. Для остальных
    // серверное превью. Нет ни того, ни другого — заглушка.
    function canRender(up) {
      if (!up) return false;
      if (typeof up.canPreview === 'boolean') return up.canPreview;
      return RENDERABLE_RE.test(up.name || up.url || '');
    }
    function editorSrc(up) {
      if (!up) return '';
      if (canRender(up) && up.url) return up.url;
      return up.previewUrl || '';
    }
    function fileExt(up) {
      var m = /\.([a-z0-9]{1,5})$/i.exec((up && (up.name || up.url)) || '');
      return m ? m[1].toUpperCase() : '';
    }
    function showStub(up) {
      imgStub  = true;
      imgRatio = 1;           // настоящего соотношения сторон у файла нет
      stubExt  = fileExt(up) || 'ФАЙЛ';
      stubName = (up && up.name) ? up.name : '';
      imgEl.hidden = true;
      imgEl.removeAttribute('src');
      if (stubEl) {
        stubEl.hidden = false;
        if (stubExtEl)  stubExtEl.textContent  = stubExt;
        if (stubNameEl) stubNameEl.textContent = stubName;
      }
      if (noteEl) noteEl.hidden = false;
    }
    function hideStub() {
      imgStub = false;
      if (stubEl) stubEl.hidden = true;
      if (noteEl) noteEl.hidden = true;
      imgEl.hidden = false;
    }
    // Ставит источник слоя и вызывает cb, когда пропорции известны.
    function setImgSource(up, cb) {
      var url = editorSrc(up);
      if (!url) { showStub(up); cb && cb(); return; }
      hideStub();
      imgEl.onload = function() {
        imgRatio = (imgEl.naturalHeight || 1) / (imgEl.naturalWidth || 1);
        cb && cb();
      };
      // Битый или недогруженный файл не должен оставлять пустую рамку.
      imgEl.onerror = function() { showStub(up); cb && cb(); };
      imgEl.src = url;
      if (imgEl.complete && imgEl.naturalWidth) imgEl.onload();
    }

    // ── Defaults ──────────────────────────────────────────────
    function defaultImg() {
      var w = 0.38, h = w * imgRatio;
      return { x: 0.5 - w / 2, y: 0.24, w: w, h: h, present: true };
    }
    function defaultText() {
      return { x: 0.30, y: 0.60, size: 0.07, value: '', align: 'left', present: false };
    }
    function cloneWork(w) {
      return {
        img:  { x: w.img.x, y: w.img.y, w: w.img.w, h: w.img.h, present: w.img.present },
        text: { x: w.text.x, y: w.text.y, size: w.text.size, value: w.text.value, align: w.text.align || 'left', present: w.text.present }
      };
    }

    // ── Open / close ──────────────────────────────────────────
    function open(slot) {
      activeSlot = slot;
      var up = (typeof uploads !== 'undefined') ? uploads[slot] : null;
      var hasImg = up && up.url;
      var slotText = (typeof texts !== 'undefined' && texts[slot]) ? String(texts[slot]).trim() : '';

      loadGarment(slot);

      // Seed working state: restore prior, else build from current inputs
      var hadStore = !!(store[slot] && store[slot].layout);
      if (hadStore) {
        work = cloneWork(store[slot].layout);
      } else {
        work = { img: defaultImg(), text: defaultText() };
        work.img.present = !!hasImg;
      }

      // Text value source of truth:
      //  - Restored layout carries the full multiline value + size + align.
      //  - On first open (no saved layout) seed from the page text-line field.
      //  - On reopen where the page text changed (stale), adopt the new text but
      //    keep the saved position/size/align so the user only re-confirms.
      if (!hadStore && slotText) {
        work.text.value = slotText;
        work.text.present = true; // auto-show first time
      } else if (hadStore && slotText && slotText !== (store[slot].srcText || '').replace(/\r?\n/g, ' ').trim()) {
        work.text.value = slotText;     // adopt updated text
        work.text.present = true;
      }
      if (textInput) textInput.value = work.text.value || '';
      if (typeof syncAlignButtons === 'function') syncAlignButtons();

      // If a NEW image was added after saving (snapshot had none), adopt it with a default box.
      if (hadStore && hasImg && !store[slot].srcImgId && up && up.id) {
        work.img = defaultImg();
        work.img.present = true;
      }

      // Image layer
      if (hasImg) {
        setImgSource(up, function() {
          if (!work) return;
          if (!work.img.h) work.img.h = work.img.w * imgRatio;
          applyWork();
        });
        imgLayer.hidden = !work.img.present;
        work.img.present = !!hasImg && work.img.present;
      } else {
        work.img.present = false;
        imgLayer.hidden = true;
        hideStub();
      }

      applyWork();
      updateEmpty();

      modal.hidden = false;
      modal.setAttribute('aria-hidden', 'false');
      document.documentElement.classList.add('awp-lock');
      requestAnimationFrame(function(){
        modal.classList.add('is-open');
        // Stage now has real dimensions — re-apply so text fontSize (derived from
        // stage height) and textarea auto-height compute correctly on reopen.
        applyWork();
        if (textInput) { textInput.style.height = 'auto'; textInput.style.height = textInput.scrollHeight + 'px'; }
      });
      refreshSaveBtn();
    }

    function close() {
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');
      document.documentElement.classList.remove('awp-lock');
      setTimeout(function(){ modal.hidden = true; }, 200);
      activeSlot = null;
    }

    // ── Apply working state to DOM ────────────────────────────
    function applyWork() {
      if (!work) return;
      // image
      if (work.img.present) {
        imgLayer.hidden = false;
        imgLayer.style.left   = (work.img.x * 100) + '%';
        imgLayer.style.top    = (work.img.y * 100) + '%';
        imgLayer.style.width  = (work.img.w * 100) + '%';
        imgLayer.style.height = (work.img.h * 100) + '%';
      } else {
        imgLayer.hidden = true;
      }
      // text
      if (work.text.present && work.text.value) {
        textLayer.hidden = false;
        textEl.textContent = work.text.value;
        textEl.style.textAlign = work.text.align || 'left';
        textLayer.style.left = (work.text.x * 100) + '%';
        textLayer.style.top  = (work.text.y * 100) + '%';
        // size is fraction of stage height = PER-LINE height
        var sb = stage.getBoundingClientRect();
        textEl.style.fontSize = Math.max(8, work.text.size * sb.height) + 'px';
      } else {
        textLayer.hidden = true;
      }
    }

    function updateEmpty() {
      var hasImg = work && work.img.present;
      var hasText = work && work.text.present && work.text.value;
      var nothing = !hasImg && !hasText;
      emptyEl.hidden = !nothing;
      // Upload button stays available whenever there is no image yet,
      // independent of whether text was added.
      if (emptyUpload) emptyUpload.hidden = !!hasImg;
    }
    function refreshSaveBtn() {
      var ok = work && (work.img.present || (work.text.present && work.text.value));
      saveBtn.disabled = !ok;
    }

    // ── Text input wiring (two-way with main field) ───────────
    function autoGrow() {
      if (!textInput) return;
      textInput.style.height = 'auto';
      textInput.style.height = textInput.scrollHeight + 'px';
    }
    if (textInput) {
      textInput.addEventListener('input', function() {
        if (!work) return;
        autoGrow();
        var v = textInput.value.replace(/\s+$/, ''); // trim trailing whitespace/newlines only
        work.text.value = v;
        work.text.present = !!v.trim();
        // mirror back to the page text-line field (single-line: collapse newlines to space)
        if (activeSlot) {
          var pageInput = document.querySelector('.aw-textline__input[data-slot="' + activeSlot + '"]');
          var flat = v.replace(/\r?\n/g, ' ').trim();
          if (pageInput && pageInput.value.trim() !== flat) {
            pageInput.value = flat;
            if (typeof texts !== 'undefined') {
              if (flat) { texts[activeSlot] = flat; } else { delete texts[activeSlot]; }
            }
            // The page text-line opens via the .is-open class on .aw-textline
            // (the toggle is a <label>, there is no checkbox to tick).
            var wrap = pageInput.closest('.aw-textline');
            if (flat && wrap && !wrap.classList.contains('is-open')) {
              wrap.classList.add('is-open');
            }
          }
        }
        applyWork();
        updateEmpty();
        refreshSaveBtn();
      });
    }

    // ── Alignment buttons ─────────────────────────────────────
    var alignBtns = modal.querySelectorAll('.awp-align');
    function syncAlignButtons() {
      var a = (work && work.text.align) || 'left';
      alignBtns.forEach(function(btn){
        btn.classList.toggle('is-active', btn.getAttribute('data-align') === a);
      });
    }
    alignBtns.forEach(function(btn){
      btn.addEventListener('click', function(){
        if (!work) return;
        work.text.align = btn.getAttribute('data-align');
        syncAlignButtons();
        applyWork();
      });
    });

    // ── In-modal image upload (when slot has no file yet) ─────
    if (emptyUpload && fileInput) {
      emptyUpload.addEventListener('click', function(){ fileInput.click(); });
      fileInput.addEventListener('change', function(){
        if (!fileInput.files || !fileInput.files.length || !activeSlot) return;
        var slot = activeSlot;
        var file = fileInput.files[0];
        fileInput.value = ''; // allow re-pick of same file
        emptyUpload.classList.add('is-loading');
        if (uploadingEl) uploadingEl.hidden = false;   // show spinner overlay
        if (emptyEl) emptyEl.hidden = true;            // hide the placeholder text under it
        // Reuse the page's handleFile: it updates the page zone UI AND uploads,
        // populating uploads[slot] on success. We then reflect it in the editor.
        handleFile(file, slot);
        // Poll for uploads[slot] to be set by the shared upload pipeline
        var tries = 0;
        var poll = setInterval(function(){
          tries++;
          if (typeof uploads !== 'undefined' && uploads[slot] && uploads[slot].url) {
            clearInterval(poll);
            emptyUpload.classList.remove('is-loading');
            if (uploadingEl) uploadingEl.hidden = true;
            var up = uploads[slot];
            work.img.present = true;
            setImgSource(up, function(){
              if (!work) return;
              work.img = defaultImg();
              work.img.present = true;
              applyWork(); updateEmpty(); refreshSaveBtn();
            });
          } else if (tries > 100) { // ~20s timeout
            clearInterval(poll);
            emptyUpload.classList.remove('is-loading');
            if (uploadingEl) uploadingEl.hidden = true;
            updateEmpty();
          }
        }, 200);
      });
    }

    // ── Generic pointer drag for a layer ──────────────────────
    function makeDraggable(layer, getPos, setPos) {
      var st = null;
      layer.addEventListener('pointerdown', function(e) {
        if (e.target.classList.contains('awp-layer__handle')) return;
        if (!work) return;
        e.preventDefault();
        var rect = stage.getBoundingClientRect();
        var p = getPos();
        st = { px: e.clientX, py: e.clientY, ox: p.x, oy: p.y, rw: rect.width, rh: rect.height };
        layer.setPointerCapture(e.pointerId);
        layer.classList.add('is-dragging');
      });
      layer.addEventListener('pointermove', function(e) {
        if (!st) return;
        var dx = (e.clientX - st.px) / st.rw;
        var dy = (e.clientY - st.py) / st.rh;
        setPos(st.ox + dx, st.oy + dy);
        applyWork();
      });
      function up(e) {
        if (!st) return;
        st = null;
        layer.classList.remove('is-dragging');
        try { layer.releasePointerCapture(e.pointerId); } catch(_) {}
      }
      layer.addEventListener('pointerup', up);
      layer.addEventListener('pointercancel', up);
    }

    if (imgLayer) makeDraggable(imgLayer,
      function(){ return { x: work.img.x, y: work.img.y }; },
      function(x, y){ work.img.x = clamp(x, -work.img.w*0.5, 1-work.img.w*0.5); work.img.y = clamp(y, -work.img.h*0.5, 1-work.img.h*0.5); }
    );
    if (textLayer) makeDraggable(textLayer,
      function(){ return { x: work.text.x, y: work.text.y }; },
      function(x, y){ work.text.x = clamp(x, 0, 0.98); work.text.y = clamp(y, 0, 0.98); }
    );

    // ── Image resize (SE handle, keep aspect) ─────────────────
    (function() {
      if (!imgHandle) return;
      var rs = null;
      imgHandle.addEventListener('pointerdown', function(e) {
        if (!work) return;
        e.preventDefault(); e.stopPropagation();
        var rect = stage.getBoundingClientRect();
        rs = { px: e.clientX, ow: work.img.w, rw: rect.width };
        imgHandle.setPointerCapture(e.pointerId);
        imgLayer.classList.add('is-resizing');
      });
      imgHandle.addEventListener('pointermove', function(e) {
        if (!rs) return;
        var dx = (e.clientX - rs.px) / rs.rw;
        work.img.w = clamp(rs.ow + dx, MIN_W, MAX_W);
        work.img.h = work.img.w * imgRatio;
        applyWork();
      });
      function up(e) { if (!rs) return; rs = null; imgLayer.classList.remove('is-resizing'); try { imgHandle.releasePointerCapture(e.pointerId); } catch(_) {} }
      imgHandle.addEventListener('pointerup', up);
      imgHandle.addEventListener('pointercancel', up);
    })();

    // ── Text resize (SE handle — scale so the corner tracks the cursor) ──
    (function() {
      if (!textHandle) return;
      var rs = null;
      textHandle.addEventListener('pointerdown', function(e) {
        if (!work) return;
        e.preventDefault(); e.stopPropagation();
        var rect = stage.getBoundingClientRect();
        var tb = textLayer.getBoundingClientRect();
        rs = {
          rh: rect.height,
          os: work.text.size,
          // distances from the text's top-left anchor to the pointer at grab time
          startW: Math.max(1, tb.width),
          startH: Math.max(1, tb.height),
          originX: tb.left,
          originY: tb.top
        };
        textHandle.setPointerCapture(e.pointerId);
        textLayer.classList.add('is-resizing');
      });
      textHandle.addEventListener('pointermove', function(e) {
        if (!rs) return;
        // startH is the measured block height (already includes all lines), so the
        // ratio of desired-to-start height scales per-line size correctly for N lines.
        var newH = e.clientY - rs.originY;
        var ratio = newH / rs.startH;
        if (!isFinite(ratio) || ratio <= 0) return;
        work.text.size = clamp(rs.os * ratio, MIN_TEXT, MAX_TEXT);
        applyWork();
      });
      function up(e) { if (!rs) return; rs = null; textLayer.classList.remove('is-resizing'); try { textHandle.releasePointerCapture(e.pointerId); } catch(_) {} }
      textHandle.addEventListener('pointerup', up);
      textHandle.addEventListener('pointercancel', up);
    })();

    // ── Pinch-zoom image on touch ─────────────────────────────
    var pinch = null;
    stage.addEventListener('touchstart', function(e) {
      if (e.touches.length === 2 && work && work.img.present) pinch = { d: touchDist(e), ow: work.img.w };
    }, { passive: true });
    stage.addEventListener('touchmove', function(e) {
      if (pinch && e.touches.length === 2) {
        e.preventDefault();
        var ratio = touchDist(e) / (pinch.d || 1);
        work.img.w = clamp(pinch.ow * ratio, MIN_W, MAX_W);
        work.img.h = work.img.w * imgRatio;
        applyWork();
      }
    }, { passive: false });
    stage.addEventListener('touchend', function(e) { if (e.touches.length < 2) pinch = null; });
    function touchDist(e) { var a = e.touches[0], b = e.touches[1]; return Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY); }

    // ── Tools ─────────────────────────────────────────────────
    if (resetBtn) resetBtn.addEventListener('click', function() {
      if (!work) return;
      var keepImg = work.img.present, keepText = work.text.present, val = work.text.value;
      work.img = defaultImg(); work.img.present = keepImg;
      work.text = defaultText(); work.text.value = val; work.text.present = keepText;
      applyWork(); updateEmpty();
    });
    if (centerBtn) centerBtn.addEventListener('click', function() {
      if (!work) return;
      if (work.img.present) work.img.x = 0.5 - work.img.w / 2;
      if (work.text.present) {
        // center text horizontally using measured width
        var sb = stage.getBoundingClientRect();
        var tb = textLayer.getBoundingClientRect();
        work.text.x = 0.5 - (tb.width / sb.width) / 2;
      }
      applyWork();
    });

    // ── Close handlers ────────────────────────────────────────
    modal.querySelectorAll('[data-awp-close]').forEach(function(el){ el.addEventListener('click', close); });
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && !modal.hidden) close(); });

    // ── Save: render to JPEG, upload, persist ─────────────────
    if (saveBtn) saveBtn.addEventListener('click', function() {
      if (!activeSlot || !work) return;
      var slot = activeSlot;
      saveBtn.classList.add('is-loading'); saveBtn.disabled = true;
      renderPreview(slot, function(blob) {
        if (!blob) { saveBtn.classList.remove('is-loading'); saveBtn.disabled = false; showToast('Не удалось сохранить схему'); return; }
        var fd = new FormData();
        fd.append('action', 'podval_upload_print');
        fd.append('podval_print_file', blob, 'placement-' + slot + '.jpg');
        fd.append('_wpnonce', cfg.nonce);
        fd.append('podval_is_placement', '1');
        fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
          .then(function(r){ return r.json(); })
          .then(function(resp){
            saveBtn.classList.remove('is-loading'); saveBtn.disabled = false;
            if (resp.success && resp.data && resp.data.id) {
              store[slot] = {
                layout: cloneWork(work),
                previewId: resp.data.id,
                previewUrl: resp.data.url || '',
                // snapshot of sources used → lets us detect later edits (stale state)
                srcText: work.text.value || '',
                srcImgId: (typeof uploads !== 'undefined' && uploads[slot]) ? (uploads[slot].id || 0) : 0
              };
              markSaved(slot); close();
            } else {
              showToast(resp.data && resp.data.message ? resp.data.message : 'Ошибка сохранения схемы');
            }
          })
          .catch(function(){ saveBtn.classList.remove('is-loading'); saveBtn.disabled = false; showToast('Ошибка сети при сохранении схемы'); });
      });
    });

    function renderPreview(slot, cb) {
      try {
        var canvas = document.createElement('canvas');
        canvas.width = RENDER_W; canvas.height = RENDER_H;
        var ctx = canvas.getContext('2d');
        ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, RENDER_W, RENDER_H);
        var garmentUrl = (cfg.garments && cfg.garments[slot]) || (cfg.garments && cfg.garments.front);
        drawImageEl(garmentUrl, function(gImg) {
          if (gImg) {
            // Fit the garment into the canvas preserving aspect (matches the
            // editor's xMidYMid meet), so the JPEG isn't stretched for SVGs
            // whose viewBox ratio differs from the canvas.
            var iw = gImg.naturalWidth || RENDER_W, ih = gImg.naturalHeight || RENDER_H;
            var scale = Math.min(RENDER_W / iw, RENDER_H / ih);
            var dw = iw * scale, dh = ih * scale;
            var dx = (RENDER_W - dw) / 2, dy = (RENDER_H - dh) / 2;
            ctx.drawImage(gImg, dx, dy, dw, dh);
          }
          if (work.img.present) {
            var bx = work.img.x * RENDER_W, by = work.img.y * RENDER_H;
            var bw = work.img.w * RENDER_W, bh = work.img.h * RENDER_H;
            if (imgStub) {
              drawStub(ctx, bx, by, bw, bh);
            } else {
              // drawImage на незагруженной картинке бросает — раньше это молча
              // глотал catch, и схема уходила печатнику вообще без принта.
              try { ctx.drawImage(imgEl, bx, by, bw, bh); }
              catch(_) { drawStub(ctx, bx, by, bw, bh); }
            }
          }
          if (work.text.present && work.text.value) {
            var fontPx = work.text.size * RENDER_H;
            var lineH = fontPx * 1.05;
            var align = work.text.align || 'left';
            ctx.fillStyle = isDark ? '#ffffff' : '#111111';
            ctx.font = '700 ' + fontPx + 'px Oswald, Arial, sans-serif';
            ctx.textBaseline = 'top';
            ctx.textAlign = align === 'center' ? 'center' : (align === 'right' ? 'right' : 'left');
            var lines = String(work.text.value).split(/\r?\n/);
            // X anchor: left uses x; center/right need the measured block width
            var blockW = 0;
            lines.forEach(function(ln){ blockW = Math.max(blockW, ctx.measureText(ln).width); });
            var baseX = work.text.x * RENDER_W;
            var drawX = align === 'center' ? baseX + blockW / 2 : (align === 'right' ? baseX + blockW : baseX);
            var baseY = work.text.y * RENDER_H;
            lines.forEach(function(ln, i){
              ctx.fillText(ln, drawX, baseY + i * lineH);
            });
          }
          canvas.toBlob(function(b){ cb(b); }, 'image/jpeg', 0.85);
        });
      } catch(e) { cb(null); }
    }

    // Рамка с типом и именем файла вместо превью. Белая заливка
    // с оранжевым пунктиром читается и на светлой, и на тёмной футболке.
    function drawStub(ctx, x, y, w, h) {
      ctx.save();
      ctx.fillStyle = 'rgba(255,255,255,0.92)';
      ctx.fillRect(x, y, w, h);
      ctx.strokeStyle = '#ff6a00';
      ctx.lineWidth = Math.max(2, Math.round(w * 0.012));
      if (ctx.setLineDash) ctx.setLineDash([Math.max(6, w * 0.04), Math.max(4, w * 0.025)]);
      ctx.strokeRect(x, y, w, h);
      if (ctx.setLineDash) ctx.setLineDash([]);

      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      var cx = x + w / 2, cy = y + h / 2;

      var extPx = Math.max(11, Math.min(w * 0.16, h * 0.22));
      ctx.fillStyle = '#ff6a00';
      ctx.font = '700 ' + extPx + 'px Oswald, Arial, sans-serif';
      ctx.fillText(stubExt || 'ФАЙЛ', cx, cy - extPx * 0.35);

      if (stubName) {
        var namePx = Math.max(8, extPx * 0.42);
        ctx.fillStyle = '#444444';
        ctx.font = '400 ' + namePx + 'px Arial, sans-serif';
        // Длинное имя обрезаем: переносить по слогам незачем, печатник
        // всё равно открывает файл по ссылке из заказа.
        var label = stubName;
        while (label.length > 4 && ctx.measureText(label).width > w * 0.9) {
          label = label.slice(0, -2);
        }
        if (label !== stubName) label += '…';
        ctx.fillText(label, cx, cy + extPx * 0.75);
      }
      ctx.restore();
    }

    function drawImageEl(url, cb) {
      if (!url) { cb(null); return; }
      var im = new Image();
      im.crossOrigin = 'anonymous';
      im.onload = function(){ cb(im); };
      im.onerror = function(){ cb(null); };
      im.src = url;
    }

    // ── Saved-state UI ────────────────────────────────────────
    function markSaved(slot) {
      var wrap = document.querySelector('.aw-placement[data-slot="' + slot + '"]');
      if (!wrap) return;
      var btn = wrap.querySelector('.aw-placement__btn');
      var saved = wrap.querySelector('.aw-placement__saved');
      var thumb = wrap.querySelector('.aw-placement__thumb');
      if (store[slot] && store[slot].previewUrl && thumb) thumb.src = store[slot].previewUrl;
      if (btn) btn.hidden = true;
      if (saved) saved.hidden = false;
      setStale(slot, false); // fresh save → not stale
    }
    function clearSavedUI(slot) {
      var wrap = document.querySelector('.aw-placement[data-slot="' + slot + '"]');
      if (!wrap) return;
      var btn = wrap.querySelector('.aw-placement__btn');
      var saved = wrap.querySelector('.aw-placement__saved');
      if (btn) btn.hidden = false;
      if (saved) saved.hidden = true;
      setStale(slot, false);
    }
    // Toggle the "данные изменились" notice on the saved badge
    function setStale(slot, isStale) {
      var saved = document.querySelector('.aw-placement__saved[data-slot="' + slot + '"]');
      if (!saved) return;
      saved.classList.toggle('is-stale', !!isStale);
    }
    // Compare live sources to the saved snapshot; flag stale if they diverge.
    function checkStale(slot) {
      var s = store[slot];
      if (!s) return;
      var curText = (typeof texts !== 'undefined' && texts[slot]) ? String(texts[slot]) : '';
      var savedText = (s.srcText || '').replace(/\r?\n/g, ' ').trim();
      var curImgId = (typeof uploads !== 'undefined' && uploads[slot]) ? (uploads[slot].id || 0) : 0;
      var stale = (curText.trim() !== savedText) || (curImgId !== (s.srcImgId || 0));
      setStale(slot, stale);
    }

    // ── Wire buttons ──────────────────────────────────────────
    document.querySelectorAll('.aw-placement__btn').forEach(function(btn){ btn.addEventListener('click', function(){ open(btn.getAttribute('data-slot')); }); });
    document.querySelectorAll('.aw-placement__edit').forEach(function(btn){ btn.addEventListener('click', function(){ open(btn.getAttribute('data-slot')); }); });
    document.querySelectorAll('.aw-placement__clear').forEach(function(btn){ btn.addEventListener('click', function(){ var slot = btn.getAttribute('data-slot'); delete store[slot]; clearSavedUI(slot); }); });

    // ── Public API ────────────────────────────────────────────
    window.awPlacement = {
      open: open,
      clear: function(slot) { delete store[slot]; clearSavedUI(slot); },
      checkStale: checkStale,
      hasSaved: function(slot) { return !!store[slot]; },
      collect: function() {
        var layout = {}, previewIds = {};
        Object.keys(store).forEach(function(slot){
          var s = store[slot];
          if (s && s.layout) layout[slot] = s.layout;
          if (s && s.previewId) previewIds[slot] = s.previewId;
        });
        return { layout: layout, previewIds: previewIds };
      }
    };

    function clamp(v, min, max) { return v < min ? min : (v > max ? max : v); }
  })();


})();

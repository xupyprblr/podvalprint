/**
 * Лендинги услуг: калькулятор, лайтбокс галереи, sticky-бар,
 * «Отправить расчёт в заявку», fetch-отправка формы.
 *
 * Зависимости: podval-main (podvalSwapImage), podval-service (валидация, загрузка
 * файлов, токен P1, свежие nonce P2). Данные: window.PodvalSL { ymId, tg }.
 *
 * Калькулятор — точная копия podval_price_calc() из inc/prices.php. Серверный
 * HTML уже содержит результат для значений по умолчанию; первый рендер JS
 * обязан дать тот же DOM (проверяется тестом), иначе цифры прыгнут.
 */
(function () {
  'use strict';

  var SL = window.PodvalSL || {};

  /* ═══════════════════════════════════════════
     ФОРМАТЫ — совпадают с PHP
     ═══════════════════════════════════════════ */

  /** podval_price_round_half() */
  function roundHalf(v) {
    return Math.round(+(v * 2).toFixed(9)) / 2;
  }

  /** podval_sl_byn(): «55,00 Br» в [data-price-byn] — формат currency.js */
  function byn(v) {
    if (v === null || v === undefined) return '';
    return '<span data-price-byn="' + String(+v.toFixed(2)) + '">' +
      v.toFixed(2).replace('.', ',') + '&nbsp;Br</span>';
  }

  /** podval_price_num(): 55 → «55», 62.5 → «62,5», 1650 → «1 650» */
  function num(v) {
    var whole = Math.abs(v - Math.round(v)) < 0.001;
    var s = whole ? String(Math.round(v)) : v.toFixed(2).replace(/0$/, '');
    var parts = s.split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    return parts.join(',');
  }

  /* ═══════════════════════════════════════════
     РАСЧЁТ — копия podval_price_calc()
     ═══════════════════════════════════════════ */

  function tierIndex(cfg, qty) {
    qty = Math.max(1, qty);
    for (var i = 0; i < cfg.tiers.length; i++) {
      var t = cfg.tiers[i];
      if (qty >= t.min && (t.max === null || qty <= t.max)) return i;
    }
    return cfg.tiers.length - 1;
  }

  function transfer(cfg, size, qty) {
    var row = cfg.transfer && cfg.transfer[size];
    if (!row) return null;
    var cell = row[tierIndex(cfg, qty)];
    return (typeof cell === 'number') ? cell : null;
  }

  /** podval_price_roll_metres(): раскладка принтов по рулону. */
  function rollMetres(cfg, counts) {
    var r = cfg.roll, cm = 0, rows = {};
    Object.keys(r.dims).forEach(function (key) {
      var n = Math.max(0, parseInt(counts[key], 10) || 0);
      if (!n) return;
      var d = r.dims[key], best = null;
      [[d.w, d.h], [d.h, d.w]].forEach(function (o) {
        var perRow = Math.floor((r.width + r.gap) / (o[0] + r.gap));
        if (perRow < 1) return;
        var rowCm = o[1] + r.gap;
        if (!best || rowCm / perRow < best.rowCm / best.perRow) best = { perRow: perRow, rowCm: rowCm };
      });
      if (!best) return;
      var rr = Math.ceil(n / best.perRow);
      cm += rr * best.rowCm;
      rows[key] = { qty: n, perRow: best.perRow, rows: rr, cm: rr * best.rowCm };
    });
    return { m: Math.ceil((cm / 100) * 10) / 10, rows: rows };
  }

  /** podval_price_roll_tier() / podval_price_roll_rate(). */
  function rollRate(cfg, metres) {
    var i = cfg.roll.tiers.findIndex(function (b) { return metres <= b; });
    if (i < 0) i = cfg.roll.tiers.length;
    var cell = cfg.roll.price[i];
    return (typeof cell === 'number') ? cell : null;
  }

  function compute(cfg, p) {
    var mode = p.mode;
    if (!cfg.modes[mode]) {
      mode = Object.keys(cfg.modes).find(function (k) { return cfg.modes[k]; });
      if (!mode) return null;
    }
    var qty = parseInt(p.qty, 10);
    if (!isFinite(qty)) qty = 1;
    qty = Math.max(1, Math.min(100000, qty));

    var res = { mode: mode, qty: qty, unit: null, total: null, from: false, hint: null };
    var sleeves = parseInt(p.sleeves, 10) || 0;

    if (mode === 'roll') {
      var counts = {};
      Object.keys(cfg.roll.dims).forEach(function (k) {
        counts[k] = Math.max(0, Math.min(100000, parseInt((p.counts || {})[k], 10) || 0));
      });
      var nest = rollMetres(cfg, counts);
      var minM = cfg.roll.minM;
      var bill = Math.max(minM, nest.m);
      var rate = rollRate(cfg, bill);
      res.counts = counts;
      res.qty = Object.keys(counts).reduce(function (a, k) { return a + counts[k]; }, 0);
      res.metres = nest.m;
      res.billed = bill;
      res.rows = nest.rows;
      res.unit = rate;
      res.total = rate !== null ? roundHalf(rate * bill) : null;
      if (!res.qty) {
        res.total = null;
      } else if (nest.m < minM) {
        res.hint = { type: 'roll_min', m: nest.m, min: minM };
      } else if (rate !== null) {
        var next = cfg.roll.tiers.find(function (b) { return bill < b; });
        var nextRate = next !== undefined ? rollRate(cfg, next + 0.1) : null;
        if (next !== undefined && nextRate !== null && nextRate < rate) {
          res.hint = { type: 'roll_next', m: Math.round((next - bill + 0.1) * 10) / 10, from: rate, to: nextRate };
        }
      }
      return res;
    }

    if (mode === 'ours') {
      var g = null;
      for (var i = 0; i < cfg.garments.length; i++) {
        if (cfg.garments[i].key === p.garment) g = cfg.garments[i];
      }
      if (!g) g = cfg.garments[0];
      var second = cfg.second !== null && !!p.second;
      sleeves = cfg.sleeve !== null ? Math.max(0, Math.min(cfg.sleeveMax, sleeves)) : 0;
      res.garment = g.key;
      res.second = second;
      res.sleeves = sleeves;
      res.unit = roundHalf(g.price + (second ? cfg.second : 0) + sleeves * (cfg.sleeve || 0));
      res.total = res.unit * qty;
      // Скидка от 5 шт (размер — индивидуально) — как в podval_price_calc().
      res.hint = qty >= 5 ? { type: 'ours_bulk' } : { type: 'ours_next', n: 5 - qty };
      return res;
    }

    var sizes = ['A6', 'A5', 'A4', 'A3'];
    var s1 = sizes.indexOf(p.size1) !== -1 ? p.size1 : 'A4';
    var s2 = sizes.indexOf(p.size2) !== -1 ? p.size2 : '';
    sleeves = Math.max(0, Math.min(2, sleeves));

    var unitAt = function (q) {
      var a = transfer(cfg, s1, q);
      var b = s2 ? transfer(cfg, s2, q) : 0;
      var c = sleeves ? transfer(cfg, 'A6', q) : 0;
      if (a === null || b === null || c === null) return null;
      return roundHalf(a + b + sleeves * c);
    };

    var unit = unitAt(qty);
    res.size1 = s1;
    res.size2 = s2;
    res.sleeves = sleeves;
    res.unit = unit;
    res.total = unit !== null ? unit * qty : null;

    if (qty <= 4) {
      var next = unitAt(5);
      if (unit !== null && next !== null && next < unit) {
        res.hint = { type: 'own_next', n: 5 - qty, from: unit, to: next };
      }
    } else if (qty >= 51) {
      res.hint = { type: 'own_bulk' };
      res.from = unit !== null;
    }
    return res;
  }

  /** podval_sl_calc_hint_html() */
  function hintHtml(c) {
    if (!c || !c.hint) return '';
    switch (c.hint.type) {
      case 'roll_min': return 'Минимальный отрез — ' + num(c.hint.min) + ' м, считаем по нему';
      case 'roll_next': return 'Ещё ' + num(c.hint.m) + ' м — и метр будет ' + byn(c.hint.from) + ' → <span class="sl-nowrap">' + byn(c.hint.to) + '/м</span>';
      case 'own_next': return 'Ещё ' + c.hint.n + ' шт — и нанесение ' + byn(c.hint.from) + ' → <span class="sl-nowrap">' + byn(c.hint.to) + '/шт</span>';
      case 'own_bulk': return 'Для тиража от 50 шт снизим цену индивидуально';
      case 'ours_next': return 'Ещё ' + c.hint.n + ' шт — и будет скидка на тираж';
      case 'ours_bulk': return 'От 5 шт действует скидка — размер обсудим индивидуально';
    }
    return '';
  }

  /** podval_price_calc_summary() — читаемая сводка для комментария заявки */
  function summary(cfg, c) {
    var parts = [];
    if (c.mode === 'roll') {
      var items = Object.keys(c.counts).filter(function (k) { return c.counts[k]; }).map(function (k) { return k + ' × ' + c.counts[k]; });
      if (!items.length) return '';
      parts.push('Трансферы на плёнке: ' + items.join(', '));
      parts.push('≈ ' + num(c.metres) + ' м');
      if (c.total !== null) {
        if (c.metres < c.billed) parts.push('к оплате ' + num(c.billed) + ' м (минимум)');
        parts.push(num(c.unit) + ' Br/м');
        parts.push(num(c.total) + ' Br');
      } else {
        parts.push('цена — индивидуально');
      }
      if (cfg.lead) parts.push('срок ' + cfg.lead);
      return parts.join(' · ');
    }
    if (c.mode === 'ours') {
      var label = c.garment;
      cfg.garments.forEach(function (g) { if (g.key === c.garment) label = g.label; });
      parts.push(label);
      if (c.second) parts.push('2-я сторона');
    } else {
      parts.push('Своя вещь: принт ' + c.size1);
      if (c.size2) parts.push('2-й принт ' + c.size2);
    }
    if (c.sleeves) parts.push(c.sleeves + (c.sleeves === 1 ? ' рукав' : ' рукава'));
    parts.push(c.qty + ' шт');
    if (c.unit !== null) {
      parts.push((c.from ? 'до ' : '≈ ') + num(c.unit) + ' Br/шт');
      parts.push(num(c.total) + ' Br за ' + c.qty + ' шт');
    } else {
      parts.push('цена — индивидуально');
    }
    if (cfg.lead) parts.push('срок ' + cfg.lead);
    return parts.join(' · ');
  }

  /* ═══════════════════════════════════════════
     КАЛЬКУЛЯТОР — UI
     ═══════════════════════════════════════════ */

  function initCalc(root) {
    var cfg;
    try { cfg = JSON.parse(root.getAttribute('data-calc')); } catch (e) { return; }
    if (!cfg) return;

    var mode = cfg.mode;
    var used = false;
    var sentKey = null;

    var q = function (sel) { return root.querySelector(sel); };
    var qtyInput = q('#sl-qty');
    var priceEl = q('.sl-calc__price');
    var naEl = q('.sl-calc__na');
    var prefixEl = q('.sl-calc__prefix');
    var unitEl = q('.sl-calc__unit-val');
    var totalEl = q('.sl-calc__total');
    var qtyEl = q('.sl-calc__qty');
    var hintEl = q('.sl-calc__hint');
    var metaEl = q('.sl-calc__meta');
    var metresEl = q('.sl-calc__metres');
    var suffixEl = q('.sl-calc__unit-suffix');
    var qtyUnitEl = q('.sl-calc__qty-unit');
    var qtyField = q('.sl-field--qty');
    var productLink = q('.sl-calc__product');
    var sendBtn = q('.sl-calc__send');
    var sendLabel = sendBtn ? sendBtn.textContent : '';

    function checked(name) {
      var el = root.querySelector('input[name="' + name + '"]:checked');
      return el ? el.value : '';
    }

    function counts() {
      var out = {};
      root.querySelectorAll('[data-panel="roll"] input[type="number"]').forEach(function (i) {
        out[i.name.replace('sl_count_', '')] = Math.max(0, parseInt(i.value, 10) || 0);
      });
      return out;
    }

    function params() {
      var qty = parseInt(qtyInput && qtyInput.value, 10);
      if (!isFinite(qty) || qty < 1) qty = 1;
      if (mode === 'roll') return { mode: 'roll', counts: counts(), qty: qty };
      if (mode === 'own') {
        return { mode: 'own', size1: checked('sl_size1') || 'A4', size2: checked('sl_size2'), sleeves: parseInt(checked('sl_sleeves_own'), 10) || 0, qty: qty };
      }
      var sec = root.querySelector('input[name="sl_second"]');
      return { mode: 'ours', garment: checked('sl_garment'), second: !!(sec && sec.checked), sleeves: parseInt(checked('sl_sleeves_ours'), 10) || 0, qty: qty };
    }

    /** podval_sl_opt_price(): подпись цены под вариантом. */
    function optPrice(v, plus) {
      if (v === null || v === undefined) return '<small class="sl-seg__price sl-seg__price--na">по запросу</small>';
      if (v === 0) return '<small class="sl-seg__price sl-seg__price--zero">без доплаты</small>';
      return '<small class="sl-seg__price">' + (plus ? '+' : '') + byn(v) + '</small>';
    }

    /** Подписи цен на вариантах зависят от тиража — обновляем вместе с итогом. */
    function renderOptionPrices(qty) {
      if (!cfg.modes.own) return;
      var tr = function (size) {
        var row = cfg.transfer && cfg.transfer[size];
        if (!row) return null;
        var i = cfg.tiers.findIndex(function (t) { return qty >= t.min && (t.max === null || qty <= t.max); });
        var cell = row[i < 0 ? cfg.tiers.length - 1 : i];
        return (typeof cell === 'number') ? cell : null;
      };
      root.querySelectorAll('input[name="sl_size1"], input[name="sl_size2"]').forEach(function (i) {
        var span = i.nextElementSibling, old = span.querySelector('.sl-seg__price');
        if (!old) return;
        var plus = i.name === 'sl_size2';
        old.outerHTML = i.value === '' ? optPrice(0) : optPrice(tr(i.value), plus);
      });
      var a6 = tr('A6');
      root.querySelectorAll('input[name="sl_sleeves_own"]').forEach(function (i) {
        var old = i.nextElementSibling.querySelector('.sl-seg__price');
        if (old) old.outerHTML = optPrice(a6 === null ? null : (parseInt(i.value, 10) || 0) * a6, true);
      });
    }

    function render() {
      var c = compute(cfg, params());
      if (!c) return null;
      var isRoll = c.mode === 'roll';
      var has = c.unit !== null && (!isRoll || c.total !== null);
      if (priceEl) priceEl.hidden = !has;
      if (naEl) {
        naEl.hidden = has;
        naEl.textContent = isRoll ? 'Укажите, сколько принтов нужно' : 'Рассчитаем индивидуально';
      }
      if (metaEl) {
        metaEl.hidden = !(isRoll && c.qty);
        if (isRoll && metresEl) metresEl.textContent = num(c.metres);
      }
      if (suffixEl) suffixEl.textContent = isRoll ? '/м' : '/шт';
      if (qtyUnitEl) qtyUnitEl.textContent = isRoll ? 'м' : 'шт';
      if (prefixEl) prefixEl.textContent = c.from ? 'до' : '≈';
      if (unitEl) unitEl.innerHTML = has ? byn(c.unit) : '';
      if (totalEl) totalEl.innerHTML = has ? byn(c.total) : '';
      if (qtyEl) qtyEl.textContent = isRoll ? num(c.billed) : String(c.qty);
      if (hintEl) hintEl.innerHTML = hintHtml(c);
      renderOptionPrices(isRoll ? 1 : c.qty);

      root.querySelectorAll('.sl-chip').forEach(function (chip) {
        chip.classList.toggle('is-active', parseInt(chip.getAttribute('data-qty'), 10) === c.qty);
      });
      if (sendBtn && sentKey !== null) {
        sendBtn.textContent = sentKey === JSON.stringify(params()) ? 'Расчёт в заявке ✓' : 'Обновить расчёт в заявке';
      }
      if (window.PodvalCurrency && typeof window.PodvalCurrency.refresh === 'function') {
        window.PodvalCurrency.refresh();
      }
      return c;
    }

    function touched() {
      if (used) return;
      used = true;
      // Цель Метрики — один раз за просмотр, ymId из PodvalSL (на лендинге нет podvalAnalytics).
      if (SL.ymId && typeof window.ym === 'function') {
        try { window.ym(SL.ymId, 'reachGoal', 'svc_calc_use', { landing: document.title }); } catch (e) {}
      }
    }

    function setMode(m) {
      if (!cfg.modes[m]) return;
      mode = m;
      root.querySelectorAll('.sl-calc__mode').forEach(function (b) {
        var on = b.getAttribute('data-mode') === m;
        b.classList.toggle('is-active', on);
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      root.querySelectorAll('.sl-calc__panel').forEach(function (p) {
        p.hidden = p.getAttribute('data-panel') !== m;
      });
      // Тираж в штуках к метражу плёнки не относится.
      if (qtyField) qtyField.hidden = m === 'roll';
      // Конструктор — только для наших изделий.
      if (productLink) productLink.hidden = m !== 'ours';
    }

    root.addEventListener('click', function (e) {
      var modeBtn = e.target.closest('.sl-calc__mode');
      if (modeBtn) { touched(); setMode(modeBtn.getAttribute('data-mode')); render(); return; }

      var cstep = e.target.closest('[data-count-step]');
      if (cstep) {
        touched();
        var inp = root.querySelector('input[name="sl_count_' + cstep.getAttribute('data-size') + '"]');
        if (inp) {
          var cv = (parseInt(inp.value, 10) || 0) + parseInt(cstep.getAttribute('data-count-step'), 10);
          inp.value = String(Math.max(0, Math.min(100000, cv)));
          render();
        }
        return;
      }

      var step = e.target.closest('.sl-qty__btn');
      if (step && qtyInput) {
        touched();
        var v = (parseInt(qtyInput.value, 10) || 1) + parseInt(step.getAttribute('data-step'), 10);
        qtyInput.value = String(Math.max(1, Math.min(100000, v)));
        render();
        return;
      }
      var chip = e.target.closest('.sl-chip');
      if (chip && qtyInput) { touched(); qtyInput.value = chip.getAttribute('data-qty'); render(); }
    });
    root.addEventListener('change', function (e) {
      if (e.target.matches('input')) { touched(); render(); }
    });
    root.addEventListener('input', function (e) {
      if (e.target.matches('[data-panel="roll"] input[type="number"]')) { touched(); render(); }
    });
    root.addEventListener('blur', function (e) {
      if (e.target.matches('[data-panel="roll"] input[type="number"]')) {
        var v = parseInt(e.target.value, 10);
        e.target.value = String(isFinite(v) ? Math.max(0, Math.min(100000, v)) : 0);
        render();
      }
    }, true);
    if (qtyInput) {
      qtyInput.addEventListener('input', function () { touched(); render(); });
      qtyInput.addEventListener('blur', function () {
        var v = parseInt(qtyInput.value, 10);
        qtyInput.value = String(isFinite(v) ? Math.max(1, Math.min(100000, v)) : 1);
        render();
      });
    }

    // «Отправить расчёт в заявку»: в sl_calc — только параметры (сервер пересчитает),
    // в комментарий — читаемая сводка. Повторная отправка заменяет строку расчёта.
    if (sendBtn) {
      sendBtn.addEventListener('click', function () {
        var form = document.querySelector('#zayavka form');
        var c = render();
        if (!form || !c) return;
        touched();
        var p = params();
        var calcField = form.querySelector('input[name="sl_calc"]');
        var qtyField = form.querySelector('input[name="svc_qty"]');
        var comment = form.querySelector('textarea[name="svc_comment"]');
        if (calcField) calcField.value = JSON.stringify(p);
        if (qtyField) qtyField.value = String(c.qty);
        if (comment) {
          var line = 'Расчёт: ' + summary(cfg, c);
          var prev = comment.getAttribute('data-calc-line');
          var text = comment.value;
          if (prev && text.indexOf(prev) !== -1) {
            text = text.replace(prev, line);
          } else {
            text = line + (text ? '\n' + text : '');
          }
          comment.value = text;
          comment.setAttribute('data-calc-line', line);
        }
        sentKey = JSON.stringify(p);
        sendBtn.textContent = 'Расчёт в заявке ✓';
        var section = document.getElementById('zayavka');
        if (section) section.scrollIntoView({ behavior: 'smooth', block: 'start' });
        var name = form.querySelector('input[name="svc_name"]');
        if (name) setTimeout(function () { try { name.focus({ preventScroll: true }); } catch (e) { name.focus(); } }, 450);
      });
    }

    // Первый рендер: учитывает состояние, восстановленное браузером (назад/вперёд).
    // Для значений по умолчанию DOM совпадает с серверным.
    var activeBtn = root.querySelector('.sl-calc__mode.is-active');
    if (activeBtn) setMode(activeBtn.getAttribute('data-mode'));
    render();
    if (sendBtn) sendBtn.textContent = sendLabel;
  }

  /* ═══════════════════════════════════════════
     ГАЛЕРЕЯ — лайтбокс на <dialog>
     Крупное фото — новый <img> вне <picture>: EWWW его не оборачивает,
     и <source> не перебивает src (см. podvalSwapImage в main.js).
     ═══════════════════════════════════════════ */

  function initGallery() {
    var dlg = document.getElementById('sl-lightbox');
    var btns = Array.prototype.slice.call(document.querySelectorAll('.sl-gallery__btn'));
    if (!btns.length) return;

    var canDialog = dlg && typeof dlg.showModal === 'function';
    var frame = dlg ? dlg.querySelector('.sl-lightbox__frame') : null;
    var cap = dlg ? dlg.querySelector('.sl-lightbox__cap') : null;
    var current = 0;

    function show(i) {
      current = (i + btns.length) % btns.length;
      var b = btns[current];
      var thumb = b.querySelector('img');
      var img = document.createElement('img');
      img.className = 'sl-lightbox__img';
      img.alt = thumb ? thumb.alt : '';
      var w = parseInt(b.getAttribute('data-w'), 10);
      var h = parseInt(b.getAttribute('data-h'), 10);
      if (w && h) { img.width = w; img.height = h; }
      img.decoding = 'async';
      img.src = b.getAttribute('data-full');
      frame.innerHTML = '';
      frame.appendChild(img);
      var fc = b.parentElement && b.parentElement.querySelector('.sl-gallery__cap');
      cap.textContent = fc ? fc.textContent : '';
      cap.hidden = !cap.textContent;
    }

    btns.forEach(function (b, i) {
      b.addEventListener('click', function () {
        if (!canDialog) { window.open(b.getAttribute('data-full'), '_blank', 'noopener'); return; }
        show(i);
        dlg.showModal();
      });
    });
    if (!canDialog) return;

    dlg.querySelector('.sl-lightbox__close').addEventListener('click', function () { dlg.close(); });
    dlg.querySelector('.sl-lightbox__nav--prev').addEventListener('click', function () { show(current - 1); });
    dlg.querySelector('.sl-lightbox__nav--next').addEventListener('click', function () { show(current + 1); });
    dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
    dlg.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowLeft') show(current - 1);
      if (e.key === 'ArrowRight') show(current + 1);
    });
    dlg.addEventListener('close', function () { frame.innerHTML = ''; });
  }

  /* ═══════════════════════════════════════════
     STICKY-БАР (мобиль) — виден, когда hero, калькулятор и форма вне экрана
     ═══════════════════════════════════════════ */

  function initSticky() {
    var bar = document.getElementById('sl-sticky');
    if (!bar || !('IntersectionObserver' in window)) return;
    var targets = [document.querySelector('.sl-hero'), document.getElementById('calc'), document.getElementById('zayavka')].filter(Boolean);
    if (!targets.length) return;
    var inView = new Map();
    var obs = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) { inView.set(en.target, en.isIntersecting); });
      var any = false;
      inView.forEach(function (v) { if (v) any = true; });
      bar.classList.toggle('is-visible', !any);
    }, { threshold: 0 });
    targets.forEach(function (t) { obs.observe(t); });
  }

  /* ═══════════════════════════════════════════
     ФОРМА — fetch-отправка (Р9 ТЗ)
     Ответ не JSON (WAF BitNinja, 5xx, сеть) → сообщение со ссылкой на Telegram,
     поля сохраняются. service.js блокирует кнопку на 8 с — разблокируем сами.
     ═══════════════════════════════════════════ */

  function initForm() {
    var form = document.querySelector('#zayavka form.sl-form');
    if (!form || !window.fetch || !window.FormData) return;

    var btn = form.querySelector('button[type="submit"]');
    var btnLabel = btn ? btn.textContent : '';
    var errBox = form.querySelector('.sl-form__error');
    var ajaxField = form.querySelector('input[name="svc_ajax"]');
    var tg = form.getAttribute('data-tg') || SL.tg || '';

    function unlock() {
      if (!btn) return;
      btn.disabled = false;
      btn.dataset.submitting = '';
      btn.style.opacity = '';
      btn.style.pointerEvents = '';
      btn.textContent = btnLabel;
    }

    function showError(message, withTg) {
      if (!errBox) { window.alert(message); return; }
      errBox.innerHTML = '';
      errBox.appendChild(document.createTextNode(message));
      if (withTg && tg) {
        errBox.appendChild(document.createTextNode(' '));
        var a = document.createElement('a');
        a.href = tg;
        a.target = '_blank';
        a.rel = 'noopener';
        a.textContent = 'Написать в Telegram';
        errBox.appendChild(a);
      }
      errBox.hidden = false;
      errBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    form.addEventListener('submit', function (e) {
      // service.js уже отработал: невалидная форма или повторный клик — defaultPrevented.
      if (e.defaultPrevented) return;
      e.preventDefault();
      if (errBox) errBox.hidden = true;

      var ready = typeof window.podvalRefreshServiceNonces === 'function'
        ? window.podvalRefreshServiceNonces()
        : Promise.resolve();

      ready.then(function () {
        if (ajaxField) ajaxField.value = '1';
        var fd = new FormData(form);
        if (ajaxField) ajaxField.value = '';

        return fetch(form.action, {
          method: 'POST',
          body: fd,
          credentials: 'same-origin',
          headers: { 'Accept': 'application/json' }
        }).then(function (r) { return r.text(); });
      }).then(function (text) {
        var json = null;
        try { json = JSON.parse(text); } catch (err) { json = null; }

        if (json && json.success && json.data && json.data.redirect) {
          window.location.assign(json.data.redirect);
          return;
        }
        unlock();
        if (json && json.success === false) {
          showError((json.data && json.data.message) || 'Не удалось отправить заявку. Проверьте поля и попробуйте ещё раз.', false);
          return;
        }
        showError('Не получилось отправить заявку — введённые данные сохранены. Попробуйте ещё раз или напишите нам:', true);
      }).catch(function () {
        unlock();
        showError('Нет связи с сервером — введённые данные сохранены. Попробуйте ещё раз или напишите нам:', true);
      });
    });
  }

  function init() {
    document.querySelectorAll('.sl-calc[data-calc]').forEach(initCalc);
    initGallery();
    initSticky();
    initForm();
  }

  // Экспорт для тестов (jsdom): расчёт и форматы без DOM.
  window.PodvalSLCalc = { compute: compute, byn: byn, num: num, roundHalf: roundHalf, hintHtml: hintHtml, summary: summary };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

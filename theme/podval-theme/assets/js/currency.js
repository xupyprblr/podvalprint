/**
 * Podval Print — Currency Switcher (BYN / RUB)
 *
 * убран IP-детект через ipwho.is. Дефолт — BYN.
 * Пользователи переключают валюту через UI (.js-currency-switch).
 * Причина: основная аудитория из Беларуси; пользователи РФ обычно с VPN,
 * IP-детект всё равно ненадёжен. Это устраняет XHR на третий домен и
 * экономит ~210ms LCP (по данным Lighthouse).
 *
 * - Дефолт: BYN (если cookie не установлена)
 * - Хранит выбор в cookie `podval_currency` (365 дней)
 * - Конвертирует элементы [data-price-byn] на лету
 * - Совместимо со страничным кэшем (вся логика клиентская)
 *
 *
 */
(function () {
  'use strict';

  /* ── Config from PHP (wp_localize_script) ── */
  var CFG = window.PodvalCurrency || {};
  var RATE       = CFG.rate       || 28.8;
  var ROUND_STEP = CFG.roundStep  || 50;
  var RUB_SYM    = CFG.symbol     || '₽';
  var BYN_SYM    = CFG.bynSymbol  || 'Br';

  var COOKIE_NAME = 'podval_currency';
  var COOKIE_DAYS = 365;

  /* ══════════════════════════════════════════
     HELPERS
     ══════════════════════════════════════════ */

  /** Read cookie */
  function getCookie(name) {
    var m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : null;
  }

  /** Write cookie */
  function setCookie(name, value, days) {
    var d = new Date();
    d.setTime(d.getTime() + days * 864e5);
    document.cookie = name + '=' + encodeURIComponent(value) +
      ';expires=' + d.toUTCString() +
      ';path=/;SameSite=Lax';
  }

  /** Round up to nearest step */
  function roundUp(val, step) {
    return Math.ceil(val / step) * step;
  }

  /** Format number with space as thousands separator: 1500 → "1 500" */
  function formatNum(n) {
    return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '\u00A0');
  }


  /* ══════════════════════════════════════════
     PRICE CONVERSION
     ══════════════════════════════════════════ */

  var _converting = false; // prevent re-entrant MutationObserver calls
  var _convertTimer = null;

  function convertPrices(currency) {
    _converting = true;
    clearTimeout(_convertTimer);
    // 1) Elements with data-price-byn (from wc_price filter)
    var els = document.querySelectorAll('[data-price-byn]');
    for (var i = 0; i < els.length; i++) {
      var el = els[i];
      var byn = parseFloat(el.getAttribute('data-price-byn'));
      if (isNaN(byn)) continue;

      if (currency === 'RUB') {
        var rub = roundUp(byn * RATE, ROUND_STEP);
        el.innerHTML = formatNum(rub) + '&nbsp;' + RUB_SYM;
      } else {
        // Restore BYN — use WooCommerce native format
        var formatted = byn.toFixed(2).replace('.', ',');
        el.innerHTML = formatted + '&nbsp;' + BYN_SYM;
      }
    }

    // 2) Plain-text price elements from cart/checkout JS (no data-price-byn)
    //    These contain "150,00 Br" or "1 500 ₽" text set by checkout.js
    var textSelectors = [
      '.podval-cart__summary-price',
      '.podval-cart__item-total',
      '#podval-shipping-cost-value',
      '#podval-total-display'
    ];
    textSelectors.forEach(function (sel) {
      document.querySelectorAll(sel).forEach(function (el) {
        // Skip if already has a child with data-price-byn (wc_price output)
        if (el.querySelector('[data-price-byn]')) {
          // But first, extract BYN from the data-price-byn child and store on parent
          // This covers the case where checkout.js later overwrites innerHTML
          if (!el.hasAttribute('data-orig-byn')) {
            var inner = el.querySelector('[data-price-byn]');
            if (inner) el.setAttribute('data-orig-byn', inner.getAttribute('data-price-byn'));
          }
          return;
        }
        // Try to extract BYN amount from text
        var text = el.textContent || '';
        // Already converted to RUB? Store original in data attr
        if (!el.hasAttribute('data-orig-byn')) {
          // Parse BYN value: "150,00 Br" or "150.00"
          var match = text.replace(/\s/g, '').replace(',', '.').match(/([\d.]+)/);
          if (match) {
            el.setAttribute('data-orig-byn', match[1]);
          }
        }
        var origByn = parseFloat(el.getAttribute('data-orig-byn'));
        if (isNaN(origByn)) return;

        if (currency === 'RUB') {
          var rub = roundUp(origByn * RATE, ROUND_STEP);
          el.textContent = formatNum(rub) + ' ' + RUB_SYM;
        } else {
          el.textContent = origByn.toFixed(2).replace('.', ',') + ' ' + BYN_SYM;
        }
      });
    });

    // 3) Unit prices with suffix "/ шт."
    document.querySelectorAll('.podval-cart__item-unit').forEach(function (el) {
      if (!el.hasAttribute('data-orig-byn')) {
        var text = el.textContent || '';
        var match = text.replace(/\s/g, '').replace(',', '.').match(/([\d.]+)/);
        if (match) el.setAttribute('data-orig-byn', match[1]);
      }
      var origByn = parseFloat(el.getAttribute('data-orig-byn'));
      if (isNaN(origByn)) return;

      if (currency === 'RUB') {
        var rub = roundUp(origByn * RATE, ROUND_STEP);
        el.textContent = formatNum(rub) + ' ' + RUB_SYM + ' / шт.';
      } else {
        el.textContent = origByn.toFixed(2).replace('.', ',') + ' ' + BYN_SYM + ' / шт.';
      }
    });

    // Reset flag after all MutationObserver callbacks have had a chance to fire
    _convertTimer = setTimeout(function () { _converting = false; }, 100);
  }

  /**
   * Handle variation price switching on single product page.
   * When WooCommerce changes price_html, new elements won't have data-price-byn.
   * We observe #sp-price for changes.
   */
  function observePriceChanges() {
    var priceWrap = document.getElementById('sp-price');
    if (!priceWrap) return;

    var debounce;
    var observer = new MutationObserver(function () {
      if (_converting) return;
      clearTimeout(debounce);
      debounce = setTimeout(function () {
        if (_converting) return;
        // Only re-convert if user is in RUB mode and mutation was caused
        // by WC variation switch (not by our currency conversion)
        if (getCurrency() === 'RUB') {
          convertPrices('RUB');
        }
      }, 150); // longer than _convertTimer (100ms) to ensure flag is checked after reset
    });
    observer.observe(priceWrap, { childList: true, subtree: true });
  }

  /**
   * Observe cart/checkout totals that are updated by checkout.js
   * These set textContent directly (e.g. "150,00 Br"), so we parse the BYN
   * value from the text when no data-price-byn attribute is present.
   */
  function observeCartTotals() {
    var selectors = [
      '.podval-cart__summary-price',
      '.podval-cart__item-total',
      '.podval-cart__item-unit',
      '#podval-total-display',
      '#podval-shipping-cost-value'
    ];
    var targets = [];
    selectors.forEach(function (sel) {
      document.querySelectorAll(sel).forEach(function (el) { targets.push(el); });
    });
    if (!targets.length) return;

    var debounceTimer;
    var obs = new MutationObserver(function () {
      if (_converting) return; // skip mutations caused by our own conversion
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(function () {
        if (_converting) return;
        if (getCurrency() === 'RUB') convertPrices('RUB');
      }, 150);
    });
    targets.forEach(function (el) {
      obs.observe(el, { childList: true, characterData: true, subtree: true });
    });
  }


  /* ══════════════════════════════════════════
     SELECTOR UI
     ══════════════════════════════════════════ */

  function buildSelector() {
    var container = document.querySelector('.js-currency-switch');
    if (!container) return;

    var current = getCurrency();

    container.innerHTML =
      '<button class="currency-switch__btn' + (current === 'BYN' ? ' is-active' : '') + '" data-cur="BYN">BYN</button>' +
      '<button class="currency-switch__btn' + (current === 'RUB' ? ' is-active' : '') + '" data-cur="RUB">RUB</button>';

    container.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-cur]');
      if (!btn) return;
      var cur = btn.getAttribute('data-cur');
      setCurrency(cur);
      updateSelectorUI(container, cur);
      convertPrices(cur);
    });
  }

  function updateSelectorUI(container, currency) {
    var btns = container.querySelectorAll('[data-cur]');
    for (var i = 0; i < btns.length; i++) {
      btns[i].classList.toggle('is-active', btns[i].getAttribute('data-cur') === currency);
    }
  }

  /* Mobile selector in mnav */
  function buildMobileSelector() {
    var container = document.querySelector('.js-currency-switch-mob');
    if (!container) return;

    var current = getCurrency();

    container.innerHTML =
      '<button class="currency-switch__btn' + (current === 'BYN' ? ' is-active' : '') + '" data-cur="BYN">BYN</button>' +
      '<button class="currency-switch__btn' + (current === 'RUB' ? ' is-active' : '') + '" data-cur="RUB">RUB</button>';

    container.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-cur]');
      if (!btn) return;
      var cur = btn.getAttribute('data-cur');
      setCurrency(cur);
      // Update both desktop and mobile selectors
      var desktopContainer = document.querySelector('.js-currency-switch');
      if (desktopContainer) updateSelectorUI(desktopContainer, cur);
      updateSelectorUI(container, cur);
      convertPrices(cur);
    });
  }


  /* ══════════════════════════════════════════
     CURRENCY STATE
     ══════════════════════════════════════════ */

  function getCurrency() {
    return getCookie(COOKIE_NAME) || 'BYN';
  }

  function setCurrency(cur) {
    setCookie(COOKIE_NAME, cur, COOKIE_DAYS);
  }


  /* ══════════════════════════════════════════
     CURRENCY RESOLUTION (no network, no IP detect)
     ══════════════════════════════════════════ */

  /** Возвращает выбранную валюту: значение cookie, либо BYN по умолчанию */
  function resolveCurrency() {
    return getCookie(COOKIE_NAME) || 'BYN';
  }


  /* ══════════════════════════════════════════
     CHECKOUT: show BYN note for RUB users
     ══════════════════════════════════════════ */

  function addCheckoutNote() {
    if (getCurrency() !== 'RUB') return;
    var submitBtn = document.getElementById('place_order');
    if (!submitBtn) return;
    // Don't add twice
    if (document.getElementById('podval-rub-notice')) return;
    var note = document.createElement('p');
    note.id = 'podval-rub-notice';
    note.className = 'podval-currency-notice';
    note.innerHTML = 'Оплата производится в белорусских рублях (BYN). Ваш банк выполнит конвертацию автоматически.';
    submitBtn.parentNode.insertBefore(note, submitBtn.nextSibling);
  }


  /* ══════════════════════════════════════════
     AJAX CART: re-convert after cart updates
     ══════════════════════════════════════════ */

  function hookCartUpdates() {
    // After WC AJAX events (fragments refresh, add-to-cart, etc.)
    document.body.addEventListener('wc_fragments_refreshed', function () {
      if (getCurrency() === 'RUB') {
        setTimeout(function () { convertPrices('RUB'); }, 50);
      }
    });

    // After our custom AJAX cart updates
    document.addEventListener('podval:cart:updated', function () {
      if (getCurrency() === 'RUB') {
        setTimeout(function () { convertPrices('RUB'); }, 50);
      }
    });
  }


  /* ══════════════════════════════════════════
     INIT
     ══════════════════════════════════════════ */

  function init() {
    var currency = resolveCurrency();

    buildSelector();
    buildMobileSelector();

    if (currency === 'RUB') {
      convertPrices('RUB');
    }

    observePriceChanges();
    observeCartTotals();
    addCheckoutNote();
    hookCartUpdates();
  }

  // Run on DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  /* ══════════════════════════════════════════
     PUBLIC API
     Внешние модули (например bundle-single.js) могут:
       - window.PodvalCurrency.refresh()       — перерисовать все [data-price-byn]
                                                элементы согласно текущей валюте
       - window.PodvalCurrency.getCurrency()   — узнать текущую валюту ('BYN'|'RUB')
     CFG (rate, round_step, byn_symbol, rub_symbol) уже задан из PHP
     через wp_localize_script.
     ══════════════════════════════════════════ */
  window.PodvalCurrency = window.PodvalCurrency || {};
  window.PodvalCurrency.refresh     = function () { convertPrices(getCurrency()); };
  window.PodvalCurrency.getCurrency = getCurrency;

})();

/* =============================================
   ФУТЕР — аккордеон на мобиле
   ============================================= */
(function() {
  var footerInited = false;

  function initFooterAccordion() {
    if (footerInited) return;
    footerInited = true;
    document.querySelectorAll('.js-footer-toggle').forEach(function(title) {
      title.addEventListener('click', function() {
        // Работает всегда — CSS сам скрывает/показывает через media query
        var col = title.closest('.site-footer__col--collapsible');
        if (!col) return;
        col.classList.toggle('is-open');
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initFooterAccordion);
  } else {
    initFooterAccordion();
  }
})();

/* =============================================
   МОБИЛЬНОЕ МЕНЮ — mnav drawer
   ============================================= */
(function() {
  const mnav    = document.getElementById('mnav');
  if (!mnav) return;

  const panel   = mnav.querySelector('.mnav__panel');
  const toggles = document.querySelectorAll('.js-menu-toggle, .js-mnav-open');
  const closes  = mnav.querySelectorAll('.js-mnav-close');

  function openMnav() {
    mnav.classList.add('is-open');
    mnav.setAttribute('aria-hidden', 'false');
    mnav.removeAttribute('inert');
    document.body.style.overflow = 'hidden';
  }
  function closeMnav() {
    mnav.classList.remove('is-open');
    mnav.setAttribute('aria-hidden', 'true');
    mnav.setAttribute('inert', '');
    document.body.style.overflow = '';
  }

  toggles.forEach(btn => btn.addEventListener('click', openMnav));
  closes.forEach(btn => btn.addEventListener('click', closeMnav));

  // Аккордеон подменю
  mnav.querySelectorAll('.js-mnav-toggle').forEach(function(parent) {
    parent.addEventListener('click', function() {
      const sub = parent.nextElementSibling;
      if (!sub) return;
      const isOpen = sub.classList.contains('is-open');
      // Закрываем все
      mnav.querySelectorAll('.mnav__sub.is-open').forEach(s => s.classList.remove('is-open'));
      mnav.querySelectorAll('.mnav__row--parent.is-open').forEach(r => r.classList.remove('is-open'));
      if (!isOpen) {
        sub.classList.add('is-open');
        parent.classList.add('is-open');
      }
    });
  });

  // Закрытие по Escape
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeMnav();
  });
})();

/**
 * Podval Print — main.js
 */
(function () {
  'use strict';

  /* =====================================================
     0. ПОДМЕНА КАДРА У <img>
     ===================================================== */
  /**
   * Поменять картинку у <img>, даже если он завёрнут в <picture>.
   *
   * EWWW в режиме Picture WebP Rewriting оборачивает картинки в
   * <picture> с <source srcset="…webp">. Браузер тогда берёт кадр из
   * <source>, а атрибут src игнорирует: обработчики галереи честно
   * отрабатывали, src менялся, а на экране оставалось прежнее фото.
   *
   * Воспроизводится только на проде и только у части товаров: обёртка
   * появляется лишь там, где файл лежит в базе как .jpg/.png. Если
   * главное фото само по себе .webp — оборачивать нечего, и всё
   * работает. Отсюда и ложный признак «ломается только у карточек с
   * конструктором»: им недавно залили новые инфографики в JPG.
   *
   * <source> снимается насовсем, а не переписывается на .webp-соседа.
   * Во-первых, сосед есть не у всех файлов (bulk-конвертация прошла не
   * по всем), а <picture> с битым <source> НЕ откатывается на <img> —
   * показывает пустоту. Во-вторых, webp и без обёртки отдаёт nginx,
   * переписывая ответ по заголовку Accept: обёртка здесь вторая
   * реализация той же доставки, и терять от её снятия нечего.
   */
  window.podvalSwapImage = function (img, url) {
    if (!img || !url) return;
    const pic = img.parentElement;
    if (pic && pic.tagName === 'PICTURE') {
      pic.querySelectorAll('source').forEach(s => s.remove());
    }
    // srcset у самого <img> перебил бы src ровно так же, как <source>.
    img.removeAttribute('srcset');
    img.removeAttribute('sizes');
    img.src = url;
  };

  /* =====================================================
     1. HERO SLIDER
     ===================================================== */
  function initHeroSlider() {
    const slides  = document.querySelectorAll('.hero__slide');
    const dots    = document.querySelectorAll('.hero__dot');
    if ( ! slides.length ) return;

    // Slide data passed from PHP via data attributes on the section
    // We read dynamic content from hidden JSON or from slide elements
    const slideData = [];
    slides.forEach( slide => {
      slideData.push({
        label  : slide.dataset.label   || '',
        title1 : slide.dataset.title1  || '',
        title2 : slide.dataset.title2  || '',
        desc   : slide.dataset.desc    || '',
        btn1   : slide.dataset.btn1    || '',
        btn1url: slide.dataset.btn1url || '#',
        btn2   : slide.dataset.btn2    || '',
        btn2url: slide.dataset.btn2url || '#',
      });
    });

    let current = 0;
    let timer;

    function goTo(n) {
      const prev = current;
      current = ( n + slides.length ) % slides.length;

      slides[prev].classList.remove('is-active');
      slides[prev].setAttribute('aria-hidden', 'true');
      slides[current].classList.add('is-active');
      slides[current].setAttribute('aria-hidden', 'false');

      dots.forEach( (d, i) => {
        d.classList.toggle('is-active', i === current);
        d.setAttribute('aria-selected', i === current ? 'true' : 'false');
      });

      // Update content
      const d = slideData[current];
      const label  = document.querySelector('.js-hero-label');
      const line1  = document.querySelector('.js-hero-line1');
      const line2  = document.querySelector('.js-hero-line2');
      const desc   = document.querySelector('.js-hero-desc');
      const btn1   = document.querySelector('.js-hero-btn1');
      const btn2   = document.querySelector('.js-hero-btn2');

      if (label)  label.textContent  = d.label;
      if (line1)  line1.textContent  = d.title1;
      if (line2) {
        if (d.title2) { line2.textContent = d.title2; line2.style.display = ''; }
        else           line2.style.display = 'none';
      }
      if (desc) {
        if (d.desc) { desc.textContent = d.desc; desc.style.display = ''; }
        else         desc.style.display = 'none';
      }
      if (btn1) { btn1.textContent = d.btn1 + ' →'; btn1.href = d.btn1url; }
      if (btn2) {
        if (d.btn2) { btn2.textContent = d.btn2; btn2.href = d.btn2url; btn2.style.display = ''; }
        else         btn2.style.display = 'none';
      }
    }

    function startTimer() {
      clearInterval(timer);
      timer = setInterval( () => goTo(current + 1), 5500 );
    }

    dots.forEach( (dot, i) => dot.addEventListener('click', () => { goTo(i); startTimer(); }) );

    const hero = document.querySelector('.hero');
    if (hero) {
      hero.addEventListener('mouseenter', () => clearInterval(timer));
      hero.addEventListener('mouseleave', startTimer);
    }

    // Touch swipe
    let touchStartX = 0;
    if (hero) {
      hero.addEventListener('touchstart', e => { touchStartX = e.touches[0].clientX; }, { passive: true });
      hero.addEventListener('touchend', e => {
        const diff = touchStartX - e.changedTouches[0].clientX;
        if (Math.abs(diff) > 50) goTo(current + (diff > 0 ? 1 : -1));
        startTimer();
      }, { passive: true });
    }

    startTimer();
  }

  /* =====================================================
     2. MOBILE MENU
     ===================================================== */
  function initMobileMenu() {
    const toggleBtn = document.querySelector('.js-menu-toggle');
    const closeBtn  = document.querySelector('.js-menu-close');
    const nav       = document.getElementById('mobile-nav');
    if (!toggleBtn || !nav) return;

    function openMenu() {
      nav.classList.add('open');
      nav.setAttribute('aria-hidden', 'false');
      toggleBtn.setAttribute('aria-expanded', 'true');
      document.body.style.overflow = 'hidden';
    }

    function closeMenu() {
      nav.classList.remove('open');
      nav.setAttribute('aria-hidden', 'true');
      toggleBtn.setAttribute('aria-expanded', 'false');
      document.body.style.overflow = '';
    }

    toggleBtn.addEventListener('click', openMenu);
    if (closeBtn) closeBtn.addEventListener('click', closeMenu);

    // Close on overlay click
    nav.addEventListener('click', e => {
      if (e.target === nav) closeMenu();
    });

    // Close on Escape
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape' && nav.classList.contains('open')) closeMenu();
    });
  }

  /* =====================================================
     3. QUICK-ADD TO CART (AJAX)
     ===================================================== */
  function initQuickAdd() {
    document.addEventListener('click', function (e) {

      // Size selection
      const sizeBtn = e.target.closest('.size-btn, .product-size-btn');
      if (sizeBtn && !sizeBtn.classList.contains('out-of-stock')) {
        e.preventDefault();
        e.stopPropagation();
        const grid = sizeBtn.closest('.size-grid, .product-size-grid');
        grid.querySelectorAll('.size-btn, .product-size-btn').forEach(b => b.classList.remove('selected'));
        sizeBtn.classList.add('selected');

        const variationId = sizeBtn.dataset.variationId;
        const card = sizeBtn.closest('.product-card, .single-product-layout');
        if (card) {
          // Update add-btn label and store selected variation
          const addBtn = card.querySelector('.quick-add-btn, .product-add-btn');
          if (addBtn) {
            addBtn.textContent = addBtn.dataset.custom === '1' ? 'Продолжить — загрузить изображения' : 'В корзину';
            addBtn.disabled = false;
            addBtn.dataset.variationId = variationId;
          }
          // For single product form
          const hiddenInput = card.querySelector('.js-selected-variation');
          if (hiddenInput) hiddenInput.value = variationId;
        }
        return;
      }

      // Quick-add button (product grid cards)
      const quickBtn = e.target.closest('.quick-add-btn');
      if (quickBtn) {
        e.preventDefault();
        e.stopPropagation(); // не даём клику всплыть до <a>
        const card       = quickBtn.closest('.product-card, .product, li');
        const productId  = quickBtn.dataset.productId;
        const productType = quickBtn.dataset.productType;

        if (productType === 'variable') {
          const selected = card ? card.querySelector('.size-btn.selected') : null;
          if (!selected) {
            // Нет выбранного размера — переходим на страницу товара
            quickBtn.classList.add('is-loading');
            var link = card ? card.querySelector('a[href]') : null;
            if (link) {
              window.location.href = link.href;
            }
            return;
          }
          addToCart(productId, parseInt(selected.dataset.variationId), { [selected.dataset.attrKey]: selected.dataset.attrVal }, quickBtn);
        } else {
          addToCart(productId, productId, {}, quickBtn);
        }
        return;
      }
    });

    // Single product add-to-cart form
    document.addEventListener('submit', function (e) {
      const form = e.target.closest('.podval-add-to-cart-form');
      if (!form) return;
      e.preventDefault();

      const productId   = form.dataset.productId;
      const variationEl = form.querySelector('.js-selected-variation');
      const variationId = variationEl ? parseInt(variationEl.value) : 0;
      const btn         = form.querySelector('.sp-add-btn, .product-add-btn');
      const isVariable  = !!variationEl;
      const artworkUrl  = form.dataset.artworkUrl; // Custom-design redirect

      // Если вариативный и вариация не выбрана
      if (isVariable && !variationId) {
        const grid = document.querySelector('.sp-section');
        if (grid) { grid.style.outline = '2px solid rgba(255,60,60,0.5)'; setTimeout(() => grid.style.outline='', 700); }
        return;
      }

      // Collect attributes from selected buttons
      var attrs = {};
      if (isVariable && variationId) {
        document.querySelectorAll('.sp-size-btn.is-selected, .product-size-btn.selected').forEach(function(b) {
          var key = b.dataset.attrGroup || b.dataset.attrKey;
          var val = b.dataset.attrVal;
          if (key && val) attrs[key] = val;
        });
      }

      // Custom-design: redirect to artwork upload page (no cart add)
      if (artworkUrl) {
        if (btn) { btn.classList.add('is-loading'); btn.disabled = true; }
        var params = new URLSearchParams();
        params.set('product_id', productId);
        if (variationId && String(variationId) !== String(productId)) {
          params.set('variation_id', variationId);
        }
        for (var ak in attrs) {
          if (attrs.hasOwnProperty(ak)) {
            params.set('attr_' + ak, attrs[ak]);
          }
        }
        window.location.href = artworkUrl + '?' + params.toString();
        return;
      }

      // Standard product: add to cart
      if (isVariable && variationId) {
        addToCart(productId, variationId, attrs, btn);
      } else {
        addToCart(productId, productId, {}, btn);
      }
    });
  }

  function addToCart(productId, variationId, variation, btn) {
    var originalText = btn ? btn.textContent : '';
    if (btn) { btn.classList.add('is-loading'); btn.disabled = true; }

    var isVariation = variationId && parseInt(variationId) > 0 && String(variationId) !== String(productId);

    // POST через fetch — same-origin, куки передаются автоматически
    var formData = new FormData();
    formData.append('podval_add_item', '1');
    formData.append('podval_product_id', productId);
    formData.append('podval_quantity', '1');

    if (isVariation) {
      formData.append('podval_variation_id', variationId);
      if (variation && typeof variation === 'object') {
        Object.keys(variation).forEach(function(k) {
          var attrKey = k.indexOf('podval_attr_') === 0 ? k : 'podval_attr_' + k;
          formData.append(attrKey, variation[k]);
        });
      }
    }

    fetch(window.location.origin + window.location.pathname, {
      method: 'POST',
      credentials: 'same-origin',
      body: formData
    })
    .then(function(response) {
      return response.text();
    })
    .then(function(text) {
      var parts = text.trim().split('|');
      var status = parts[0];
      var detail = parts[1] || '';

      if (status === 'ok') {
        var serverCount = parseInt(detail) || 0;
        if (serverCount > 0) {
          updateCartCount(serverCount);
        } else {
          var countEl = document.querySelector('.cart-count, .js-cart-count');
          var cur = countEl ? (parseInt(countEl.textContent) || 0) : 0;
          updateCartCount(cur + 1);
        }
        /* Analytics: fire add_to_cart event */
        if (window.podvalAnalytics && window.podvalAnalytics.fireAddToCart) {
          window.podvalAnalytics.fireAddToCart();
        }
        if (btn) {
          btn.classList.remove('is-loading');
          btn.textContent = '✓ Добавлено';
          btn.disabled = false;
          setTimeout(function() {
            // После добавления меняем текст на "Ещё +1" вместо оригинального
            var goCart = document.getElementById('sp-go-cart');
            if (goCart && goCart.style.display === 'flex') {
              btn.textContent = 'Добавить ещё';
            } else {
              btn.textContent = originalText;
            }
          }, 1500);
        }
        // Show "Go to cart" button on single product page
        var goCartBtn = document.getElementById('sp-go-cart');
        if (goCartBtn) {
          // If artwork upload page is configured, build URL with product params
          var awUrl = goCartBtn.dataset.artworkUrl;
          if (awUrl) {
            var params = new URLSearchParams();
            params.set('product_id', productId);
            if (variationId && String(variationId) !== String(productId)) {
              params.set('variation_id', variationId);
            }
            if (variation && typeof variation === 'object') {
              for (var ak in variation) {
                if (variation.hasOwnProperty(ak)) {
                  params.set('attr_' + ak, variation[ak]);
                }
              }
            }
            goCartBtn.href = awUrl + '?' + params.toString();
          }
          goCartBtn.style.display = 'flex';
        }
        // Show custom design hint if present
        var customHint = document.getElementById('sp-custom-hint');
        if (customHint) {
          customHint.style.display = 'block';
        }
      } else {
        console.error('addToCart failed:', detail, parts[2] || '');
        if (btn) {
          btn.classList.remove('is-loading');
          btn.textContent = 'Ошибка';
          btn.disabled = false;
          setTimeout(function() { btn.textContent = originalText; }, 3000);
        }
      }
    })
    .catch(function(err) {
      console.error('addToCart error:', err);
      if (btn) { btn.classList.remove('is-loading'); btn.textContent = originalText; btn.disabled = false; }
    });
  }

  function updateCartCount(count) {
    document.querySelectorAll('.js-cart-count').forEach(el => {
      el.textContent = count;
      el.style.display = count > 0 ? 'flex' : 'none';
    });
    // If no count element exists, create one
    if (count > 0) {
      const icon = document.querySelector('.js-cart-icon');
      if (icon && !icon.querySelector('.js-cart-count')) {
        const span = document.createElement('span');
        span.className = 'header-action-count js-cart-count';
        span.textContent = count;
        icon.appendChild(span);
      }
    }
  }

  /* =====================================================
     4. PRODUCT THUMB HOVER SWAP
     ===================================================== */
  function initThumbHover() {
    document.querySelectorAll('[data-hover]').forEach(img => {
      const hover = img.dataset.hover;
      if (!hover) return;

      /* Capture the real original src right from the HTML attribute
         so it works regardless of lazy-loading or WebP rewriting state */
      const htmlSrc = img.getAttribute('src');

      img.addEventListener('mouseenter', () => {
        /* On first hover, if data-original is not yet set, capture
           whatever the browser actually resolved (WebP or original) */
        if (!img.dataset.original) {
          // currentSrc читается ДО снятия <source>: только он знает, какой
          // кадр браузер показывает на самом деле.
          img.dataset.original = img.currentSrc || img.src || htmlSrc;
        }
        window.podvalSwapImage(img, hover);
      });

      img.addEventListener('mouseleave', () => {
        window.podvalSwapImage(img, img.dataset.original || htmlSrc);
      });
    });
  }

  /* =====================================================
     5. SINGLE PRODUCT GALLERY
     ===================================================== */
  function initProductGallery() {
    const mainImg = document.getElementById('gallery-main-img');
    if (!mainImg) return;

    document.querySelectorAll('.gallery-thumb').forEach(thumb => {
      function activate() {
        window.podvalSwapImage(mainImg, thumb.dataset.large);
        document.querySelectorAll('.gallery-thumb').forEach(t => t.classList.remove('active'));
        thumb.classList.add('active');
      }
      thumb.addEventListener('click', activate);
      thumb.addEventListener('mouseenter', activate);
    });
  }

  /* =====================================================
     6. SEARCH TOGGLE (simple overlay)
     ===================================================== */
  function initSearch() {
    const toggleBtns = document.querySelectorAll('.js-search-toggle');
    if (!toggleBtns.length) return;

    // Create search overlay
    const overlay = document.createElement('div');
    overlay.id = 'search-overlay';
    overlay.style.cssText = `
      display:none;position:fixed;inset:0;background:rgba(10,10,10,0.97);
      z-index:500;align-items:center;justify-content:center;flex-direction:column;gap:24px;
    `;
    overlay.innerHTML = `
      <button id="search-close" style="position:absolute;top:24px;right:24px;background:none;border:none;color:var(--white);font-size:28px;cursor:pointer;line-height:1;">✕</button>
      <form role="search" method="get" action="${window.location.origin}" style="width:100%;max-width:600px;padding:0 24px;">
        <input type="search" name="s" placeholder="Поиск товаров..." autocomplete="off"
          style="width:100%;background:transparent;border:none;border-bottom:2px solid var(--accent);
                 color:var(--white);font-family:var(--font-display);font-size:clamp(28px,5vw,56px);
                 letter-spacing:0.04em;text-transform:uppercase;padding:8px 0;outline:none;caret-color:var(--accent);"
          id="search-input">
        <input type="hidden" name="post_type" value="product">
      </form>
      <p style="font-family:var(--font-body);font-size:10px;letter-spacing:0.2em;text-transform:uppercase;color:var(--gray-light);">Нажми Enter для поиска</p>
    `;
    document.body.appendChild(overlay);

    function openSearch() {
      overlay.style.display = 'flex';
      setTimeout(() => document.getElementById('search-input')?.focus(), 100);
    }
    function closeSearch() {
      overlay.style.display = 'none';
    }

    toggleBtns.forEach(btn => btn.addEventListener('click', e => { e.preventDefault(); openSearch(); }));
    document.getElementById('search-close')?.addEventListener('click', closeSearch);
    overlay.addEventListener('click', e => { if (e.target === overlay) closeSearch(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeSearch(); });
  }

  /* =====================================================
     7. STICKY HEADER SHRINK
     ===================================================== */
  function initStickyHeader() {
    const header = document.getElementById('site-header');
    if (!header) return;
    let lastY = 0;
    window.addEventListener('scroll', () => {
      const y = window.scrollY;
      if (y > 100) {
        header.style.borderBottomColor = 'rgba(255,255,255,0.12)';
      } else {
        header.style.borderBottomColor = '';
      }
      lastY = y;
    }, { passive: true });
  }


  /* =====================================================
     MOBILE NAV ACCORDION
     ===================================================== */
  function initMobileAccordion() {
    document.querySelectorAll('.js-mobile-toggle').forEach(btn => {
      btn.addEventListener('click', () => {
        const sub = btn.nextElementSibling;
        const isOpen = btn.classList.contains('is-open');
        // Close all
        document.querySelectorAll('.js-mobile-toggle').forEach(b => {
          b.classList.remove('is-open');
          if (b.nextElementSibling) b.nextElementSibling.classList.remove('is-open');
        });
        // Toggle clicked
        if (!isOpen) {
          btn.classList.add('is-open');
          if (sub) sub.classList.add('is-open');
        }
      });
    });
  }

  /* =====================================================
     INIT
     ===================================================== */
  document.addEventListener('DOMContentLoaded', () => {
    // initHeroSlider();  // disabled главный слайдер обслуживается
    // встроенным скриптом в index.php; параллельный таймер тут вызывал двойные
    // opacity-переходы и постоянную нагрузку на GPU (compositor).
    initMobileMenu();
    initQuickAdd();
    initThumbHover();
    initProductGallery();
    initSearch();
    initStickyHeader();
    initMobileAccordion();
  });

})();

/* ═══════════════════════════════════════════
   COOKIE CONSENT
   ═══════════════════════════════════════════ */
(function() {
  var banner = document.getElementById('podval-cookie');
  if (!banner) return;

  // Check if already answered
  try {
    var consent = localStorage.getItem('podval_cookie_consent');
    if (consent) return; // Already accepted or declined
  } catch(e) {}

  // Show after short delay
  setTimeout(function() {
    banner.classList.add('is-visible');
  }, 1500);

  var acceptBtn = document.getElementById('podval-cookie-accept');
  var declineBtn = document.getElementById('podval-cookie-decline');

  if (acceptBtn) {
    acceptBtn.addEventListener('click', function() {
      try { localStorage.setItem('podval_cookie_consent', 'accepted'); } catch(e) {}
      banner.classList.remove('is-visible');
    });
  }

  if (declineBtn) {
    declineBtn.addEventListener('click', function() {
      try { localStorage.setItem('podval_cookie_consent', 'declined'); } catch(e) {}
      banner.classList.remove('is-visible');
    });
  }
})();

/* ═══════════════════════════════════════════
   DEFAULT ATTRIBUTES — Pre-select on load
   ═══════════════════════════════════════════ */
(function() {
  if (typeof podvalDefaultAttrs === 'undefined' || !podvalDefaultAttrs) return;

  setTimeout(function() {
    Object.keys(podvalDefaultAttrs).forEach(function(groupKey) {
      var val = podvalDefaultAttrs[groupKey];
      if (!val) return;

      // Ищем секцию по data-attr-group — пробуем разные варианты ключа
      var section = null;
      var candidates = [groupKey];
      try { candidates.push(decodeURIComponent(groupKey)); } catch(e) {}
      try { candidates.push(encodeURIComponent(groupKey)); } catch(e) {}

      for (var i = 0; i < candidates.length; i++) {
        // CSS.escape для безопасных селекторов с кириллицей и спецсимволами
        try {
          section = document.querySelector('.sp-section[data-attr-group="' + candidates[i] + '"]');
        } catch(e) {}
        if (section) break;
      }

      // Fallback: перебираем все секции и сравниваем data-attr-group декодированно
      if (!section) {
        var allSections = document.querySelectorAll('.sp-section[data-attr-group]');
        var keyDecoded = groupKey;
        try { keyDecoded = decodeURIComponent(groupKey); } catch(e) {}
        for (var j = 0; j < allSections.length; j++) {
          var sKey = allSections[j].getAttribute('data-attr-group') || '';
          var sDecoded = sKey;
          try { sDecoded = decodeURIComponent(sKey); } catch(e) {}
          if (sDecoded.toLowerCase() === keyDecoded.toLowerCase() || sKey === groupKey) {
            section = allSections[j];
            break;
          }
        }
      }

      // Fallback: search .sp-segment and .sp-toggle containers
      if (!section) {
        var altContainers = document.querySelectorAll('.sp-segment[data-attr-group]');
        var keyDecoded2 = groupKey;
        try { keyDecoded2 = decodeURIComponent(groupKey); } catch(e) {}
        for (var k = 0; k < altContainers.length; k++) {
          var aKey = altContainers[k].getAttribute('data-attr-group') || '';
          var aDecoded = aKey;
          try { aDecoded = decodeURIComponent(aKey); } catch(e) {}
          if (aDecoded.toLowerCase() === keyDecoded2.toLowerCase() || aKey === groupKey) {
            section = altContainers[k];
            break;
          }
        }
      }

      // Last fallback: find buttons directly by data-attr-group
      var buttons;
      if (section) {
        buttons = section.querySelectorAll('.sp-size-btn');
      } else {
        // Search all buttons matching this group key
        buttons = document.querySelectorAll('.sp-size-btn');
        var keyDecoded3 = groupKey;
        try { keyDecoded3 = decodeURIComponent(groupKey); } catch(e) {}
        var filtered = [];
        buttons.forEach(function(b) {
          var bg = (b.getAttribute('data-attr-group') || '');
          var bgDecoded = bg;
          try { bgDecoded = decodeURIComponent(bg); } catch(e) {}
          if (bg === groupKey || bgDecoded.toLowerCase() === keyDecoded3.toLowerCase()) {
            filtered.push(b);
          }
        });
        buttons = filtered;
      }
      var valLower = val.toLowerCase();
      var valDecoded = valLower;
      try { valDecoded = decodeURIComponent(valLower); } catch(e) {}

      buttons.forEach(function(btn) {
        var btnVal = (btn.getAttribute('data-attr-val') || '').toLowerCase();
        var btnValDecoded = btnVal;
        try { btnValDecoded = decodeURIComponent(btnVal); } catch(e) {}
        // Также сравниваем по видимому тексту кнопки (label)
        var btnText = (btn.textContent || '').trim().toLowerCase();

        if (btnVal === valLower || btnValDecoded === valLower
            || btnVal === valDecoded || btnValDecoded === valDecoded
            || btnText === valLower || btnText === valDecoded) {
          btn.click();
        }
      });
    });
  }, 150);
})();

/* ═══════════════════════════════════════════
   SIZE CHART — popup open/close + tabs
   ═══════════════════════════════════════════ */
(function() {
  /* попапов на странице может быть несколько: на бандле каждая
     футболка ссылается на свою таблицу (id вида sc-popup-<meta>).
     Раньше блок брал жёстко #size-chart-popup и на бандле молча выходил. */
  var popups = document.querySelectorAll('.sc-overlay');
  if (!popups.length) return;

  function closePopup(popup) {
    popup.classList.remove('is-open');
    document.body.style.overflow = '';
  }

  // Open — цель берём из data-sc-target, иначе первый попап на странице
  document.querySelectorAll('.js-size-chart-open').forEach(function(link) {
    link.addEventListener('click', function(e) {
      e.preventDefault();
      var id     = link.getAttribute('data-sc-target');
      var target = id ? document.getElementById(id) : null;
      if (!target) target = document.getElementById('size-chart-popup') || popups[0];
      if (!target) return;
      target.classList.add('is-open');
      document.body.style.overflow = 'hidden';
    });
  });

  popups.forEach(function(popup) {
    // Close
    popup.querySelectorAll('.js-sc-close').forEach(function(el) {
      el.addEventListener('click', function(e) {
        if (e.target === popup || e.target.closest('.sc-close')) {
          closePopup(popup);
        }
      });
    });

    // Tabs (Classic / Oversize)
    var tabs   = popup.querySelectorAll('.sc-tab');
    var panels = popup.querySelectorAll('.sc-panel');
    tabs.forEach(function(tab) {
      tab.addEventListener('click', function() {
        var target = tab.dataset.tab;
        tabs.forEach(function(t) { t.classList.remove('is-active'); });
        panels.forEach(function(pn) { pn.classList.remove('is-active'); });
        tab.classList.add('is-active');
        var panel = popup.querySelector('[data-panel="' + target + '"]');
        if (panel) panel.classList.add('is-active');
      });
    });
  });

  // Close on Escape — закрываем любой открытый
  document.addEventListener('keydown', function(e) {
    if (e.key !== 'Escape') return;
    popups.forEach(function(popup) {
      if (popup.classList.contains('is-open')) closePopup(popup);
    });
  });
})();

/* ═══════════════════════════════════════════
   CUSTOM DESIGN — FAQ accordion + smooth scroll
   ═══════════════════════════════════════════ */
(function() {
  // FAQ
  document.querySelectorAll('.js-cd-faq').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var answer = btn.nextElementSibling;
      if (!answer) return;
      var isOpen = answer.classList.contains('is-open');
      // Close all
      document.querySelectorAll('.cd-faq__a.is-open').forEach(function(a) { a.classList.remove('is-open'); });
      document.querySelectorAll('.cd-faq__q.is-open').forEach(function(q) { q.classList.remove('is-open'); });
      if (!isOpen) {
        answer.classList.add('is-open');
        btn.classList.add('is-open');
      }
    });
  });

  // Smooth scroll for nav buttons
  document.querySelectorAll('.cd-nav__btn').forEach(function(link) {
    link.addEventListener('click', function(e) {
      var href = link.getAttribute('href');
      if (href && href.charAt(0) === '#') {
        e.preventDefault();
        var target = document.querySelector(href);
        if (target) {
          target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      }
    });
  });
})();

/* ═══════════════════════════════════════════
   GLOBAL NAVIGATION LOADING FEEDBACK
   Progress bar + button spinners + double-click prevention
   ═══════════════════════════════════════════ */
(function() {
  // Create progress bar element
  var bar = document.createElement('div');
  bar.className = 'podval-progress';
  document.body.appendChild(bar);

  var isNavigating = false;

  function startProgress() {
    if (isNavigating) return;
    isNavigating = true;
    bar.className = 'podval-progress';
    void bar.offsetWidth;
    bar.classList.add('is-active');
  }

  // 1. Product card clicks — overlay + progress bar
  document.addEventListener('click', function(e) {
    if (e.target.closest('.quick-add-btn, .size-btn, .product-quick-add, button, [type="submit"]')) return;
    var cardLink = e.target.closest('.product-card a[href], .product-card-thumb[href]');
    if (cardLink) {
      var card = cardLink.closest('.product-card');
      if (card && !card.classList.contains('is-loading-nav')) {
        card.classList.add('is-loading-nav');
        startProgress();
      }
    }
  });

  // 2. Navigation buttons (go-to-cart, artwork buttons) — spinner + progress bar
  document.addEventListener('click', function(e) {
    var navBtn = e.target.closest('.sp-go-cart-btn, .aw-btn--outline');
    if (navBtn && navBtn.href && !navBtn.classList.contains('is-loading')) {
      navBtn.classList.add('is-loading');
      startProgress();
    }
  });

  // 3. Before page unload — complete progress bar
  window.addEventListener('beforeunload', function() {
    bar.className = 'podval-progress is-done';
  });

  // 4. bfcache — reset all loading states
  // When user hits browser Back/Forward, the page is restored from bfcache
  // with identical DOM state — which means any button that had .is-loading
  // (e.g. «Продолжить — загрузить изображения» after clicking it) is still
  // spinning. We clear EVERYTHING that was mid-transition: any in-flight
  // fetch was aborted when the page was frozen, so restoring these states
  // to "disabled/loading" is always wrong on a bfcache restore.
  window.addEventListener('pageshow', function(e) {
    if (e.persisted) {
      isNavigating = false;
      bar.className = 'podval-progress';
      document.querySelectorAll('.is-loading-nav').forEach(function(el) {
        el.classList.remove('is-loading-nav');
      });
      document.querySelectorAll('.is-loading').forEach(function(el) {
        el.classList.remove('is-loading');
        if (el.tagName === 'BUTTON' || el.tagName === 'INPUT') {
          el.disabled = false;
        }
      });
    }
  });
})();

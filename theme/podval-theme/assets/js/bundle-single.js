/**
 * Single bundle configurator.
 *
 * Управляет интерактивом на странице одного бандла:
 *  - color / size / type selectors (single-choice per slot)
 *  - "исключить из набора" toggle (понижает скидку по шкале)
 *  - realtime пересчёт цены за каждую футболку и итоговой
 *  - реактивный timeline «Как работает скидка» (current/achievable/locked)
 *  - sticky bottom bar появляется когда hero уезжает вверх
 *  - smart sync с hero-tiles (excluded подсветка)
 *  - anchor scroll по data-scroll-to кнопкам
 *  - AJAX add-to-cart с _bundle_meta агрегацией
 *
 * Глобал window.PodvalBundleSingle инициализируется в шаблоне:
 *   {
 *     bundleId, bundleName, bundleSlug,
 *     shirts: [
 *       { product_id, name, image, price, is_variable,
 *         attributes: { color, size, type }, defaults, variations, error },
 *       ...
 *     ],
 *     discountScale: [0, 5, 10, 15, 20],
 *     totalShirts: 4,
 *     attrUnion: ['type','color','size'],
 *     ajax: { url, nonce },
 *     cartUrl: '/cart/'
 *   }
 *
 *
 * @version 2.5.5
 */
(function () {
    'use strict';

    if (typeof window.PodvalBundleSingle === 'undefined') {
        return;
    }

    var page = document.querySelector('[data-bundle-page]');
    if (!page) return;

    var DATA   = window.PodvalBundleSingle;
    var SHIRTS = DATA.shirts || [];
    var SCALE  = DATA.discountScale || [0, 5, 10, 15, 20];
    var DTYPE  = DATA.discountType === 'fixed' ? 'fixed' : 'percent';
    var TOTAL  = DATA.totalShirts || SHIRTS.length;
    var AJAX   = DATA.ajax || {};
    var CART_URL = DATA.cartUrl || '/cart/';

    // Состояние: для каждого slot текущая selection
    // state[idx] = {color, size, type, excluded, price}
    var state = SHIRTS.map(function (s) {
        return {
            color:    (s.defaults && s.defaults.color) || '',
            size:     (s.defaults && s.defaults.size)  || '',
            type:     (s.defaults && s.defaults.type)  || '',
            excluded: false,
            price:    parseFloat(s.price) || 0
        };
    });

    /* ════════════════════════════════════════════════════════════
       UTILS
       ════════════════════════════════════════════════════════════ */

    function ruWord(n, one, few, many) {
        var n10 = n % 10, n100 = n % 100;
        if (n100 >= 11 && n100 <= 14) return many;
        if (n10 === 1) return one;
        if (n10 >= 2 && n10 <= 4) return few;
        return many;
    }

    function shirtsWord(n) {
        return n + ' ' + ruWord(n, 'футболка', 'футболки', 'футболок');
    }

    function getScaleRaw(activeCount) {
        if (activeCount <= 0) return 0;
        var idx = Math.min(activeCount, SCALE.length) - 1;
        return parseInt(SCALE[idx], 10) || 0;
    }

    /**
     * зеркало PHP podval_bundle_resolve_discount().
     * Возвращает {amount, percent}. Для fixed сумма клампится по subtotal,
     * чтобы клиентский расчёт совпал с серверным при добавлении в корзину.
     */
    function resolveDiscount(subtotal, activeCount) {
        var raw = getScaleRaw(activeCount);
        var amount;
        if (DTYPE === 'fixed') {
            amount = Math.min(raw, subtotal);
        } else {
            amount = subtotal * Math.max(0, Math.min(100, raw)) / 100;
        }
        amount = Math.round(amount * 100) / 100;
        return {
            amount:  amount,
            percent: subtotal > 0 ? Math.round(amount / subtotal * 100) : 0
        };
    }

    function fmtDiscount(res) {
        if (DTYPE === 'fixed') {
            return '−<span data-price-byn="' + res.amount + '">' +
                   res.amount.toFixed(2).replace('.', ',') + '&nbsp;Br</span>';
        }
        return '−' + res.percent + '%';
    }

    function setDiscountLabel(el, res) {
        if (!el) return;
        el.innerHTML = fmtDiscount(res);
    }

    function getDiscount(activeCount) {
        return getScaleRaw(activeCount);
    }

    function getBestDiscount() {
        return getDiscount(TOTAL);
    }

    /**
     * Находит variation в slot матчащую текущую selection state.
     * Возвращает {variation, price, image} или null.
     */
    function matchVariation(idx) {
        var slot = SHIRTS[idx];
        var st = state[idx];
        if (!slot || !slot.variations || !slot.variations.length) return null;
        for (var i = 0; i < slot.variations.length; i++) {
            var v = slot.variations[i];
            var attrs = v.attributes || {};
            var match = true;
            if (slot.attributes && slot.attributes.color && attrs.color && attrs.color !== st.color) match = false;
            if (slot.attributes && slot.attributes.size  && attrs.size  && attrs.size  !== st.size)  match = false;
            if (slot.attributes && slot.attributes.type  && attrs.type  && attrs.type  !== st.type)  match = false;
            if (match) return v;
        }
        return null;
    }

    function getSlotPrice(idx) {
        var slot = SHIRTS[idx];
        if (!slot) return 0;
        if (!slot.is_variable || !slot.variations || !slot.variations.length) {
            return parseFloat(slot.price) || 0;
        }
        var v = matchVariation(idx);
        if (v) {
            var p = parseFloat(v.price);
            if (p > 0) return p;
        }
        return parseFloat(slot.price) || 0;
    }

    function getSlotImage(idx) {
        var slot = SHIRTS[idx];
        if (!slot) return '';
        var v = matchVariation(idx);
        if (v && v.image) return v.image;
        return slot.image || '';
    }

    /* ════════════════════════════════════════════════════════════
       RECALC + TIMELINE
       ════════════════════════════════════════════════════════════ */

    function setBynPrice(el, byn) {
        if (!el) return;
        el.setAttribute('data-price-byn', byn);
        el.innerHTML = byn.toFixed(2).replace('.', ',') + '&nbsp;Br';
    }

    function updateTimeline(activeCount, discRes, saved) {
        var timeline = page.querySelector('[data-timeline]');
        if (!timeline) return;

        var steps = timeline.querySelectorAll('[data-tstep]');
        var lines = timeline.querySelectorAll('[data-tline]');
        var isFullSet = (activeCount === TOTAL);

        // Steps в DOM идут сверху вниз: TOTAL → 1.
        // current = sc === activeCount (если full set — это «max»)
        // achievable = sc > activeCount (выше выгода доступна)
        // locked = sc < activeCount
        steps.forEach(function (step) {
            var sc  = parseInt(step.getAttribute('data-step-count'), 10);
            var cap = step.querySelector('[data-tstep-cap]');
            step.classList.remove(
                'tstep--current', 'tstep--current-full',
                'tstep--achievable', 'tstep--locked'
            );
            if (cap) cap.classList.remove('tstep__cap--current');

            if (sc === activeCount) {
                if (isFullSet) {
                    step.classList.add('tstep--current-full');
                } else {
                    step.classList.add('tstep--current');
                }
                if (cap) {
                    if (isFullSet) {
                        cap.textContent = '✓ лучшая скидка';
                    } else {
                        cap.textContent = '↑ текущий выбор';
                        cap.classList.add('tstep__cap--current');
                    }
                }
            } else if (sc > activeCount) {
                step.classList.add('tstep--achievable');
                if (cap) {
                    cap.textContent = (sc === TOTAL) ? 'полный набор' : 'добавь футболку';
                }
            } else {
                step.classList.add('tstep--locked');
                if (cap) {
                    cap.textContent = (sc === 1) ? 'без скидки' : 'меньше выгода';
                }
            }
        });

        // Линии-коннекторы.
        lines.forEach(function (line, i) {
            var top = steps[i];
            var bot = steps[i + 1];
            if (!top || !bot) return;
            var topLocked = top.classList.contains('tstep--locked');
            var botLocked = bot.classList.contains('tstep--locked');
            line.classList.toggle('tline--on',  !topLocked && !botLocked);
            line.classList.toggle('tline--off',  topLocked ||  botLocked);
        });

        // Result box
        var resCount = timeline.querySelector('[data-tres-count]');
        var resPill  = timeline.querySelector('[data-tres-pill]');
        var resSave  = timeline.querySelector('[data-tres-save]');
        if (resCount) resCount.textContent = activeCount;
        if (resPill) {
            resPill.innerHTML = fmtDiscount(discRes);
            resPill.classList.toggle('tres__pill--best', isFullSet);
        }
        if (resSave) {
            if (saved > 0) {
                var savedTxt = saved.toFixed(2).replace('.', ',') + '&nbsp;Br';
                resSave.innerHTML = 'Экономия: <span data-price-byn="' + saved + '">' + savedTxt + '</span>';
            } else {
                resSave.innerHTML = '';
            }
        }
    }

    function recalc() {
        var activeCount = 0;
        var excludedCount = 0;
        var fullSum = 0;
        var activeSum = 0;

        state.forEach(function (st, idx) {
            var p = getSlotPrice(idx);
            st.price = p;
            fullSum += p;
            if (st.excluded) {
                excludedCount++;
            } else {
                activeCount++;
                activeSum += p;
            }
        });

        var discRes   = resolveDiscount(activeSum, activeCount);
        var bestRes   = resolveDiscount(activeSum, TOTAL);
        var discount  = discRes.percent;
        var bestDisc  = bestRes.percent;
        var nowPrice  = Math.round(activeSum - discRes.amount);
        var fullPrice = Math.round(activeSum);
        var saved     = fullPrice - nowPrice;

        /* Stamp в hero */
        var stampPct   = page.querySelector('[data-stamp-pct]');
        var stampCount = page.querySelector('[data-stamp-count]');
        setDiscountLabel(stampPct, discRes);
        if (stampCount) stampCount.textContent = 'за ' + activeCount + ' из ' + TOTAL;

        /* Subtitle */
        var infoSub = page.querySelector('[data-info-sub]');
        if (infoSub) infoSub.textContent = shirtsWord(activeCount) + ' в наборе';

        /* Price block */
        var discPct = page.querySelector('[data-discount-pct]');
        var nowEl   = page.querySelector('[data-price-now]');
        var oldEl   = page.querySelector('[data-price-old]');
        var saveEl  = page.querySelector('[data-price-save]');
        setDiscountLabel(discPct, discRes);
        setBynPrice(nowEl, nowPrice);
        if (oldEl) {
            if (saved > 0) {
                setBynPrice(oldEl, fullPrice);
                oldEl.hidden = false;
            } else {
                oldEl.hidden = true;
            }
        }
        if (saveEl) {
            if (saved > 0) {
                var savedRu = saved.toFixed(2).replace('.', ',') + '&nbsp;Br';
                saveEl.innerHTML = 'Экономия <span data-price-byn="' + saved + '">' + savedRu + '</span>';
                saveEl.hidden = false;
            } else {
                saveEl.hidden = true;
            }
        }

        /* Warning bar */
        var warn      = page.querySelector('[data-warn]');
        var warnCount = page.querySelector('[data-warn-count]');
        var warnBest  = page.querySelector('[data-warn-best-pct]');
        var warnNow   = page.querySelector('[data-warn-now-pct]');
        if (warn) {
            if (excludedCount > 0 && bestDisc > discount) {
                warn.hidden = false;
                if (warnCount) warnCount.textContent = excludedCount + ' ' + ruWord(excludedCount, 'футболка', 'футболки', 'футболок');
                if (warnBest)  warnBest.innerHTML = DTYPE === 'fixed'
                    ? bestRes.amount.toFixed(2).replace('.', ',') + '&nbsp;Br'
                    : bestDisc + '%';
                if (warnNow)   warnNow.innerHTML  = DTYPE === 'fixed'
                    ? discRes.amount.toFixed(2).replace('.', ',') + '&nbsp;Br'
                    : discount + '%';
            } else {
                warn.hidden = true;
            }
        }

        /* Sticky bar */
        var sSub = page.querySelector('[data-sticky-sub]');
        var sNow = page.querySelector('[data-sticky-now]');
        var sOld = page.querySelector('[data-sticky-old]');
        if (sSub) sSub.textContent = activeCount + ' из ' + TOTAL + ' футболок';
        setBynPrice(sNow, nowPrice);
        if (sOld) {
            if (saved > 0) {
                setBynPrice(sOld, fullPrice);
                sOld.hidden = false;
            } else {
                sOld.hidden = true;
            }
        }

        /* Hero tiles excluded sync (только class — overlay/ribbon через CSS ::after) */
        page.querySelectorAll('.bhero-tee').forEach(function (tile) {
            var sid = tile.getAttribute('data-shirt-id');
            var matchIdx = -1;
            for (var i = 0; i < SHIRTS.length; i++) {
                if (String(SHIRTS[i].product_id) === String(sid)) { matchIdx = i; break; }
            }
            if (matchIdx === -1) return;
            var isEx = state[matchIdx].excluded;
            tile.classList.toggle('bhero-tee--excluded', isEx);
        });

        /* Per-slot price labels + image swap */
        page.querySelectorAll('[data-bundle-item]').forEach(function (card) {
            var idx = parseInt(card.getAttribute('data-shirt-idx'), 10);
            if (isNaN(idx) || !state[idx]) return;
            var label = card.querySelector('[data-shirt-price]');
            if (label) setBynPrice(label, state[idx].price);

            var imgEl = card.querySelector('[data-shirt-img]');
            if (imgEl) {
                var newImg = getSlotImage(idx);
                if (newImg && imgEl.getAttribute('src') !== newImg) {
                    imgEl.setAttribute('src', newImg);
                }
            }
        });

        /* TIMELINE — реактив */
        updateTimeline(activeCount, discRes, saved);

        /* Disable add-to-cart если активных = 0 */
        page.querySelectorAll('[data-add-to-cart]').forEach(function (b) {
            b.disabled = (activeCount === 0);
            b.classList.toggle('is-disabled', activeCount === 0);
        });

        /* Конвертация в текущую валюту */
        if (window.PodvalCurrency && typeof window.PodvalCurrency.refresh === 'function') {
            window.PodvalCurrency.refresh();
        }
    }

    /* ════════════════════════════════════════════════════════════
       EVENT HANDLERS — селекторы атрибутов + exclude toggle
       ════════════════════════════════════════════════════════════ */

    page.querySelectorAll('[data-bundle-item]').forEach(function (card) {
        var idx = parseInt(card.getAttribute('data-shirt-idx'), 10);
        if (isNaN(idx)) return;

        var typeGroup = card.querySelector('[data-attr-group="type"]');
        if (typeGroup) {
            typeGroup.addEventListener('click', function (e) {
                var btn = e.target.closest('.size-btn');
                if (!btn || btn.disabled) return;
                typeGroup.querySelectorAll('.size-btn').forEach(function (b) { b.classList.remove('is-active'); });
                btn.classList.add('is-active');
                state[idx].type = btn.getAttribute('data-value');
                recalc();
            });
        }

        var sizeGroup = card.querySelector('[data-attr-group="size"]');
        if (sizeGroup) {
            sizeGroup.addEventListener('click', function (e) {
                var btn = e.target.closest('.size-btn');
                if (!btn || btn.disabled) return;
                sizeGroup.querySelectorAll('.size-btn').forEach(function (b) { b.classList.remove('is-active'); });
                btn.classList.add('is-active');
                state[idx].size = btn.getAttribute('data-value');
                recalc();
            });
        }

        var colorGroup = card.querySelector('[data-attr-group="color"]');
        if (colorGroup) {
            colorGroup.addEventListener('click', function (e) {
                var sw = e.target.closest('.sw');
                if (!sw || sw.classList.contains('is-disabled')) return;
                colorGroup.querySelectorAll('.sw').forEach(function (s) { s.classList.remove('is-active'); });
                sw.classList.add('is-active');
                state[idx].color = sw.getAttribute('data-value');
                recalc();
            });
        }

        var toggle = card.querySelector('.bi-toggle');
        if (toggle) {
            toggle.addEventListener('click', function (e) {
                if (e.target.matches('.bi-toggle__input')) return;
                e.preventDefault();
                var willExclude = !state[idx].excluded;
                state[idx].excluded = willExclude;
                card.classList.toggle('bi-card--excluded', willExclude);

                var shirt = card.querySelector('.bi-shirt');
                if (shirt) shirt.classList.toggle('bi-shirt--excluded', willExclude);
                var overlay = card.querySelector('.bi-shirt .excluded-overlay');
                if (overlay) overlay.hidden = !willExclude;

                var input = card.querySelector('.bi-toggle__input');
                if (input) input.checked = willExclude;
                var sw = card.querySelector('.switch');
                if (sw) sw.classList.toggle('switch--on', willExclude);

                card.querySelectorAll('.size-btn').forEach(function (b) { b.disabled = willExclude; });
                card.querySelectorAll('.sw').forEach(function (s) { s.classList.toggle('is-disabled', willExclude); });

                recalc();
            });
        }
    });

    /* ════════════════════════════════════════════════════════════
       ANCHOR SCROLL — data-scroll-to кнопки
       ════════════════════════════════════════════════════════════ */
    page.querySelectorAll('[data-scroll-to]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            e.preventDefault();
            var sel = el.getAttribute('data-scroll-to');
            var target = sel ? document.querySelector(sel) : null;
            if (!target) return;
            // Offset под фиксированный header.
            var headerH = 0;
            var siteHeader = document.querySelector('.site-header');
            if (siteHeader) headerH = siteHeader.offsetHeight || 0;
            var top = target.getBoundingClientRect().top + window.pageYOffset - headerH - 12;
            window.scrollTo({ top: top, behavior: 'smooth' });
        });
    });

    /* ════════════════════════════════════════════════════════════
       STICKY BAR (mobile)
       ════════════════════════════════════════════════════════════ */
    (function () {
        var bar = page.querySelector('[data-sticky-bar]');
        var hero = page.querySelector('.bundle-hero');
        if (!bar || !hero || !('IntersectionObserver' in window)) return;
        var obs = new IntersectionObserver(function (entries) {
            entries.forEach(function (e) {
                bar.classList.toggle('is-visible', !e.isIntersecting);
                bar.setAttribute('aria-hidden', e.isIntersecting ? 'true' : 'false');
            });
        }, { rootMargin: '-100px 0px 0px 0px' });
        obs.observe(hero);
    })();

    /* ════════════════════════════════════════════════════════════
       TOAST
       ════════════════════════════════════════════════════════════ */
    var toastEl   = page.querySelector('[data-toast]');
    var toastTimer = null;
    function toast(html, kind, durationMs) {
        if (!toastEl) return;
        kind = kind || 'info';
        durationMs = durationMs || 4500;
        toastEl.className = 'bundle-toast bundle-toast--' + kind + ' is-visible';
        toastEl.innerHTML = html;
        toastEl.hidden = false;
        if (toastTimer) clearTimeout(toastTimer);
        toastTimer = setTimeout(function () {
            toastEl.classList.remove('is-visible');
            setTimeout(function () { toastEl.hidden = true; }, 300);
        }, durationMs);
    }

    /* ════════════════════════════════════════════════════════════
       BUILD PAYLOAD (для AJAX)
       ════════════════════════════════════════════════════════════ */
    function buildPayload() {
        var shirts = [];
        state.forEach(function (st, idx) {
            if (st.excluded) return;
            var slot = SHIRTS[idx];
            if (!slot || slot.error) return;
            var v = matchVariation(idx);
            shirts.push({
                slot_idx:     idx,
                product_id:   parseInt(slot.product_id, 10),
                variation_id: v ? parseInt(v.id, 10) : 0,
                color:        st.color,
                size:         st.size,
                type:         st.type
            });
        });
        return {
            bundle_id: DATA.bundleId,
            shirts:    shirts
        };
    }

    /* ════════════════════════════════════════════════════════════
       PRE-FLIGHT VALIDATION (до отправки AJAX)
       Возвращает {ok: true} либо {ok: false, message, slotIdx}.
       ════════════════════════════════════════════════════════════ */
    function preflightCheck() {
        for (var idx = 0; idx < state.length; idx++) {
            var st = state[idx];
            if (st.excluded) continue;
            var slot = SHIRTS[idx];
            if (!slot || slot.error) continue;
            if (!slot.is_variable) continue;

            var v = matchVariation(idx);
            if (!v || !v.id) {
                // Найти какие именно атрибуты не выбраны
                var missing = [];
                if (slot.attributes) {
                    if (slot.attributes.color && !st.color) missing.push('цвет');
                    if (slot.attributes.size  && !st.size)  missing.push('размер');
                    if (slot.attributes.type  && !st.type)  missing.push('крой');
                }
                if (!missing.length) missing.push('параметры');
                return {
                    ok: false,
                    slotIdx: idx,
                    message: 'Для футболки №' + (idx + 1) + ' («' + slot.name + '») выберите: ' + missing.join(', ')
                };
            }
        }
        return { ok: true };
    }

    /* Подсветка проблемной карточки + скролл к ней */
    function flashInvalidCard(slotIdx) {
        var card = page.querySelector('[data-bundle-item][data-shirt-idx="' + slotIdx + '"]');
        if (!card) return;
        card.classList.add('bi-card--invalid');
        setTimeout(function () { card.classList.remove('bi-card--invalid'); }, 3000);
        // Скролл если карточка не в viewport
        var rect = card.getBoundingClientRect();
        if (rect.top < 0 || rect.bottom > window.innerHeight) {
            var headerH = 0;
            var siteHeader = document.querySelector('.site-header');
            if (siteHeader) headerH = siteHeader.offsetHeight || 0;
            var top = rect.top + window.pageYOffset - headerH - 16;
            window.scrollTo({ top: top, behavior: 'smooth' });
        }
    }

    /* ════════════════════════════════════════════════════════════
       ADD TO CART — реальный AJAX
       ════════════════════════════════════════════════════════════ */
    page.querySelectorAll('[data-add-to-cart]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            if (btn.disabled || btn.classList.contains('is-loading')) return;

            var activeCount = state.filter(function (s) { return !s.excluded; }).length;
            if (activeCount === 0) {
                toast('Выберите хотя бы одну футболку.', 'error');
                return;
            }

            // Pre-flight: все ли атрибуты заполнены
            var check = preflightCheck();
            if (!check.ok) {
                toast(check.message, 'error', 5500);
                if (typeof check.slotIdx === 'number') flashInvalidCard(check.slotIdx);
                return;
            }

            if (!AJAX.url || !AJAX.nonce) {
                toast('Ошибка конфигурации. Перезагрузите страницу.', 'error');
                return;
            }

            // Loading state — на ВСЕХ add-to-cart кнопках (header + sticky)
            page.querySelectorAll('[data-add-to-cart]').forEach(function (b) {
                b.classList.add('is-loading');
                b.disabled = true;
            });

            var payload = buildPayload();
            var body = new FormData();
            body.append('action', 'podval_bundle_add_to_cart');
            body.append('nonce',  AJAX.nonce);
            body.append('payload', JSON.stringify(payload));

            fetch(AJAX.url, {
                method: 'POST',
                credentials: 'same-origin',
                body: body
            }).then(function (r) {
                return r.json().catch(function () {
                    return { success: false, data: { message: 'Сервер вернул некорректный ответ.' } };
                });
            }).then(function (res) {
                if (window.console) console.log('[podval-bundle] AJAX response:', res);

                if (res && res.success) {
                    var msg = (res.data && res.data.message) || 'Бандл добавлен в корзину';
                    var html = msg + ' <a href="' + CART_URL + '">Перейти в корзину →</a>';
                    toast(html, 'success', 6000);

                    // Обновить counter в header
                    if (res.data && typeof res.data.cart_count !== 'undefined') {
                        var newCount = parseInt(res.data.cart_count, 10) || 0;
                        var counterEls = document.querySelectorAll('.js-cart-count, .cart-count');
                        if (window.console) {
                            console.log(
                                '[podval-bundle] cart_count:', newCount,
                                '/ cart_items_count:', res.data.cart_items_count,
                                '/ updating', counterEls.length, 'counter el(s)'
                            );
                        }
                        counterEls.forEach(function (el) {
                            el.textContent = newCount;
                            el.classList.toggle('is-empty', newCount === 0);
                            el.removeAttribute('hidden');
                        });
                    }

                    // Обновить WC fragments (на случай если используется)
                    if (window.jQuery && window.jQuery.fn && window.jQuery.fn.trigger) {
                        window.jQuery(document.body).trigger('wc_fragment_refresh');
                        window.jQuery(document.body).trigger('added_to_cart');
                    }
                } else {
                    var err = (res && res.data && res.data.message) || 'Не удалось добавить в корзину.';
                    toast(err, 'error', 6000);
                    if (window.console && res && res.data && res.data.debug) {
                        console.warn('[podval-bundle] add_to_cart failed:', res.data.debug);
                    }
                }
            }).catch(function () {
                toast('Сетевая ошибка. Попробуйте ещё раз.', 'error', 6000);
            }).then(function () {
                // Снять loading независимо от результата.
                page.querySelectorAll('[data-add-to-cart]').forEach(function (b) {
                    b.classList.remove('is-loading');
                    // disabled оставляем по recalc-логике (если activeCount === 0)
                    b.disabled = (state.filter(function (s) { return !s.excluded; }).length === 0);
                });
            });
        });
    });

    /* ════════════════════════════════════════════════════════════
       INIT
       ════════════════════════════════════════════════════════════ */
    recalc();
})();

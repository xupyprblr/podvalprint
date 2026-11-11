/**
 * Home anchors — sticky-меню якорей на главной (мобильное)
 * прежней версии
 *
 * Возможности:
 *  - Плавный скролл по клику на пункт (с компенсацией высоты header + anchors).
 *  - Подсветка активного пункта при скролле (IntersectionObserver).
 *  - Автоскролл активного пункта в видимую область внутри горизонтального скролла.
 *  - Скрытие меню до того, как пользователь начал скроллить (минус один UI-элемент в hero).
 */
(function () {
    'use strict';

    var nav = document.getElementById('home-anchors');
    if (!nav) return;

    var items = nav.querySelectorAll('.home-anchors__item');
    if (!items.length) return;

    var inner = nav.querySelector('.home-anchors__inner');

    // ── 1. Карта якорь → секция ──────────────────────────────────────────
    var anchorMap = {};
    var sections = [];
    items.forEach(function (a) {
        var id = a.getAttribute('data-anchor');
        if (!id) return;
        var sec = document.getElementById(id);
        if (!sec) return;
        anchorMap[id] = a;
        sections.push(sec);
    });

    if (!sections.length) return;

    // ── 2. Плавный скролл по клику ──────────────────────────────────────
    // Браузерный scroll-margin-top на секциях уже отрабатывает при native-anchor jump,
    // но мы перехватываем клик чтобы:
    //  а) гарантировать smooth-поведение даже если глобально не задано scroll-behavior;
    //  б) не было flash-перепрыгивания на старых iOS Safari.
    nav.addEventListener('click', function (e) {
        var link = e.target.closest('.home-anchors__item');
        if (!link) return;
        var id = link.getAttribute('data-anchor');
        var target = id ? document.getElementById(id) : null;
        if (!target) return;

        e.preventDefault();

        // Высота сдвига = sticky header + sticky anchors (если приклеены)
        var headerEl = document.getElementById('site-header');
        var headerH = headerEl ? headerEl.getBoundingClientRect().height : 0;
        var anchorsH = nav.getBoundingClientRect().height;
        var offset = Math.round(headerH + anchorsH);

        var top = target.getBoundingClientRect().top + window.pageYOffset - offset + 1;

        window.scrollTo({ top: top, behavior: 'smooth' });

        // Обновляем URL hash без прыжка
        if (history.replaceState) {
            history.replaceState(null, '', '#' + id);
        }
    });

    // ── 3. Подсветка активного пункта (IntersectionObserver) ────────────
    if (!('IntersectionObserver' in window)) return;

    var lastActive = null;

    function setActive(id) {
        if (lastActive === id) return;
        lastActive = id;

        items.forEach(function (a) {
            var on = a.getAttribute('data-anchor') === id;
            a.classList.toggle('is-active', on);
            if (on && inner) {
                // Прокручиваем активный пункт в видимую часть строки
                var rect = a.getBoundingClientRect();
                var parentRect = inner.getBoundingClientRect();
                if (rect.left < parentRect.left + 8) {
                    inner.scrollBy({ left: rect.left - parentRect.left - 16, behavior: 'smooth' });
                } else if (rect.right > parentRect.right - 8) {
                    inner.scrollBy({ left: rect.right - parentRect.right + 16, behavior: 'smooth' });
                }
            }
        });
    }

    // Карта видимости: { id → intersectionRatio }
    var visMap = {};

    var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            visMap[entry.target.id] = entry.isIntersecting ? entry.intersectionRatio : 0;
        });

        // Активный = тот, у которого max ratio > 0
        var bestId = null;
        var bestRatio = 0;
        sections.forEach(function (sec) {
            var r = visMap[sec.id] || 0;
            if (r > bestRatio) {
                bestRatio = r;
                bestId = sec.id;
            }
        });

        if (bestId) setActive(bestId);
    }, {
        // rootMargin: верх — отрицательный на высоту шапки+меню (60+44 ≈ 104px),
        // чтобы секция считалась активной только когда её заголовок реально под навом.
        // Низ — отрицательный, чтобы переключение происходило раньше выхода секции.
        rootMargin: '-110px 0px -55% 0px',
        threshold: [0, 0.1, 0.25, 0.5, 0.75, 1]
    });

    sections.forEach(function (sec) { observer.observe(sec); });

    // ── 4. Скрываем меню в самом верху страницы (когда виден hero) ──────
    // Меню становится видимым, как только пользователь прокрутил больше ~50% высоты hero.
    var hero = document.getElementById('hero-slider');
    if (hero) {
        // кэшируем threshold (hero.offsetHeight * 0.6) вместо вычисления
        // на каждом scroll — раньше revealAt() форсил reflow при каждом скролле.
        var threshold = Math.max(120, (hero.offsetHeight || 0) * 0.6);

        var ticking = false;
        var update = function () {
            ticking = false;
            var y = window.pageYOffset || document.documentElement.scrollTop;
            nav.classList.toggle('is-revealed', y >= threshold);
        };
        var onScroll = function () {
            if (!ticking) {
                ticking = true;
                requestAnimationFrame(update);
            }
        };
        window.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('resize', function () {
            // Пересчитываем порог только при изменении размера viewport
            threshold = Math.max(120, (hero.offsetHeight || 0) * 0.6);
            update();
        });
        update();
    } else {
        // Hero нет (нестандартный case) — показываем сразу
        nav.classList.add('is-revealed');
    }
})();

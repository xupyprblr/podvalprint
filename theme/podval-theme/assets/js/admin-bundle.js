/**
 * Admin JS for CPT podval_bundle.
 * ─────────────────────────────────────────────────────────────────
 * Управляет meta-box'ами:
 *   1) Состав бандла (#podval-bundle-shirts)
 *      — Select2 ajax-поиск WC-товаров
 *      — Repeater строк с native HTML5 drag-and-drop
 *      — Сериализация состава в скрытое поле _bundle_shirts (JSON)
 *
 *   2) Бейджи (#podval-bundle-badges)
 *      — Repeater строк (max 3)
 *      — Сериализация в _bundle_badges (JSON)
 *
 *   3) Основные настройки
 *      — Toggle блока «featured-size» по чекбоксу featured
 *
 *   4) Скидки
 *      — Toggle disabled-state у полей шкалы по чекбоксу «дефолтная»
 *
 * Глобал PodvalBundleAdmin приходит из wp_localize_script:
 *   { ajaxUrl, nonce, i18n: { searching, noResults, errorLoading, placeholder, confirmDel, badgeMax, badgeCount } }
 *
 *
 */
(function ($) {
    'use strict';

    if (typeof window.PodvalBundleAdmin === 'undefined') {
        return;
    }
    var cfg = window.PodvalBundleAdmin;
    var i18n = cfg.i18n || {};

    /* =================================================================
       1. FEATURED TOGGLE
       ================================================================= */
    function initFeaturedToggle() {
        var $cb = $('[data-podval-featured-toggle]');
        if (!$cb.length) return;
        $cb.on('change', function () {
            $('[data-podval-featured-size]').toggle(this.checked);
        });
    }

    /* =================================================================
       2. DISCOUNT SCALE TOGGLE
       ================================================================= */
    function initScaleToggle() {
        var $cb = $('[data-scale-default-toggle]');
        if ($cb.length) {
            $cb.on('change', function () {
                var disabled = this.checked;
                var $table = $('[data-scale-table]');
                $table.toggleClass('is-disabled', disabled);
                $table.find('input[type="number"]').prop('disabled', disabled);
            });
        }

        /* тип скидки: % или Br */
        var $type = $('[data-discount-type]');
        if (!$type.length) return;
        $type.on('change', function () {
            var isFixed = $('[data-discount-type]:checked').val() === 'fixed';
            $('[data-scale-suffix]').text(isFixed ? 'Br' : '%');
            $('[data-scale-input]').attr('max', isFixed ? 99999 : 100);
            $('[data-type-hint-percent]').toggle(!isFixed);
            $('[data-type-hint-fixed]').toggle(isFixed);
            $('[data-default-scale-warn]').toggle(isFixed);
        });
    }

    /* =================================================================
       3. SHIRTS REPEATER
       ================================================================= */
    var Shirts = (function () {
        var $wrap, $list, $empty, $payload, tplHtml, productCache = {};

        function init() {
            $wrap = $('#podval-bundle-shirts');
            if (!$wrap.length) return;

            $list    = $wrap.find('[data-shirts-list]');
            $empty   = $wrap.find('[data-shirts-empty]');
            $payload = $wrap.find('[data-shirts-payload]');

            var tplNode = document.getElementById('podval-shirt-row-tpl');
            tplHtml = tplNode ? tplNode.innerHTML : '';

            // Preview данные (preloaded для существующих товаров)
            try {
                var preview = JSON.parse($wrap.attr('data-preview') || '{}');
                Object.keys(preview).forEach(function (k) {
                    productCache[k] = preview[k];
                });
            } catch (e) { /* noop */ }

            // Начальное состояние из data-initial
            var initial = [];
            try {
                initial = JSON.parse($wrap.attr('data-initial') || '[]');
                if (!Array.isArray(initial)) initial = [];
            } catch (e) { initial = []; }

            initial.forEach(function (s) {
                addRow(s);
            });

            updateEmpty();
            updateNums();

            // Кнопка «добавить»
            $wrap.on('click', '[data-shirts-add]', function (e) {
                e.preventDefault();
                addRow();
                updateEmpty();
                updateNums();
                serialize();
            });

            // Удаление
            $list.on('click', '[data-shirt-remove]', function (e) {
                e.preventDefault();
                if (!confirm(i18n.confirmDel || 'Удалить?')) return;
                $(this).closest('[data-shirt-row]').remove();
                updateEmpty();
                updateNums();
                serialize();
            });

            // поля default_color/size/type удалены — берутся из WC product.

            // Drag and drop
            initDragDrop();
        }

        function addRow(data) {
            data = data || {};
            var $row = $(tplHtml);
            // default_color/size/type больше не используются —
            // берутся из настроек товара (WC product variations).

            $list.append($row);

            // Инициализируем Select2 на product field
            initProductSelect($row.find('[data-shirt-product]'), data.product_id || 0);
        }

        function initProductSelect($select, preselectId) {
            // Если уже инициализирован — пропустить
            if ($select.data('select2')) return;

            $select.select2({
                ajax: {
                    url: cfg.ajaxUrl,
                    dataType: 'json',
                    delay: 250,
                    data: function (params) {
                        return {
                            action: 'podval_bundle_product_search',
                            nonce:  cfg.nonce,
                            q:      params.term || ''
                        };
                    },
                    processResults: function (data) {
                        var results = (data && data.results) ? data.results : [];
                        // Кэшируем для будущего ре-открытия
                        results.forEach(function (r) {
                            productCache[r.id] = r;
                        });
                        return { results: results };
                    },
                    cache: true
                },
                minimumInputLength: 0,
                placeholder: i18n.placeholder || 'Найти товар...',
                allowClear: true,
                width: '100%',
                templateResult: formatProductOption,
                templateSelection: function (item) {
                    if (!item.id) return item.text || '';
                    return item.text || (productCache[item.id] && productCache[item.id].title) || ('#' + item.id);
                },
                language: {
                    searching:    function () { return i18n.searching    || 'Поиск...'; },
                    noResults:    function () { return i18n.noResults    || 'Не найдено'; },
                    errorLoading: function () { return i18n.errorLoading || 'Ошибка'; },
                    inputTooShort: function () { return i18n.searching   || 'Введите запрос'; }
                }
            });

            // Если есть preselect — подставить
            if (preselectId && productCache[preselectId]) {
                var p = productCache[preselectId];
                var option = new Option(p.title || ('#' + p.id), p.id, true, true);
                $select.append(option).trigger('change');
                renderPreview($select, p);
            } else if (preselectId) {
                // Preview-данных нет — отрисуем хотя бы ID, тыкая placeholder
                var opt = new Option('#' + preselectId, preselectId, true, true);
                $select.append(opt).trigger('change');
            }

            // Обновление preview + payload при выборе
            $select.on('select2:select', function (e) {
                var item = e.params && e.params.data ? e.params.data : null;
                if (item && item.id) {
                    productCache[item.id] = {
                        id:    item.id,
                        title: item.text,
                        thumb: item.thumb || '',
                        price: item.price || ''
                    };
                    renderPreview($(this), productCache[item.id]);
                }
                serialize();
            });

            $select.on('select2:clear', function () {
                clearPreview($(this));
                serialize();
            });
        }

        function formatProductOption(item) {
            if (!item.id) return item.text;
            var $opt = $('<div class="podval-shirt-search-result"></div>');
            if (item.thumb) {
                $opt.append('<img src="' + item.thumb + '" alt="">');
            } else {
                $opt.append('<span class="podval-shirt-search-result__no-img">—</span>');
            }
            var $meta = $('<div class="podval-shirt-search-result__meta"></div>');
            $meta.append('<div class="podval-shirt-search-result__title"></div>').find('.podval-shirt-search-result__title').text(item.text);
            if (item.price) {
                $meta.append('<div class="podval-shirt-search-result__price"></div>').find('.podval-shirt-search-result__price').text(item.price);
            }
            $opt.append($meta);
            return $opt;
        }

        function renderPreview($select, p) {
            var $row = $select.closest('[data-shirt-row]');
            var $prev = $row.find('[data-shirt-preview]');
            $row.find('[data-shirt-preview-img]').attr('src', p.thumb || '').attr('alt', p.title || '');
            $row.find('[data-shirt-preview-title]').text(p.title || '');
            $row.find('[data-shirt-preview-price]').text(p.price || '');
            $prev.prop('hidden', !p.thumb && !p.title);
        }

        function clearPreview($select) {
            var $row = $select.closest('[data-shirt-row]');
            $row.find('[data-shirt-preview]').prop('hidden', true);
            $row.find('[data-shirt-preview-img]').attr('src', '').attr('alt', '');
            $row.find('[data-shirt-preview-title]').text('');
            $row.find('[data-shirt-preview-price]').text('');
        }

        function updateEmpty() {
            var hasRows = $list.children('[data-shirt-row]').length > 0;
            $empty.toggle(!hasRows);
        }

        function updateNums() {
            $list.children('[data-shirt-row]').each(function (i) {
                $(this).find('[data-shirt-num]').text(i + 1);
            });
        }

        function serialize() {
            var data = [];
            $list.children('[data-shirt-row]').each(function () {
                var $r = $(this);
                var pid = parseInt($r.find('[data-shirt-product]').val(), 10) || 0;
                if (!pid) return; // пропускаем пустые строки
                data.push({ product_id: pid });
            });
            $payload.val(JSON.stringify(data));
        }

        function initDragDrop() {
            var dragSrc = null;

            $list.on('dragstart', '[data-shirt-row]', function (e) {
                dragSrc = this;
                this.classList.add('is-dragging');
                e.originalEvent.dataTransfer.effectAllowed = 'move';
                // Safari требует setData
                try { e.originalEvent.dataTransfer.setData('text/plain', ''); } catch (err) { /* noop */ }
            });

            $list.on('dragend', '[data-shirt-row]', function () {
                this.classList.remove('is-dragging');
                dragSrc = null;
                $list.children('[data-shirt-row]').removeClass('is-drag-over');
            });

            $list.on('dragover', '[data-shirt-row]', function (e) {
                if (!dragSrc || this === dragSrc) return;
                e.preventDefault();
                e.originalEvent.dataTransfer.dropEffect = 'move';
                $list.children('[data-shirt-row]').removeClass('is-drag-over');
                this.classList.add('is-drag-over');
            });

            $list.on('drop', '[data-shirt-row]', function (e) {
                e.preventDefault();
                if (!dragSrc || this === dragSrc) return;

                var rows = Array.prototype.slice.call($list.children('[data-shirt-row]'));
                var srcIdx = rows.indexOf(dragSrc);
                var dstIdx = rows.indexOf(this);

                if (srcIdx < dstIdx) {
                    this.parentNode.insertBefore(dragSrc, this.nextSibling);
                } else {
                    this.parentNode.insertBefore(dragSrc, this);
                }

                this.classList.remove('is-drag-over');
                updateNums();
                serialize();
            });
        }

        return { init: init };
    })();

    /* =================================================================
       4. BADGES REPEATER
       ================================================================= */
    var Badges = (function () {
        var $wrap, $list, $payload, $counter, tplHtml, MAX = 3;

        function init() {
            $wrap = $('#podval-bundle-badges');
            if (!$wrap.length) return;

            $list    = $wrap.find('[data-badges-list]');
            $payload = $wrap.find('[data-badges-payload]');
            $counter = $wrap.find('.podval-badges-counter');

            var tplNode = document.getElementById('podval-badge-row-tpl');
            tplHtml = tplNode ? tplNode.innerHTML : '';

            var initial = [];
            try {
                initial = JSON.parse($wrap.attr('data-initial') || '[]');
                if (!Array.isArray(initial)) initial = [];
            } catch (e) { initial = []; }

            initial.slice(0, MAX).forEach(function (b) { addRow(b); });

            updateCounter();

            $wrap.on('click', '[data-badges-add]', function (e) {
                e.preventDefault();
                if ($list.children('[data-badge-row]').length >= MAX) {
                    alert(i18n.badgeMax || 'Максимум 3 бейджа');
                    return;
                }
                addRow();
                updateCounter();
                serialize();
            });

            $list.on('click', '[data-badge-remove]', function (e) {
                e.preventDefault();
                $(this).closest('[data-badge-row]').remove();
                updateCounter();
                serialize();
            });

            $list.on('input change', '[data-badge-type], [data-badge-label]', function () {
                serialize();
            });
        }

        function addRow(data) {
            data = data || {};
            var $row = $(tplHtml);
            if (data.type)  $row.find('[data-badge-type]').val(data.type);
            if (data.label) $row.find('[data-badge-label]').val(data.label);
            $list.append($row);
        }

        function updateCounter() {
            var count = $list.children('[data-badge-row]').length;
            var fmt = i18n.badgeCount || 'Бейджей: %d / 3';
            $counter.text(fmt.replace('%d', count));
        }

        function serialize() {
            var data = [];
            $list.children('[data-badge-row]').each(function () {
                var $r    = $(this);
                var type  = ($r.find('[data-badge-type]').val()  || '').trim();
                var label = ($r.find('[data-badge-label]').val() || '').trim();
                if (!type || !label) return;
                data.push({ type: type, label: label });
            });
            $payload.val(JSON.stringify(data));
        }

        return { init: init };
    })();

    /* =================================================================
       INIT
       ================================================================= */
    $(function () {
        initFeaturedToggle();
        initScaleToggle();
        Shirts.init();
        Badges.init();
    });
})(jQuery);

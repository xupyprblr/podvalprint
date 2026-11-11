/**
 * Конструктор-лайт шаблонных принтов.
 *
 * Ключевая идея: ОБЩИЙ ПУЛ ФОТО. Пользователь грузит фото один раз,
 * они лежат общей лентой. Смена основы или раскладки НЕ сбрасывает пул —
 * слоты переназначаются, лишние фото остаются в ленте.
 *
 * Превью рендерится средствами CSS (без canvas):
 *   letters  → background-clip:text по буквам
 *   poster   → абсолютные слоты по долям 0..1
 *   single   → одно фото + подписи
 *   polaroid → две рамки с подписями
 *
 * @package Podval Print
 */
(function () {
	'use strict';

	if (typeof window.PodvalTpl === 'undefined') return;

	var CFG = window.PodvalTpl;

	/* ───────────────────── Шрифт печатного макета ─────────────────────
	   кириллица — Unbounded Black, подключённый к семейству
	   'Horizon' по unicode-range (style-tpl-builder.css) с size-adjust под
	   капитель Horizon. Для кода это теперь одна широкая гарнитура: кегли
	   и коэффициенты кириллицы считаются как у латиницы. Oswald в стеке
	   остался только запасом для символов вне обеих (❯, эмодзи).

	   До 3.7.30: латиница и цифры набирались Horizon, кириллица — Oswald. В файле
	   Horizon кириллицы нет вообще, поэтому в вёрстке всё решает обычный
	   фолбэк по стеку; ветки ниже нужны только там, где до CSS не
	   дотянуться — canvas растра и выбор контурного начертания.

	   Стеки продублированы в style-tpl-builder.css (--tplp-display,
	   --tplp-display-outline). Менять вместе. */

	var TPL_FONT = "'Horizon', 'Oswald', sans-serif";
	var TPL_FONT_OUTLINE = "'Horizon Outlined', 'Oswald', sans-serif";

	var CYR_RE = /[\u0400-\u04FF]/;

	function hasCyr(s) { return CYR_RE.test(s || ''); }

	/**
	 * Опорный символ для замера высоты капители.
	 *
	 * 'H' есть в обеих гарнитурах, и для кириллической строки он был бы
	 * взят из Horizon — а сама строка рисуется Oswald'ом, у которого
	 * капитель выше (.81em против .70em). Русское 'Н' в Horizon
	 * отсутствует и гарантированно меряется тем же шрифтом, каким
	 * набрана строка.
	 */
	function capProbe(text) { return hasCyr(text) ? '\u041D' : 'H'; }

	/**
	 * Отношение капителей Oswald/Horizon.
	 *
	 * Кегль в макете задан числом (SVG-заголовок, растр), а не подгоном
	 * по месту. При одинаковом font-size Horizon даёт заметно более
	 * низкие буквы, и стопка повторов заголовка переставала смыкаться.
	 * Множитель применяется только к латинским строкам.
	 */
	var TPL_CAP_K = 0.810 / 0.7007;

	/**
	 * Множитель кегля мелких подписей, набранных Horizon.
	 *
	 * TPL_CAP_K чинит высоту, а это — ширину: при одинаковом кегле
	 * латинская строка в Horizon примерно вдвое шире той же строки в
	 * Oswald (замер: «ALEX» 407.8 px против 195.6 px на 100 px). Кегли
	 * нижнего блока подбирались под Oswald, и на Horizon подпись под
	 * штрихкодом разъезжалась на три строки, а фраза упиралась в пол
	 * fitLines() и обрезалась многоточием.
	 *
	 * Компенсируется не вся разница: 0.72 подобран по рендеру так, чтобы
	 * подписи вставали ровными строками без переносов посреди слова и при
	 * этом не мельчили рядом с кириллическим вариантом.
	 */
	var TPL_LAT_K = 0.72;

	/**
	 * Доля строки, которую отрисует Horizon.
	 *
	 * Считается только по буквам и цифрам. Пунктуация есть в обеих
	 * гарнитурах и о языке строки не говорит ничего: из-за дефиса в
	 * «ГРУППА - НАЗВАНИЕ» кириллическая подпись поехала бы в уменьшение
	 * на ровном месте. Цифры, наоборот, учитываются — они всегда идут
	 * Horizon'ом, и «с 00.00.0000» разъезжается так же, как латиница.
	 */
	function latShare(text) {
		var s = String(text || '');
		var lat = (s.match(/[A-Za-z0-9]/g) || []).length;
		var cyr = (s.match(/[\u0400-\u04FF]/g) || []).length;
		var total = lat + cyr;
		// кириллица (Unbounded) такая же широкая, как Horizon.
		lat += cyr;
		return total ? lat / total : 0;
	}

	/**
	 * Коэффициент кегля для строки. Непрерывный, а не «латиница/кириллица»:
	 * смешанная строка ужимается ровно настолько, насколько она широкая.
	 */
	function fontK(text) {
		return Math.round((1 - (1 - TPL_LAT_K) * latShare(text)) * 1000) / 1000;
	}

	/**
	 * Инлайновый `--tplp-k` для разметки превью. Пустая строка при k=1,
	 * чтобы не сорить атрибутами в кириллических макетах.
	 */
	function kStyle(text) {
		var k = fontK(text);
		return k < 1 ? ' style="--tplp-k:' + k + '"' : '';
	}

	/* ───────────────────────── Состояние ───────────────────────── */

	var state = {
		tplId: null,
		layoutId: null,
		texts: {},
		styles: {},
		pool: [],      // [{ id, url, w, h, soft }]
		slots: [],     // [{ key, poolIndex|null }]
		garment: {},
		addons: [],
		comment: '',
		photosLater: false,
		// галку «Пришлю фото позже» ставит и снимает сам
		// конструктор (нет фото / не все слоты), пока человек не тронул
		// её руками. После ручного выбора — уважаем его.
		laterTouched: false,
		// Выбранный слот для обмена по «клик — клик». Пула как отдельного
		// списка больше нет: слоты макета и есть список загруженных фото.
		selectedSlot: null,
		pickTarget: null,
		busy: false,
		// Ключи текстов, которые пользователь редактировал сам. Нетронутые
		// дефолты не переносятся при смене основы — иначе пример «ALEXANDROVICH»
		// уехал бы в подпись постера.
		textsTouched: {},
		// Шаг считается пройденным, когда пользователь его закрыл (перешёл дальше
		// или свернул вручную). Автопереходы на старте сюда не попадают — см. booting.
		seen: {}
	};

	/** Пока true, автопереходы init() не отмечают шаги пройденными. */
	var booting = true;

	/* ───────────────────────── DOM ───────────────────────── */

	var $ = function (id) { return document.getElementById(id); };
	var el = {
		steps: $('tplb-steps'),
		bases: $('tplb-bases'),
		layouts: $('tplb-layouts'),
		texts: $('tplb-texts'),
		textsMain: $('tplb-texts'),
		textsMedia: $('tplb-media-texts'),
		stylesBox: $('tplb-styles'),
		tone: $('tplb-tone'),
		drop: $('tplb-drop'),
		file: $('tplb-file'),
		dropBtn: $('tplb-drop-btn'),
		slotsBox: $('tplb-slots'),
		counter: $('tplb-photo-counter'),
		headThumbs: $('tplb-head-thumbs'),
		later: $('tplb-later'),
		laterInput: $('tplb-later-input'),
		garment: $('tplb-garment'),
		addons: $('tplb-addons'),
		commentField: $('tplb-comment-field'),
		side: $('tplb-side'),
		sideInner: document.querySelector('.tplb-side__inner'),
		previewFit: $('tplb-previewfit'),
		zoom: $('tplb-zoom'),
		cropHint: $('tplb-crophint'),
		preview: $('tplb-preview'),
		art: $('tplb-art'),
		empty: $('tplb-empty'),
		summary: $('tplb-summary'),
		totalPrice: $('tplb-total-price'),
		stickyPrice: $('tplb-sticky-price'),
		submit: $('tplb-submit'),
		stickyBtn: $('tplb-sticky-btn'),
		error: $('tplb-error'),
		priceHead: $('tplb-price-head')
	};

	/* ───────────────────────── Утилиты ───────────────────────── */

	function tpl() { return state.tplId ? CFG.templates[state.tplId] : null; }

	/* Заголовок-повтор и имя блочного низа читаются из полей, которые
	   задаёт шаблон: у постеров это headline и name, у «Одно фото + имя»
	   — name (имя/отчество сверху) и foot_name (имя над штрихкодом). */
	function titleKey() { var t = tpl(); return (t && t.titleKey) || 'headline'; }
	function footNameKey() { var t = tpl(); return (t && t.footNameKey) || 'name'; }
	function titleText() { return (state.texts[titleKey()] || '').trim(); }
	function footNameText() { return (state.texts[footNameKey()] || '').trim(); }

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function money(n) {
		return n.toFixed(2).replace('.', ',') + ' ' + (CFG.currency || 'Br');
	}

	function step(name, show) {
		var node = el.steps.querySelector('[data-step="' + name + '"]');
		if (node) node.hidden = !show;
	}

	function showError(msg) {
		if (!el.error) return;
		if (!msg) { el.error.hidden = true; el.error.textContent = ''; return; }
		el.error.hidden = false;
		el.error.textContent = msg;
	}

	/* ───────────────────────── Слоты ───────────────────────── */

	/**
	 * Раскладка «своя компоновка»: сетка считается по числу фото.
	 *
	 * Область коллажа всегда квадратная (aspect 1.0) — как у статичных сеток,
	 * иначе высота блока прыгала бы на каждом добавленном фото. Ячейки при
	 * этом квадратные, а неполные ряды и колонки центрируются внутри квадрата.
	 */
	function freeLayout(n) {
		n = Math.max(1, Math.min(9, n | 0));

		var cols = n <= 3 ? n : (n === 4 ? 2 : 3);
		var rows = Math.ceil(n / cols);
		var gap = 0.02;
		// Ячейка квадратная: доли по обеим осям равны, т.к. область — квадрат.
		var side = (1 - (cols - 1) * gap) / cols;
		var yOff = (1 - (rows * side + (rows - 1) * gap)) / 2;

		var slots = [];
		for (var i = 0; i < n; i++) {
			var r = Math.floor(i / cols);
			var c = i - r * cols;
			var inRow = Math.min(cols, n - r * cols);
			var xOff = (1 - (inRow * side + (inRow - 1) * gap)) / 2;
			slots.push({
				key: 's' + (i + 1),
				x: xOff + c * (side + gap),
				y: yOff + r * (side + gap),
				w: side,
				h: side
			});
		}

		return { slots: slots, aspect: 1.0 };
	}

	/** Сколько слотов показывать в свободной компоновке. */
	function freeCount(lay) {
		var max = lay.max || 9;
		return Math.max(1, Math.min(max, state.pool.length || 1));
	}

	/* ─────────────────── Геометрия коллажа ───────────────────
	 *
	 * Раскладки правятся мышью в админке, поэтому в базе лежат «живые»
	 * координаты: между кадрами то 0.02, то 0.035, а сетка из шести
	 * квадратов занимает 0.66 высоты квадратной области — снизу остаётся
	 * пустая полоса до подписей. Чинить это в данных бессмысленно:
	 * следующая правка мышью вернёт всё назад.
	 *
	 * Поэтому раскладка нормализуется на выходе, перед каждым рендером:
	 *
	 *   1. Швы. Рёбра слотов группируются в линии — начала отдельно от
	 *      концов, иначе зазор 0.02 схлопнулся бы в одну линию и кадры
	 *      слиплись. Каждый шов «конец → ближайшее начало правее»
	 *      раздвигается ровно на GRID_GAP вокруг своей середины: кадры
	 *      почти не двигаются, но зазор везде одинаковый.
	 *
	 *   2. Пустое место. Габарит слотов становится всей областью коллажа.
	 *      Меняется пропорция контейнера (aspect), а не размеры кадров —
	 *      иначе квадраты растянулись бы в прямоугольники.
	 *
	 * Считаем в «единицах ширины»: Y умножается на aspect. Тогда один и тот
	 * же GRID_GAP даёт одинаковый зазор в пикселях по обеим осям.
	 *
	 * Нормализация применяется только к движку poster (коллаж из сетки).
	 * У onlyyou/polaroid/single слот один-два, и aspect там задаёт кадрирование,
	 * а не геометрию сетки — трогать его нельзя.
	 */

	/** Зазор между кадрами, доля ширины коллажа (≈3-4 px в превью, ≈14 px в растре). */
	var GRID_GAP = 0.012;

	/** Разброс, в пределах которого рёбра считаются одной линией. */
	var GRID_TOL = 0.025;

	/** Дальше этого «конец → начало» уже не шов, а задуманная пустота. */
	var GRID_SEAM_MAX = 0.12;

	function gridEngine(t) { return !!t && 'poster' === t.engine; }

	/** Группировка координат в линии. Возвращает позиции линий и карту индексов. */
	function clusterEdges(vals) {
		var order = vals.map(function (v, i) { return i; })
			.sort(function (a, b) { return vals[a] - vals[b]; });

		var lines = [], of = [];
		order.forEach(function (i) {
			var g = lines[lines.length - 1];
			if (g && vals[i] - g.min <= GRID_TOL) { g.sum += vals[i]; g.n++; }
			else { g = { min: vals[i], sum: vals[i], n: 1 }; lines.push(g); }
			of[i] = lines.length - 1;
		});

		return { pos: lines.map(function (g) { return g.sum / g.n; }), of: of };
	}

	/** Выравнивание швов по одной оси. Меняет rects на месте. */
	function fixSeams(rects, p, sz) {
		var st = clusterEdges(rects.map(function (r) { return r[p]; }));
		var en = clusterEdges(rects.map(function (r) { return r[p] + r[sz]; }));

		var sp = st.pos.slice();
		var ep = en.pos.slice();

		en.pos.forEach(function (e, i) {
			// Ближайшее начало правее конца — это и есть шов между колонками
			// (или рядами). У крайнего ребра пары нет, и правильно: внешний
			// контур коллажа двигать нельзя.
			var j = -1;
			for (var k = 0; k < st.pos.length; k++) {
				if (st.pos[k] > e + 1e-6) { j = k; break; }
			}
			if (j < 0 || st.pos[j] - e > GRID_SEAM_MAX) return;

			var mid = (e + st.pos[j]) / 2;
			ep[i] = mid - GRID_GAP / 2;
			sp[j] = mid + GRID_GAP / 2;
		});

		rects.forEach(function (r, i) {
			var a = sp[st.of[i]];
			var b = ep[en.of[i]];
			r[p]  = a;
			r[sz] = Math.max(GRID_GAP, b - a);
		});
	}

	function r4(v) { return Math.round(v * 10000) / 10000; }

	/**
	 * Одинаковые зазоры + область коллажа по габариту слотов.
	 *
	 * @param {Object} lay Раскладка с развёрнутыми слотами.
	 * @return {Object} Новая раскладка; исходная не меняется.
	 */
	function normalizeGrid(lay) {
		var src = (lay && lay.slots) || [];
		if (src.length < 2) return lay;

		// Тот же дефолт, что у превью (CSS .tplp-grid) и растра.
		var A = parseFloat(lay.aspect) || 1.05;

		var rects = src.map(function (s) {
			return {
				key: s.key,
				x: parseFloat(s.x) || 0,
				w: parseFloat(s.w) || 0,
				y: (parseFloat(s.y) || 0) * A,
				h: (parseFloat(s.h) || 0) * A
			};
		});

		// Битые данные лучше отрисовать как есть, чем поделить на ноль.
		for (var i = 0; i < rects.length; i++) {
			if (!(rects[i].w > 0) || !(rects[i].h > 0)) return lay;
		}

		fixSeams(rects, 'x', 'w');
		fixSeams(rects, 'y', 'h');

		var x0 = Infinity, y0 = Infinity, x1 = -Infinity, y1 = -Infinity;
		rects.forEach(function (r) {
			x0 = Math.min(x0, r.x);
			y0 = Math.min(y0, r.y);
			x1 = Math.max(x1, r.x + r.w);
			y1 = Math.max(y1, r.y + r.h);
		});

		var W = x1 - x0, H = y1 - y0;
		if (!(W > 0) || !(H > 0)) return lay;

		var out = {};
		for (var k in lay) {
			if (Object.prototype.hasOwnProperty.call(lay, k)) out[k] = lay[k];
		}
		out.aspect = r4(H / W);
		out.slots  = rects.map(function (r) {
			return {
				key: r.key,
				x: r4((r.x - x0) / W),
				y: r4((r.y - y0) / H),
				w: r4(r.w / W),
				h: r4(r.h / H)
			};
		});

		return out;
	}

	/** Раскладка с развёрнутыми слотами: свободная сетка + нормализация. */
	function layoutGeometry(t, lay) {
		if (!lay) return null;

		if (lay.free) {
			var built = freeLayout(freeCount(lay));
			lay = {
				title:  lay.title,
				free:   true,
				max:    lay.max || 9,
				aspect: built.aspect,
				slots:  built.slots
			};
		}

		return gridEngine(t) ? normalizeGrid(lay) : lay;
	}

	/** Раскладка текущего состояния с уже развёрнутыми слотами. */
	function activeLayout() {
		var t = tpl();
		if (!t) return null;
		return layoutGeometry(t, (t.layouts || {})[state.layoutId]);
	}

	function slotDefs() {
		var t = tpl();
		if (!t) return [];

		if (t.photos.mode === 'by_word') {
			var word = (state.texts.word || '').trim();
			var chars = Array.from(word);
			return chars.map(function (ch, i) {
				return { key: 'L' + i, char: ch, ratio: t.slotRatio || 0.46 };
			});
		}

		var lay = activeLayout();
		if (!lay) return [];
		return lay.slots.map(function (s) {
			return { key: s.key, x: s.x, y: s.y, w: s.w, h: s.h };
		});
	}

	/**
	 * Пересинхронизация слотов с пулом. Сохраняет уже сделанные назначения
	 * по позиции — смена раскладки не обнуляет работу пользователя.
	 */
	function syncSlots() {
		var defs = slotDefs();
		var prev = state.slots.slice();

		state.slots = defs.map(function (d, i) {
			var keep = prev[i] && prev[i].poolIndex != null && state.pool[prev[i].poolIndex]
				? prev[i].poolIndex
				: null;
			return { key: d.key, poolIndex: keep };
		});

		autoFill();
	}

	/** Автозаполнение пустых слотов неиспользованными фото из пула. */
	function autoFill() {
		var used = {};
		state.slots.forEach(function (s) { if (s.poolIndex != null) used[s.poolIndex] = true; });

		state.slots.forEach(function (s) {
			if (s.poolIndex != null) return;
			for (var i = 0; i < state.pool.length; i++) {
				if (!used[i]) { s.poolIndex = i; used[i] = true; return; }
			}
		});
	}

	function filledCount() {
		return state.slots.filter(function (s) { return s.poolIndex != null; }).length;
	}

	/** Слоты с уже загруженным на сервер фото (без «загружается»). */
	function readyCount() {
		return state.slots.filter(function (s) {
			var p = s.poolIndex != null ? state.pool[s.poolIndex] : null;
			return p && !p.pending;
		}).length;
	}

	function pendingCount() {
		return state.pool.filter(function (p) { return p && p.pending; }).length;
	}

	/**
	 * «Пришлю фото позже».
	 *
	 * по умолчанию галка снята — её ставит сам покупатель
	 * (в 3.7.22 она стояла автоматически, и часть заказов уходила без
	 * фото по невнимательности). Чтобы человек с неполным набором фото не
	 * упёрся в неактивную кнопку, над «В корзину» висит подсказка со
	 * ссылкой прямо на этот чекбокс (renderWhy).
	 *
	 * Все слоты заполнены — галка снимается сама: «пришлю позже» теряет
	 * смысл, и в заказе висела бы ложная пометка «ждём фото».
	 */
	function syncLater() {
		var need = state.slots.length;
		var have = readyCount();
		var full = need > 0 && have >= need;

		var want = full ? false : !!state.photosLater;
		state.photosLater = want;

		if (el.laterInput) el.laterInput.checked = want;
		if (el.later) {
			el.later.classList.toggle('is-on', want);
			var title = el.later.querySelector('.tplb-later__title');
			if (title) {
				title.textContent = (have > 0 && !full) ? 'Пришлю остальные фото позже' : 'Пришлю фото позже';
			}
		}
		var box = stepNode('layout');
		if (box) box.classList.toggle('is-later', want);
	}

	function isComplete() {
		return !blockReason();
	}

	/**
	 * Почему заказ сейчас нельзя отправить — текст для подсказки под
	 * кнопкой «В корзину». Пустая строка — можно.
	 */
	var WHY_LATER_TAIL = 'отметьте «Пришлю фото позже».';

	function blockReason() {
		var t = tpl();
		if (!t) return 'Выберите основу макета.';
		if (!state.slots.length) return 'Выберите раскладку.';

		var pending = pendingCount();
		if (pending) return 'Фото ещё загружаются — подождите несколько секунд.';

		// «Пришлю фото позже» — заказ оформляется с тем, что есть, состав
		// макета добираем в Telegram при подтверждении.
		var miss = state.slots.length - readyCount();
		if (!state.photosLater && miss > 0) {
			// Хвост фразы renderWhy() превращает в ссылку на чекбокс.
			return 'Загрузите ещё ' + miss + ' фото или ' + WHY_LATER_TAIL;
		}

		return textsReason();
	}

	/* ───────────────────────── Рендер: основы ───────────────────────── */

	function renderBases() {
		Array.prototype.forEach.call(el.bases.querySelectorAll('.tplb-base'), function (btn) {
			btn.classList.toggle('is-active', btn.dataset.tpl === state.tplId);
			btn.addEventListener('click', function () { selectBase(btn.dataset.tpl); });
		});
	}

	function selectBase(id) {
		if (!CFG.templates[id]) return;

		state.tplId = id;
		var t = tpl();

		// Раскладка: первая доступная.
		var layKeys = Object.keys(t.layouts || {});
		state.layoutId = layKeys.length ? layKeys[0] : null;

		// Тексты: дефолты новой основы, но введённое вручную по возможности
		// переносим — терять набранное при переборе основ обидно.
		//
		// «По возможности» — это не «всегда». Заголовок называет саму основу:
		// набранное в GIRLFRIEND «МОЯ ДЕВОЧКА» переезжало на ONLY YOU и
		// оставалось там, потому что ключ у поля один и тот же. То же со
		// «Словом» в шаблоне из букв — от него зависит число слотов.
		//
		// Переносим только поля, помеченные `sample`. Это поля-образцы
		// («ВАШЕ ИМЯ», «ВАШ ТЕКСТ», «SINCE 00.00.0000») — они про клиента,
		// а не про основу, и осмысленны в любом шаблоне. Всё остальное
		// сбрасывается на дефолт новой основы.
		//
		// Признак именно положительный, а не «есть непустой дефолт без
		// sample»: дефолт можно очистить в админке или подменить пресетом
		// у locked-поля, и тогда косвенная проверка молча переворачивалась
		// — заголовок начинал переезжать между основами.
		var oldTexts = state.texts;
		state.texts = {};
		Object.keys(t.texts).forEach(function (k) {
			var def = t.texts[k];
			var carry = !!def.sample && !def.locked &&
				state.textsTouched[k] && oldTexts[k] != null && oldTexts[k] !== '';

			if (carry) {
				state.texts[k] = oldTexts[k];
			} else {
				state.texts[k] = def.default || '';
				// Иначе поле осталось бы «тронутым» с чужим значением и при
				// возврате к прежней основе перенеслось бы обратно.
				delete state.textsTouched[k];
			}
		});

		// Стили: дефолты.
		state.styles = {};
		Object.keys(t.styles || {}).forEach(function (k) {
			state.styles[k] = t.styles[k].default;
		});

		// Цвет изделия ограничен списком шаблона.
		if (t.colors.indexOf(state.garment.color) === -1) {
			state.garment.color = t.colors[0];
		}

		Array.prototype.forEach.call(el.bases.querySelectorAll('.tplb-base'), function (btn) {
			btn.classList.toggle('is-active', btn.dataset.tpl === id);
		});

		// Шаг «Раскладка и фото» показывается всегда. Условие
		// layKeys.length > 1 стояло здесь до объединения с загрузкой фото:
		// у letters и single раскладок нет, шаг скрывался целиком — теперь
		// это унесло бы с собой и загрузку. Прячется только сетка плиток,
		// см. renderLayouts().
		step('layout', true);
		// Шаблоны с mergeTexts (слово из фото) выводят поля в шаге фото —
		// отдельный шаг «Надписи» им не нужен и в нумерации не участвует.
		step('texts', !t.mergeTexts);
		step('garment', true);

		renderLayouts();
		renderTexts();
		renderStyles();
		renderGarment();
		syncSlots();
		renderAll();

		// Шаг НЕ схлопывается: выбор основы — это ещё не переход дальше.
		// Пользователь может перебрать все основы подряд и видеть превью,
		// а вниз уходит кнопкой «Далее» или кликом по шапке следующего шага.
	}

	/* ───────────────────────── Рендер: раскладки ───────────────────────── */

	function renderLayouts() {
		var t = tpl();
		if (!t) return;
		var keys = Object.keys(t.layouts || {});

		// Заголовок шага честно говорит, что внутри. Обещать «раскладку»
		// там, где выбирать не из чего, — тот же ступор, ради которого
		// шаги и объединялись, только меньшего масштаба.
		var title = document.getElementById('tplb-media-title');
		if (title) {
			title.textContent = t.stepTitle
				? t.stepTitle
				: t.mergeTexts
				? 'Слово и фото'
				: (keys.length > 1 ? 'Раскладка и фото' : 'Ваши фото');
		}

		if (keys.length < 2) {
			el.layouts.innerHTML = '';
			// hidden, а не только пустой innerHTML: у .tplb-layouts свой
			// grid-gap и margin, пустой контейнер оставлял бы отступ
			// над кнопкой загрузки.
			el.layouts.hidden = true;
			return;
		}
		el.layouts.hidden = false;

		// Плитка рисуется в квадрате фиксированной высоты, а раскладки одного
		// шаблона могут отличаться только пропорцией области (ONLY YOU: полоса
		// 0.34 / 2/3 0.83 / полный кадр 1.25). Без учёта aspect все три плитки
		// — один и тот же залитый прямоугольник, выбирать не из чего.
		// Считаем долю от самой высокой раскладки и центрируем по вертикали.
		//
		// Геометрия берётся нормализованной — той же, что уйдёт в превью и в
		// растр. Иначе плитка обещает одно, а после выбора рисуется другое:
		// именно так «6 фото · 3×2» показывала пустую полосу снизу.
		var geo = {};
		keys.forEach(function (k) {
			var lay = t.layouts[k];
			// У свободной компоновки координат нет — рисуем условную сетку 3×3.
			if (lay.free) {
				var built = freeLayout(9);
				lay = { title: lay.title, free: true, max: lay.max || 9, aspect: built.aspect, slots: built.slots };
			}
			geo[k] = gridEngine(t) ? normalizeGrid(lay) : lay;
		});

		var maxAspect = keys.reduce(function (m, k) {
			var a = parseFloat(geo[k].aspect) || 1;
			return a > m ? a : m;
		}, 0) || 1;

		el.layouts.innerHTML = keys.map(function (k) {
			var lay = t.layouts[k];
			var cells = geo[k].slots || [];
			var f = Math.min(1, (parseFloat(geo[k].aspect) || maxAspect) / maxAspect);
			var off = (1 - f) / 2;
			var mini = cells.map(function (s) {
				return '<i style="left:' + (s.x * 100) + '%;top:' + ((off + s.y * f) * 100) + '%;width:' +
					(s.w * 100) + '%;height:' + (s.h * f * 100) + '%"></i>';
			}).join('');
			return '<button type="button" class="tplb-laytile' + (k === state.layoutId ? ' is-active' : '') +
				(lay.free ? ' is-free' : '') + '" data-layout="' + esc(k) + '">' +
				'<span class="tplb-laytile__mini">' + mini + '</span>' +
				'<span class="tplb-laytile__label">' + esc(lay.title) + '</span>' +
				(lay.free ? '<span class="tplb-laytile__note">до ' + (lay.max || 9) + ' фото</span>' : '') +
				'</button>';
		}).join('');

		Array.prototype.forEach.call(el.layouts.querySelectorAll('[data-layout]'), function (btn) {
			btn.addEventListener('click', function () {
				state.layoutId = btn.dataset.layout;
				Array.prototype.forEach.call(el.layouts.querySelectorAll('[data-layout]'), function (b) {
					b.classList.toggle('is-active', b === btn);
				});
				syncSlots();
				// Набор полей может зависеть от раскладки: «Подпись ко
				// второму фото» существует только при двух кадрах.
				if (hasLayoutConditions()) {
					renderTexts();
					renderStyles();
				}
				renderAll();
			});
		});
	}

	/* ───────────────────────── Рендер: тексты ───────────────────────── */

	/**
	 * Поле или стиль может зависеть от другого стиля.
	 * Показывать «Трек» при выбранном QR-коде бессмысленно — в макет он
	 * всё равно не попадёт, а список полей раздувается.
	 *
	 * Две формы записи, зеркало podval_tpl_when_matches() на сервере:
	 *   { footer_left: ['player'] }   — И по всем ключам
	 *   [ { … }, { … } ]              — ИЛИ по вариантам
	 *
	 * ИЛИ нужно «Дате» в постерах: она подписывает коллаж при простом низе
	 * и штрихкод при блочном, одним И-условием это не выразить.
	 */
	function whenMatches(when) {
		if (!when) return true;

		// Пустой список вариантов или пустой объект — это «условия нет».
		// Иначе поле молча пропадало бы из формы: цикл не находит совпадений
		// и возвращает false.
		if (Array.isArray(when)) {
			if (!when.length) return true;
			for (var i = 0; i < when.length; i++) {
				if (whenMatches(when[i])) return true;
			}
			return false;
		}

		for (var key in when) {
			if (!Object.prototype.hasOwnProperty.call(when, key)) continue;
			// `layout` — зарезервированный ключ: раскладка не стиль, но
			// условия по ней нужны так же часто. Зеркало серверной
			// podval_tpl_when_matches().
			var current = ('layout' === key) ? state.layoutId : state.styles[key];
			if (when[key].indexOf(current) === -1) return false;
		}
		return true;
	}

	function textVisible(def) {
		return !def || whenMatches(def.when);
	}

	/** Есть ли у шаблона условные поля или стили — тогда смена стиля требует перерисовки. */
	/** Есть ли условия, завязанные на раскладку. */
	function hasLayoutConditions() {
		var t = tpl();
		if (!t) return false;
		function usesLayout(when) {
			if (!when) return false;
			if (Array.isArray(when)) return when.some(usesLayout);
			return Object.prototype.hasOwnProperty.call(when, 'layout');
		}
		var key;
		for (key in t.texts) {
			if (usesLayout(t.texts[key].when)) return true;
		}
		for (key in t.styles) {
			if (usesLayout(t.styles[key].when)) return true;
		}
		return false;
	}

	function hasConditionalTexts() {
		var t = tpl();
		if (!t) return false;
		var key;
		for (key in t.texts) {
			if (Object.prototype.hasOwnProperty.call(t.texts, key) && t.texts[key].when) return true;
		}
		for (key in t.styles) {
			if (Object.prototype.hasOwnProperty.call(t.styles, key) && t.styles[key].when) return true;
		}
		return false;
	}

	/** Подсказка под словом: пока букв меньше минимума — говорим, сколько нужно. */
	function wordShortNote(key, f, v) {
		var hint = el.texts && el.texts.querySelector('[data-hint="' + key + '"]');
		if (!hint) return;
		var n = Array.from(v || '').length;
		var short = !!f.min && n < f.min;
		hint.classList.toggle('is-warn', short);
		hint.textContent = short
			? 'Нужно минимум ' + f.min + ' буквы — сейчас ' + n + '.'
			: (f.hint || '');
	}

	function renderTexts() {
		var t = tpl();
		if (!t) return;

		// Куда выводить поля: в свой шаг или в шаг фото (mergeTexts).
		// el.texts переключается целиком — ниже все обработчики ищут
		// элементы через него. Второй контейнер очищается, иначе при
		// переборе основ в нём оставались бы поля прежнего шаблона.
		var merged = !!t.mergeTexts && !!el.textsMedia;
		el.texts = merged ? el.textsMedia : el.textsMain;
		var other = merged ? el.textsMain : el.textsMedia;
		if (other) other.innerHTML = '';
		if (el.textsMedia) el.textsMedia.hidden = !merged;

		// Строки опций, привязанные к полю через `after`, выводятся прямо
		// под ним. Раньше все настройки лежали общим списком в конце шага,
		// и связь «эта кнопка меняет вон то поле» приходилось угадывать.
		function attachedStyles(field) {
			return Object.keys(t.styles || {}).filter(function (k) {
				return t.styles[k].after === field &&
					!isPhotoStyle(k) &&
					whenMatches(t.styles[k].when);
			}).map(function (k) {
				return styleRowHtml(t, k);
			}).join('');
		}

		el.texts.innerHTML = Object.keys(t.texts).filter(function (key) {
			return textVisible(t.texts[key]);
		}).map(function (key) {
			var f = t.texts[key];
			var val = state.texts[key] || '';
			var locked = !!f.locked && (f.presets || []).length;

			// Подсветка выбранного — у locked-поля и у слова: для слова
			// пресеты — основное управление, ввод вторичен.
			var markActive = locked || f.type === 'word';
			var presets = (f.presets || []).map(function (p) {
				return '<button type="button" class="tplb-preset' + (markActive && p === val ? ' is-active' : '') +
					'" data-field="' + esc(key) + '" data-val="' + esc(p) + '">' + esc(p) + '</button>';
			}).join('');

			// Заблокированное поле: только выбор из списка, свободного ввода нет.
			// Значение едет в скрытом input, чтобы разметка и обработчики совпадали.
			if (locked) {
				return '<div class="tplb-field is-locked">' +
					'<label>' + esc(f.label) +
					(f.required ? ' <em class="tplb-req">обязательно</em>' : '') + '</label>' +
					(f.hint ? '<div class="tplb-field__meta"><span class="tplb-field__hint">' + esc(f.hint) + '</span></div>' : '') +
					'<div class="tplb-presets">' + presets + '</div>' +
					'<input type="hidden" data-field="' + esc(key) + '" value="' + esc(val) + '">' +
					'</div>' + attachedStyles(key);
			}

			// Слово из фото: крупные пресеты + своё слово. Ввод показывает
			// текущее слово целиком, правка в нём и есть «своё».
			if (f.type === 'word') {
				return '<div class="tplb-field is-word">' +
					'<label for="tplf-' + esc(key) + '">' + esc(f.label) +
					(f.required ? ' <em class="tplb-req">обязательно</em>' : '') + '</label>' +
					(presets ? '<div class="tplb-presets">' + presets + '</div>' : '') +
					'<div class="tplb-word-own">' +
					'<span class="tplb-word-own__label">или своё</span>' +
					'<input type="text" id="tplf-' + esc(key) + '" data-field="' + esc(key) + '" ' +
					'maxlength="' + f.max + '" value="' + esc(val) + '" autocomplete="off" spellcheck="false" ' +
					'placeholder="' + esc(f.default || '') + '">' +
					'<span class="tplb-field__count" data-count="' + esc(key) + '">' + val.length + '/' + f.max + '</span>' +
					'</div>' +
					(f.hint ? '<div class="tplb-field__meta"><span class="tplb-field__hint" data-hint="' + esc(key) + '">' +
						esc(f.hint) + '</span></div>' : '') +
					'</div>' + attachedStyles(key);
			}

			return '<div class="tplb-field">' +
				'<label for="tplf-' + esc(key) + '">' + esc(f.label) +
				(f.required ? ' <em class="tplb-req">обязательно</em>' : '') + '</label>' +
				'<input type="text" id="tplf-' + esc(key) + '" data-field="' + esc(key) + '" ' +
				'maxlength="' + f.max + '" value="' + esc(val) + '" ' +
				'placeholder="' + esc(f.default || '') + '">' +
				'<div class="tplb-field__meta">' +
				'<span class="tplb-field__hint">' + esc(f.hint || '') + '</span>' +
				'<span class="tplb-field__count" data-count="' + esc(key) + '">' + val.length + '/' + f.max + '</span>' +
				'</div>' +
				(presets ? '<div class="tplb-presets">' + presets + '</div>' : '') +
				'</div>' + attachedStyles(key);
		}).join('');

		bindStyleButtons(el.texts);

		Array.prototype.forEach.call(el.texts.querySelectorAll('input[type="text"][data-field]'), function (inp) {
			inp.addEventListener('input', function () {
				var key = inp.dataset.field;
				var f = t.texts[key];
				var v = inp.value;

				if (f.type === 'word') {
					v = v.toUpperCase().replace(/[^A-ZА-ЯЁ]/g, '');
					if (v !== inp.value) inp.value = v;
				}

				state.texts[key] = v;
				state.textsTouched[key] = true;
				var counter = el.texts.querySelector('[data-count="' + key + '"]');
				if (counter) counter.textContent = v.length + '/' + f.max;

				// Набранное совпало с пресетом — подсвечиваем его.
				Array.prototype.forEach.call(
					el.texts.querySelectorAll('.tplb-preset[data-field="' + key + '"]'),
					function (b) { b.classList.toggle('is-active', b.dataset.val === v); }
				);
				if (f.type === 'word') wordShortNote(key, f, v);

				if (f.type === 'word') syncSlots();
				renderAll();
			});
		});

		Array.prototype.forEach.call(el.texts.querySelectorAll('.tplb-preset'), function (btn) {
			btn.addEventListener('click', function () {
				var key = btn.dataset.field;
				var f = t.texts[key];
				state.texts[key] = btn.dataset.val;
				state.textsTouched[key] = true;

				var inp = el.texts.querySelector('[data-field="' + key + '"]');
				if (inp) inp.value = btn.dataset.val;

				var counter = el.texts.querySelector('[data-count="' + key + '"]');
				if (counter) counter.textContent = btn.dataset.val.length + '/' + f.max;

				Array.prototype.forEach.call(
					el.texts.querySelectorAll('.tplb-preset[data-field="' + key + '"]'),
					function (b) { b.classList.toggle('is-active', b === btn); }
				);

				if (f.type === 'word') syncSlots();
				renderAll();
			});
		});
	}

	/* ───────────────────────── Рендер: стили ───────────────────────── */

	/** Стили, относящиеся к самому фото: место им в шаге «Ваши фото»,
	    а не в «Надписях». Порядок массива = порядок вывода. */
	var PHOTO_STYLE_KEYS = ['frame', 'photo_size', 'photo_tone'];
	var TONE_KEY = 'photo_tone';

	function isPhotoStyle(key) { return PHOTO_STYLE_KEYS.indexOf(key) !== -1; }

	/**
	 * Строка опций. `major` — для переключателей, которые меняют не одну
	 * деталь, а всю компоновку и состав полей вокруг: в общем списке такой
	 * выбор терялся среди «2 или 3 строки». Отдельная рамка, акцентная
	 * подпись и пояснение под опциями.
	 */
	function styleRowHtml(t, key) {
		var st = t.styles[key];
		var opts = Object.keys(st.options).map(function (v) {
			var label = st.options[v];
			// Подпись кнопки повторяет то, что будет на футболке (см. inkColor).
			if ('script_color' === key && 'black' === v && t.engine === 'single' &&
				state.garment.color === 'black') {
				label = 'Белый';
			}
			return '<button type="button" class="tplb-chip' + (state.styles[key] === v ? ' is-active' : '') +
				'" data-style="' + esc(key) + '" data-val="' + esc(v) + '">' + esc(label) + '</button>';
		}).join('');

		var hint = st.hint ? '<span class="tplb-optrow__hint">' + esc(st.hint) + '</span>' : '';

		return '<div class="tplb-optrow' + (st.major ? ' is-major' : '') + '">' +
			'<span class="tplb-optrow__label">' + esc(st.label) + '</span>' +
			'<div class="tplb-optrow__opts">' + opts + '</div>' + hint + '</div>';
	}

	function bindStyleButtons(scope) {
		if (!scope) return;
		Array.prototype.forEach.call(scope.querySelectorAll('[data-style]'), function (btn) {
			btn.addEventListener('click', function () {
				var key = btn.dataset.style;
				state.styles[key] = btn.dataset.val;
				// Кнопка может быть продублирована в двух шагах — синхронизируем обе.
				Array.prototype.forEach.call(
					document.querySelectorAll('[data-style="' + key + '"]'),
					function (b) { b.classList.toggle('is-active', b.dataset.val === btn.dataset.val); }
				);
				// Набор видимых полей и стилей мог измениться вместе со стилем:
				// «Низ макета» прячет одни строки опций и показывает другие.
				// renderStyles() перерисовывает и сам этот переключатель,
				// поэтому обработчики навешиваются заново — обращаться к btn
				// после этой строки уже нельзя.
				if (hasConditionalTexts()) {
					renderTexts();
					renderStyles();
				}
				renderAll();
			});
		});
	}

	/** Блок стилей внутри шага «Ваши фото»: рамка, размер, тон. */
	function renderTone() {
		var t = tpl();
		if (!el.tone) return;
		if (!t || !t.styles) { el.tone.innerHTML = ''; return; }

		// Условие `when` и здесь: у «Одно фото + имя» рамка и размер фото
		// есть только в рукописном варианте.
		var rows = PHOTO_STYLE_KEYS.filter(function (key) {
			return !!t.styles[key] && whenMatches(t.styles[key].when);
		}).map(function (key) {
			return styleRowHtml(t, key);
		}).join('');

		if (!rows) { el.tone.innerHTML = ''; return; }

		// пояснение про Ч/Б убрано. То же самое, только полнее,
		// говорит оговорка о кадрировании двумя строками ниже — в
		// объединённом шаге две подряд подсказки мелким шрифтом читались
		// как шум.
		el.tone.innerHTML = rows;
		bindStyleButtons(el.tone);
	}

	function renderStyles() {
		var t = tpl();
		if (!t || !t.styles) { el.stylesBox.innerHTML = ''; return; }

		// Одна разметка на оба места вывода: раньше здесь была своя копия,
		// и любой новый атрибут строки (hint, major) приходилось добавлять
		// дважды. Теперь строку строит только styleRowHtml().
		el.stylesBox.innerHTML = Object.keys(t.styles).filter(function (key) {
			return !isPhotoStyle(key) && !t.styles[key].after && whenMatches(t.styles[key].when);
		}).map(function (key) {
			return styleRowHtml(t, key);
		}).join('');

		bindStyleButtons(el.stylesBox);
		renderTone();
	}

	/* ───────────────────────── Рендер: изделие ───────────────────────── */

	function renderGarment() {
		var t = tpl();
		var g = CFG.garment;

		el.garment.innerHTML = Object.keys(g).map(function (field) {
			var def = g[field];
			var keys = Object.keys(def.options);

			// Цвет ограничен списком шаблона.
			if (field === 'color' && t) {
				keys = keys.filter(function (k) { return t.colors.indexOf(k) !== -1; });
			}

			var opts = keys.map(function (v) {
				var o = def.options[v];
				var active = state.garment[field] === v ? ' is-active' : '';
				if (field === 'color') {
					return '<button type="button" class="tplb-swatch' + active + '" data-g="' + esc(field) + '" data-val="' + esc(v) + '" title="' + esc(o.label) + '">' +
						'<i style="background:' + esc(o.hex) + '"></i><span>' + esc(o.label) + '</span></button>';
				}
				return '<button type="button" class="tplb-chip' + active + '" data-g="' + esc(field) + '" data-val="' + esc(v) + '">' + esc(o.label) + '</button>';
			}).join('');

			// Таблица размеров нужна ровно там, где выбирают размер.
			var chart = (field === 'size' && CFG.sizeChart)
				? '<button type="button" class="tplb-sizechart" data-sizechart="1">Таблица размеров</button>'
				: '';

			return '<div class="tplb-optrow"><span class="tplb-optrow__label">' + esc(def.label) + '</span>' +
				'<div class="tplb-optrow__opts">' + opts + chart + '</div></div>';
		}).join('');

		// Открытие вешаем сами: попап статичен, но ссылка рисуется динамически,
		// и обработчик main.js по .js-size-chart-open её уже не застаёт.
		var chartBtn = el.garment.querySelector('[data-sizechart]');
		if (chartBtn) {
			chartBtn.addEventListener('click', function () {
				var overlay = document.getElementById('tplb-size-chart') ||
					document.querySelector('.sc-overlay');
				if (!overlay) return;
				overlay.classList.add('is-open');
				document.body.style.overflow = 'hidden';
			});
		}

		Array.prototype.forEach.call(el.garment.querySelectorAll('[data-g]'), function (btn) {
			btn.addEventListener('click', function () {
				state.garment[btn.dataset.g] = btn.dataset.val;
				Array.prototype.forEach.call(
					el.garment.querySelectorAll('[data-g="' + btn.dataset.g + '"]'),
					function (b) { b.classList.toggle('is-active', b === btn); }
				);
				// Кнопка «Чёрный / Белый» у цвета надписи зависит от футболки.
				var tt = tpl();
				if ('color' === btn.dataset.g && tt && tt.styles && tt.styles.script_color) {
					renderTexts();
					renderStyles();
				}
				renderAll();
			});
		});

		renderAddons();
	}

	function renderAddons() {
		var manual = Object.keys(CFG.addons).filter(function (c) { return !CFG.addons[c].auto; });
		if (!manual.length) { el.addons.innerHTML = ''; return; }

		el.addons.innerHTML = '<div class="tplb-addons__head">Дополнительно</div>' + manual.map(function (code) {
			var a = CFG.addons[code];
			var on = state.addons.indexOf(code) !== -1;
			return '<label class="tplb-addon' + (on ? ' is-on' : '') + '">' +
				'<input type="checkbox" data-addon="' + esc(code) + '"' + (on ? ' checked' : '') + '>' +
				'<span class="tplb-addon__body">' +
				'<span class="tplb-addon__title">' + esc(a.title) + '</span>' +
				(a.hint ? '<span class="tplb-addon__hint">' + esc(a.hint) + '</span>' : '') +
				'</span>' +
				'<span class="tplb-addon__price">+' + money(parseFloat(a.amount)) + '</span>' +
				'</label>';
		}).join('');

		Array.prototype.forEach.call(el.addons.querySelectorAll('[data-addon]'), function (cb) {
			cb.addEventListener('change', function () {
				var code = cb.dataset.addon;
				var i = state.addons.indexOf(code);
				if (cb.checked && i === -1) state.addons.push(code);
				if (!cb.checked && i !== -1) state.addons.splice(i, 1);
				cb.closest('.tplb-addon').classList.toggle('is-on', cb.checked);
				renderAll();
			});
		});
	}

	/* ───────────────────────── Загрузка фото ───────────────────────── */

	function initUpload() {
		el.dropBtn.addEventListener('click', function () {
			state.pickTarget = null;
			el.file.click();
		});

		el.file.addEventListener('change', function () {
			var files = Array.prototype.slice.call(el.file.files || []);
			// Первый файл идёт в слот, с которого открыли диалог; остальные —
			// в свободные слоты по порядку.
			var target = state.pickTarget;
			state.pickTarget = null;
			files.forEach(function (f, i) {
				uploadOne(f, i === 0 ? target : null);
			});
			el.file.value = '';
		});

		['dragenter', 'dragover'].forEach(function (ev) {
			el.drop.addEventListener(ev, function (e) {
				e.preventDefault();
				el.drop.classList.add('is-over');
			});
		});
		['dragleave', 'drop'].forEach(function (ev) {
			el.drop.addEventListener(ev, function (e) {
				e.preventDefault();
				el.drop.classList.remove('is-over');
			});
		});
		el.drop.addEventListener('drop', function (e) {
			var files = Array.prototype.slice.call((e.dataTransfer && e.dataTransfer.files) || []);
			files.forEach(uploadOne);
		});
	}

	/** Открыть диалог выбора файла с привязкой к конкретному слоту. */
	function pickForSlot(slotIndex) {
		state.pickTarget = slotIndex;
		el.file.click();
	}

	/**
	 * Уложить фото в лимит загрузки PHP.
	 *
	 * Лимит берётся с сервера (`wp_max_upload_size()` → CFG.serverMax): на
	 * хостинге он бывает и 2 МБ, а фото с телефона весят 3–8. Раньше такое
	 * фото отклонялось с «Файл превышает upload_max_filesize», хотя ни
	 * клиент, ни мы ничего не нарушали.
	 *
	 * Если файл влезает — уходит как есть, без перекодирования. Если нет —
	 * перерисовывается в JPEG с понижением стороны и качества, пока не
	 * влезет. Не вышло (экзотический формат, нет памяти) — уходит оригинал,
	 * сервер вернёт понятную ошибку.
	 *
	 * @return {Promise<{blob: Blob, name: string, shrunk: boolean}>}
	 */
	function fitForServer(file) {
		var limit = parseInt(CFG.serverMax, 10) || 0;
		var asIs = { blob: file, name: file.name, shrunk: false };
		// Запас на остальные поля формы — они тоже считаются в post_max_size.
		var target = limit - 64 * 1024;
		if (!limit || file.size <= target || !/^image\/(jpeg|png|webp)$/.test(file.type)) {
			return Promise.resolve(asIs);
		}

		var steps = [
			[4000, 0.9], [3200, 0.88], [2560, 0.86], [2048, 0.85], [1600, 0.82], [1280, 0.8]
		];
		var url = URL.createObjectURL(file);
		var name = file.name.replace(/\.[^.]+$/, '') + '.jpg';

		return new Promise(function (resolve) {
			var img = new Image();
			img.onload = function () {
				var i = 0;
				(function next() {
					if (i >= steps.length) { URL.revokeObjectURL(url); resolve(asIs); return; }
					var side = steps[i][0], q = steps[i][1];
					i++;
					var k = Math.min(1, side / Math.max(img.naturalWidth, img.naturalHeight));
					var cv = document.createElement('canvas');
					cv.width = Math.round(img.naturalWidth * k);
					cv.height = Math.round(img.naturalHeight * k);
					var ctx = cv.getContext('2d');
					if (!ctx || typeof cv.toBlob !== 'function') { URL.revokeObjectURL(url); resolve(asIs); return; }
					// PNG с прозрачностью — на белом, иначе в JPEG станет чёрным.
					ctx.fillStyle = '#fff';
					ctx.fillRect(0, 0, cv.width, cv.height);
					ctx.drawImage(img, 0, 0, cv.width, cv.height);
					cv.toBlob(function (blob) {
						if (blob && blob.size <= target) {
							URL.revokeObjectURL(url);
							resolve({ blob: blob, name: name, shrunk: true });
						} else {
							next();
						}
					}, 'image/jpeg', q);
				})();
			};
			img.onerror = function () { URL.revokeObjectURL(url); resolve(asIs); };
			img.src = url;
		});
	}

	function uploadOne(file, targetSlot) {
		if (!/^image\//.test(file.type)) {
			showError('Можно загружать только изображения.');
			return;
		}
		if (file.size > CFG.maxUpload * 1024 * 1024) {
			showError('Файл «' + file.name + '» больше ' + CFG.maxUpload + ' МБ.');
			return;
		}
		showError('');

		// Позицию ищем по идентичности объекта, а не по захваченному индексу:
		// параллельная загрузка может упасть и сдвинуть весь пул.
		var placeholder = { id: 0, url: '', pending: true, soft: false };
		state.pool.push(placeholder);
		// Плейсхолдер сразу занимает слот — прогресс виден в самом макете,
		// отдельной ленты загрузок больше нет. renderAll, а не только
		// слоты: пока фото грузится, кнопка «В корзину» должна погаснуть
		// и объяснить почему.
		syncSlots();
		renderAll();

		var at = function () { return state.pool.indexOf(placeholder); };

		// фото крупнее лимита сервера ужимается в браузере (см.
		// fitForServer). Локальное превью и растр для заказа всё равно
		// строятся из оригинала — URL ниже берётся от `file`, не от сжатой копии.
		fitForServer(file).then(function (payload) {
			var fd = new FormData();
			fd.append('action', 'podval_upload_print');
			fd.append('_wpnonce', CFG.nonce);
			fd.append('podval_print_file', payload.blob, payload.name);
			return fetch(CFG.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
		})
			.then(function (r) { return r.json(); })
			.then(function (res) {
				var idx = at();
				if (idx === -1) return;

				if (!res || !res.success) {
					state.pool.splice(idx, 1);
					showError((res && res.data && res.data.message) || 'Не удалось загрузить файл.');
					syncSlots();
					renderAll();
					return;
				}

				var localUrl = URL.createObjectURL(file);
				var entry = { id: res.data.id, url: localUrl, remoteUrl: res.data.url, pending: false, soft: false };
				state.pool[idx] = entry;

				checkQuality(entry, localUrl);

				// Слот, с которого открыли диалог, получает фото приоритетно.
				if (targetSlot != null && state.slots[targetSlot]) {
					var prev = state.slots[targetSlot].poolIndex;
					state.slots[targetSlot].poolIndex = idx;
					// Освободившееся фото не теряем — вернём его в свободный слот.
					if (prev != null && prev !== idx) {
						var free = state.slots.filter(function (sl) { return sl.poolIndex == null; })[0];
						if (free) free.poolIndex = prev;
					}
				}

				syncSlots();
				renderAll();
			})
			.catch(function () {
				var idx = at();
				if (idx !== -1) state.pool.splice(idx, 1);
				showError('Сбой сети при загрузке. Попробуйте ещё раз.');
				syncSlots();
				renderAll();
			});
	}

	/** Порог качества по короткой стороне — предупреждение, не блокировка. */
	function checkQuality(entry, url) {
		var img = new Image();
		img.onload = function () {
			entry.w = img.naturalWidth;
			entry.h = img.naturalHeight;
			entry.soft = Math.min(img.naturalWidth, img.naturalHeight) < CFG.minPixels;
			renderSlots();
			renderPreview();
		};
		img.src = url;
	}

	/* ───────────────────────── Рендер: пул и слоты ───────────────────────── */

	function removeFromPool(i) {
		state.pool.splice(i, 1);
		state.selectedSlot = null;

		state.slots.forEach(function (s) {
			if (s.poolIndex === i) s.poolIndex = null;
			else if (s.poolIndex != null && s.poolIndex > i) s.poolIndex--;
		});

		// В свободной компоновке число слотов равно числу фото — пересобираем.
		syncSlots();
		renderAll();
	}

	/* Слоты макета — единственный список фото в конструкторе.
	   Отдельная лента «Загруженные фото» убрана: она дублировала те же
	   миниатюры и заставляла целиться дважды (взять фото → положить в слот).
	   Здесь всё на месте: загрузка, удаление, перестановка. */

	function slotInner(d, i, isLetters) {
		var s = state.slots[i] || { poolIndex: null };
		var p = s.poolIndex != null ? state.pool[s.poolIndex] : null;
		var label = isLetters ? d.char : ('Фото ' + (i + 1));

		if (p && p.pending) {
			return {
				cls: ' is-pending',
				html: '<span class="tplb-spin" aria-hidden="true"></span>' +
					'<span class="tplb-slot__label">' + esc(label) + '</span>',
				title: 'Загружается…'
			};
		}

		if (!p) {
			return {
				cls: ' is-empty',
				html: '<span class="tplb-slot__plus" aria-hidden="true">+</span>' +
					'<span class="tplb-slot__label">' + esc(label) + '</span>',
				title: 'Нажмите, чтобы загрузить фото в этот слот'
			};
		}

		return {
			cls: ' is-filled' + (state.selectedSlot === i ? ' is-picked' : ''),
			html: '<img src="' + esc(p.url) + '" alt="" draggable="false">' +
				(p.soft ? '<span class="tplb-slot__warn" role="img" aria-label="Небольшое разрешение" ' +
					'title="Небольшое разрешение — расшифровка под сеткой">!</span>' : '') +
				'<span class="tplb-slot__label">' + esc(label) + '</span>' +
				(!isLetters && cropSupported()
					? '<button type="button" class="tplb-slot__crop' + (p.fit ? ' is-set' : '') + '" data-cropbtn="' + i + '" ' +
						'aria-label="Кадрировать фото" title="Кадрировать: масштаб и положение">' +
						'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 2v14a2 2 0 0 0 2 2h14M2 6h14a2 2 0 0 1 2 2v14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>' +
						'</button>'
					: '') +
				'<button type="button" class="tplb-slot__del" data-del="' + i + '" aria-label="Удалить фото">×</button>',
			title: 'Перетащите на другой слот, чтобы поменять местами'
		};
	}

	function renderSlots() {
		var defs = slotDefs();

		if (!defs.length) { el.slotsBox.innerHTML = ''; return; }

		var t = tpl();
		var isLetters = t && t.engine === 'letters';

		var lay = activeLayout();
		var isFree = !!(lay && lay.free);
		var maxFree = (lay && lay.max) || 9;

		var hint = isFree
			? 'добавляйте фото — сетка подстроится, до ' + maxFree
			: 'нажмите на пустой слот, чтобы загрузить фото';
		hint += '. Перетащите фото на другое или выберите два — поменяются местами';

		// В свободной компоновке слот = загруженное фото. Пустых слотов здесь
		// по определению нет, поэтому нужна отдельная плитка добавления.
		var addTile = (isFree && state.pool.length < maxFree)
			? '<button type="button" class="tplb-slot is-add" data-add="1" ' +
				'title="Добавить ещё фото в макет">' +
				'<span class="tplb-slot__plus" aria-hidden="true">+</span>' +
				'<span class="tplb-slot__label">Ещё фото</span></button>'
			: '';

		// Расшифровка бейджа «!». Значок на миниатюре сам по себе ничего не
		// объясняет, а title на тач-экране не открывается вообще — на телефоне
		// клиент видел восклицательный знак и никакого способа узнать, что он
		// значит. Строка появляется только когда такое фото реально есть.
		var softNums = [];
		defs.forEach(function (d, i) {
			var s = state.slots[i];
			var p = (s && s.poolIndex != null) ? state.pool[s.poolIndex] : null;
			if (p && p.soft) softNums.push(isLetters ? d.char : String(i + 1));
		});

		var softNote = '';
		if (softNums.length) {
			var many   = softNums.length > 1;
			var prefix = isLetters ? (many ? 'Буквы ' : 'Буква ') : 'Фото ';
			softNote = '<p class="tplb-softnote">' +
				'<span class="tplb-softnote__mark" aria-hidden="true">!</span>' +
				'<span>' + prefix + esc(softNums.join(', ')) +
				' — небольшое разрешение. Дизайнер дополнительно обработает ' +
				(many ? 'кадры' : 'кадр') + ' на согласовании.</span></p>';
		}

		el.slotsBox.innerHTML = '<div class="tplb-slots__head">Фото в макете' +
			'<span class="tplb-slots__hint">' + esc(hint) + '</span></div>' +
			'<div class="tplb-slots__grid" id="tplb-slots-grid">' + defs.map(function (d, i) {
				var v = slotInner(d, i, isLetters);
				// div, а не button: внутри лежит настоящая кнопка удаления,
				// вложенные интерактивные элементы в button невалидны.
				return '<div class="tplb-slot' + v.cls + (isLetters ? ' is-letter' : '') + '" role="button" tabindex="0" ' +
					'data-slot="' + i + '" title="' + esc(v.title) + '">' + v.html + '</div>';
			}).join('') + addTile + '</div>' +
			(isFree
				? '<div class="tplb-slots__count">В макете ' + state.pool.length + ' из ' + maxFree + '</div>'
				: '') +
			softNote;

		var addBtn = el.slotsBox.querySelector('[data-add]');
		if (addBtn) {
			addBtn.addEventListener('click', function () { pickForSlot(null); });
		}

		Array.prototype.forEach.call(el.slotsBox.querySelectorAll('[data-cropbtn]'), function (btn) {
			btn.addEventListener('click', function (e) {
				e.preventDefault();
				e.stopPropagation();
				openCrop(parseInt(btn.dataset.cropbtn, 10));
			});
		});
		Array.prototype.forEach.call(el.slotsBox.querySelectorAll('[data-del]'), function (btn) {
			btn.addEventListener('click', function (e) {
				e.stopPropagation();
				var i = parseInt(btn.dataset.del, 10);
				var s = state.slots[i];
				if (!s || s.poolIndex == null) return;
				removeFromPool(s.poolIndex);
			});
		});

		bindSlotInteractions();
	}

	/** Обмен содержимым двух слотов. */
	function swapSlots(a, b) {
		if (a === b || !state.slots[a] || !state.slots[b]) return;
		var tmp = state.slots[a].poolIndex;
		state.slots[a].poolIndex = state.slots[b].poolIndex;
		state.slots[b].poolIndex = tmp;
	}

	/** Клик по слоту: пустой — загрузка, заполненный — выбор либо обмен. */
	function activateSlot(i) {
		if (!state.slots[i]) return;

		var picked = state.selectedSlot;

		if (picked != null && picked !== i) {
			swapSlots(picked, i);
			state.selectedSlot = null;
			renderAll();
			return;
		}

		if (picked === i) {
			state.selectedSlot = null;
			renderSlots();
			return;
		}

		if (state.slots[i].poolIndex == null) {
			pickForSlot(i);
			return;
		}

		state.selectedSlot = i;
		renderSlots();
	}

	/* Перетаскивание — Pointer Events, и только мышью: на тач-устройстве
	   перехват жеста отнял бы у страницы вертикальный скролл, а обмен там
	   и так доступен в два тапа. */
	function bindSlotInteractions() {
		var grid = document.getElementById('tplb-slots-grid');
		if (!grid) return;

		Array.prototype.forEach.call(grid.querySelectorAll('[data-slot]'), function (node) {
			var idx = parseInt(node.dataset.slot, 10);

			node.addEventListener('keydown', function (e) {
				if (e.target !== node) return; // Enter на кнопке внутри слота — её дело
				if (e.key === 'Enter' || e.key === ' ') {
					e.preventDefault();
					activateSlot(idx);
				}
			});

			node.addEventListener('pointerdown', function (e) {
				if (e.target.closest('[data-del]') || e.target.closest('[data-cropbtn]')) return;

				var filled = state.slots[idx] && state.slots[idx].poolIndex != null;
				var canDrag = filled && e.pointerType === 'mouse' && e.button === 0;

				var startX = e.clientX;
				var startY = e.clientY;
				var dragging = false;
				var over = null;

				function clearOver() {
					if (over) over.classList.remove('is-drop');
					over = null;
				}

				function onMove(ev) {
					if (!canDrag) return;
					if (!dragging) {
						if (Math.abs(ev.clientX - startX) + Math.abs(ev.clientY - startY) < 6) return;
						dragging = true;
						node.classList.add('is-dragging');
						grid.classList.add('is-swapping');
					}
					var el2 = document.elementFromPoint(ev.clientX, ev.clientY);
					var target = el2 && el2.closest ? el2.closest('[data-slot]') : null;
					if (target === node) target = null;
					if (target !== over) {
						clearOver();
						if (target) { over = target; over.classList.add('is-drop'); }
					}
				}

				function onUp(ev) {
					node.removeEventListener('pointermove', onMove);
					node.removeEventListener('pointerup', onUp);
					node.removeEventListener('pointercancel', onUp);
					node.classList.remove('is-dragging');
					grid.classList.remove('is-swapping');

					var target = over;
					clearOver();

					if (dragging) {
						if (target) {
							swapSlots(idx, parseInt(target.dataset.slot, 10));
							state.selectedSlot = null;
							renderAll();
						} else {
							renderSlots();
						}
						return;
					}

					// Движения не было — это обычный клик.
					if (ev.type !== 'pointercancel') activateSlot(idx);
				}

				if (canDrag && node.setPointerCapture) {
					try { node.setPointerCapture(e.pointerId); } catch (err) { /* noop */ }
				}
				node.addEventListener('pointermove', onMove);
				node.addEventListener('pointerup', onUp);
				node.addEventListener('pointercancel', onUp);
			});
		});
	}

	/* ───────────────────────── Рендер: превью ───────────────────────── */

	function photoFor(i) {
		var s = state.slots[i];
		if (!s || s.poolIndex == null) return null;
		return state.pool[s.poolIndex] || null;
	}

	/* фото, которое ещё загружается, — спиннер в макете, а не
	   <img src=""> (битая картинка). Класс ячейки: пусто / грузится / фото. */
	function cellCls(p) { return p ? (p.pending ? ' is-loading' : '') : ' is-empty'; }

	function photoHtml(p, emptyHtml, i) {
		if (!p) return emptyHtml;
		if (p.pending || !p.url) {
			return '<span class="tplp-loading" role="status" aria-label="Фото загружается">' +
				'<span class="tplp-loading__spin" aria-hidden="true"></span>' +
				'<span class="tplp-loading__txt">загрузка…</span></span>';
		}
		// обёртка режет края кадрированного фото и служит точкой
		// входа в редактор кадра (клик по фото в макете).
		return '<span class="tplp-crop" data-crop="' + (i || 0) + '" title="Нажмите, чтобы кадрировать">' +
			'<img src="' + esc(p.url) + '" alt="" draggable="false">' +
			'<span class="tplp-crop__badge" aria-hidden="true"><i></i><b>Кадр</b></span></span>';
	}

	/* ───────────────────────── Кадрирование ─────────────────────────
	   Кадр хранится на самом фото (запись пула): p.fit = { zoom, cx, cy }.
	   zoom ∈ [1, FIT_MAX], 1 — «заполнить рамку» (прежнее поведение);
	   cx, cy ∈ [0, 1] — какая точка фото стоит в центре рамки.
	   Доли не зависят ни от разрешения (оригинал, ужатая копия для печати,
	   уменьшенная копия в редакторе), ни от размера рамки — поэтому кадр
	   переезжает вместе с фото при обмене слотов и при смене раскладки,
	   а под пропорции конкретной рамки зажимается при каждой отрисовке.
	   Одна функция cropRect() считает вырезку для превью, растра и заказа. */

	var FIT_MAX = 4;

	function clampNum(v, a, b) { return v < a ? a : (v > b ? b : v); }

	/**
	 * Нижняя граница масштаба для рамки W×H: фото целиком в рамке
	 * («вписать», прежней версии). 1 — «заполнить» (cover), меньше — с полями.
	 */
	function fitMin(iw, ih, W, H) {
		return Math.min(W / iw, H / ih) / Math.max(W / iw, H / ih);
	}

	/**
	 * Какой кусок фото iw×ih попадает в рамку W×H при кадре fit.
	 *
	 * Окно sw×sh в координатах фото. При zoom < 1 окно шире фото по одной
	 * или обеим осям — фото тогда лежит внутри рамки и не может выйти за
	 * её край (поля — цвет футболки / прозрачность в печатном файле).
	 * @return {{sx:number, sy:number, sw:number, sh:number, s:number, z:number}}
	 *         окно в пикселях фото (sx, sy бывают < 0) и масштаб
	 *         «пиксели рамки / пиксели фото».
	 */
	function cropRect(iw, ih, W, H, fit) {
		var zmin = fitMin(iw, ih, W, H);
		var z = fit ? clampNum(+fit.zoom || 1, zmin, FIT_MAX) : 1;
		var s = Math.max(W / iw, H / ih) * z;
		var sw = W / s;
		var sh = H / s;
		var cx = fit && isFinite(+fit.cx) ? +fit.cx : 0.5;
		var cy = fit && isFinite(+fit.cy) ? +fit.cy : 0.5;
		var sx = clampNum(cx * iw - sw / 2, Math.min(0, iw - sw), Math.max(0, iw - sw));
		var sy = clampNum(cy * ih - sh / 2, Math.min(0, ih - sh), Math.max(0, ih - sh));
		return { sx: sx, sy: sy, sw: sw, sh: sh, s: s, z: z };
	}

	/** Кадр «по умолчанию» — его не храним, чтобы не плодить шум в данных. */
	function isDefaultFit(fit) {
		return !fit || (Math.abs((+fit.zoom || 1) - 1) < 0.005 &&
			Math.abs((+fit.cx) - 0.5) < 0.002 && Math.abs((+fit.cy) - 0.5) < 0.002);
	}

	/** Можно ли кадрировать фото этого шаблона (буквы — отдельный этап). */
	function cropSupported() {
		var t = tpl();
		return !!t && 'letters' !== t.engine;
	}

	/**
	 * Применить кадр к картинкам превью. Зовётся после каждой отрисовки и
	 * подгона (fitPreviewText): пропорции ячейки известны только из DOM.
	 * Смещение — в процентах от бокса картинки, поэтому от ресайза без
	 * смены пропорций пересчёт не нужен.
	 */
	function applyCrops() {
		if (!el.art) return;
		Array.prototype.forEach.call(el.art.querySelectorAll('[data-crop]'), function (box) {
			var img = box.querySelector('img');
			var p = photoFor(parseInt(box.dataset.crop, 10));
			if (!img) return;
			var cell = box.parentElement;
			if (!p || isDefaultFit(p.fit) || !p.w || !p.h) {
				img.removeAttribute('style');
				box.classList.remove('is-cropped', 'is-contain');
				if (cell) cell.classList.remove('is-contain');
				return;
			}
			var W = box.clientWidth, H = box.clientHeight;
			if (!W || !H) return;
			var r = cropRect(p.w, p.h, W, H, p.fit);
			// не transform поверх object-fit — браузер режет
			// содержимое object-fit по рамке самого <img>, и при сдвиге вместе
			// с картинкой уезжала её рамка (серые куски). Теперь <img> —
			// это фото целиком в нужном масштабе, а режет обёртка.
			// Проценты — от бокса обёртки, поэтому ресайз без смены
			// пропорций пересчёта не требует.
			var st = img.style;
			st.position = 'absolute';
			st.left = (-r.sx * r.s / W * 100) + '%';
			st.top = (-r.sy * r.s / H * 100) + '%';
			st.width = (p.w * r.s / W * 100) + '%';
			st.height = (p.h * r.s / H * 100) + '%';
			st.right = 'auto';
			st.bottom = 'auto';
			st.maxWidth = 'none';
			st.maxHeight = 'none';
			st.objectFit = 'fill';
			st.transform = 'none';
			var contain = r.z < 0.999;
			box.classList.add('is-cropped');
			box.classList.toggle('is-contain', contain);
			if (cell) cell.classList.toggle('is-contain', contain);
		});
		syncCropHint();
	}

	/* Подсказка под макетом и пульс значков — пока редактор кадра ни разу
	   не открывали: кнопка «Кадр» на фото сама по себе не все
	   замечают. */
	function cropUsed() {
		try { return !!localStorage.getItem('tplc_used'); } catch (e) { return false; }
	}
	function syncCropHint() {
		var show = cropSupported() && cropSlots().length > 0 && !cropUsed();
		if (el.cropHint) el.cropHint.hidden = !show;
		if (el.art) el.art.classList.toggle('tplc-new', show);
	}

	/* ───────────────────────── Редактор кадра ─────────────────────────
	   Отдельный экран, а не жесты прямо в превью: ячейка коллажа на телефоне
	   100–150 px, и щипок в ней спорил бы с прокруткой и зумом страницы.
	   Здесь рамка слота крупно, в реальных пропорциях, снаружи — затемнение.

	   Жесты — Pointer Events, один код для мыши, пера и пальцев:
	     1 палец / мышь   — двигать;
	     2 пальца         — масштаб вокруг точки между пальцами (+ сдвиг);
	     колесо / щипок тачпада (ctrl+wheel) — масштаб вокруг курсора;
	     двойной тап / клик — ×1 ↔ ×2 в этой точке;
	     стрелки, + / −    — клавиатура.
	   Во время жеста двигается только transform одного холста (rAF), макет
	   перерисовывается один раз — по «Готово». */

	var crop = null; // состояние открытого редактора

	function cropSlots() {
		if (!cropSupported()) return [];
		var out = [];
		state.slots.forEach(function (s, i) {
			var p = s.poolIndex != null ? state.pool[s.poolIndex] : null;
			if (p && !p.pending && p.url) out.push(i);
		});
		return out;
	}

	/** Пропорции и доля ширины принта для рамки слота — из живого превью. */
	function slotFrameInfo(i) {
		var box = el.art && el.art.querySelector('[data-crop="' + i + '"]');
		var art = el.art && (el.art.firstElementChild || el.art);
		if (!box || !box.clientWidth || !box.clientHeight) return { aspect: 1, frac: 0.5 };
		var aw = art ? art.getBoundingClientRect().width : 0;
		var bw = box.getBoundingClientRect().width;
		return {
			aspect: box.clientWidth / box.clientHeight,
			frac: aw > 0 ? Math.min(1, bw / aw) : 0.5
		};
	}

	/**
	 * @param {number}  i          Слот.
	 * @param {boolean} [reuseHist] Запись истории уже есть (листание фото).
	 */
	function openCrop(i, reuseHist) {
		var p = photoFor(i);
		if (!p || p.pending || !p.url || !cropSupported() || crop) return;

		var info = slotFrameInfo(i);
		var root = document.createElement('div');
		root.className = 'tplc';
		root.setAttribute('role', 'dialog');
		root.setAttribute('aria-modal', 'true');
		root.setAttribute('aria-label', 'Кадрирование фото');
		var touch = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
		root.innerHTML =
			'<div class="tplc__panel">' +
				'<div class="tplc__bar">' +
					'<button type="button" class="tplc__nav" data-c="prev" aria-label="Предыдущее фото">‹</button>' +
					'<div class="tplc__title"></div>' +
					'<button type="button" class="tplc__nav" data-c="next" aria-label="Следующее фото">›</button>' +
					'<button type="button" class="tplc__close" data-c="cancel" aria-label="Закрыть без изменений">×</button>' +
				'</div>' +
				'<div class="tplc__stage" tabindex="0">' +
					'<canvas class="tplc__img" aria-hidden="true"></canvas>' +
					'<div class="tplc__frame" aria-hidden="true"><i></i><i></i><i></i><i></i></div>' +
					'<div class="tplc__h" data-h="tl" aria-hidden="true"></div>' +
					'<div class="tplc__h" data-h="tr" aria-hidden="true"></div>' +
					'<div class="tplc__h" data-h="bl" aria-hidden="true"></div>' +
					'<div class="tplc__h" data-h="br" aria-hidden="true"></div>' +
					'<div class="tplc__spin" aria-hidden="true"></div>' +
					'<div class="tplc__hint">' + (touch
						? 'Двигайте фото пальцем<br>Масштаб — двумя пальцами или за угол'
						: 'Перетаскивайте фото мышью<br>Масштаб — колесом или за угол фото') + '</div>' +
				'</div>' +
				'<div class="tplc__tools">' +
					'<div class="tplc__zoom">' +
						'<button type="button" class="tplc__zbtn" data-c="out" aria-label="Уменьшить">−</button>' +
						'<input type="range" class="tplc__range" min="100" max="' + (FIT_MAX * 100) + '" step="1" value="100" aria-label="Масштаб">' +
						'<button type="button" class="tplc__zbtn" data-c="in" aria-label="Увеличить">+</button>' +
					'</div>' +
					'<div class="tplc__q" aria-live="polite"></div>' +
					'<div class="tplc__actions">' +
						'<button type="button" class="tplc__btn tplc__btn--ghost" data-c="reset">Сбросить</button>' +
						'<button type="button" class="tplc__btn tplc__btn--main" data-c="done">Готово</button>' +
					'</div>' +
				'</div>' +
			'</div>';
		document.body.appendChild(root);
		document.documentElement.classList.add('tplc-lock');
		try { localStorage.setItem('tplc_used', '1'); } catch (e) { /* noop */ }
		syncCropHint();

		var fit = p.fit || {};
		crop = {
			slot: i,
			p: p,
			root: root,
			stage: root.querySelector('.tplc__stage'),
			cv: root.querySelector('.tplc__img'),
			frameEl: root.querySelector('.tplc__frame'),
			range: root.querySelector('.tplc__range'),
			q: root.querySelector('.tplc__q'),
			hint: root.querySelector('.tplc__hint'),
			handles: Array.prototype.slice.call(root.querySelectorAll('.tplc__h')),
			zmin: 1,
			aspect: info.aspect,
			frac: info.frac,
			iw: p.w || 0,
			ih: p.h || 0,
			z: clampNum(+fit.zoom || 1, 0.05, FIT_MAX),
			cx: isFinite(+fit.cx) && fit.cx !== undefined ? +fit.cx : 0.5,
			cy: isFinite(+fit.cy) && fit.cy !== undefined ? +fit.cy : 0.5,
			ready: false,
			pts: {},
			last: null,
			tap: null,
			raf: 0,
			pushed: false
		};
		root.querySelector('.tplc__title').textContent = cropTitle();
		root.dataset.tone = state.styles.photo_tone || 'color';
		var many = cropSlots().length > 1;
		Array.prototype.forEach.call(root.querySelectorAll('.tplc__nav'), function (b) { b.hidden = !many; });

		try {
			if (localStorage.getItem('tplc_hint_seen')) crop.hint.hidden = true;
		} catch (e) { /* приватный режим — подсказка просто покажется */ }

		bindCrop(crop);
		loadCropImage(crop);

		// Рамка — в пропорциях печатного растра, а не DOM-превью: превью
		// ужимается под лимит 30×44 своим расчётом, и ячейки там могут быть
		// на несколько процентов шире или уже. Кадрируют под печать.
		var c0 = crop;
		buildPreviewDataUrl(function (geo) {
			var g = geo && geo[i];
			if (crop !== c0 || !g || !(g.aspect > 0)) return;
			c0.aspect = g.aspect;
			c0.frac = Math.min(1, g.frac || c0.frac);
			if (c0.ready) layoutCrop(c0);
		}, false, true);

		// «Назад» на телефоне закрывает редактор, а не уводит со страницы
		// с недособранным макетом.
		if (reuseHist) {
			crop.pushed = true;
		} else {
			try {
				history.pushState({ tplc: 1 }, '');
				crop.pushed = true;
			} catch (e) { /* noop */ }
		}

		window.setTimeout(function () { if (crop) crop.stage.focus({ preventScroll: true }); }, 30);
	}

	function cropTitle() {
		var list = cropSlots();
		var n = list.indexOf(crop.slot);
		var isPhotoWord = list.length > 1 ? 'Фото ' + (n + 1) + ' из ' + list.length : 'Кадрирование';
		return isPhotoWord;
	}

	/** Уменьшенная копия для экрана: 24–48 Мп оригинал как текстура
	   GPU на телефоне — лишние сотни МБ и рывки при жесте. */
	function loadCropImage(c) {
		var img = new Image();
		img.onload = function () {
			if (crop !== c) return;
			c.iw = img.naturalWidth;
			c.ih = img.naturalHeight;
			var k = Math.min(1, 2048 / Math.max(c.iw, c.ih));
			c.cv.width = Math.max(1, Math.round(c.iw * k));
			c.cv.height = Math.max(1, Math.round(c.ih * k));
			var ctx = c.cv.getContext('2d');
			if (ctx) {
				ctx.imageSmoothingQuality = 'high';
				ctx.drawImage(img, 0, 0, c.cv.width, c.cv.height);
			}
			c.cv.style.width = c.cv.width + 'px';
			c.cv.style.height = c.cv.height + 'px';
			c.ready = true;
			c.root.classList.add('is-ready');
			layoutCrop(c);
		};
		img.onerror = function () { if (crop === c) closeCrop(false); };
		img.src = c.p.url;
	}

	/** Рамка вписывается в сцену с полями; пересчёт при повороте экрана. */
	function layoutCrop(c) {
		var r = c.stage.getBoundingClientRect();
		// рамка меньше — вокруг видно фото целиком и есть место
		// для ручек на его углах.
		var padX = Math.max(40, r.width * 0.19);
		var padY = Math.max(40, r.height * 0.17);
		var aw = r.width - padX * 2, ah = r.height - padY * 2;
		var fw = aw, fh = aw / c.aspect;
		if (fh > ah) { fh = ah; fw = ah * c.aspect; }
		c.fw = fw;
		c.fh = fh;
		c.fx = (r.width - fw) / 2;
		c.fy = (r.height - fh) / 2;
		c.frameEl.style.left = c.fx + 'px';
		c.frameEl.style.top = c.fy + 'px';
		c.frameEl.style.width = fw + 'px';
		c.frameEl.style.height = fh + 'px';
		if (c.iw && c.ih) {
			c.zmin = fitMin(c.iw, c.ih, fw, fh);
			c.range.min = String(Math.floor(c.zmin * 100));
		}
		clampCrop(c);
		drawCrop(c);
	}

	/** Экранных пикселей на пиксель фото при текущем масштабе. */
	function cropScale(c) { return Math.max(c.fw / c.iw, c.fh / c.ih) * c.z; }

	/* Та же логика, что в cropRect: фото больше рамки — не даём открыть
	   край рамки; меньше (уменьшили «вписать») — не даём фото выйти за неё. */
	function clampCrop(c) {
		if (!c.iw || !c.fw) return;
		c.z = clampNum(c.z, c.zmin || 1, FIT_MAX);
		var S = cropScale(c);
		var hw = c.fw / S / c.iw / 2;
		var hh = c.fh / S / c.ih / 2;
		c.cx = clampNum(c.cx, Math.min(hw, 1 - hw), Math.max(hw, 1 - hw));
		c.cy = clampNum(c.cy, Math.min(hh, 1 - hh), Math.max(hh, 1 - hh));
	}

	/** Прямоугольник фото на сцене (для ручек и якоря). */
	function cropImgRect(c) {
		var S = cropScale(c);
		var x0 = c.fx + c.fw / 2 - c.cx * c.iw * S;
		var y0 = c.fy + c.fh / 2 - c.cy * c.ih * S;
		return { x0: x0, y0: y0, x1: x0 + c.iw * S, y1: y0 + c.ih * S, S: S };
	}

	function drawCrop(c) {
		if (c.raf) return;
		c.raf = window.requestAnimationFrame(function () {
			c.raf = 0;
			if (!c.ready || !c.fw) return;
			var S = cropScale(c);
			var k = S * c.iw / c.cv.width; // экран / пиксель холста
			var x = c.fx + c.fw / 2 - c.cx * c.iw * S;
			var y = c.fy + c.fh / 2 - c.cy * c.ih * S;
			c.cv.style.transform = 'translate3d(' + x + 'px,' + y + 'px,0) scale(' + k + ')';
			c.range.value = Math.round(c.z * 100);
			renderCropQuality(c, S);
			// Ручки на углах фото; угол за пределами сцены — ручку прячем.
			var ir = cropImgRect(c);
			var sw = c.stage.clientWidth, sh = c.stage.clientHeight;
			c.handles.forEach(function (hd) {
				var hx = 'l' === hd.dataset.h[1] ? ir.x0 : ir.x1;
				var hy = 't' === hd.dataset.h[0] ? ir.y0 : ir.y1;
				var off = hx < 6 || hy < 6 || hx > sw - 6 || hy > sh - 6;
				hd.hidden = off;
				if (!off) hd.style.transform = 'translate3d(' + hx + 'px,' + hy + 'px,0)';
			});
		});
	}

	/**
	 * Качество печати слота: сколько пикселей фото приходится на дюйм
	 * при ширине слота в см на футболке. Предупреждение, не запрет.
	 */
	function renderCropQuality(c, S) {
		var t = tpl();
		var cm = (parseFloat(t && t.printWidthCm) || 30) * c.frac;
		var ppi = cm > 0 ? (c.fw / S) / (cm / 2.54) : 999;
		var label, cls;
		if (ppi >= 200) { label = 'отличное'; cls = 'is-good'; }
		else if (ppi >= 130) { label = 'хорошее'; cls = 'is-good'; }
		else if (ppi >= 90) { label = 'среднее'; cls = 'is-mid'; }
		else { label = 'низкое — фото может выйти размытым'; cls = 'is-low'; }
		c.q.className = 'tplc__q ' + cls;
		c.q.textContent = 'Качество печати: ' + label;
	}

	/** Масштаб с якорем: точка фото под (ax, ay) экрана остаётся на месте. */
	function zoomCropAt(c, z, ax, ay) {
		if (!c.ready) return;
		var S = cropScale(c);
		var ox = c.fx + c.fw / 2, oy = c.fy + c.fh / 2;
		var ux = c.cx + (ax - ox) / S / c.iw;
		var uy = c.cy + (ay - oy) / S / c.ih;
		c.z = clampNum(z, c.zmin || 1, FIT_MAX);
		var S2 = cropScale(c);
		c.cx = ux - (ax - ox) / S2 / c.iw;
		c.cy = uy - (ay - oy) / S2 / c.ih;
		clampCrop(c);
		drawCrop(c);
	}

	function panCrop(c, dx, dy) {
		if (!c.ready) return;
		var S = cropScale(c);
		c.cx -= dx / S / c.iw;
		c.cy -= dy / S / c.ih;
		clampCrop(c);
		drawCrop(c);
	}

	function hideCropHint(c) {
		if (c.hint.hidden) return;
		c.hint.hidden = true;
		try { localStorage.setItem('tplc_hint_seen', '1'); } catch (e) { /* noop */ }
	}

	function stagePos(c, e) {
		var r = c.stage.getBoundingClientRect();
		return { x: e.clientX - r.left, y: e.clientY - r.top };
	}

	function pinchOf(c) {
		var ids = Object.keys(c.pts);
		if (ids.length < 2) return null;
		var a = c.pts[ids[0]], b = c.pts[ids[1]];
		return {
			x: (a.x + b.x) / 2,
			y: (a.y + b.y) / 2,
			d: Math.max(1, Math.hypot(a.x - b.x, a.y - b.y))
		};
	}

	function bindCrop(c) {
		var st = c.stage;

		/* Ручки на углах: масштаб вокруг противоположного угла, без поворота.
		   Новый масштаб — проекция пальца на исходную диагональ «якорь → угол». */
		c.handles.forEach(function (hd) {
			hd.addEventListener('pointerdown', function (e) {
				if (!c.ready || (e.pointerType === 'mouse' && e.button !== 0)) return;
				e.preventDefault();
				e.stopPropagation(); // не пан сцены
				hideCropHint(c);
				try { hd.setPointerCapture(e.pointerId); } catch (err) { /* noop */ }
				var ir = cropImgRect(c);
				var left = 'l' === hd.dataset.h[1], top = 't' === hd.dataset.h[0];
				var drag = {
					// якорь — противоположный угол: на экране и в долях фото
					ax: left ? ir.x1 : ir.x0, ay: top ? ir.y1 : ir.y0,
					ux: left ? 1 : 0, uy: top ? 1 : 0,
					dx: (left ? ir.x0 : ir.x1) - (left ? ir.x1 : ir.x0),
					dy: (top ? ir.y0 : ir.y1) - (top ? ir.y1 : ir.y0),
					z0: c.z
				};
				var dd = drag.dx * drag.dx + drag.dy * drag.dy || 1;
				st.classList.add('is-grab');

				function move(ev) {
					var pos = stagePos(c, ev);
					var k = ((pos.x - drag.ax) * drag.dx + (pos.y - drag.ay) * drag.dy) / dd;
					c.z = clampNum(drag.z0 * Math.max(0.05, k), c.zmin || 1, FIT_MAX);
					var S = cropScale(c);
					var ox = c.fx + c.fw / 2, oy = c.fy + c.fh / 2;
					c.cx = drag.ux - (drag.ax - ox) / (S * c.iw);
					c.cy = drag.uy - (drag.ay - oy) / (S * c.ih);
					clampCrop(c);
					drawCrop(c);
				}
				function up() {
					hd.removeEventListener('pointermove', move);
					hd.removeEventListener('pointerup', up);
					hd.removeEventListener('pointercancel', up);
					st.classList.remove('is-grab');
				}
				hd.addEventListener('pointermove', move);
				hd.addEventListener('pointerup', up);
				hd.addEventListener('pointercancel', up);
			});
		});

		st.addEventListener('pointerdown', function (e) {
			if (e.pointerType === 'mouse' && e.button !== 0) return;
			e.preventDefault();
			hideCropHint(c);
			try { st.setPointerCapture(e.pointerId); } catch (err) { /* noop */ }
			var pos = stagePos(c, e);
			c.pts[e.pointerId] = { x: pos.x, y: pos.y, sx: pos.x, sy: pos.y };
			c.last = pinchOf(c);
			st.classList.add('is-grab');
		});

		st.addEventListener('pointermove', function (e) {
			var pt = c.pts[e.pointerId];
			if (!pt) return;
			var pos = stagePos(c, e);
			var dx = pos.x - pt.x, dy = pos.y - pt.y;
			pt.x = pos.x;
			pt.y = pos.y;
			var pin = pinchOf(c);
			if (pin && c.last) {
				// Щипок: масштаб по расстоянию, сдвиг по середине.
				zoomCropAt(c, c.z * pin.d / c.last.d, c.last.x, c.last.y);
				panCrop(c, pin.x - c.last.x, pin.y - c.last.y);
				c.last = pin;
			} else if (!pin) {
				panCrop(c, dx, dy);
			}
		});

		function up(e) {
			var pt = c.pts[e.pointerId];
			if (!pt) return;
			delete c.pts[e.pointerId];
			c.last = pinchOf(c);
			if (!Object.keys(c.pts).length) st.classList.remove('is-grab');

			// Двойной тап / клик: без движения, два раза подряд рядом.
			if (e.type === 'pointerup' && Math.abs(pt.x - pt.sx) + Math.abs(pt.y - pt.sy) < 10) {
				var now = Date.now();
				if (c.tap && now - c.tap.t < 320 && Math.abs(c.tap.x - pt.x) + Math.abs(c.tap.y - pt.y) < 30) {
					zoomCropAt(c, c.z < 1.5 ? 2 : 1, pt.x, pt.y);
					c.tap = null;
				} else {
					c.tap = { t: now, x: pt.x, y: pt.y };
				}
			}
		}
		st.addEventListener('pointerup', up);
		st.addEventListener('pointercancel', up);

		st.addEventListener('wheel', function (e) {
			e.preventDefault();
			hideCropHint(c);
			var pos = stagePos(c, e);
			var d = e.deltaY * (e.deltaMode === 1 ? 33 : (e.deltaMode === 2 ? 400 : 1));
			// ctrl+wheel — щипок тачпада на Mac: шаги мелкие, нужен больший коэффициент.
			var k = Math.exp(-d * (e.ctrlKey ? 0.01 : 0.0015));
			zoomCropAt(c, c.z * k, pos.x, pos.y);
		}, { passive: false });

		// Safari: без этого щипок масштабирует ещё и страницу под оверлеем.
		['gesturestart', 'gesturechange'].forEach(function (ev) {
			c.root.addEventListener(ev, function (e) { e.preventDefault(); });
		});

		c.range.addEventListener('input', function () {
			zoomCropAt(c, parseInt(c.range.value, 10) / 100, c.fx + c.fw / 2, c.fy + c.fh / 2);
		});

		c.root.addEventListener('click', function (e) {
			var b = e.target.closest('[data-c]');
			if (!b) return;
			var cmd = b.dataset.c;
			var mx = c.fx + c.fw / 2, my = c.fy + c.fh / 2;
			if ('in' === cmd) zoomCropAt(c, c.z * 1.25, mx, my);
			else if ('out' === cmd) zoomCropAt(c, c.z / 1.25, mx, my);
			else if ('reset' === cmd) { c.z = 1; c.cx = 0.5; c.cy = 0.5; clampCrop(c); drawCrop(c); }
			else if ('done' === cmd) closeCrop(true);
			else if ('cancel' === cmd) closeCrop(false);
			else if ('prev' === cmd || 'next' === cmd) stepCrop('next' === cmd ? 1 : -1);
		});

		c.onKey = function (e) {
			if (crop !== c) return;
			var step = 0.03 / c.z;
			var handled = true;
			if ('Escape' === e.key) closeCrop(false);
			else if ('Enter' === e.key && e.target.tagName !== 'BUTTON') closeCrop(true);
			else if ('ArrowLeft' === e.key) panCrop(c, c.fw * step, 0);
			else if ('ArrowRight' === e.key) panCrop(c, -c.fw * step, 0);
			else if ('ArrowUp' === e.key) panCrop(c, 0, c.fh * step);
			else if ('ArrowDown' === e.key) panCrop(c, 0, -c.fh * step);
			else if ('+' === e.key || '=' === e.key) zoomCropAt(c, c.z * 1.25, c.fx + c.fw / 2, c.fy + c.fh / 2);
			else if ('-' === e.key || '_' === e.key) zoomCropAt(c, c.z / 1.25, c.fx + c.fw / 2, c.fy + c.fh / 2);
			else handled = false;
			if (handled && e.target !== c.range) {
				e.preventDefault();
				// Esc не должен закрыть заодно и развёрнутое превью под нами.
				e.stopPropagation();
			}
		};
		window.addEventListener('keydown', c.onKey, true);

		c.onResize = function () { if (crop === c) layoutCrop(c); };
		window.addEventListener('resize', c.onResize);

		c.onPop = function () {
			if (crop !== c) return;
			c.pushed = false; // запись истории уже снята самим «Назад»
			closeCrop(false);
		};
		window.addEventListener('popstate', c.onPop);
	}

	function commitCrop(c) {
		if (!c.ready) return;
		var fit = { zoom: Math.round(c.z * 1000) / 1000, cx: r4(c.cx), cy: r4(c.cy) };
		c.p.fit = isDefaultFit(fit) ? null : fit;
		// Размеры могли быть ещё не известны (checkQuality не успел).
		if (!c.p.w) { c.p.w = c.iw; c.p.h = c.ih; }
	}

	function teardownCrop(c) {
		window.removeEventListener('keydown', c.onKey, true);
		window.removeEventListener('resize', c.onResize);
		window.removeEventListener('popstate', c.onPop);
		if (c.raf) window.cancelAnimationFrame(c.raf);
		if (c.root.parentNode) c.root.parentNode.removeChild(c.root);
		c.cv.width = 1; c.cv.height = 1; // освободить память холста
		document.documentElement.classList.remove('tplc-lock');
	}

	function closeCrop(save) {
		var c = crop;
		if (!c) return;
		if (save) commitCrop(c);
		crop = null;
		teardownCrop(c);
		if (c.pushed) {
			try { if (history.state && history.state.tplc) history.back(); } catch (e) { /* noop */ }
		}
		if (save) {
			renderSlots();
			renderPreview();
		}
	}

	/** Соседнее фото: текущий кадр сохраняется, редактор открывается заново. */
	function stepCrop(dir) {
		var c = crop;
		if (!c) return;
		var list = cropSlots();
		var n = list.indexOf(c.slot);
		if (list.length < 2) return;
		var next = list[(n + dir + list.length) % list.length];
		commitCrop(c);
		crop = null;
		teardownCrop(c);
		// Запись истории оставляем — её переиспользует следующий экран.
		renderSlots();
		renderPreview();
		openCrop(next, c.pushed);
	}

	/* Точки входа: клик по фото в макете и кнопка ✥ на слоте. */
	function initCrop() {
		if (!el.art) return;
		if (el.cropHint) {
			el.cropHint.addEventListener('click', function () {
				var list = cropSlots();
				if (list.length) openCrop(list[0]);
			});
		}
		el.art.addEventListener('click', function (e) {
			var box = e.target.closest && e.target.closest('[data-crop]');
			if (!box || !cropSupported()) return;
			e.preventDefault();
			e.stopPropagation();
			openCrop(parseInt(box.dataset.crop, 10));
		});
	}

	/* Рукописные подписи: семейство и цвет чернил клиент выбирает сам.
	   Значения кладутся переменными на корень макета — их же читает CSS
	   и повторяет растр для заказа. */
	var INK = { black: '#111111', blue: '#1b3fbd', red: '#b3261e' };

	function scriptFamily() {
		var list = CFG.scriptFonts || [];
		if (!list.length) return "Georgia, 'Times New Roman', serif";
		var pick = String(state.styles.script_font || 'f0').replace('f', '');
		var fam = list[parseInt(pick, 10)] || list[0];
		return '"' + fam + '", Georgia, serif';
	}

	/* «Чёрные» чернила подписи под фото на чёрной футболке печатаются
	   белым: подпись лежит прямо на ткани, и чёрное на чёрном не видно.
	   У полароидов подпись на белой карточке — там чёрный остаётся. */
	function inkFlipped() {
		var t = tpl();
		return !!t && t.engine === 'single' && state.garment.color === 'black' &&
			(state.styles.script_color || 'black') === 'black';
	}

	function inkColor() {
		if (inkFlipped()) return '#ffffff';
		return INK[state.styles.script_color] || INK.black;
	}

	function applyScriptStyle() {
		if (!el.art) return;
		el.art.style.setProperty('--tplb-script', scriptFamily());
		el.art.style.setProperty('--tplb-ink', inkColor());
	}

	function renderPreview() {
		var t = tpl();
		if (!t) return;

		el.preview.dataset.color = state.garment.color || 'black';
		el.preview.dataset.tone = state.styles.photo_tone || 'color';
		if (el.empty) el.empty.hidden = true;
		applyScriptStyle();

		var html = '';
		if (t.engine === 'letters') html = previewLetters();
		else if (t.engine === 'poster') html = previewPoster();
		else if (t.engine === 'single') html = previewSingle();
		else if (t.engine === 'polaroid') html = previewPolaroid();
		else if (t.engine === 'onlyyou') html = previewOnlyYou();
		else if (t.engine === 'papa') html = previewPapa();

		el.art.innerHTML = html;
		fitPreviewText();
	}

	function previewLetters() {
		var word = Array.from((state.texts.word || '').trim());
		if (!word.length) return '<div class="tplb-preview__empty">Введите слово</div>';

		// Буквы рисуются масками из canvas (см. applySolidLetters) в обоих
		// режимах: CSS-текст не умеет ни залить просветы глифа, ни
		// вытянуть букву по высоте, не растянув вместе с ней фото.
		// Класс is-solid — «режим масок», а не «сплошные буквы»: заливку
		// просветов решает letter_fill внутри applySolidLetters().
		return '<div class="tplp-letters is-solid">' + word.map(function (ch, i) {
			var p = photoFor(i);
			var ready = p && !p.pending && p.url;
			var style = ready ? 'background-image:url(' + p.url + ')' : '';
			return '<span class="tplp-letter' + (ready ? ' has-img' : (p ? ' is-loading' : '')) + '" style="' + style + '">' + esc(ch) + '</span>';
		}).join('') + '</div>';
	}

	/* Заголовок рисуем SVG с textLength: ширина задаётся декларативно и
	   гарантированно равна ширине области печати. Замер в DOM для этого не
	   годился — он зависел от момента загрузки Oswald и letter-spacing,
	   и строка стабильно недобирала до края.

	   viewBox 1000×118 при кегле 160 — заглавные Oswald (капитель .81em)
	   слегка выходят за строку вверх, отчего повторы смыкаются в стопку.
	   Высота блока при этом от текста не зависит.

	   У Horizon капитель ниже (.70em), поэтому его кегль умножается на
	   TPL_CAP_K: без этого латинские повторы расходились с зазором. */
	/* Прозрачность контурных повторов — общая для превью (CSS) и растра.
	   .72 → .9 вместе с утолщением: тонкий полупрозрачный контур
	   на печати терялся. Менять вместе с .tplp-tline.is-outline* в CSS. */
	var TITLE_OUTLINE_ALPHA = 0.9;

	var TITLE_VB_W = 1000;
	var TITLE_VB_H = 118;

	function titleLineSvg(text, solid) {
		var t = esc(text);
		var cyr = hasCyr(text);

		// font-family задаётся классом, а не атрибутом: presentation
		// attribute проигрывает любому правилу CSS, а .tplp-tline своё
		// семейство объявляет.
		// капитель кириллицы выровнена под Horizon (size-adjust) —
		// кегль общий.
		var fs = Math.round(160 * TPL_CAP_K);
		var common = 'font-weight="700" ' +
			'font-size="' + fs + '" x="0" y="116" textLength="' + TITLE_VB_W + '" ' +
			'lengthAdjust="spacingAndGlyphs"';

		// Контур латиницей рисуется настоящим контурным начертанием;
		// для кириллицы его нет, там остаётся обводка по Oswald.
		var cls = 'tplp-tline' + (cyr ? ' is-cyr' : '');
		var paint = solid
			? 'class="' + cls + ' is-solid"'
			: 'class="' + cls + (cyr ? ' is-outline' : ' is-outlineface') + '"';

		// width/height в атрибутах — страховка интринсик-пропорции для тех
		// движков, где aspect-ratio из viewBox не подхватывается во flex.
		return '<svg class="tplp-titlesvg" viewBox="0 0 ' + TITLE_VB_W + ' ' + TITLE_VB_H + '" ' +
			'width="' + TITLE_VB_W + '" height="' + TITLE_VB_H + '" ' +
			'preserveAspectRatio="xMidYMid meet" ' +
			'xmlns="http://www.w3.org/2000/svg" aria-hidden="true">' +
			'<text ' + paint + ' ' + common + '>' + t + '</text></svg>';
	}

	function previewPoster() {
		var t = tpl();
		var lay = activeLayout();
		if (!lay) return '';

		var repeat = parseInt(state.styles.title_repeat || '3', 10);
		var head = (state.texts.headline || '').trim();
		var titles = '';
		for (var i = 0; i < repeat; i++) {
			titles += titleLineSvg(head, i === 0);
		}

		// Раскладка может задавать пропорции области коллажа (ровные квадраты
		// требуют другого соотношения, чем вертикальный коллаж).
		var aspect = lay.aspect ? ' style="aspect-ratio:1 / ' + lay.aspect + '"' : '';

		var grid = lay.slots.map(function (s, i) {
			var p = photoFor(i);
			var pos = 'left:' + (s.x * 100) + '%;top:' + (s.y * 100) + '%;width:' + (s.w * 100) + '%;height:' + (s.h * 100) + '%';
			return '<div class="tplp-cell' + cellCls(p) + '" style="' + pos + '">' +
				photoHtml(p, '<span>' + (i + 1) + '</span>', i) +
				'</div>';
		}).join('');

		// Блочный низ — тот же, что у ONLY YOU. Обёртка .tplp-oy нужна ему
		// для отступов и колонок: классы низа завязаны на неё, дублировать
		// их под постер значило бы держать две копии одних правил.
		// Нет переключателя `footer_kind` — значит у основы остался только
		// блочный низ (GIRLFRIEND / BOYFRIEND). Простой рисуем лишь там,
		// где выбор действительно есть и выбран он.
		if ('simple' !== (state.styles.footer_kind || 'block')) {
			return '<div class="tplp-poster">' +
				'<div class="tplp-titles">' + titles + '</div>' +
				'<div class="tplp-grid"' + aspect + '>' + grid + '</div>' +
				'<div class="tplp-oy is-footonly">' + footerBlockHtml() + '</div>' +
				'</div>';
		}

		// Декоративные штрихи между подписями убраны: приём читался как
		// артефакт вёрстки, а не как элемент макета.
		// простой низ — только по центру и без имени: дата,
		// под ней подпись. Имя осталось у блочного низа.
		var caption = (state.texts.caption || '').trim();
		var footer = caption
			? '<div class="tplp-footer is-center"><span class="tplp-footer__right">' + esc(caption) + '</span></div>'
			: '';

		var date = (state.texts.date || '').trim();
		var dateRow = date ? '<div class="tplp-date">' + esc(date) + '</div>' : '';

		return '<div class="tplp-poster">' +
			'<div class="tplp-titles">' + titles + '</div>' +
			'<div class="tplp-grid"' + aspect + '>' + grid + '</div>' +
			dateRow + footer +
			'</div>';
	}

	/** «Одно фото + имя» в варианте «Имя и штрихкод» (реф. с повтором имени). */
	function singleBlock() {
		var t = tpl();
		return !!t && t.engine === 'single' && 'block' === state.styles.footer_kind;
	}

	/* Пропорция кадра в блочном варианте — по референсу (≈ 430×480). */
	var SINGLE_BLOCK_ASPECT = 1.12;

	/* Потолок кегля подписей, подогнанных под ширину фото, — доля ширины.
	   Без него короткое «ОЛЕГ» или «1994» раздувалось бы на полмакета. */
	var SINGLE_NAME_CAP = 0.2;
	var SINGLE_BOT_CAP  = 0.24;

	function previewSingle() {
		var p = photoFor(0);

		// Блочный вариант собирается из готовых деталей ONLY YOU и
		// постеров: повтор заголовка, кадр, строка имени и низ со
		// штрихкодом. Обёртка .tplp-oy даёт им общие отступы и колонки.
		if (singleBlock()) {
			var repeat = parseInt(state.styles.title_repeat || '3', 10);
			var head = titleText();
			var titles = '';
			for (var i = 0; i < repeat; i++) titles += titleLineSvg(head, i === 0);

			return '<div class="tplp-oy is-singleblock">' +
				'<div class="tplp-titles">' + titles + '</div>' +
				'<div class="tplp-oy__photo' + cellCls(p) + '" style="aspect-ratio:1 / ' + SINGLE_BLOCK_ASPECT + '">' +
				photoHtml(p, '<span class="tplp-ph">фото</span>', 0) +
				'</div>' + footerBlockHtml() + '</div>';
		}

		var frame = state.styles.frame === 'polaroid' ? ' is-polaroid' : '';
		var size = ' is-size-' + (state.styles.photo_size || 'large');
		var tilt = state.styles.bottom_tilt === 'tilt' ? ' is-tilt' : '';
		var bottom = (state.texts.bottom || '').trim();

		// Имя и подпись подгоняются под ширину фото в fitSingle().
		return '<div class="tplp-single' + size + '">' +
			'<div class="tplp-single__name">' + esc(state.texts.name || '') + '</div>' +
			'<div class="tplp-single__photo' + frame + '">' +
			photoHtml(p, '<span class="tplp-ph">фото</span>', 0) +
			'</div>' +
			(bottom ? '<div class="tplp-single__bottom' + tilt + '">' + esc(bottom) + '</div>' : '') +
			'</div>';
	}

	/**
	 * Кегль, при котором строка встаёт ровно в ширину `w` (с потолком).
	 * Замер одним прогоном на 100px — как у fitLetters().
	 */
	function sizeToWidth(text, fontAt100, w, cap) {
		if (!measureCtx) measureCtx = document.createElement('canvas').getContext('2d');
		measureCtx.font = fontAt100;
		var own = measureCtx.measureText(text).width;
		if (!own || !w) return cap;
		return Math.min(cap, (w / own) * 100 * 0.98);
	}

	function singleNameFont100() { return '700 100px ' + TPL_FONT; }
	function singleBotFont100() { return 'italic 100px ' + scriptFamily(); }

	/** Подгон имени и рукописной подписи под ширину кадра в превью. */
	function fitSingle() {
		var root = el.art.querySelector('.tplp-single');
		if (!root) return;
		var photo = root.querySelector('.tplp-single__photo');
		var w = photo ? photo.getBoundingClientRect().width : 0;
		// Превью может быть сжато transform-ом: кегль задаётся в CSS-пикселях
		// раскладки, а rect отдаёт экранные. offsetWidth — раскладка.
		if (photo && photo.offsetWidth) w = photo.offsetWidth;
		if (!w) return;

		var nameEl = root.querySelector('.tplp-single__name');
		var name = (state.texts.name || '').trim().toUpperCase();
		if (nameEl && name) {
			nameEl.style.letterSpacing = '0';
			nameEl.style.fontSize = sizeToWidth(name, singleNameFont100(), w, w * SINGLE_NAME_CAP).toFixed(1) + 'px';
		}

		var botEl = root.querySelector('.tplp-single__bottom');
		var bottom = (state.texts.bottom || '').trim();
		if (botEl && bottom) {
			botEl.style.fontSize = sizeToWidth(bottom, singleBotFont100(), w, w * SINGLE_BOT_CAP).toFixed(1) + 'px';
		}
	}

	/* Иконки плеера — один инлайновый SVG: пять элементов управления
	   в одной системе координат, чтобы не плодить узлы и не зависеть
	   от шрифтовых глифов.
	 *
	 * Два обязательных требования к разметке, оба выстраданы:
	 *
	 * 1. width/height атрибуты. Без них у SVG нет собственных размеров —
	 *    только viewBox. Chrome из viewBox выводит пропорцию и честно
	 *    считает `height: auto`, Safari (в т.ч. iOS) — нет: высота
	 *    схлопывалась в пару пикселей, preserveAspectRatio вписывал
	 *    рисунок в эту высоту, и весь блок управления рисовался кучкой
	 *    в 16px по центру колонки. Отсюда «микро размер».
	 *
	 * 2. Пропорция viewBox ≈ 4.3, а не 6.9 как было. Ширина у SVG всегда
	 *    100% колонки, значит видимый размер иконок задаёт ровно
	 *    отношение сторон: чем оно меньше, тем крупнее рисунок.
	 *    Круг play = 62 из 300 по ширине (было 26 из 220) — как на
	 *    референсе, где диаметр круга ≈ 22% от всей полосы. */
	function playerIconsSvg() {
		return '<svg class="tplp-oy__icons" viewBox="0 0 300 70" width="300" height="70" ' +
			'xmlns="http://www.w3.org/2000/svg" aria-hidden="true" ' +
			'fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round">' +
			// перемешать
			'<path d="M16 26h6l16 18h6M16 44h6l16-18h6"/>' +
			'<path d="M38 20l6 6-6 6M38 38l6 6-6 6" fill="currentColor"/>' +
			// предыдущий
			'<path d="M80 21v28"/><path d="M102 21L102 49L84 35Z" fill="currentColor"/>' +
			// play в круге
			'<circle cx="150" cy="35" r="29"/>' +
			'<path d="M141 20L169 35L141 50Z" fill="currentColor" stroke="none"/>' +
			// следующий
			'<path d="M220 21v28"/><path d="M198 21L216 35L198 49Z" fill="currentColor"/>' +
			// повтор
			'<path d="M262 22h16a8 8 0 0 1 8 8v10a8 8 0 0 1-8 8h-16a8 8 0 0 1-8-8v-10a8 8 0 0 1 8-8" ' +
			'stroke-dasharray="86 16"/><path d="M262 15l-7 7 7 7"/>' +
			'</svg>';
	}

	/* Штрихкод и QR в превью декоративные: настоящие коды генерирует дизайнер
	   из данных заказа. Но рисунок должен быть стабильным — при каждом
	   перерендере одна и та же строка обязана давать одинаковый узор, иначе
	   макет «мерцает» на каждое нажатие клавиши. Отсюда хеш строки + LCG
	   вместо Math.random(). */
	function seedFrom(str) {
		var h = 2166136261;
		str = String(str || location.hostname);
		for (var i = 0; i < str.length; i++) {
			h ^= str.charCodeAt(i);
			h = (h * 16777619) >>> 0;
		}
		return h || 1;
	}

	function rngFrom(seed) {
		var s = seed >>> 0;
		return function () {
			s = (s * 1664525 + 1013904223) >>> 0;
			return s / 4294967296;
		};
	}

	function barcodeSvg(seed) {
		var rnd = rngFrom(seedFrom(seed));
		var bars = '';
		var x = 0;
		while (x < 197) {
			var w = 1 + Math.floor(rnd() * 4);
			bars += '<rect x="' + x + '" y="0" width="' + w + '" height="60"/>';
			x += w + 1 + Math.floor(rnd() * 3);
		}
		return '<svg class="tplp-oy__barcode" viewBox="0 0 200 60" width="200" height="60" preserveAspectRatio="none" ' +
			'xmlns="http://www.w3.org/2000/svg" aria-hidden="true" fill="currentColor">' + bars + '</svg>';
	}

	function qrSvg(seed) {
		var n = 21;
		var rnd = rngFrom(seedFrom(seed));
		var cells = '';

		function isEye(r, c) {
			return (r < 7 && c < 7) || (r < 7 && c >= n - 7) || (r >= n - 7 && c < 7);
		}

		for (var r = 0; r < n; r++) {
			for (var c = 0; c < n; c++) {
				if (isEye(r, c)) continue;
				if (rnd() > 0.52) cells += '<rect x="' + c + '" y="' + r + '" width="1" height="1"/>';
			}
		}

		// Три «глаза» — обязательная часть силуэта QR, без них он не читается
		// как код даже на схеме.
		function eye(x, y) {
			return '<rect x="' + x + '" y="' + y + '" width="7" height="7"/>' +
				'<rect x="' + (x + 1) + '" y="' + (y + 1) + '" width="5" height="5" fill="var(--tplb-qr-bg,#000)"/>' +
				'<rect x="' + (x + 2) + '" y="' + (y + 2) + '" width="3" height="3"/>';
		}

		return '<svg class="tplp-oy__qr" viewBox="0 0 ' + n + ' ' + n + '" width="' + n + '" height="' + n + '" ' +
			'xmlns="http://www.w3.org/2000/svg" aria-hidden="true" fill="currentColor" shape-rendering="crispEdges">' +
			cells + eye(0, 0) + eye(n - 7, 0) + eye(0, n - 7) + '</svg>';
	}

	/** Левый блок низа: плеер / штрихкод / произвольный текст / QR. */
	function onlyYouLeft() {
		var kind = state.styles.footer_left || 'player';

		if ('player' === kind) {
			return '<div class="tplp-oy__player">' +
				'<div class="tplp-oy__track"' + kStyle(state.texts.track) + '>' + esc((state.texts.track || '').trim()) + '</div>' +
				'<div class="tplp-oy__bar"><i></i></div>' +
				playerIconsSvg() +
				'</div>';
		}

		if ('barcode' === kind) {
			var date = (state.texts.date || '').trim();
			return '<div class="tplp-oy__code">' +
				barcodeSvg(date || 'since') +
				'<div class="tplp-oy__codecap"' + kStyle(date) + '>' + esc(date) + '</div>' +
				'</div>';
		}

		if ('qr' === kind) {
			return '<div class="tplp-oy__code is-qr">' + qrSvg(state.texts.qr || location.hostname) + '</div>';
		}

		if ('text' === kind) {
			return '<div class="tplp-oy__note"' + kStyle(state.texts.note) + '>' +
				esc((state.texts.note || '').trim()) + '</div>';
		}

		return '';
	}

	/* Движок onlyyou: заголовок → кадр → строка «декор + имя» → низ.
	   Низ всегда двухколоночный: слева переключаемый блок, справа — фраза
	   или дата. Раньше выбор блока подменял и правую колонку, и подпись
	   пропадала вместе с плеером — это сбивало с толку. */
	function previewOnlyYou() {
		var t = tpl();
		var lay = activeLayout();
		if (!lay) return '';

		var repeat = parseInt(state.styles.title_repeat || '3', 10);
		var head = (state.texts.headline || '').trim();
		var titles = '';
		for (var i = 0; i < repeat; i++) {
			titles += titleLineSvg(head, i === 0);
		}

		var p = photoFor(0);
		var aspect = lay.aspect ? ' style="aspect-ratio:1 / ' + lay.aspect + '"' : '';
		var photo = '<div class="tplp-oy__photo' + cellCls(p) + '"' + aspect + '>' +
			photoHtml(p, '<span class="tplp-ph">фото</span>', 0) +
			'</div>';

		return '<div class="tplp-oy">' +
			'<div class="tplp-titles">' + titles + '</div>' +
			photo + footerBlockHtml() +
			'</div>';
	}

	/**
	 * Строка «декор + имя» плюс двухколоночный низ.
	 *
	 * Вынесено из previewOnlyYou(), потому что тот же блок теперь доступен
	 * постерам (GIRLFRIEND / BOYFRIEND / BEST DAD). Разметка и классы общие
	 * — значит и CSS один, и правки не расходятся по движкам.
	 */
	function footerBlockHtml() {
		var name = footNameText();
		var decor = state.styles.name_decor || 'arrows';
		var decorChar = 'slashes' === decor ? '/' : (('none' === decor) ? '' : '\u276F');

		// Имя слева — просто разворот строки: декор всегда с той стороны,
		// где имени нет. Порядок узлов не меняем, чтобы fitNameRow искал
		// их по одним и тем же селекторам.
		var rowCls = 'tplp-oy__namerow' +
			('left' === state.styles.name_side ? ' is-nameleft' : '') +
			('half' === state.styles.decor_fill ? ' is-decorhalf' : '');

		// Количество символов декора считается после вёрстки (fitNameRow):
		// оно зависит от того, сколько места осталось от имени.
		var nameRow = (name || decorChar)
			? '<div class="' + rowCls + '">' +
				(decorChar
					? '<span class="tplp-oy__arrows" data-char="' + esc(decorChar) + '" aria-hidden="true"></span>'
					: '') +
				'<span class="tplp-oy__name">' + esc(name) + '</span></div>'
			: '';

		var left = onlyYouLeft();
		var phrase = (state.texts.phrase || '').trim();
		var foot = (left || phrase)
			? '<div class="tplp-oy__foot">' +
				'<div class="tplp-oy__side">' + left + '</div>' +
				'<div class="tplp-oy__phrase"' + kStyle(phrase) + '>' + esc(phrase) + '</div>' +
				'</div>'
			: '';

		return nameRow + foot;
	}

	function previewPolaroid() {
		// Число карточек берём из раскладки, а не константой: у основы
		// «Полароид» их одна или две.
		var lay = activeLayout();
		var n = (lay && lay.slots) ? lay.slots.length : 2;
		var idx = [];
		for (var i = 0; i < n; i++) idx.push(i);

		return '<div class="tplp-polaroid' + (1 === n ? ' is-single' : '') + '">' + idx.map(function (i) {
			var p = photoFor(i);
			var cap = state.texts['cap' + (i + 1)] || '';
			return '<div class="tplp-pol">' +
				'<div class="tplp-pol__img">' + photoHtml(p, '<span class="tplp-ph">фото</span>', i) + '</div>' +
				'<div class="tplp-pol__cap">' + esc(cap) + '</div>' +
				'</div>';
		}).join('') + '</div>';
	}



	/* ─────────────────── Движок papa: «PAPA и 4 фото» ───────────────────
	 *
	 * Область печати 25 × 40 см: всё считается в долях ширины при
	 * фиксированной высоте 1.6 ширины. Строка подписи зарезервирована
	 * всегда — пустая подпись не меняет пропорцию макета.
	 *
	 * Два варианта расположения — раскладки основы (layout_id):
	 *
	 *   grid — сетка 2 × 3, как в рабочем шаблоне студии:
	 *     [ фото 1 ]  [  P A   ]
	 *     [ фото 2 ]  [ фото 3 ]
	 *     [  P A   ]  [ фото 4 ]
	 *          EST. 11.01.2025        ← по центру во всю ширину
	 *   Буквы занимают ячейку размером с кадр и центрируются в ней.
	 *
	 *   stairs — колонки лесенкой (реф. «PAPA EST. 2026»): буквы зигзагом
	 *   по шву, подпись под нижним правым кадром. Колонка A сдвинута вниз
	 *   ровно на строку подписи — обе колонки начинаются и кончаются вровень.
	 *
	 * Одна геометрия на превью (SVG viewBox 1000 × 1600 поверх кадров) и
	 * растр (RW): papaLayout() отдаёт координаты в долях ширины.
	 */
	var PAPA = {
		aspect:     1.6,    // 40 / 25
		// grid
		gridGap:    0.035,  // зазор между ячейками
		gridLetGap: 0.06,   // просвет между P и A в ячейке
		gridCapMax: 0.075,  // потолок кегля подписи
		// stairs
		gap:        0.025,
		capRow:     0.07,
		capGap:     0.025,
		lettersK:   0.84,   // высота блока букв от высоты кадра
		letterGapK: 0.12,
		capMax:     0.058,
		capMin:     0.022
	};

	var PAPA_FONT = "'Montserrat', 'Inter', 'Arial', sans-serif";
	var PAPA_LETTER_FONT = PAPA_FONT;
	var PAPA_CAP_FONT = PAPA_FONT;

	function papaCapWeight() {
		return 'bold' === state.styles.caption_weight ? 700 : 400;
	}

	function papaCaption() {
		return (state.texts.caption || '').trim();
	}

	function papaVariant() {
		// Вариант — это раскладка основы (плитки в шаге), а не стиль:
		// так он попадает в сводку заказа строкой «Раскладка».
		if ('stairs' === state.layoutId) return 'stairs';
		return 'grid3' === state.layoutId ? 'grid3' : 'grid';
	}

	/** Замер на 100px тем же canvas, что у остальных подгонов. */
	function papaMeasure(font, text) {
		if (!measureCtx) measureCtx = document.createElement('canvas').getContext('2d');
		measureCtx.font = font;
		measureCtx.textAlign = 'left';
		var m = measureCtx.measureText(text);
		return {
			w: textWidth(measureCtx, text),
			l: (m && isFinite(m.actualBoundingBoxLeft)) ? m.actualBoundingBoxLeft : 0,
			r: (m && isFinite(m.actualBoundingBoxRight)) ? m.actualBoundingBoxRight : textWidth(measureCtx, text),
			asc: (m && isFinite(m.actualBoundingBoxAscent) && m.actualBoundingBoxAscent > 0)
				? m.actualBoundingBoxAscent : 72
		};
	}

	/**
	 * Буквы P и A стопкой в прямоугольнике (x, y, w, h): капитель делит
	 * высоту пополам за вычетом просвета, каждая буква центрируется по
	 * ширине прямоугольника по фактическим чернилам, а не по адвансу —
	 * иначе у «A» и «P» с разными боковыми полями центры не совпадут.
	 */
	/** Пара букв ячейки: P/A у папы, M/A у мамы (papaLetters, прежней версии). */
	function papaPair() {
		var t = tpl();
		var l = (t && t.papaLetters && t.papaLetters.length >= 2) ? t.papaLetters : ['P', 'A'];
		// переключатель «Буквы» — PA PA / ПА ПА (MA MA / МА МА).
		if ('cyr' === state.styles.papa_script && t && t.papaLettersCyr && t.papaLettersCyr.length >= 2) {
			l = t.papaLettersCyr;
		}
		return [String(l[0]), String(l[1])];
	}

	function papaLetterBlock(x, y, w, h, gapK) {
		var lf = '400 100px ' + PAPA_LETTER_FONT;
		var pair = papaPair();
		var mP = papaMeasure(lf, pair[0]);
		var mA = papaMeasure(lf, pair[1]);
		var capH = h * (1 - gapK) / 2;
		var size = capH / (Math.max(mP.asc, mA.asc) / 100);
		var k = size / 100;

		function center(m) {
			var inkL = -m.l * k, inkR = m.r * k;   // от точки привязки
			return x + w / 2 - (inkL + inkR) / 2;
		}

		return {
			size: size,
			list: [
				{ ch: pair[0], x: center(mP), y: y + capH },  // y — базовая линия
				{ ch: pair[1], x: center(mA), y: y + h }
			]
		};
	}

	function papaCapSize(text, maxW, maxSize) {
		if (!text) return maxSize;
		var own = papaMeasure(papaCapWeight() + ' 100px ' + PAPA_CAP_FONT, text).w;
		if (!(own > 0)) return maxSize;
		return Math.max(PAPA.capMin, Math.min(maxSize, maxW / own * 100 * 0.98));
	}

	function papaLayoutGrid() {
		var H = PAPA.aspect;
		var g = PAPA.gridGap;
		var c = (1 - g) / 2;        // ячейка квадратная
		var bx = c + g;
		var rows = [0, c + g, 2 * (c + g)];

		// «Сетка · 3 фото»: левые ячейки 1-го и 2-го рядов —
		// один высокий кадр, зазор между ними входит в его высоту.
		var photos = 'grid3' === papaVariant()
			? [
				{ x: 0,  y: rows[0], w: c, h: 2 * c + g },
				{ x: bx, y: rows[1], w: c, h: c },
				{ x: bx, y: rows[2], w: c, h: c }
			]
			: [
				{ x: 0,  y: rows[0], w: c, h: c },
				{ x: 0,  y: rows[1], w: c, h: c },
				{ x: bx, y: rows[1], w: c, h: c },
				{ x: bx, y: rows[2], w: c, h: c }
			];

		var top = papaLetterBlock(bx, rows[0], c, c, PAPA.gridLetGap);
		var bot = papaLetterBlock(0, rows[2], c, c, PAPA.gridLetGap);

		// Подпись — по центру во всю ширину, базовая линия у нижнего
		// края области печати.
		var cap = papaCaption();
		var cs = papaCapSize(cap, 1, PAPA.gridCapMax);

		return {
			H: H,
			photos: photos,
			letterSize: top.size,
			letters: top.list.concat(bot.list),
			caption: { text: cap, x: 0.5, y: H - cs * 0.02, size: cs, weight: papaCapWeight(), align: 'center' }
		};
	}

	function papaLayoutStairs() {
		var g = PAPA.gap;
		var col = (1 - g) / 2;
		var H = PAPA.aspect;

		// Высота кадра — из баланса колонки B:
		// буквы + g + кадр + g + кадр + capGap + строка подписи = H.
		var ph = (H - 2 * g - PAPA.capGap - PAPA.capRow) / (2 + PAPA.lettersK);
		var L = ph * PAPA.lettersK;
		var a0 = PAPA.capGap + PAPA.capRow; // сдвиг колонки A вниз
		var bx = col + g;

		var photos = [
			{ x: 0,  y: a0,             w: col, h: ph },
			{ x: bx, y: L + g,          w: col, h: ph },
			{ x: 0,  y: a0 + ph + g,    w: col, h: ph },
			{ x: bx, y: L + g + ph + g, w: col, h: ph }
		];

		// Буквы центрируются по ширине колонки — по оси кадров под ними.
		var top = papaLetterBlock(bx, 0, col, L, PAPA.letterGapK);
		var bot = papaLetterBlock(0, H - L, col, L, PAPA.letterGapK);

		// Подпись: базовая линия вровень с нижней «A», по центру колонки B.
		var cap = papaCaption();
		var cs = papaCapSize(cap, col, PAPA.capMax);

		return {
			H: H,
			photos: photos,
			letterSize: top.size,
			letters: top.list.concat(bot.list),
			caption: { text: cap, x: bx + col / 2, y: H, size: cs, weight: papaCapWeight(), align: 'center' }
		};
	}

	function papaLayout() {
		return 'stairs' === papaVariant() ? papaLayoutStairs() : papaLayoutGrid();
	}

	/** Буквы и подпись превью — SVG в той же системе координат, что растр. */
	function papaTypeSvg(geo) {
		var k = 1000;
		var vbH = Math.round(geo.H * k);
		var letters = geo.letters.map(function (l) {
			return '<text class="tplp-papa__letter" x="' + (l.x * k).toFixed(1) + '" y="' + (l.y * k).toFixed(1) +
				'" font-size="' + (geo.letterSize * k).toFixed(1) + '">' + l.ch + '</text>';
		}).join('');

		var c = geo.caption;
		var cap = c.text
			? '<text class="tplp-papa__cap' + (700 === c.weight ? ' is-bold' : '') + '" text-anchor="middle" x="' + (c.x * k).toFixed(1) +
				'" y="' + (c.y * k).toFixed(1) + '" font-size="' + (c.size * k).toFixed(1) + '">' + esc(c.text) + '</text>'
			: '';

		return '<svg class="tplp-papa__type" viewBox="0 0 1000 ' + vbH + '" width="1000" height="' + vbH + '" ' +
			'xmlns="http://www.w3.org/2000/svg" aria-hidden="true">' + letters + cap + '</svg>';
	}

	function previewPapa() {
		loadPapaFonts();
		var geo = papaLayout();
		var cells = geo.photos.map(function (s, i) {
			var p = photoFor(i);
			var pos = 'left:' + (s.x * 100) + '%;top:' + (s.y / geo.H * 100) + '%;width:' +
				(s.w * 100) + '%;height:' + (s.h / geo.H * 100) + '%';
			return '<div class="tplp-cell' + cellCls(p) + '" style="' + pos + '">' +
				photoHtml(p, '<span>' + (i + 1) + '</span>', i) +
				'</div>';
		}).join('');

		return '<div class="tplp-papa" style="aspect-ratio:1 / ' + geo.H + '">' +
			cells + papaTypeSvg(geo) + '</div>';
	}

	/** Перестроить буквы после догрузки шрифтов: кегль считается по метрикам. */
	function fitPapa() {
		var root = el.art.querySelector('.tplp-papa');
		if (!root) return;
		var tmp = document.createElement('div');
		tmp.innerHTML = papaTypeSvg(papaLayout());
		var old = root.querySelector('.tplp-papa__type');
		if (old) root.replaceChild(tmp.firstChild, old);
		else root.appendChild(tmp.firstChild);
	}

	/* Montserrat нужен только этой основе — грузим по требованию: без
	   явного запроса браузер не тянет шрифт, пока им ничего не набрано,
	   и первый замер подписи шёл бы по фолбэку. */
	var papaFontsAsked = false;
	function loadPapaFonts() {
		if (papaFontsAsked || !document.fonts || !document.fonts.load) return;
		papaFontsAsked = true;
		Promise.all([
			document.fonts.load('400 100px Montserrat', 'PA EST 2026'),
			document.fonts.load('700 100px Montserrat', 'EST 2026')
		]).then(function () {
			var t = tpl();
			if (t && 'papa' === t.engine) fitPreviewText();
		}, function () { });
	}


	/* ───────────────────────── Аккордеон шагов ─────────────────────────
	   Шаги открываются по одному: пошаговый процесс вместо простыни.
	   Свёрнутая шапка показывает выбранное значение. */

	function stepNode(name) {
		return el.steps.querySelector('[data-step="' + name + '"]');
	}

	/** Только показанные шаги: раскладка с единственным вариантом и основа
	    под пресетом товара скрыты и в нумерации не участвуют. */
	function visibleSteps() {
		return Array.prototype.filter.call(
			el.steps.querySelectorAll('.tplb-step'),
			function (n) { return !n.hidden; }
		);
	}

	function nextVisibleStep(name) {
		var list = visibleSteps();
		for (var i = 0; i < list.length; i++) {
			if (list[i].dataset.step === name) return list[i + 1] || null;
		}
		return null;
	}

	function firstVisibleStep() {
		return visibleSteps()[0] || null;
	}

	/** Сквозная нумерация по видимым шагам: без дыр вида 1, 3, 4, 5. */
	function renderStepNums() {
		visibleSteps().forEach(function (node, i) {
			var num = node.querySelector('.tplb-step__num');
			if (num) num.textContent = String(i + 1);
		});
	}

	/** Кнопка «Далее» знает только своего соседа — пересобираем при каждом
	    изменении видимости шагов. */
	function renderStepNav() {
		Array.prototype.forEach.call(el.steps.querySelectorAll('.tplb-step'), function (node) {
			var btn = node.querySelector('.tplb-step__next');
			if (!btn) return;

			var nav = btn.parentNode;
			var nx = node.hidden ? null : nextVisibleStep(node.dataset.step);

			if (!nx) {
				btn.hidden = true;
				btn.removeAttribute('data-next');
				if (nav) nav.hidden = true;
				return;
			}

			var title = nx.querySelector('.tplb-step__title');
			btn.hidden = false;
			if (nav) nav.hidden = false;
			btn.dataset.next = nx.dataset.step;
			btn.textContent = 'Далее: ' + (title ? title.textContent : '');
		});
	}

	/* ─────────────────── Индикатор прогресса по шагам ───────────────────
	   Три состояния кружка:
	     is-todo  — до шага ещё не дошли (серый)
	     (без)    — текущий или требующий внимания (оранжевый)
	     is-done  — пройден И валиден (зелёный, галочка, подсветка строки)

	   Зажигать по одному лишь наличию значения нельзя: основа автовыбирается
	   в init(), раскладка берётся первой, тексты и изделие приходят с дефолтами —
	   все пять кружков позеленели бы сразу при загрузке. Поэтому нужен факт
	   прохождения (state.seen), который ставится при закрытии шага. */

	/**
	 * Половина шага «Раскладка и фото», отвечающая за сами фото.
	 *
	 * Отдельной функцией, а не веткой в stepValid(): после объединения
	 * шагов у неё нет своего имени, но проверка нужна и в stepValid(),
	 * и там, где решается, можно ли отправлять заказ.
	 */
	function photosValid() {
		// «Пришлю фото позже» — осознанный выбор, половина закрыта.
		// Пока что-то грузится — не закрыта: в заказ ушёл бы пустой слот.
		if (pendingCount()) return false;
		return state.photosLater ||
			(state.slots.length > 0 && readyCount() === state.slots.length);
	}

	/**
	 * Валидность надписей. Отдельно от stepValid(): у шаблонов с
	 * mergeTexts шага «Надписи» нет, а проверка нужна и в шаге фото,
	 * и перед отправкой заказа — иначе слово из двух букв уходило бы
	 * на сервер и возвращалось ошибкой валидации.
	 */
	/** Первое незаполненное поле — текстом для подсказки. */
	function textsReason() {
		var t = tpl();
		if (!t) return 'Выберите основу макета.';
		for (var key in t.texts) {
			if (!Object.prototype.hasOwnProperty.call(t.texts, key)) continue;
			var f = t.texts[key];
			if (!textVisible(f)) continue;
			var v = (state.texts[key] || '').trim();
			if (f.required && !v) return 'Заполните поле «' + f.label + '».';
			if (f.locked && (f.presets || []).length && v && f.presets.indexOf(v) === -1) {
				return 'Для поля «' + f.label + '» выберите вариант из списка.';
			}
			if (f.type === 'word' && v) {
				var n = Array.from(v).length;
				if ((f.min && n < f.min) || (f.max && n > f.max)) {
					return 'Слово: от ' + f.min + ' до ' + f.max + ' букв — сейчас ' + n + '.';
				}
			}
		}
		return '';
	}

	function textsValid() {
		var t = tpl();
		if (!t) return false;
		for (var key in t.texts) {
			if (!Object.prototype.hasOwnProperty.call(t.texts, key)) continue;
			var f = t.texts[key];
			if (!textVisible(f)) continue;
			var v = (state.texts[key] || '').trim();

			if (f.required && !v) return false;

			// Locked-поле: значение обязано быть из пресетов, иначе сервер отвергнет.
			if (f.locked && (f.presets || []).length && v && f.presets.indexOf(v) === -1) {
				return false;
			}

			// Слово-маска: длина = числу слотов, границы заданы полем.
			if (f.type === 'word' && v) {
				var n = Array.from(v).length;
				if (f.min && n < f.min) return false;
				if (f.max && n > f.max) return false;
			}
		}
		return true;
	}

	/** Валидность содержимого шага. Не путать с «пройден». */
	function stepValid(name) {
		var t = tpl();
		if (!t) return false;

		if (name === 'base') {
			return true; // без выбранной основы tpl() уже вернул бы null
		}

		// Шаг объединённый: и раскладка, и фото. Зелёным он загорается,
		// только когда закрыты обе половины.
		if (name === 'layout') {
			var keys = Object.keys(t.layouts || {});
			var layoutOk = !keys.length || !!(state.layoutId && t.layouts[state.layoutId]);
			// Объединённый со словом шаг (mergeTexts) закрывает и надписи.
			return layoutOk && photosValid() && (!t.mergeTexts || textsValid());
		}

		if (name === 'texts') {
			return textsValid();
		}
		if (name === 'garment') {
			var g = CFG.garment;
			for (var fld in g) {
				if (!Object.prototype.hasOwnProperty.call(g, fld)) continue;
				if (!g[fld].options[state.garment[fld]]) return false;
			}
			// Цвет ограничен списком шаблона: смена основы могла его обнулить.
			if (t.colors && t.colors.indexOf(state.garment.color) === -1) return false;
			return true;
		}

		return false;
	}

	/** Отметить шаг пройденным. Во время init() — no-op. */
	function markSeen(name) {
		if (booting || !name || state.seen[name]) return;
		state.seen[name] = true;
	}

	/** Пересчёт классов состояния на всех шагах. */
	function updateSteps() {
		if (!el.steps) return;

		renderStepNums();
		renderStepNav();

		Array.prototype.forEach.call(el.steps.querySelectorAll('.tplb-step'), function (node) {
			var name = node.dataset.step;

			// Скрытый шаг (например, раскладка при единственном варианте)
			// не участвует в прогрессе — снимаем метки, чтобы не всплыли позже.
			if (node.hidden) {
				node.classList.remove('is-done', 'is-todo');
				return;
			}

			var seen = !!state.seen[name];

			// Открытый шаг не считается пройденным, даже если он валиден и
			// пользователь уже был на нём. Зелёная подложка «пройдено» под
			// раскрытым содержимым съедала контраст: поля, чипы и подсказки
			// сливались с фоном. Раскрытый шаг — это текущий, у него свой
			// вид (оранжевый кружок по .is-open:not(.is-done)).
			var open = node.classList.contains('is-open');
			var done = seen && !open && stepValid(name);

			node.classList.toggle('is-done', done);
			node.classList.toggle('is-todo', !seen);
		});
	}

	function openStep(name, exclusive) {
		var node = stepNode(name);
		if (!node || node.hidden) return;

		if (exclusive !== false) {
			Array.prototype.forEach.call(el.steps.querySelectorAll('.tplb-step'), function (s) {
				if (s !== node) {
					// Шаг закрывается потому, что пользователь ушёл дальше — это и есть
					// «пройден». Закрытие скрытого шага не считаем.
					if (s.classList.contains('is-open') && !s.hidden) markSeen(s.dataset.step);
					s.classList.remove('is-open');
					var h = s.querySelector('.tplb-step__head');
					if (h) h.setAttribute('aria-expanded', 'false');
				}
			});
		}

		node.classList.add('is-open');
		var head = node.querySelector('.tplb-step__head');
		if (head) head.setAttribute('aria-expanded', 'true');

		updateSteps();
	}

	/**
	 * Насколько отступить сверху при прокрутке к шагу.
	 *
	 * Фиксированные 80px годились, пока сверху висела только шапка сайта.
	 * На мобильных над формой ещё и липкое превью — вместе они закрывают
	 * ~290px, и шаг уезжал под них: пользователь видел середину блока
	 * и не понимал, что именно раскрылось.
	 *
	 * Липкий элемент меряем по его собственному `top` плюс высота, а не по
	 * текущему положению на экране: пока страница не прокручена, он ещё
	 * не «прилип», и getBoundingClientRect().bottom дал бы другое число.
	 */
	function scrollOffset() {
		var off = 16;

		var header = document.querySelector('.site-header');
		if (header && window.getComputedStyle(header).position === 'sticky') {
			off += header.getBoundingClientRect().height;
		}

		if (el.side) {
			var cs = window.getComputedStyle(el.side);
			if (cs.position === 'sticky') {
				off += el.side.getBoundingClientRect().height;
			}
		}

		return off < 80 ? 80 : off;
	}

	/**
	 * Прокрутка к раскрытому шагу.
	 *
	 * Два ограничения, оба про липкую колонку превью справа.
	 *
	 * 1. Если шапка шага и так на виду — не дёргаем страницу вовсе.
	 *    Прокрутка нужна, чтобы показать раскрытое, а не чтобы двигать
	 *    ради движения.
	 *
	 * 2. Верхняя граница прокрутки. Колонка липнет внутри своей ячейки
	 *    грида, и когда та кончается, её «отклеивает» — колонка уезжает
	 *    под шапку сайта. У коротких шагов (например «Раскладка фото»)
	 *    страница как раз докручивалась до этого места, и превью пропадало
	 *    ровно тогда, когда человек перебирает раскладки и смотрит на него.
	 *    Дальше точки, где колонка ещё держится, не прокручиваем.
	 */
	function scrollToStep(node) {
		if (!node) return;

		var offset = scrollOffset();
		var head = node.querySelector('.tplb-step__head') || node;
		var headTop = head.getBoundingClientRect().top;

		if (headTop >= offset - 2 && headTop <= window.innerHeight - 80) return;

		var target = headTop + window.pageYOffset - offset;

		if (el.side && el.sideInner) {
			var cs = window.getComputedStyle(el.sideInner);
			if ('sticky' === cs.position) {
				var asideBottom = el.side.getBoundingClientRect().bottom + window.pageYOffset;
				var colH = el.sideInner.getBoundingClientRect().height;
				var stickyTop = parseFloat(cs.top) || 0;
				var limit = asideBottom - colH - stickyTop;
				if (isFinite(limit) && target > limit) target = limit;
			}
		}

		window.scrollTo({ top: Math.max(0, target), behavior: 'smooth' });
	}

	function toggleStep(node) {
		var open = node.classList.contains('is-open');
		if (open) {
			node.classList.remove('is-open');
			node.querySelector('.tplb-step__head').setAttribute('aria-expanded', 'false');
			markSeen(node.dataset.step);
			updateSteps();
		} else {
			openStep(node.dataset.step);
			// Прокручиваем к шапке, иначе после схлопывания соседа страница «прыгает».
			scrollToStep(node);
		}
	}

	/**
	 * Разворот превью на весь экран (только мобильная вёрстка).
	 *
	 * Сам масштаб делает CSS через --tplb-scale, JS только переключает
	 * класс. Но ширина вёрстки в развороте другая (нет отступов колонки),
	 * а подгон кеглей — fitNameRow / fitLines — считает по фактическим
	 * размерам, поэтому после переключения его надо прогнать заново.
	 * Через rAF: до перерисовки новые размеры ещё не применены.
	 */
	function initZoom() {
		if (!el.zoom || !el.side) return;

		function setZoom(on) {
			el.side.classList.toggle('is-zoom', on);
			document.body.classList.toggle('tplb-noscroll', on);
			el.zoom.setAttribute('aria-expanded', on ? 'true' : 'false');
			if (on) el.side.scrollTop = 0;
			window.requestAnimationFrame(fitPreviewText);
		}

		el.zoom.addEventListener('click', function () {
			setZoom(!el.side.classList.contains('is-zoom'));
		});

		// Второй выход: тап по пустому полю вокруг макета. Кнопка может
		// уехать за верх при прокрутке длинного превью, и остаться запертым
		// в оверлее — худшее, что тут может случиться.
		el.side.addEventListener('click', function (e) {
			if (e.target === el.side && el.side.classList.contains('is-zoom')) setZoom(false);
		});

		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && el.side.classList.contains('is-zoom')) setZoom(false);
		});

		// Поворот экрана или переход на десктопную ширину: разворот теряет
		// смысл, а зафиксированный оверлей остался бы поверх страницы.
		window.addEventListener('resize', function () {
			if (window.innerWidth > 1024 && el.side.classList.contains('is-zoom')) setZoom(false);
		});
	}

	function initAccordion() {
		Array.prototype.forEach.call(el.steps.querySelectorAll('.tplb-step__head'), function (head) {
			head.addEventListener('click', function () {
				toggleStep(head.closest('.tplb-step'));
			});
		});

		// Переход к следующему шагу — единственное место, где шаг схлопывается
		// сам. Выбор внутри шага его больше не закрывает.
		Array.prototype.forEach.call(el.steps.querySelectorAll('.tplb-step__next'), function (btn) {
			btn.addEventListener('click', function () {
				var name = btn.dataset.next;
				if (!name) return;
				openStep(name);
				scrollToStep(stepNode(name));
			});
		});
	}

	/** Сводка выбранного в шапках свёрнутых шагов. */
	function renderStepHeads() {
		var t = tpl();
		if (!t) return;

		function setPick(name, text) {
			var node = stepNode(name);
			if (!node) return;
			var pick = node.querySelector('[data-pick="' + name + '"]');
			if (pick) pick.textContent = text || '';
		}

		setPick('base', t.title);

		// Сброс на пустую строку обязателен: у letters и single раскладок
		// нет, и без явного вызова в шапке осталось бы название раскладки
		// от предыдущей основы. Раньше это было незаметно — шаг целиком
		// прятался, — но после объединения с фото он виден всегда.
		setPick('layout', (state.layoutId && t.layouts[state.layoutId])
			? t.layouts[state.layoutId].title
			: (t.mergeTexts && state.texts.word ? '«' + state.texts.word + '»' : ''));

		var main = Object.keys(t.texts)[0];
		setPick('texts', main && state.texts[main] ? '«' + state.texts[main] + '»' : '');

		var g = CFG.garment;
		var parts = [];
		// Перебор по фактическим полям, а не по фиксированной тройке
		// color/type/size: список изделия задаётся в PHP и растёт (так
		// появилась сторона печати), а жёсткий список молча выкидывал бы
		// новое поле из сводки в шапке.
		Object.keys(g).forEach(function (f) {
			if (g[f] && g[f].options[state.garment[f]]) {
				parts.push(g[f].options[state.garment[f]].label);
			}
		});
		setPick('garment', parts.join(' · '));

		// Состояния кружков: любое изменение может как зажечь зелёный
		// (дозаполнили слоты в уже пройденном шаге), так и погасить его
		// (стёрли обязательный заголовок).
		updateSteps();

		// Миниатюры в шапке — по слотам, чтобы в свёрнутом виде было видно
		// не только количество, но и что именно попало в макет.
		if (el.headThumbs) {
			el.headThumbs.innerHTML = state.slots.map(function (s) {
				var p = s.poolIndex != null ? state.pool[s.poolIndex] : null;
				if (p && (p.pending || !p.url)) return '<i class="is-loading"></i>';
				return p
					? '<i style="background-image:url(' + p.url + ')"></i>'
					: '<i class="is-empty"></i>';
			}).join('');
		}
	}

	/* ───────────────────────── Подгон текста по ширине ─────────────────────────
	   Заголовок должен занимать всю ширину принта, как на референсах.
	   CSS так не умеет, поэтому меряем строку канвасом и считаем кегль.
	   Один замер на 100px + пропорция — без layout thrashing. */

	var measureCtx = null;

	function measureText(text, weight, family) {
		if (!measureCtx) {
			measureCtx = document.createElement('canvas').getContext('2d');
		}
		measureCtx.font = weight + ' 100px ' + family;
		return measureCtx.measureText(text).width;
	}

	/** Буквы-маски растягивать нельзя — исказятся лица на фото. */
	function fitLetters(node, word) {
		var box = node.clientWidth;
		if (!box || !word) return;

		// Семейство — тот же стек, что у .tplp-letters: замер обязан
		// попасть в реально рисуемый шрифт, иначе латинское слово
		// (Horizon шире Oswald) вылезет за край принта.
		var own = measureText(word, '700', TPL_FONT);
		if (!own) return;

		node.style.setProperty('--tplp-fit', ((box / own) * 100 * 0.99).toFixed(1) + 'px');
	}

	/* ── Сплошные буквы ─────────────────────────────────────────────

	   Нужна маска глифа с залитыми внутренними просветами (counters):
	   у P, A, O, D, Б, Я фото должно занимать и «дырку». Ни CSS, ни
	   canvas такого начертания не дают, поэтому глиф рисуется на холст,
	   а потом от края заливкой помечается весь внешний фон. Всё, до чего
	   заливка не дошла, — сама буква плюс замкнутые просветы.

	   Открытые просветы (между ногами A, в C, Г, П) соединены с краем и
	   остаются прозрачными — ровно как на референсе.

	   Один код на превью и на растр заказа. */

	/** Залить замкнутые просветы на холсте с одним глифом (in place). */
	function fillCounters(cx, w, h) {
		var img = cx.getImageData(0, 0, w, h);
		var d = img.data;
		var n = w * h;
		var out = new Uint8Array(n); // 1 = внешний фон
		var stack = [];
		var TH = 128;                // порог «чернила / фон» по альфе

		function seed(i) {
			if (!out[i] && d[i * 4 + 3] < TH) { out[i] = 1; stack.push(i); }
		}
		var x, y;
		for (x = 0; x < w; x++) { seed(x); seed((h - 1) * w + x); }
		for (y = 0; y < h; y++) { seed(y * w); seed(y * w + w - 1); }

		while (stack.length) {
			var i = stack.pop();
			x = i % w;
			if (x > 0) seed(i - 1);
			if (x < w - 1) seed(i + 1);
			if (i >= w) seed(i - w);
			if (i < n - w) seed(i + w);
		}

		// Внешний фон сохраняет свою альфу (сглаживание контура),
		// всё остальное — сплошная заливка цветом глифа.
		for (var k = 0; k < n; k++) {
			if (!out[k]) d[k * 4 + 3] = 255;
		}
		cx.putImageData(img, 0, 0);
	}

	/**
	 * Холст w×h с глифом `ch` (чёрный, по альфе — маска).
	 * Базовая линия на baseY, начало глифа на x0.
	 * solid — с залитыми просветами.
	 */
	function glyphLayer(ch, font, w, h, x0, baseY, solid) {
		var W = Math.max(1, Math.ceil(w));
		var H = Math.max(1, Math.ceil(h));
		// Поле в 2px вокруг: внешний фон должен быть связным, иначе
		// глиф, касающийся края, разрезал бы его и часть фона «залилась».
		var P = 2;
		var pad = document.createElement('canvas');
		pad.width = W + P * 2;
		pad.height = H + P * 2;
		var pc = pad.getContext('2d');
		pc.font = font;
		pc.textAlign = 'left';
		pc.textBaseline = 'alphabetic';
		pc.fillStyle = '#000';
		pc.fillText(ch, P + x0, P + baseY);
		if (solid) {
			try { fillCounters(pc, pad.width, pad.height); } catch (e) { /* без заливки просветов */ }
		}

		var cv = document.createElement('canvas');
		cv.width = W;
		cv.height = H;
		cv.getContext('2d').drawImage(pad, -P, -P);
		return cv;
	}

	/** Вписать изображение по принципу cover без отсечки (для source-in). */
	function drawCoverRaw(cx, img, x, y, w, h) {
		var iw = img.naturalWidth || img.width;
		var ih = img.naturalHeight || img.height;
		if (!iw || !ih) return;
		var k = Math.max(w / iw, h / ih);
		cx.drawImage(img, x + (w - iw * k) / 2, y + (h - ih * k) / 2, iw * k, ih * k);
	}

	var SOLID_F = 200;          // кегль, в котором строятся маски превью

	/**
	 * Вытягивание букв по высоте.
	 *
	 * Horizon — широкий «экстендед»: «PAPA» на 30 см выходило полосой
	 * ~30 × 6 см, буквы читались сплюснутыми, а фото в них — щелями.
	 * Глиф (маска) растягивается по вертикали; фото внутри вписываются
	 * по cover в уже вытянутую букву и сами не искажаются. 1.35 — по
	 * референсу «PAPA» на белой футболке.
	 *
	 * Кириллица набрана Oswald — он и так узкий и высокий, его не тянем.
	 * Одно число на превью и растр.
	 */
	var LETTERS_STRETCH_LAT = 1.35;

	function lettersStretch(word) {
		// кириллица теперь тоже широкая (Unbounded) — тянем так же.
		return LETTERS_STRETCH_LAT;
	}

	/**
	 * Блочные буквы «П» и «А» — по рабочему шаблону студии из
	 * Photoshop: буква занимает почти всю свою клетку, просветы сведены
	 * к узкой прорези / маленькому вырезу снизу. Пропорции не «шрифтовые»,
	 * зато под фото в разы больше места, чем у узкого Oswald.
	 *
	 * Единица — высота буквы. Замерено по шаблону (высота 273 px):
	 *   П — 236 px, прорезь 43 × 118 по центру снизу;
	 *   А — низ 308 px, плоская верхушка 110 px, вырез-трапеция снизу
	 *       135 → 105 px высотой 46 px; просвет между буквами 23 px.
	 *
	 * Включается, только если ВСЕ буквы слова есть в наборе (ПАПА, АПА…);
	 * остальные кириллические слова — Oswald, как раньше.
	 */
	var BLOCK_GAP = 23 / 273;
	var BLOCK_GLYPHS = {
		'П': {
			w: 236 / 273,
			draw: function (c, w, h) {
				var sw = w * (43 / 236), sh = h * (118 / 273);
				c.beginPath();
				c.moveTo(0, 0); c.lineTo(w, 0); c.lineTo(w, h);
				c.lineTo((w + sw) / 2, h); c.lineTo((w + sw) / 2, h - sh);
				c.lineTo((w - sw) / 2, h - sh); c.lineTo((w - sw) / 2, h);
				c.lineTo(0, h); c.closePath();
				c.fill();
			}
		},
		// «М» — в той же логике: блок с V-вырезом
		// сверху и узкой прорезью снизу, как у «П».
		'М': {
			w: 1.0,
			draw: function (c, w, h) {
				var vw = w * 0.34, vd = h * 0.40;          // V сверху
				var sw = w * (43 / 236), sh = h * 0.40;     // прорезь снизу
				c.beginPath();
				c.moveTo(0, 0);
				c.lineTo((w - vw) / 2, 0); c.lineTo(w / 2, vd); c.lineTo((w + vw) / 2, 0);
				c.lineTo(w, 0); c.lineTo(w, h);
				c.lineTo((w + sw) / 2, h); c.lineTo((w + sw) / 2, h - sh);
				c.lineTo((w - sw) / 2, h - sh); c.lineTo((w - sw) / 2, h);
				c.lineTo(0, h); c.closePath();
				c.fill();
			}
		},
		'А': {
			w: 308 / 273,
			draw: function (c, w, h) {
				var top = w * (110 / 308);
				var nb = w * (135 / 308), nt = w * (105 / 308), nh = h * (46 / 273);
				c.beginPath();
				c.moveTo((w - top) / 2, 0); c.lineTo((w + top) / 2, 0);
				c.lineTo(w, h);
				c.lineTo((w + nb) / 2, h); c.lineTo((w + nt) / 2, h - nh);
				c.lineTo((w - nt) / 2, h - nh); c.lineTo((w - nb) / 2, h);
				c.lineTo(0, h); c.closePath();
				c.fill();
			}
		}
	};

	function blockWord(word) {
		var chars = Array.from(word || '');
		return chars.length > 0 && chars.every(function (ch) { return !!BLOCK_GLYPHS[ch]; });
	}

	/** Ширина слова в единицах высоты буквы. */
	function blockUnits(chars) {
		var u = 0;
		chars.forEach(function (ch) { u += BLOCK_GLYPHS[ch].w; });
		return u + BLOCK_GAP * Math.max(0, chars.length - 1);
	}

	/** Маска блочной буквы: w × h px, чёрная заливка по форме. */
	function blockLayer(ch, w, h) {
		var cv = document.createElement('canvas');
		cv.width = Math.max(1, Math.round(w));
		cv.height = Math.max(1, Math.round(h));
		var c = cv.getContext('2d');
		c.fillStyle = '#000';
		BLOCK_GLYPHS[ch].draw(c, cv.width, cv.height);
		return cv;
	}

	/** Превью блочных букв: те же маски, что у applySolidLetters. */
	function applyBlockLetters(node, chars, box) {
		var gapPx = 1; // .tplp-letters gap
		var fit = Math.max(1, (box - gapPx * (chars.length - 1)) / blockUnits(chars) * 0.99);
		node.style.setProperty('--tplp-fit', fit.toFixed(1) + 'px');
		var spans = node.querySelectorAll('.tplp-letter');
		chars.forEach(function (ch, i) {
			var span = spans[i];
			if (!span) return;
			var g = BLOCK_GLYPHS[ch];
			var key = 'block|' + ch;
			var url = solidMaskCache[key];
			if (!url) {
				try { url = blockLayer(ch, g.w * SOLID_F, SOLID_F).toDataURL('image/png'); } catch (e) { url = ''; }
				solidMaskCache[key] = url;
			}
			if (!url) return;
			span.style.width = g.w + 'em';
			span.style.height = '1em';
			span.style.marginLeft = '0';
			// Просвет между буквами — как в шаблоне, минус CSS-gap.
			span.style.marginRight = (i < chars.length - 1) ? 'calc(' + BLOCK_GAP + 'em - ' + gapPx + 'px)' : '0';
			span.style.setProperty('--tplp-mask', 'url("' + url + '")');
		});
		node.classList.add('is-masked');
	}
	var solidMaskCache = {};

	/**
	 * Превью сплошных букв. Каждая буква — блок с фото (или заливкой
	 * у пустой), обрезанный маской сплошного глифа. Габариты блоков
	 * в em от общего кегля --tplp-fit, поэтому подгон по ширине — тот
	 * же одиночный расчёт, что у fitLetters().
	 */
	function applySolidLetters(node, word) {
		var box = node.clientWidth;
		var chars = Array.from(word || '');
		if (!box || !chars.length) return;

		if (blockWord(word)) {
			applyBlockLetters(node, chars, box);
			return;
		}

		var solid = 'solid' === state.styles.letter_fill;
		var K = lettersStretch(word);

		if (!measureCtx) measureCtx = document.createElement('canvas').getContext('2d');
		var font = '700 ' + SOLID_F + 'px ' + TPL_FONT;
		measureCtx.font = font;

		var asc = 0, desc = 0, total = 0;
		var ms = chars.map(function (ch) {
			var m = measureCtx.measureText(ch);
			var a = isFinite(m.actualBoundingBoxAscent) ? m.actualBoundingBoxAscent : SOLID_F * 0.75;
			var d = isFinite(m.actualBoundingBoxDescent) ? m.actualBoundingBoxDescent : 0;
			asc = Math.max(asc, a);
			desc = Math.max(desc, d, 0);
			total += m.width;
			return m;
		});
		if (!total) return;

		// Выносы крайних букв за адванс тоже должны поместиться.
		var m0 = ms[0], mN = ms[ms.length - 1];
		total += Math.max(0, isFinite(m0.actualBoundingBoxLeft) ? m0.actualBoundingBoxLeft : 0);
		total += Math.max(0, (isFinite(mN.actualBoundingBoxRight) ? mN.actualBoundingBoxRight : mN.width) - mN.width);

		var gap = 1; // как gap у .tplp-letters
		var fit = Math.max(1, (box - gap * (chars.length - 1)) / total * SOLID_F * 0.99);
		node.style.setProperty('--tplp-fit', fit.toFixed(1) + 'px');

		var H = Math.ceil(asc + desc);
		var spans = node.querySelectorAll('.tplp-letter');
		chars.forEach(function (ch, i) {
			var span = spans[i];
			if (!span) return;
			var m = ms[i];
			var adv = m.width;
			var l = Math.max(0, isFinite(m.actualBoundingBoxLeft) ? m.actualBoundingBoxLeft : 0);
			var r = Math.max(adv, isFinite(m.actualBoundingBoxRight) ? m.actualBoundingBoxRight : adv);
			var w = Math.ceil(l + r);

			// Ключ включает замеры: пока Horizon не догрузился, метрики
			// другие, и маска от запасного шрифта не переживёт подмену.
			var key = ch + '|' + (solid ? 's' : 'c') + '|' + Math.round(adv * 10) + '|' + Math.round(asc) + '|' + Math.round(desc);
			var url = solidMaskCache[key];
			if (!url) {
				try {
					url = glyphLayer(ch, font, w, H, l, asc, solid).toDataURL('image/png');
				} catch (e) { url = ''; }
				solidMaskCache[key] = url;
			}
			if (!url) return;

			span.style.width = (w / SOLID_F) + 'em';
			// mask-size 100% 100%: маска растягивается вместе с блоком.
			span.style.height = (H * K / SOLID_F) + 'em';
			span.style.marginLeft = (-l / SOLID_F) + 'em';
			span.style.marginRight = (-(r - adv) / SOLID_F) + 'em';
			span.style.setProperty('--tplp-mask', 'url("' + url + '")');
		});
		node.classList.add('is-masked');
	}

	/* Строка «декор + имя» в ONLY YOU.

	   Две задачи, обе решаются только после вёрстки:
	     • имя не должно распирать превью — длинное уменьшаем по кеглю,
	       иначе оно выдавливало декор в ноль и вылезало за край макета;
	     • декор должен доходить ровно до имени — количество символов
	       считается по фактически оставшемуся месту, а не берётся с потолка.
	       Обрезкой через overflow это не решить: край получался рваным. */
	/**
	 * Во сколько раз уменьшить кегль декоративного символа, чтобы его
	 * высота совпала с высотой заглавной буквы.
	 *
	 * Меряется на canvas: TextMetrics отдаёт фактические границы чернил,
	 * а не метрики шрифта, — только так учитывается, что слэш выше
	 * капители, а шеврон ниже. Результат кешируется: замер стоит дорого,
	 * а вызывается подгон на каждый ввод символа.
	 */
	/**
	 * Базовый трекинг декоративной полосы, в em.
	 *
	 * Значения разные намеренно. У «/» в Oswald нет боковых пробелов:
	 * при нулевом трекинге штрихи смыкаются в сплошную заливку. У «❯»
	 * пробелы заложены в глиф, ему добавка не нужна.
	 *
	 * Калибровано по референсу: доля зазора в шаге 65%. Замер — по
	 * длинам светлых и тёмных участков на середине высоты полосы.
	 * Слэши: 0 → 57%, 0.06em → 65%. Шевроны: -0.08em → 64%, 0 → 68%.
	 */
	/* Оба символа берутся из стека макета, но из разных гарнитур: «/»
	   есть в Horizon, «❯» (U+276F) в него не входит и падает на Oswald.
	   Поэтому значения калиброваны по разным шрифтам и вместе не
	   пересчитываются.

	   Слэш Horizon почти без боковых пробелов (0.007em против 0.043em у
	   Oswald) и заметно шире — при прежних 0.06em полоса читалась почти
	   сплошной. 0.15em возвращает тот же прирост доли зазора, что дала
	   исходная калибровка на Oswald (+0.08 к доле по замеру светлых и
	   тёмных участков на середине высоты). */
	var DECOR_TRACKING = { '/': 0.15, '\u276F': -0.02 };

	function decorTracking(ch) {
		return DECOR_TRACKING[ch] || 0;
	}

	var decorScaleCache = {};

	function decorScale(ch, family, capCh) {
		capCh = capCh || 'H';
		var key = ch + '|' + family + '|' + capCh;
		if (decorScaleCache[key] != null) return decorScaleCache[key];

		var res = 1;
		try {
			var ctx = document.createElement('canvas').getContext('2d');
			ctx.font = '700 100px ' + family;

			function ink(text) {
				var m = ctx.measureText(text);
				var a = m.actualBoundingBoxAscent;
				var d = m.actualBoundingBoxDescent;
				return (typeof a === 'number' && typeof d === 'number') ? (a + d) : 0;
			}

			// Опора капители: плоские верх и низ, без выносных элементов.
			// Латиница — 'H', кириллица — 'Н' (см. capProbe): у Oswald и
			// Horizon разная высота капители, и мерить надо тем шрифтом,
			// каким набрано имя.
			var cap = ink(capCh);
			var glyph = ink(ch);
			if (cap > 0 && glyph > 0) res = cap / glyph;
		} catch (e) {
			// TextMetrics без actualBoundingBox — оставляем как было.
			res = 1;
		}

		decorScaleCache[key] = res;
		return res;
	}

	function fitNameRow() {
		var row = el.art.querySelector('.tplp-oy__namerow');
		if (!row) return;

		var nameEl = row.querySelector('.tplp-oy__name');
		var decEl = row.querySelector('.tplp-oy__arrows');
		var rowW = row.clientWidth;
		if (!rowW) return;

		if (nameEl) {
			nameEl.style.fontSize = '';
			var base = parseFloat(window.getComputedStyle(nameEl).fontSize) || 20;
			// С декором имени отдаём не больше двух третей строки, без — всю.
			var maxW = rowW * (decEl ? 0.66 : 1);

			// Два прохода: после смены кегля ширина текста меняется, и одной
			// итерации хватает не всегда (letter-spacing, округление шрифта).
			for (var pass = 0; pass < 2; pass++) {
				var w = nameEl.scrollWidth;
				if (w <= maxW || !w) break;
				var next = Math.max(8, parseFloat(window.getComputedStyle(nameEl).fontSize) * (maxW / w));
				nameEl.style.fontSize = next + 'px';
			}
			if (nameEl.style.fontSize && parseFloat(nameEl.style.fontSize) >= base) {
				nameEl.style.fontSize = '';
			}
		}

		if (!decEl) return;

		var ch = decEl.dataset.char || '';
		if (!ch) { decEl.textContent = ''; return; }

		// Кегль декора подгоняем так, чтобы штрихи и стрелки были ровно той
		// же высоты, что заглавные буквы имени. Одинаковый font-size тут не
		// годится: у «/» и «❯» своя высота очка, у слэша она заметно больше
		// капители, у шеврона меньше — при равном кегле строка выглядит
		// разнобоем. Меряем фактическую высоту чернил обоих глифов и делим.
		var nameFs = nameEl ? parseFloat(window.getComputedStyle(nameEl).fontSize) : 0;
		if (nameFs) {
			decEl.style.fontSize = (nameFs * decorScale(
				ch,
				window.getComputedStyle(nameEl).fontFamily,
				capProbe(nameEl.textContent)
			)) + 'px';
		}

		// Превью на мобильных уменьшается transform: scale(). scrollWidth
		// и computedStyle отдают размеры до трансформации, а
		// getBoundingClientRect — после. Смешивать их нельзя: rowW считался
		// в вёрстке, nameW — в экранных пикселях, свободного места выходило
		// вдвое больше реального, и строка декора вылезала за кадр.
		// Приводим замер обратно к единицам вёрстки.
		var k = row.clientWidth ? (row.getBoundingClientRect().width / row.clientWidth) : 1;
		if (!k || !isFinite(k) || k <= 0) k = 1;

		var nameW = nameEl ? nameEl.getBoundingClientRect().width / k : 0;
		var gap = parseFloat(window.getComputedStyle(row).columnGap) || 6;
		var avail = rowW - nameW - gap;

		// «Половина строки»: полоса декора занимает ровно 50% ширины, а не
		// весь остаток. Между ней и именем остаётся воздух — приём с
		// референса. Если имя длинное и половина не влезает, берём остаток:
		// иначе декор налез бы на имя.
		if (row.classList.contains('is-decorhalf')) {
			avail = Math.min(avail, rowW * 0.5);
		}

		if (avail <= 2) { decEl.textContent = ''; return; }

		// Ширину одного символа меряем на пробной десятке: так учитываются
		// и кегль, и letter-spacing, заданные в CSS.
		// Базовый трекинг задаётся здесь, а не в CSS: тем же числом
		// пользуется растр для заказа, и держать его в двух местах —
		// значит однажды получить разное превью и макет.
		var decFs = parseFloat(window.getComputedStyle(decEl).fontSize) || 0;
		decEl.style.letterSpacing = (decorTracking(ch) * decFs) + 'px';

		var probe = 10;
		decEl.textContent = new Array(probe + 1).join(ch);
		var unit = decEl.scrollWidth / probe;
		if (!unit || !isFinite(unit)) return;

		var n = Math.max(0, Math.floor(avail / unit));
		decEl.textContent = new Array(n + 1).join(ch);

		// Целое число символов почти никогда не заполняет остаток ровно —
		// разницу раскидываем в letter-spacing, иначе перед именем зияет
		// дыра до одного глифа шириной.
		if (n > 0) {
			var baseLs = parseFloat(window.getComputedStyle(decEl).letterSpacing) || 0;
			var extra = (avail - n * unit) / n;
			if (extra > 0) {
				decEl.style.letterSpacing = (baseLs + Math.min(extra, unit * 0.5)) + 'px';
			}
		}
	}

	/**
	 * Уместить текст в заданное число строк, уменьшая кегль.
	 *
	 * Одной обрезки многоточием мало: «I'm just enjoying every moment.»
	 * превращалось в «I'm just enjoying eve…», хотя на референсе фраза
	 * стоит целиком. Обрезка остаётся страховкой для совсем длинных строк.
	 */
	/**
	 * Строка без переноса, ужатая по ширине своего блока.
	 * Подпись под штрихкодом («SINCE 00.00.0000») уходила в две строки
	 * в узкой колонке — по ширине штрихкода она должна вставать одной.
	 */
	function fitOneLine(node, minPx) {
		if (!node) return;
		node.style.fontSize = '';
		var box = node.clientWidth;
		var own = node.scrollWidth;
		if (!box || own <= box + 1) return;
		var size = parseFloat(window.getComputedStyle(node).fontSize);
		if (!size) return;
		node.style.fontSize = Math.max(minPx || 6, size * (box / own) * 0.97) + 'px';
	}

	function fitLines(node, maxLines, minPx) {
		if (!node) return;
		node.style.fontSize = '';

		var lh = parseFloat(window.getComputedStyle(node).lineHeight);
		if (!lh || !isFinite(lh)) return;

		for (var i = 0; i < 10; i++) {
			if (node.scrollHeight <= lh * maxLines + 1) break;
			var size = parseFloat(window.getComputedStyle(node).fontSize);
			if (!size || size <= minPx) break;
			node.style.fontSize = Math.max(minPx, size * 0.92) + 'px';
			lh = parseFloat(window.getComputedStyle(node).lineHeight) || lh;
		}
	}

	/**
	 * Нижняя граница кегля для fitLines — берётся из CSS, не из константы.
	 *
	 * Зонд создаётся на время замера: у `.tplp-oy__minprobe` тот же clamp,
	 * что у подписи под штрихкодом, поэтому пол автоматически едет вместе
	 * с вьюпортом и остаётся с ней в одном кегле. Прописать 15px числом
	 * нельзя — на мобиле clamp даёт ~11px, и текст перестал бы ужиматься
	 * там, где место реально кончилось.
	 *
	 * `k` — тот же множитель Horizon, что стоит на самой фразе: иначе пол
	 * остался бы на кириллическом кегле подписи, и латинская фраза
	 * упиралась бы в него, не успев ужаться.
	 */
	function minTextPx(host, k) {
		if (!host) return 9;
		var probe = document.createElement('span');
		probe.className = 'tplp-oy__minprobe';
		if (k && k < 1) probe.style.setProperty('--tplp-k', k);
		host.appendChild(probe);
		var px = parseFloat(window.getComputedStyle(probe).fontSize);
		host.removeChild(probe);
		return (px && isFinite(px)) ? px : 9;
	}

	/**
	 * Десктоп: ужать макет так, чтобы колонка влезла в экран целиком.
	 *
	 * У колонки есть max-height и своя прокрутка — страховка на случай,
	 * когда содержимое выше окна. На деле она срабатывала всегда: превью
	 * 3/4 при ширине 400 px — это 574 px, плюс сводка, оговорка и блок с
	 * ценой; на ноутбучном экране не хватало сотни пикселей. Пользователь
	 * получал полосу прокрутки между макетом и кнопкой и сводку, наполовину
	 * уехавшую под липкий низ.
	 *
	 * Считаем, сколько высоты остаётся превью после всего остального, и
	 * ставим --tplb-peek. Дальше работает та же механика, что на мобильных:
	 * вёрстка в полную ширину + transform: scale().
	 *
	 * Обратной связи нет: ширина самого .tplb-preview от масштаба не
	 * зависит (обёртка сужается ровно во столько же раз, во сколько
	 * растягивается превью), поэтому ResizeObserver не зациклится.
	 */
	// Масштаб, который уже стоит на обёртке. Ноль — обёртка не ужата.
	var peekApplied = 0;

	function fitSideColumn() {
		var inner = el.sideInner;
		if (!inner || !el.previewFit || !el.preview) return;

		// На мобильных масштаб задаёт медиазапрос — переменная приходит с
		// колонки. Свой инлайновый вариант там обязан быть снят: он
		// перебил бы CSS и сломал раскладку миниатюры.
		var mobile = parseFloat(
			window.getComputedStyle(inner).getPropertyValue('--tplb-peek')
		);
		if (mobile && isFinite(mobile)) {
			peekApplied = 0;
			el.previewFit.classList.remove('is-scaled');
			el.previewFit.style.removeProperty('--tplb-peek');
			return;
		}

		var cap = parseFloat(window.getComputedStyle(inner).maxHeight);
		var natural = el.preview.offsetHeight;
		if (!isFinite(cap) || !natural) return;

		// Всё, кроме превью: сводка, оговорка, кнопка разворота, блок цены.
		// От масштаба не зависит, поэтому считается один раз за проход.
		var rest = inner.scrollHeight - el.previewFit.offsetHeight;
		var room = cap - rest - 4;

		// Ниже этого макет уже не разглядеть — пусть лучше остаётся
		// прокрутка колонки, чем марка вместо превью.
		var peek = Math.max(0.55, Math.min(1, room / natural));
		peek = Math.floor(peek * 1000) / 1000;

		if (peek >= 1) {
			if (peekApplied) {
				peekApplied = 0;
				el.previewFit.classList.remove('is-scaled');
				el.previewFit.style.removeProperty('--tplb-peek');
			}
			return;
		}

		// Зона нечувствительности. Соседние проходы расходятся на тысячные
		// (высота колонки меряется в целых пикселях, масштаб — дробью), и
		// без порога transform переписывался бы на каждом кадре впустую.
		if (peekApplied && Math.abs(peek - peekApplied) < 0.005) return;

		peekApplied = peek;
		el.previewFit.classList.add('is-scaled');
		el.previewFit.style.setProperty('--tplb-peek', peek);
	}

	/**
	 * Высота обёртки-масштабатора на мобильных.
	 *
	 * Раньше она задавалась фиксированным `padding-top: 133.333%` — в
	 * расчёте на пропорцию футболки 3/4. Но `aspect-ratio` у макета лишь
	 * пожелание: если содержимое выше (девять фото в сетке, четыре строки
	 * фразы), блок растёт, а обёртка остаётся прежней и режет низ по
	 * `overflow: hidden`. Как раз низ с именем и плеером люди и теряли.
	 *
	 * Меряем фактическую высоту вёрстки и умножаем на масштаб. На десктопе
	 * --tplb-peek не задан, высоту диктует само превью — тогда сбрасываем.
	 */
	function syncPeekHeight() {
		if (!el.previewFit || !el.preview) return;

		// Десктопный масштаб считается здесь же: дальше код одинаковый —
		// высота обёртки = высота вёрстки × масштаб.
		fitSideColumn();

		var peek = parseFloat(
			window.getComputedStyle(el.previewFit).getPropertyValue('--tplb-peek')
		);

		if (!peek || !isFinite(peek) || peek >= 1) {
			el.previewFit.style.height = '';
			el.previewFit.style.paddingTop = '';
			return;
		}

		var h = el.preview.offsetHeight;
		if (!h) return;

		el.previewFit.style.paddingTop = '0px';
		el.previewFit.style.height = Math.ceil(h * peek) + 'px';
	}

	/**
	 * Макет меняет высоту не только от перерисовки: догружаются шрифты,
	 * подтягиваются фотографии, переносится длинная фраза. Разовый замер
	 * ловит не все такие случаи — у полароидов высота вырастала уже после
	 * него, и низ снова обрезался на несколько пикселей.
	 * Наблюдатель пересчитывает обёртку на каждое изменение.
	 */
	function watchPeekHeight() {
		if (!el.preview || typeof window.ResizeObserver !== 'function') return;

		var last = -1;
		var queued = false;

		new window.ResizeObserver(function () {
			// Наблюдателя интересует только высота вёрстки: ширина превью
			// от масштаба больше не зависит (см. .tplb-previewfit.is-scaled
			// в CSS), а ширина колонки приходит отдельным обработчиком
			// resize. Сравнение с прошлым замером гасит холостые срабатывания.
			var h = el.preview.offsetHeight;
			if (h === last) return;
			last = h;

			// Пересчёт откладывается до кадра: если за один тик прилетело
			// несколько изменений (догрузились шрифт и фото), проход нужен
			// один, и обязательно ПОСЛЕ того, как раскладка устоялась.
			if (queued) return;
			queued = true;
			window.requestAnimationFrame(function () {
				queued = false;
				syncPeekHeight();
				last = el.preview.offsetHeight;
			});
		}).observe(el.preview);
	}

	/* ─────────────────── Предел пропорции печати ───────────────────
	 *
	 * макет должен вставать в печать 30 × 44 см — при ширине
	 * 30 см длина не больше 44. Раньше постеры выходили
	 * 30 × 47…54, ONLY YOU «Полный кадр» — до 61 см, и макет приходилось
	 * пересобирать вручную перед отправкой в печать.
	 *
	 * Лишнюю высоту забирает ОБЛАСТЬ ФОТО: заголовок, имя, низ и кегли
	 * не трогаются, а коллаж или кадр становятся ниже ровно на перебор.
	 * Фото кадрируются по центру (object-fit: cover), поэтому ниже —
	 * значит чуть шире по пропорции, а не сплющено. Макет короче предела
	 * (6 фото, полоса глаз, буквы, полароид) остаётся как был.
	 *
	 * Исключения — раскладки с флагом `no_print_cap` (ONLY YOU «Полный
	 * кадр»): портрет во всю грудь печатается по своей высоте.
	 *
	 * Пол — PHOTO_MIN_K от естественной высоты: дальше кадр превращался
	 * бы в полоску. У «PAPA и 4 фото» своя фиксированная геометрия
	 * 25 × 40 по ТЗ, предел её не касается.
	 *
	 * Превью и растр ограничиваются независимо, но одним правилом.
	 */
	// 42 → 44 см — при 42 коллаж выходил узкой полосой.
	var PRINT_MAX_ASPECT = 44 / 30;
	var PHOTO_MIN_K = 0.45;

	/** Узел области фото в превью — его высоту и ужимаем. */
	function photoAreaSelector(t) {
		if (!t) return '';
		if ('poster' === t.engine) return '.tplp-grid';
		if ('onlyyou' === t.engine) return '.tplp-oy__photo';
		if ('single' === t.engine) return singleBlock() ? '.tplp-oy__photo' : '.tplp-single__photo';
		return '';
	}

	/** Раскладка помечена исключением (`no_print_cap`): ONLY YOU «Полный кадр». */
	function printCapOff() {
		var t = tpl();
		var lay = t && state.layoutId ? (t.layouts || {})[state.layoutId] : null;
		return !!(lay && lay.no_print_cap);
	}

	function capPreviewAspect() {
		var t = tpl();
		var sel = printCapOff() ? '' : photoAreaSelector(t);
		var root = el.art && el.art.firstElementChild;
		if (!sel || !root) return;
		var box = root.querySelector(sel);
		if (!box) return;

		// fitPreviewText() зовётся повторно без перерисовки (шрифты, resize):
		// сначала возвращаем естественную высоту, потом меряем заново.
		if (box.dataset.capped) {
			box.style.height = '';
			box.style.aspectRatio = box.dataset.ar || '';
			box.style.boxSizing = box.dataset.bs || '';
			delete box.dataset.capped;
		}

		// offset*, а не rect: превью может быть ужато transform-ом.
		var W = root.offsetWidth;
		var H = root.offsetHeight;
		if (!W || !H) return;

		var excess = H - W * PRINT_MAX_ASPECT;
		if (excess <= 0.5) return;

		var bh = box.offsetHeight;
		var nh = Math.max(bh * PHOTO_MIN_K, bh - excess);

		box.dataset.capped = '1';
		box.dataset.ar = box.style.aspectRatio;
		box.dataset.bs = box.style.boxSizing;
		box.style.boxSizing = 'border-box';
		box.style.aspectRatio = 'auto';
		box.style.height = nh.toFixed(1) + 'px';
	}

	function fitPreviewText() {
		var t = tpl();
		if (!t) return;

		// Постеру подгон не нужен: SVG с textLength сам держит ширину.
		if (t.engine === 'letters') {
			var letters = el.art.querySelector('.tplp-letters');
			if (letters) {
				var w = (state.texts.word || '').trim();
				applySolidLetters(letters, w);
			}
		}

		// Блочный низ живёт и в постерах — подгон нужен там же, иначе имя
		// вылезает за край, а фраза мельчает до нечитаемого.
		if (t.engine === 'single' && !singleBlock()) fitSingle();
		if (t.engine === 'papa') fitPapa();

		if (t.engine === 'onlyyou' || singleBlock() ||
			(t.engine === 'poster' && 'simple' !== (state.styles.footer_kind || 'block'))) {
			fitNameRow();
			fitLines(el.art.querySelector('.tplp-oy__phrase'), 4, minTextPx(el.art, fontK(state.texts.phrase)));
			fitLines(el.art.querySelector('.tplp-oy__note'), 4, 8);
			fitOneLine(el.art.querySelector('.tplp-oy__codecap'), 6);
		}

		// Строго после подгона: переносы влияют на высоту макета.
		capPreviewAspect();
		syncPeekHeight();
		applyCrops();
	}


	/* ───────────────────────── Цена и сводка ───────────────────────── */

	function resolvedAddons() {
		var out = [];
		Object.keys(CFG.addons).forEach(function (code) {
			var a = CFG.addons[code];
			var apply;
			if (a.auto) {
				apply = true;
				var when = a.when || {};
				Object.keys(when).forEach(function (field) {
					if (when[field].indexOf(state.garment[field]) === -1) apply = false;
				});
			} else {
				apply = state.addons.indexOf(code) !== -1;
			}
			if (apply) out.push({ code: code, title: a.title, amount: parseFloat(a.amount) });
		});
		return out;
	}

	/**
	 * Подсказка у неактивной кнопки «В корзину».
	 *
	 * Узлы создаются здесь, а не в шаблоне: на десктопе — над кнопкой в
	 * блоке «Итого», на мобиле — полоской над липкой панелью (блок «Итого»
	 * там скрыт). Неактивная кнопка не ловит клик, поэтому объяснять надо
	 * заранее, а не по нажатию.
	 *
	 * подсказка стоит НАД кнопкой и подсвечена; если не хватает
	 * фото, хвост «отметьте «Пришлю фото позже»» — ссылка: раскрывает шаг
	 * с фото, докручивает до чекбокса и подсвечивает его. Сам чекбокс не
	 * ставим — решение за покупателем.
	 */
	function renderWhy(text) {
		if (!el.why && el.submit && el.submit.parentNode) {
			el.why = document.createElement('div');
			el.why.className = 'tplb-why';
			el.why.setAttribute('role', 'status');
			el.submit.parentNode.insertBefore(el.why, el.submit);
			el.why.addEventListener('click', onWhyClick);
		}
		var sticky = document.getElementById('tplb-sticky');
		if (!el.stickyWhy && sticky) {
			el.stickyWhy = document.createElement('div');
			el.stickyWhy.className = 'tplb-sticky__why';
			sticky.insertBefore(el.stickyWhy, sticky.firstChild);
			el.stickyWhy.addEventListener('click', onWhyClick);
		}

		var html = esc(text || '');
		var linked = !!(text && el.laterInput && el.later &&
			text.slice(-WHY_LATER_TAIL.length) === WHY_LATER_TAIL);
		if (linked) {
			html = esc(text.slice(0, -WHY_LATER_TAIL.length)) +
				'<button type="button" class="tplb-why__link">' +
				esc(WHY_LATER_TAIL.replace(/\.$/, '')) + '</button>.';
		}
		if (html === lastWhy) return;
		lastWhy = html;

		[el.why, el.stickyWhy].forEach(function (n) {
			if (!n) return;
			n.innerHTML = html;
			n.hidden = !text;
		});
	}
	var lastWhy = null;

	function onWhyClick(e) {
		var link = e.target && e.target.closest ? e.target.closest('.tplb-why__link') : null;
		if (!link) return;
		e.preventDefault();
		goToLater();
	}

	/** Якорь из подсказки: шаг с фото → чекбокс «Пришлю фото позже». */
	function goToLater() {
		if (!el.later || !el.laterInput) return;
		var step = stepNode('layout');
		if (step && !step.classList.contains('is-open')) openStep('layout');

		// Шаг раскрывается анимацией — крутим после раскладки.
		window.setTimeout(function () {
			var r = el.later.getBoundingClientRect();
			// Видимая зона: под шапкой и липким превью (на мобиле оно
			// висит сверху) и над липкой панелью с кнопкой.
			var top = scrollOffset();
			if (el.sideInner && 'sticky' === window.getComputedStyle(el.sideInner).position &&
				window.innerWidth <= 1024) {
				top = Math.max(top, parseFloat(window.getComputedStyle(el.sideInner).top || 0) +
					el.sideInner.getBoundingClientRect().height);
			}
			top += 12;
			var sticky = document.getElementById('tplb-sticky');
			var bottom = window.innerHeight - ((sticky && sticky.offsetHeight) ? sticky.offsetHeight + 50 : 24);
			if (r.top < top || r.bottom > bottom) {
				var room = Math.max(0, bottom - top - r.height);
				var target = r.top + window.pageYOffset - top - room / 2;
				window.scrollTo({ top: Math.max(0, target), behavior: 'smooth' });
			}
			try { el.laterInput.focus({ preventScroll: true }); } catch (err) { el.laterInput.focus(); }
			el.later.classList.remove('is-flash');
			void el.later.offsetWidth; // перезапуск анимации
			el.later.classList.add('is-flash');
			window.setTimeout(function () { el.later.classList.remove('is-flash'); }, 2200);
		}, 60);
	}

	function renderTotal() {
		var total = parseFloat(CFG.basePrice) || 0;
		var addons = resolvedAddons();
		addons.forEach(function (a) { total += a.amount; });

		var txt = money(total);
		if (el.totalPrice) el.totalPrice.textContent = txt;
		if (el.stickyPrice) el.stickyPrice.textContent = txt;

		syncLater();
		var reason = blockReason();
		var ready = !reason && !state.busy;
		if (el.submit) el.submit.disabled = !ready;
		if (el.stickyBtn) el.stickyBtn.disabled = !ready;
		renderWhy(state.busy ? '' : reason);

		// Сводка
		if (el.summary) {
			var rows = [];
			var t = tpl();
			if (t) {
				rows.push(['Основа', t.title]);
				if (state.layoutId && t.layouts[state.layoutId]) {
					rows.push(['Раскладка', t.layouts[state.layoutId].title]);
				}
				var slay = activeLayout();
				var haveNow = readyCount();
				rows.push(['Фото', state.photosLater
					? (haveNow ? haveNow + ' из ' + state.slots.length + ', остальные позже' : 'пришлю позже')
					: (slay && slay.free
						? filledCount() + ' шт.'
						: filledCount() + ' из ' + state.slots.length)]);
			}
			addons.forEach(function (a) { rows.push([a.title, '+' + money(a.amount)]); });

			el.summary.hidden = rows.length === 0;
			el.summary.innerHTML = rows.map(function (r) {
				return '<div class="tplb-summary__row"><span>' + esc(r[0]) + '</span><b>' + esc(r[1]) + '</b></div>';
			}).join('');
		}

		if (el.counter) {
			if (state.photosLater) {
				var hn = readyCount();
				el.counter.textContent = hn ? (hn + ' / ' + state.slots.length + ', остальные позже') : 'фото позже';
			} else {
				// В свободной компоновке «2 / 2» ничего не сообщает: слотов ровно
				// столько, сколько фото. Показываем запас до максимума.
				var flay = activeLayout();
				if (flay && flay.free) {
					el.counter.textContent = state.pool.length + ' из ' + (flay.max || 9);
				} else {
					el.counter.textContent = state.slots.length
						? (filledCount() + ' / ' + state.slots.length)
						: '';
				}
			}
		}
	}

	function renderAll() {
		renderSlots();
		renderPreview();
		renderTotal();
		renderStepHeads();
	}

	/* ───────────────────────── Отправка ───────────────────────── */

	function buildPayload() {
		return {
			tpl_id: state.tplId,
			layout_id: state.layoutId || '',
			texts: state.texts,
			styles: state.styles,
			garment: state.garment,
			addons: state.addons,
			comment: el.commentField ? el.commentField.value : '',
			photos_later: state.photosLater ? 1 : 0,
			slots: state.slots.map(function (s, i) {
				var p = s.poolIndex != null ? state.pool[s.poolIndex] : null;
				return {
					key: s.key,
					attachment_id: p ? p.id : 0,
					// фактическая вырезка из растра (доли фото).
					crop: (p && state.cropOut && state.cropOut[i]) || { x: 0, y: 0, w: 1, h: 1 },
					zoom: p && p.fit ? Math.round((+p.fit.zoom || 1) * 100) / 100 : 1
				};
			}).filter(function (s) {
				// При «фото позже» пустые слоты не отправляем — сервер
				// проверяет полноту только когда флаг снят.
				return !state.photosLater || s.attachment_id;
			})
		};
	}

	/* ═════════════════════════════════════════════════════════════════
	   РАСТЕРИЗАЦИЯ МАКЕТА ДЛЯ ЗАКАЗА

	   В заказ раньше уезжал «контактный лист» — просто фотографии из слотов,
	   выложенные в сетку. Печатнику этого мало: он видел исходники, но не
	   видел, что из них собрал клиент, и собирал макет в фотошопе по
	   текстовому описанию полей.

	   Здесь макет рисуется заново на canvas в тех же пропорциях, что и
	   превью на странице. Почему не снимок DOM:

	   • foreignObject требует инлайна шрифтов и всех правил CSS, а в Safari
	     рендерится через раз — тихо пустой холст в заказе хуже, чем его
	     отсутствие;
	   • снимок DOM берёт экранное разрешение (~320px), а печатнику нужен
	     кадр, с которого можно снимать пропорции — здесь 1200px.

	   Плата за это — вторая реализация раскладки. Поэтому все размеры
	   выражены долями ширины области печати и откалиброваны по десктопному
	   превью, где clamp() стоят на максимуме: одно число на константу,
	   менять вместе с CSS.
	   ═════════════════════════════════════════════════════════════════ */

	// Ширина растра, px. 1200 — схема для заказа и все замеры. На время
	// сборки печатного файла подменяется на ширину печати при
	// 300 ppi, потом возвращается: вся геометрия выражена долями RW.
	var RW_BASE = 1200;
	var RW = RW_BASE;

	// Запас сверху в сухом прогоне: поле в него укладывается целиком,
	// так что даже аномальный вынос не упрётся в край холста-заглушки.
	var SLACK = Math.round(RW * 0.10);

	/* ─────────────── Ширина строки и выравнивание в растре ───────────────

	   Заказ #412: имя «ПУПС ❤️🤗» и строка «∞ +1» уехали за правый край
	   печати. По замеру присланного файла (полоса прогресса даёт
	   геометрию с точностью 0.1%): чернила обеих строк начинались ровно
	   на правой границе печати и обрывались о край холста, а соседняя
	   чисто кириллическая «БЕСКОНЕЧНОСТЬ» стояла на месте. То есть
	   выравнивание вправо сработало так, будто ширина этих строк — ноль.

	   Общий признак уехавших строк — символы вне Oswald: эмодзи и «∞»,
	   они рисуются системным фолбэком. Воспроизвести не удалось (в
	   headless Chrome с Noto Color Emoji и настоящим Oswald переносы
	   совпадают с заказом, а вылета нет) — судя по всему, на машине
	   клиента системный эмодзи-шрифт в момент растеризации ещё не был
	   резидентен и вернул нулевой адванс.

	   Отсюда две независимые меры. Первая — считать ширину как максимум
	   из адванса и фактической правой границы чернил: если схлопнулся
	   адванс, останутся метрики чернил. Вторая — обрезка по области
	   печати в paint(). */

	/**
	 * Ширина строки для выравнивания.
	 *
	 * Замер строго при textAlign:'left' — actualBoundingBoxRight
	 * отсчитывается от точки привязки, и при 'right' он вернул бы
	 * величину около нуля вместо ширины.
	 */
	function textWidth(ctx, text) {
		var prev = ctx.textAlign;
		ctx.textAlign = 'left';
		var m = ctx.measureText(text);
		ctx.textAlign = prev;

		var adv = (m && typeof m.width === 'number' && isFinite(m.width)) ? m.width : 0;
		var ink = (m && typeof m.actualBoundingBoxRight === 'number' && isFinite(m.actualBoundingBoxRight))
			? m.actualBoundingBoxRight : 0;
		return Math.max(adv, ink, 0);
	}

	/**
	 * fillText, который считает выравнивание сам.
	 *
	 * Внешне ведёт себя как обычный ctx.fillText и уважает текущий
	 * ctx.textAlign — вся арифметика раскладки в растре осталась прежней.
	 * Разница в том, что смещение берётся из textWidth(), а не из
	 * внутреннего адванса браузера.
	 */
	function fillText(ctx, text, x, y) {
		var align = ctx.textAlign;
		if ('right' !== align && 'center' !== align) {
			ctx.fillText(text, x, y);
			return;
		}
		var w = textWidth(ctx, text);
		ctx.textAlign = 'left';
		ctx.fillText(text, 'right' === align ? x - w : x - w / 2, y);
		ctx.textAlign = align;
	}

	// Доли ширины области печати. Числитель — значение из CSS, знаменатель —
	// ширина области печати на десктопе (318px), при которой clamp() насыщены.
	var R = {
		titleLine:   0.118,   // aspect-ratio 1000/118 у строки заголовка
		titleStroke: 0.007,   // stroke-width 7 в системе координат viewBox
		titleFaceStroke: 0.003, // stroke-width 3 поверх контурного начертания Horizon
		titleGap:    0.0126,  // margin-bottom 4px
		photoTop:    0.0063,  // margin-top 2px
		// блочный низ ужат примерно на четверть, чтобы фото
		// досталось больше места в пределе 30 × 44. Числа — из CSS
		// (.tplp-oy__*), менять вместе.
		nameTop:     0.0094,  // margin-top 3px
		nameSize:    0.0755,  // clamp(12px, 4vw, 24px)
		// decorSize убран: кегль декора считается от капители имени
		// через decorScale(), а не задаётся долей ширины.
		nameGap:     0.0189,  // column-gap 6px
		footTop:     0.0157,  // margin-top 5px
		sideW:       0.43,    // flex-basis левой колонки
		phraseW:     0.52,    // flex-basis правой колонки (зазор 5%)
		trackSize:   0.0409,  // clamp(8px, 2.6vw, 13px)
		barH:        0.0094,  // height 3px
		barGap:      0.0157,  // margin 5px
		knob:        0.0283,  // 9px
		capSize:     0.0472,  // clamp(9px, 2.8vw, 15px)
		phraseSize:  0.0535,  // clamp(10px, 3vw, 17px)
		phraseMin:   0.0472,  // пол кегля = кегль подписи под штрихкодом
		gridTop:     0.0126,  // margin-top 4px у .tplp-grid
		dateSize:    0.044,   // clamp(10px, 2.6vw, 14px)
		footerSize:  0.0377,  // clamp(9px, 2.2vw, 12px)
		singleName:  0.107,   // clamp(18px, 6vw, 34px)
		singleGap:   0.0189,  // margin-bottom 6px
		singleBot:   0.0818,  // clamp(14px, 4.5vw, 26px)
		polCap:      0.0377   // clamp(8px, 2.2vw, 12px)
	};

	function px(frac) { return frac * RW; }

	/* иконки плеера — 78% ширины колонки (было 100%), штрихкод
	   10 : 2.2 (было 10 : 3). В CSS: .tplp-oy__icons, .tplp-oy__barcode. */
	var PLAYER_ICONS_K = 0.78;
	var BARCODE_K = 0.22;

	/* Перебор высоты растра над PRINT_MAX_ASPECT, px. Считается сухим
	   прогоном в paint() и вычитается из области фото — см. squeezeH. */
	var rasterSqueeze = 0;

	function squeezeH(natural) {
		if (!rasterSqueeze) return natural;
		return Math.max(natural * PHOTO_MIN_K, natural - rasterSqueeze);
	}

	/** Цвета берутся от изделия: белая футболка — чёрная печать. */
	function rasterInk() {
		return state.garment.color === 'white' ? '#0b0b0b' : '#ffffff';
	}

	function rasterBg() {
		return state.garment.color === 'white' ? '#ededed' : '#111111';
	}

	/**
	 * Печатный файл с прозрачным фоном. Пока флаг поднят,
	 * paintPrint() не заливает фон, «вырезы» цветом футболки делаются
	 * прозрачными (rasterKnockout), а полупрозрачные элементы (контур
	 * заголовка, полоса плеера) рисуются непрозрачными: частичная
	 * прозрачность в DTF/DTG уходит в растр подложки. Схема и превью —
	 * как раньше, на фоне футболки.
	 */
	var rasterClear = false;

	function rasterAlpha(a) { return rasterClear ? 1 : a; }

	/** Залить «цветом фона»: в прозрачном печатном файле — вырезать. */
	function rasterKnockout(ctx, x, y, w, h) {
		ctx.save();
		if (rasterClear) {
			ctx.globalCompositeOperation = 'destination-out';
			ctx.fillStyle = '#000';
		} else {
			ctx.fillStyle = rasterBg();
		}
		ctx.fillRect(x, y, w, h);
		ctx.restore();
	}

	/**
	 * Шрифт макета для canvas.
	 *
	 * Стек, а не одна гарнитура: canvas подбирает шрифт поглифно теми же
	 * правилами, что и вёрстка, поэтому кириллица уходит на Oswald сама —
	 * ветки по языку тут не нужны.
	 */
	function dispFont(size, weight) {
		return (weight || 700) + ' ' + Math.round(size) + 'px ' + TPL_FONT;
	}

	/**
	 * Текст, растянутый ровно на заданную ширину — аналог
	 * `textLength` + `lengthAdjust="spacingAndGlyphs"` в превью.
	 * Масштаб по X применяется к трансформации, поэтому обводка по
	 * горизонтали тоже растягивается: для схемы это допустимо, а
	 * компенсировать её пришлось бы разной толщиной по осям.
	 */
	function drawStretched(ctx, text, x, y, w, size, outline) {
		if (!text) return;

		// Те же два решения, что в titleLineSvg(): латинская строка идёт
		// увеличенным кеглем (капитель Horizon ниже) и настоящим контурным
		// начертанием вместо обводки. Кириллица остаётся на Oswald.
		var cyr = hasCyr(text);
		var face = (outline && !cyr) ? TPL_FONT_OUTLINE : TPL_FONT;
		size = size * TPL_CAP_K; // и для кириллицы (Unbounded)

		ctx.save();
		ctx.font = '700 ' + Math.round(size) + 'px ' + face;
		ctx.textAlign = 'left';
		ctx.textBaseline = 'alphabetic';
		var m = textWidth(ctx, text);
		if (m > 0 && outline && !cyr) {
			// латинский контур — заливка контурного глифа плюс
			// утолщающая обводка. Рисовать их по очереди с прозрачностью
			// нельзя: там, где они перекрываются, альфа складывается, и
			// вдоль контура шла светлая полоса. Как `opacity` у SVG в
			// превью: сначала строка целиком на слое без прозрачности,
			// потом слой — с TITLE_OUTLINE_ALPHA.
			var k = w / m;
			var lw = px(R.titleFaceStroke);
			var pad = Math.ceil(lw * 2 + 2);
			var above = Math.ceil(size * 1.1 + pad);
			var below = Math.ceil(size * 0.35 + pad);
			var layer = document.createElement('canvas');
			layer.width = Math.ceil(w + pad * 2);
			layer.height = above + below;
			var lctx = layer.getContext('2d');
			lctx.font = ctx.font;
			lctx.textAlign = 'left';
			lctx.textBaseline = 'alphabetic';
			lctx.translate(pad, above);
			lctx.scale(k, 1);
			lctx.fillStyle = rasterInk();
			lctx.fillText(text, 0, 0);
			lctx.strokeStyle = rasterInk();
			lctx.lineJoin = 'round';
			lctx.lineWidth = lw / k;
			lctx.strokeText(text, 0, 0);

			ctx.globalAlpha = rasterAlpha(TITLE_OUTLINE_ALPHA);
			ctx.drawImage(layer, x - pad, y - above);
		} else if (m > 0) {
			ctx.translate(x, y);
			ctx.scale(w / m, 1);
			if (outline && cyr) {
				ctx.strokeStyle = rasterInk();
				ctx.globalAlpha = rasterAlpha(TITLE_OUTLINE_ALPHA);
				ctx.lineWidth = px(R.titleStroke) / (w / m);
				ctx.strokeText(text, 0, 0);
			} else {
				// Залитая строка. Латинский контур — веткой выше, слоем.
				ctx.fillStyle = rasterInk();
				fillText(ctx, text, 0, 0);
			}
		}
		ctx.restore();
	}

	/**
	 * Ч/Б для растра. В превью это `filter: grayscale(1) contrast(1.05)`,
	 * здесь — попиксельный пересчёт в офскрин-холст.
	 *
	 * `ctx.filter` не годится: его нет в Safari до 17, и тон молча уезжал бы
	 * в цвет — ровно то, что уже случилось с заказом. Пиксельный проход
	 * работает везде и стоит один раз на фото, а не на каждый кадр.
	 */
	function toMono(img) {
		var w = img.naturalWidth || img.width;
		var h = img.naturalHeight || img.height;
		if (!w || !h) return img;

		try {
			var cv = document.createElement('canvas');
			cv.width = w;
			cv.height = h;
			var ctx = cv.getContext('2d');
			if (!ctx) return img;
			ctx.drawImage(img, 0, 0);

			var d = ctx.getImageData(0, 0, w, h);
			var p = d.data;
			for (var i = 0; i < p.length; i += 4) {
				// Те же коэффициенты яркости, что у CSS-фильтра grayscale().
				var g = p[i] * 0.2126 + p[i + 1] * 0.7152 + p[i + 2] * 0.0722;
				// contrast(1.05) вокруг средней точки 127.5.
				g = (g - 127.5) * 1.05 + 127.5;
				g = g < 0 ? 0 : (g > 255 ? 255 : g);
				p[i] = p[i + 1] = p[i + 2] = g;
			}
			ctx.putImageData(d, 0, 0);
			return cv;
		} catch (e) {
			// Испорченный холст — отдаём цветной оригинал, это лучше пустоты.
			return img;
		}
	}

	/**
	 * Бюджет площади одного фото для растра, пикселей.
	 *
	 * Растр строится из ОРИГИНАЛОВ с телефона. iOS Safari не создаёт холст
	 * больше ~16.7 Мп и держит общий лимит памяти холстов — при переборе он
	 * не бросает ошибку, а молча отдаёт ПУСТОЙ холст. Фото с iPhone 15+
	 * (24 Мп) и 48 Мп кадры уходили в заказ пустыми слотами без пунктира
	 * (заказ #598). Поэтому каждое фото ужимается заранее: общий бюджет
	 * делится на число фото, плюс потолок на одно фото.
	 *
	 * 8 Мп ≈ 3464×2309 — хватает на слот во всю ширину принта 30 см
	 * (~290 ppi), на коллаж из 3–9 фото — с запасом.
	 */
	function photoAreaBudget(n) {
		var ios = isIOS();
		var total = ios ? 30000000 : 80000000;
		var cap   = ios ? 8000000  : 16000000;
		return Math.max(1500000, Math.min(cap, total / Math.max(1, n)));
	}

	/** Холст реально что-то содержит? Пустой (прозрачный) = Safari не справился. */
	function canvasHasInk(src) {
		try {
			var probe = document.createElement('canvas');
			probe.width = 8;
			probe.height = 8;
			var pc = probe.getContext('2d');
			if (!pc) return true; // проверить нечем — не мешаем
			pc.drawImage(src, 0, 0, 8, 8);
			var d = pc.getImageData(0, 0, 8, 8).data;
			for (var i = 3; i < d.length; i += 4) {
				if (d[i] > 0) return true;
			}
			return false;
		} catch (e) {
			return true; // испорченный (tainted) холст — не наш случай
		}
	}

	/**
	 * Фото для растра: ужать под бюджет (+ ч/б по желанию) и убедиться, что
	 * результат не пустой. Цепочка отката: ужатое ч/б → ужатое цветное →
	 * оригинал как есть. Цветное фото в макете лучше пустого слота.
	 */
	function prepPhoto(img, budget, mono) {
		var w = img.naturalWidth || img.width;
		var h = img.naturalHeight || img.height;
		if (!w || !h) return img;

		var src = img;
		var k = Math.min(1, Math.sqrt(budget / (w * h)));
		if (k < 1) {
			try {
				var cv = document.createElement('canvas');
				cv.width = Math.max(1, Math.round(w * k));
				cv.height = Math.max(1, Math.round(h * k));
				var ctx = cv.getContext('2d');
				if (ctx) {
					ctx.imageSmoothingEnabled = true;
					ctx.imageSmoothingQuality = 'high';
					ctx.drawImage(img, 0, 0, cv.width, cv.height);
					if (canvasHasInk(cv)) src = cv;
				}
			} catch (e) { src = img; }
		}

		if (!mono) return src;
		var m = toMono(src);
		if (m !== src && canvasHasInk(m)) return m;
		return src; // ч/б не вышло — цветное, но не пустое
	}

	/** Фото по принципу object-fit:cover + object-position:50% 50%. */
	function drawCover(ctx, img, x, y, w, h) {
		if (!img) return;
		// Замер геометрии для редактора кадра: рамка слота как на печати.
		if (img.__probe) {
			if (w > 0 && h > 0) state.slotGeo[img.__slot] = { aspect: w / h, frac: w / RW };
			return;
		}
		var iw = img.naturalWidth || img.width;
		var ih = img.naturalHeight || img.height;
		if (!iw || !ih || !(w > 0) || !(h > 0)) return;
		// кадр клиента (img.__fit) — та же cropRect, что в превью.
		// Без кадра результат совпадает с прежним cover по центру.
		var r = cropRect(iw, ih, w, h, img.__fit || null);
		// Окно может выходить за фото (уменьшение «вписать»): исходный
		// прямоугольник drawImage за границей картинки в разных браузерах
		// ведёт себя по-разному, поэтому рисуем только пересечение.
		var ax = Math.max(0, r.sx), ay = Math.max(0, r.sy);
		var bx = Math.min(iw, r.sx + r.sw), by = Math.min(ih, r.sy + r.sh);
		if (bx > ax && by > ay) {
			ctx.drawImage(img, ax, ay, bx - ax, by - ay,
				x + (ax - r.sx) * r.s, y + (ay - r.sy) * r.s, (bx - ax) * r.s, (by - ay) * r.s);
		}
		if (img.__slot != null) {
			state.cropOut[img.__slot] = {
				x: r4(r.sx / iw), y: r4(r.sy / ih), w: r4(r.sw / iw), h: r4(r.sh / ih)
			};
		}
	}

	/** Пустой слот — пунктирная рамка, как в превью. */
	function drawEmptyBox(ctx, x, y, w, h) {
		ctx.save();
		ctx.strokeStyle = rasterInk();
		ctx.globalAlpha = 0.28;
		ctx.setLineDash([px(0.008), px(0.008)]);
		ctx.lineWidth = Math.max(1, px(0.002));
		ctx.strokeRect(x, y, w, h);
		ctx.restore();
	}

	/** Перенос по словам с запасным разрывом внутри длинного слова. */
	function wrapText(ctx, text, maxW) {
		var words = String(text).split(/\s+/).filter(Boolean);
		var lines = [];
		var cur = '';

		function pushChunked(word) {
			var chunk = '';
			for (var i = 0; i < word.length; i++) {
				if (textWidth(ctx, chunk + word[i]) > maxW && chunk) {
					lines.push(chunk);
					chunk = word[i];
				} else {
					chunk += word[i];
				}
			}
			return chunk;
		}

		words.forEach(function (w) {
			var probe = cur ? cur + ' ' + w : w;
			if (textWidth(ctx, probe) <= maxW) { cur = probe; return; }
			if (cur) { lines.push(cur); cur = ''; }
			cur = textWidth(ctx, w) > maxW ? pushChunked(w) : w;
		});

		if (cur) lines.push(cur);
		return lines;
	}

	/**
	 * Подгон кегля под N строк — растровый аналог fitLines().
	 * Возвращает { size, lines }.
	 */
	function fitWrapped(ctx, text, maxW, size, maxLines, minSize) {
		for (var i = 0; i < 12; i++) {
			ctx.font = dispFont(size);
			var lines = wrapText(ctx, text, maxW);
			if (lines.length <= maxLines || size <= minSize) {
				return { size: size, lines: lines.slice(0, maxLines) };
			}
			size = Math.max(minSize, size * 0.92);
		}
		ctx.font = dispFont(size);
		return { size: size, lines: wrapText(ctx, text, maxW).slice(0, maxLines) };
	}

	/* ── Заголовок: N строк, первая залитая, остальные контуром ── */
	function rasterTitles(ctx, y) {
		var t = tpl();
		if (!t.texts[titleKey()]) return y;

		var repeat = parseInt(state.styles.title_repeat || '3', 10);
		var head = titleText();
		if (!head) return y;

		var lineH = px(R.titleLine);
		// Базовая линия внутри viewBox 1000×118 стоит на y=116 при кегле 160,
		// то есть на 0.983 высоты строки.
		for (var i = 0; i < repeat; i++) {
			drawStretched(ctx, head, 0, y + lineH * 0.983, RW, lineH * 1.355, i !== 0);
			y += lineH;
		}
		return y + px(R.titleGap);
	}

	/**
	 * Сколько чернил легло ВЫШЕ нуля раскладки — замером, а не формулой.
	 *
	 * Раскладка считается от нуля вниз, но часть чернил уходит вверх: в
	 * rasterTitles() базовая линия первой строки стоит на 0.983·lineH, а
	 * капитель при кегле 1.355·lineH — 1.098·lineH, и верхушка буквы
	 * оказывается на −0.115·lineH. В превью это видно (у .tplp-titlesvg
	 * стоит overflow:visible), в растре срезалось отсечкой.
	 *
	 * Сухой прогон рисуется со сдвигом на SLACK, поэтому вынос попадает в
	 * полосу над нулём и его достаточно найти сканом по альфе. Формулу по
	 * метрикам шрифта пришлось бы держать в синхроне с каждым движком и
	 * каждой правкой кеглей; скан верен для любого содержимого.
	 *
	 * titleOverhangByMetrics() остаётся запасным путём: getImageData()
	 * бросает SecurityError на испорченном холсте. Сейчас фото приходят
	 * из object URL и холст не портят (иначе не работал бы и toDataURL),
	 * но расчёт по капители дешевле, чем срезанный заголовок.
	 */
	function rasterTopOverhang(probe) {
		var w = probe.width;
		try {
			var d = probe.getContext('2d').getImageData(0, 0, w, SLACK).data;
			for (var y = 0; y < SLACK; y++) {
				var row = y * w * 4;
				for (var x = 0; x < w; x++) {
					if (d[row + x * 4 + 3] > 8) return SLACK - y;
				}
			}
			return 0;
		} catch (e) {
			return titleOverhangByMetrics();
		}
	}

	/**
	 * Запасной расчёт выноса — по номиналу капители заголовка.
	 *
	 * Меряется на живом шрифте, а не берётся из констант: если Horizon не
	 * догрузился и строка ушла на Oswald, вынос будет другим.
	 */
	function titleOverhangByMetrics() {
		var t = tpl();
		if (!t || !t.texts || !t.texts[titleKey()]) return 0;
		// У рукописного варианта «Одно фото + имя» повтора нет.
		if (t.engine === 'single' && !singleBlock()) return 0;

		var head = titleText();
		if (!head) return 0;

		var lineH = px(R.titleLine);
		var cyr   = hasCyr(head);
		// Те же два решения, что в drawStretched(): кегль латиницы
		// увеличен на TPL_CAP_K; первая строка — залитая, значит TPL_FONT.
		var size  = lineH * 1.355 * TPL_CAP_K; // кириллица тоже

		var asc = 0;
		try {
			var mc = document.createElement('canvas').getContext('2d');
			mc.font = '700 ' + Math.round(size) + 'px ' + TPL_FONT;
			mc.textAlign = 'left';
			mc.textBaseline = 'alphabetic';
			var m = mc.measureText(head);
			if (m && typeof m.actualBoundingBoxAscent === 'number' && isFinite(m.actualBoundingBoxAscent)) {
				asc = m.actualBoundingBoxAscent;
			}
		} catch (e) { asc = 0; }

		if (asc <= 0) asc = size * (cyr ? 0.810 : 0.7007);

		return Math.max(0, Math.ceil(asc - lineH * 0.983));
	}

	/* ── Строка «декор + имя» у ONLY YOU ── */
	function rasterNameRow(ctx, y) {
		var name = footNameText();
		var decor = state.styles.name_decor || 'arrows';
		var ch = 'slashes' === decor ? '/' : (('none' === decor) ? '' : '\u276F');
		if (!name && !ch) return y;

		y += px(R.nameTop);

		var left = 'left' === state.styles.name_side;

		var size = px(R.nameSize);
		ctx.font = dispFont(size);
		var nameW = textWidth(ctx, name.toUpperCase());
		var limit = RW * (ch ? 0.66 : 1);
		if (nameW > limit && nameW > 0) {
			size = size * (limit / nameW);
			ctx.font = dispFont(size);
			nameW = textWidth(ctx, name.toUpperCase());
		}

		ctx.fillStyle = rasterInk();
		ctx.textBaseline = 'alphabetic';
		ctx.textAlign = left ? 'left' : 'right';
		fillText(ctx, name.toUpperCase(), left ? 0 : RW, y + size);

		if (ch) {
			var free = RW - nameW - px(R.nameGap);

			// «Половина строки» — то же ограничение, что в превью.
			if ('half' === state.styles.decor_fill) {
				free = Math.min(free, RW * 0.5);
			}

			// Кегль декора — от высоты капители имени, как в превью.
			// decorScale() меряет тем же способом (фактические границы
			// чернил на canvas), поэтому пропорции совпадают.
			var dsize = size * decorScale(ch, TPL_FONT, capProbe(name));
			ctx.font = dispFont(dsize);
			var chW = textWidth(ctx, ch) + dsize * decorTracking(ch);
			if (chW > 0 && free > chW) {
				// Остаток раскидывается в шаг, а не копится с краю: иначе между
				// декором и именем висела пустота, которой в превью нет —
				// там fitNameRow() добирает разницу через letter-spacing.
				var n = Math.floor(free / chW);
				var step = free / n;
				// Декор всегда с противоположной имени стороны и прижат
				// к своему краю печати.
				var x0 = left ? (RW - free) : 0;
				ctx.textAlign = 'left';
				for (var i = 0; i < n; i++) {
					fillText(ctx, ch, x0 + i * step, y + size);
				}
			}
		}

		ctx.textAlign = 'left';
		return y + size;
	}

	/* ── Левый блок низа: плеер / штрихкод / текст / QR ── */
	function rasterFootLeft(ctx, x, y, w) {
		var kind = state.styles.footer_left || 'player';
		var ink = rasterInk();

		if ('player' === kind) {
			var track = (state.texts.track || '').trim().toUpperCase();
			// Тот же множитель Horizon, что стоит на .tplp-oy__track
			// в превью: растр обязан совпасть с ним построчно.
			var ts = px(R.trackSize) * fontK(track);
			ctx.font = dispFont(ts);
			ctx.fillStyle = ink;
			ctx.textAlign = 'center';
			ctx.textBaseline = 'alphabetic';
			var tl = wrapText(ctx, track, w);
			tl.forEach(function (line, i) {
				fillText(ctx, line, x + w / 2, y + ts * (1 + i * 1.2));
			});
			var cy = y + ts * 1.2 * Math.max(tl.length, 1) + px(R.barGap);

			ctx.fillStyle = ink;
			ctx.globalAlpha = rasterAlpha(0.85);
			ctx.fillRect(x, cy, w, px(R.barH));
			ctx.globalAlpha = 1;
			ctx.beginPath();
			ctx.arc(x + w * 0.3, cy + px(R.barH) / 2, px(R.knob) / 2, 0, Math.PI * 2);
			ctx.fill();

			var iy = cy + px(R.barH) + px(R.barGap);
			var iw = w * PLAYER_ICONS_K;
			rasterPlayerIcons(ctx, x + (w - iw) / 2, iy, iw);
			ctx.textAlign = 'left';
			return iy + iw * (70 / 300);
		}

		if ('barcode' === kind) {
			var date = (state.texts.date || '').trim();
			var bh = w * BARCODE_K; // aspect-ratio у .tplp-oy__barcode
			var rnd = rngFrom(seedFrom(date || 'since'));
			ctx.fillStyle = ink;
			var bx = x;
			while (bx < x + w - 2) {
				var bw = (1 + Math.floor(rnd() * 4)) * (w / 200);
				ctx.fillRect(bx, y, Math.min(bw, x + w - bx), bh);
				bx += bw + (1 + Math.floor(rnd() * 3)) * (w / 200);
			}
			var cs = px(R.capSize) * fontK(date);
			ctx.font = dispFont(cs);
			// Одной строкой по ширине штрихкода — как fitOneLine() в превью.
			// Трекинг .04em из CSS учтён запасом в 4%.
			var cw = textWidth(ctx, date.toUpperCase()) * 1.04;
			if (cw > w && cw > 0) {
				cs = cs * (w / cw) * 0.97;
				ctx.font = dispFont(cs);
			}
			ctx.textAlign = 'center';
			fillText(ctx, date.toUpperCase(), x + w / 2, y + bh + px(R.nameTop) + cs);
			ctx.textAlign = 'left';
			return y + bh + px(R.nameTop) + cs;
		}

		if ('qr' === kind) {
			var side = Math.min(w * 0.72, px(0.346));
			rasterQr(ctx, x + (w - side) / 2, y, side, state.texts.qr || location.hostname);
			return y + side;
		}

		if ('text' === kind) {
			var note = (state.texts.note || '').trim().toUpperCase();
			var ns = px(R.capSize) * fontK(note);
			ctx.font = dispFont(ns);
			ctx.fillStyle = ink;
			ctx.textAlign = 'left';
			ctx.textBaseline = 'alphabetic';
			var nl = wrapText(ctx, note, w).slice(0, 4);
			nl.forEach(function (line, i) {
				fillText(ctx, line, x, y + ns * (1 + i * 1.25));
			});
			return y + ns * 1.25 * Math.max(nl.length, 1);
		}

		return y;
	}

	/** Иконки плеера — та же геометрия, что в SVG превью (viewBox 300×70). */
	function rasterPlayerIcons(ctx, x, y, w) {
		var k = w / 300;
		var ink = rasterInk();
		ctx.save();
		ctx.translate(x, y);
		ctx.scale(k, k);
		ctx.strokeStyle = ink;
		ctx.fillStyle = ink;
		ctx.lineWidth = 4;
		ctx.lineCap = 'round';
		ctx.lineJoin = 'round';

		function line(pts) {
			ctx.beginPath();
			ctx.moveTo(pts[0], pts[1]);
			for (var i = 2; i < pts.length; i += 2) ctx.lineTo(pts[i], pts[i + 1]);
			ctx.stroke();
		}
		function tri(pts) {
			ctx.beginPath();
			ctx.moveTo(pts[0], pts[1]);
			ctx.lineTo(pts[2], pts[3]);
			ctx.lineTo(pts[4], pts[5]);
			ctx.closePath();
			ctx.fill();
		}

		// перемешать
		line([16, 26, 22, 26, 38, 44, 44, 44]);
		line([16, 44, 22, 44, 38, 26, 44, 26]);
		line([38, 20, 44, 26, 38, 32]);
		line([38, 38, 44, 44, 38, 50]);
		// предыдущий
		line([80, 21, 80, 49]);
		tri([102, 21, 102, 49, 84, 35]);
		// play в круге
		ctx.beginPath();
		ctx.arc(150, 35, 29, 0, Math.PI * 2);
		ctx.stroke();
		tri([141, 20, 169, 35, 141, 50]);
		// следующий
		line([220, 21, 220, 49]);
		tri([198, 21, 216, 35, 198, 49]);
		// повтор
		ctx.beginPath();
		ctx.moveTo(266, 22);
		ctx.lineTo(278, 22);
		ctx.arcTo(286, 22, 286, 30, 8);
		ctx.lineTo(286, 40);
		ctx.arcTo(286, 48, 278, 48, 8);
		ctx.lineTo(262, 48);
		ctx.arcTo(254, 48, 254, 40, 8);
		ctx.lineTo(254, 32);
		ctx.stroke();
		line([262, 15, 255, 22, 262, 29]);

		ctx.restore();
	}

	/** QR — схематичный, тот же детерминированный узор, что в превью. */
	function rasterQr(ctx, x, y, side, seed) {
		var n = 21;
		var c = side / n;
		var rnd = rngFrom(seedFrom(seed));
		ctx.save();
		ctx.fillStyle = rasterInk();

		function isEye(r, col) {
			return (r < 7 && col < 7) || (r < 7 && col >= n - 7) || (r >= n - 7 && col < 7);
		}
		for (var r = 0; r < n; r++) {
			for (var col = 0; col < n; col++) {
				if (isEye(r, col)) continue;
				if (rnd() > 0.52) ctx.fillRect(x + col * c, y + r * c, c, c);
			}
		}
		function eye(ex, ey) {
			ctx.fillStyle = rasterInk();
			ctx.fillRect(x + ex * c, y + ey * c, 7 * c, 7 * c);
			rasterKnockout(ctx, x + (ex + 1) * c, y + (ey + 1) * c, 5 * c, 5 * c);
			ctx.fillStyle = rasterInk();
			ctx.fillRect(x + (ex + 2) * c, y + (ey + 2) * c, 3 * c, 3 * c);
		}
		eye(0, 0); eye(n - 7, 0); eye(0, n - 7);
		ctx.restore();
	}

	/**
	 * Блочный низ: строка «декор + имя» и две колонки под ней.
	 * Общий для ONLY YOU и постеров — ровно как footerBlockHtml() в превью.
	 */
	function rasterFooterBlock(ctx, y) {
		y = rasterNameRow(ctx, y);
		y += px(R.footTop);

		var sideW = RW * R.sideW;
		var phrW = RW * R.phraseW;
		var leftBottom = rasterFootLeft(ctx, 0, y, sideW);

		var phrase = (state.texts.phrase || '').trim();
		var phraseBottom = y;
		if (phrase) {
			// Множитель применяется и к полу: в превью его несёт зонд
			// .tplp-oy__minprobe с тем же --tplp-k.
			var pk = fontK(phrase);
			var fit = fitWrapped(ctx, phrase, phrW, px(R.phraseSize) * pk, 4, px(R.phraseMin) * pk);
			ctx.fillStyle = rasterInk();
			ctx.textAlign = 'right';
			ctx.textBaseline = 'alphabetic';
			fit.lines.forEach(function (line, i) {
				fillText(ctx, line, RW, y + fit.size * (1 + i * 1.15));
			});
			ctx.textAlign = 'left';
			phraseBottom = y + fit.size * 1.15 * fit.lines.length;
		}

		return Math.max(leftBottom, phraseBottom);
	}

	/* ── Движок onlyyou ── */
	function rasterOnlyYou(ctx, imgs) {
		var lay = activeLayout();
		var y = rasterTitles(ctx, 0);

		y += px(R.photoTop);
		var ph = squeezeH(RW * (lay && lay.aspect ? lay.aspect : 1));
		if (imgs[0]) {
			drawCover(ctx, imgs[0], 0, y, RW, ph);
		} else {
			drawEmptyBox(ctx, 0, y, RW, ph);
		}
		y += ph;

		return rasterFooterBlock(ctx, y);
	}

	/* ── Движок poster ── */
	function rasterPoster(ctx, imgs) {
		var lay = activeLayout();
		var y = rasterTitles(ctx, 0);

		y += px(R.gridTop);
		var gh = squeezeH(RW * (lay && lay.aspect ? lay.aspect : 1.05));
		(lay ? lay.slots : []).forEach(function (s, i) {
			var cx = s.x * RW, cy = y + s.y * gh, cw = s.w * RW, chh = s.h * gh;
			if (imgs[i]) drawCover(ctx, imgs[i], cx, cy, cw, chh);
			else drawEmptyBox(ctx, cx, cy, cw, chh);
		});
		y += gh;

		// Блочный низ рисуется теми же функциями, что у ONLY YOU.
		if ('simple' !== (state.styles.footer_kind || 'block')) {
			return rasterFooterBlock(ctx, y);
		}

		var date = (state.texts.date || '').trim();
		if (date) {
			var ds = px(R.dateSize);
			ctx.font = dispFont(ds);  // 700, как в превью (было 400)
			// длинная дата не должна вылезать за принт — ужимаем кегль.
			var dw = textWidth(ctx, date.toUpperCase());
			if (dw > RW) { ds = ds * RW / dw; ctx.font = dispFont(ds); }
			ctx.fillStyle = rasterInk();
			ctx.textAlign = 'center';
			y += px(R.gridTop);
			fillText(ctx, date.toUpperCase(), RW / 2, y + ds);
			y += ds;
		}

		var fs = px(R.footerSize);
		ctx.font = dispFont(fs);  // 700, как в превью (было 400)
		ctx.fillStyle = rasterInk();
		y += px(R.footTop);
		// только подпись по центру, имени в простом низе нет.
		var caption = (state.texts.caption || '').trim().toUpperCase();
		if (caption) {
			// перенос по словам, как в превью (.tplp-footer — обычный
			// блок с переносами). Раньше строка шла одна и обрезалась по краям
			// принта: с широкой кириллицей Horizon это стало заметно.
			ctx.textAlign = 'center';
			wrapText(ctx, caption, RW).forEach(function (line, i) {
				if (i) y += fs * 0.25; // межстрочный, как line-height ≈ 1.25 в превью
				fillText(ctx, line, RW / 2, y + fs);
				y += fs;
			});
		}
		ctx.textAlign = 'left';
		return y;
	}

	/* ── Движок single ── */
	function rasterSingle(ctx, imgs) {
		// Блочный вариант — те же шаги, что у ONLY YOU: повтор заголовка,
		// кадр на всю ширину, строка имени и низ со штрихкодом.
		if (singleBlock()) {
			var by = rasterTitles(ctx, 0);
			by += px(R.photoTop);
			var bh = squeezeH(RW * SINGLE_BLOCK_ASPECT);
			if (imgs[0]) drawCover(ctx, imgs[0], 0, by, RW, bh);
			else drawEmptyBox(ctx, 0, by, RW, bh);
			by += bh;
			return rasterFooterBlock(ctx, by);
		}

		var w = 'medium' === state.styles.photo_size ? RW * 0.58 : RW;
		var x = (RW - w) / 2;

		// Имя и подпись — в ширину кадра, как в превью (fitSingle).
		var y = 0;
		var name = (state.texts.name || '').trim().toUpperCase();
		var ns = px(R.singleName);
		if (name) {
			ns = sizeToWidth(name, singleNameFont100(), w, w * SINGLE_NAME_CAP);
			ctx.font = dispFont(ns);
			ctx.fillStyle = rasterInk();
			ctx.textAlign = 'center';
			ctx.textBaseline = 'alphabetic';
			fillText(ctx, name, RW / 2, y + ns * 0.8);
			y += ns * 0.8 + px(R.singleGap);
		}

		var isPol = 'polaroid' === state.styles.frame;
		var pad = isPol ? w * 0.06 : 0;
		var padBottom = isPol ? w * 0.16 : 0;
		var inner = w - pad * 2;
		var ih = squeezeH(inner * 1.25); // aspect-ratio 4 / 5

		if (isPol) {
			ctx.fillStyle = '#fff';
			ctx.fillRect(x, y, w, ih + pad + padBottom);
		}
		if (imgs[0]) drawCover(ctx, imgs[0], x + pad, y + pad, inner, ih);
		else drawEmptyBox(ctx, x + pad, y + pad, inner, ih);
		y += ih + pad + padBottom;

		var bottom = (state.texts.bottom || '').trim();
		if (bottom) {
			var bs = sizeToWidth(bottom, singleBotFont100(), w, w * SINGLE_BOT_CAP);
			ctx.font = 'italic ' + Math.round(bs) + 'px ' + scriptFamily();
			ctx.fillStyle = inkColor();
			ctx.textAlign = 'center';
			y += px(R.footTop);
			ctx.save();
			if ('tilt' === state.styles.bottom_tilt) {
				ctx.translate(RW / 2, y + bs);
				ctx.rotate(-3 * Math.PI / 180);
				fillText(ctx, bottom, 0, 0);
			} else {
				fillText(ctx, bottom, RW / 2, y + bs);
			}
			ctx.restore();
			// Запас под нижние выносные рукописного шрифта.
			y += bs * 1.25;
		}
		ctx.textAlign = 'left';
		return y;
	}

	/* ── Движок polaroid ── */
	function rasterPolaroid(ctx, imgs) {
		var lay = activeLayout();
		var n = (lay && lay.slots) ? lay.slots.length : 2;

		var gap = RW * 0.05;
		// Одиночный кадр — не во всю ширину: полароид во всю грудь читается
		// как плакат. Доля взята из слота раскладки, чтобы растр и превью
		// не разъезжались.
		var w = (1 === n)
			? RW * ((lay && lay.slots[0] && lay.slots[0].w) || 0.56)
			: (RW - gap) / 2;
		var x0 = (1 === n) ? (RW - w) / 2 : 0;
		var pad = w * 0.06;
		var inner = w - pad * 2;
		var cs = px(R.polCap);
		var capH = cs * 2.4 + w * 0.04;
		var cardH = pad + inner + capH;

		for (var i = 0; i < n; i++) {
			var x = x0 + i * (w + gap);
			ctx.fillStyle = '#fff';
			ctx.fillRect(x, 0, w, cardH);
			if (imgs[i]) drawCover(ctx, imgs[i], x + pad, pad, inner, inner);
			else { ctx.fillStyle = '#ddd'; ctx.fillRect(x + pad, pad, inner, inner); }

			// Тот же почерк и цвет, что в превью: иначе печатник получит
			// макет, которого клиент не видел.
			ctx.font = 'italic ' + Math.round(cs) + 'px ' + scriptFamily();
			ctx.fillStyle = inkColor();
			ctx.textAlign = 'center';
			var cap = (state.texts['cap' + (i + 1)] || '').trim();
			wrapText(ctx, cap, inner).slice(0, 2).forEach(function (line, j) {
				fillText(ctx, line, x + w / 2, pad + inner + cs * (1.4 + j * 1.25));
			});
		}
		ctx.textAlign = 'left';
		return cardH;
	}

	/* ── Движок papa ──
	   Та же геометрия papaLayout(), что у превью, — в долях ширины.
	   Высота всегда 1.6 ширины (25 × 40 см), даже без подписи. */
	function rasterPapa(ctx, imgs) {
		var geo = papaLayout();

		geo.photos.forEach(function (s, i) {
			var x = px(s.x), y = px(s.y), w = px(s.w), h = px(s.h);
			if (imgs[i]) drawCover(ctx, imgs[i], x, y, w, h);
			else drawEmptyBox(ctx, x, y, w, h);
		});

		ctx.fillStyle = rasterInk();
		ctx.textAlign = 'left';
		ctx.textBaseline = 'alphabetic';

		ctx.font = '400 ' + Math.round(px(geo.letterSize)) + 'px ' + PAPA_LETTER_FONT;
		geo.letters.forEach(function (l) {
			fillText(ctx, l.ch, px(l.x), px(l.y));
		});

		var c = geo.caption;
		if (c.text) {
			ctx.font = c.weight + ' ' + Math.round(px(c.size)) + 'px ' + PAPA_CAP_FONT;
			ctx.textAlign = 'center';
			fillText(ctx, c.text, px(c.x), px(c.y));
			ctx.textAlign = 'left';
		}

		return px(geo.H);
	}

	/* ── Движок letters ── */
	/* Каждая буква собирается на отдельном слое: глиф (или сплошной
	   силуэт) → фото поверх в режиме source-in → слой на холст.
	   Раньше source-atop применялся прямо к холсту заказа, а он к этому
	   моменту уже залит фоном футболки — фото ложилось прямоугольником
	   поверх всего слота, а не в форму буквы. */
	function rasterLetters(ctx, imgs) {
		var word = (state.texts.word || '').trim().toUpperCase();
		if (!word) return 0;

		var solid = 'solid' === state.styles.letter_fill;
		var chars = Array.from(word);

		// Блочные «П»/«А»: фото — на всю клетку буквы.
		if (blockWord(word)) {
			var unit = RW * 0.99 / blockUnits(chars);
			var bh = Math.max(1, Math.round(unit));
			var bx = (RW - blockUnits(chars) * unit) / 2;
			chars.forEach(function (ch, i) {
				var bw = BLOCK_GLYPHS[ch].w * unit;
				var band = blockLayer(ch, bw, bh);
				var bc = band.getContext('2d');
				bc.globalCompositeOperation = 'source-in';
				if (imgs[i]) {
					drawCoverRaw(bc, imgs[i], 0, 0, band.width, band.height);
				} else {
					bc.fillStyle = rasterInk();
					bc.fillRect(0, 0, band.width, band.height);
				}
				ctx.save();
				if (!imgs[i]) ctx.globalAlpha = 0.28;
				ctx.drawImage(band, Math.round(bx), 0);
				ctx.restore();
				bx += bw + BLOCK_GAP * unit;
			});
			return bh;
		}

		// Метрики глифа: адванс + выносы чернил за него. У Horizon «A»
		// вылезает за свой адванс, и крайняя буква срезалась по краю
		// печати, когда ширина считалась только по адвансам.
		function metrics(sz) {
			ctx.font = dispFont(sz);
			var out = chars.map(function (ch) {
				var m = ctx.measureText(ch);
				var adv = textWidth(ctx, ch);
				var l = Math.max(0, isFinite(m.actualBoundingBoxLeft) ? m.actualBoundingBoxLeft : 0);
				var r = Math.max(adv, isFinite(m.actualBoundingBoxRight) ? m.actualBoundingBoxRight : adv);
				return {
					adv: adv, l: l, r: r,
					asc: isFinite(m.actualBoundingBoxAscent) ? m.actualBoundingBoxAscent : sz * 0.75,
					desc: Math.max(0, isFinite(m.actualBoundingBoxDescent) ? m.actualBoundingBoxDescent : 0)
				};
			});
			var w = 0;
			out.forEach(function (g) { w += g.adv; });
			w += out[0].l + (out[out.length - 1].r - out[out.length - 1].adv);
			return { list: out, width: w };
		}

		var size = RW * 0.3;
		var mt = metrics(size);
		if (mt.width > 0) {
			size = size * (RW / mt.width) * 0.99;
			mt = metrics(size);
		}
		var font = dispFont(size);
		ctx.font = font;

		// Общая полоса чернил на слово: фото вписывается в высоту букв,
		// а не в высоту строки с пустотой над капителью.
		var asc = 0, desc = 0;
		mt.list.forEach(function (g) { asc = Math.max(asc, g.asc); desc = Math.max(desc, g.desc); });

		// растр обрезан ровно по полосе чернил слова (раньше
		// сверху и снизу оставались пустые поля строки, и печатный файл
		// приходилось кадрировать руками) и вытянут по высоте так же,
		// как превью (lettersStretch).
		var K = lettersStretch(word);
		var h = size * 0.9;          // базовая линия в слое глифа
		var layerH = h * 1.2;
		var inkTop = Math.max(0, h - asc);
		var inkH = asc + desc;
		var bandH = Math.max(1, Math.ceil(inkH * K));
		var x = mt.list[0].l;        // первый вынос влево — внутрь печати
		chars.forEach(function (ch, i) {
			var g = mt.list[i];
			var lw = g.l + g.r;
			var glyph = glyphLayer(ch, font, lw, layerH, g.l, h, solid);

			// Полоса чернил глифа, вытянутая по высоте.
			var band = document.createElement('canvas');
			band.width = Math.max(1, Math.ceil(lw));
			band.height = bandH;
			var bc = band.getContext('2d');
			bc.drawImage(glyph, 0, inkTop, glyph.width, inkH, 0, 0, band.width, bandH);
			bc.globalCompositeOperation = 'source-in';
			if (imgs[i]) {
				drawCoverRaw(bc, imgs[i], 0, 0, band.width, bandH);
			} else {
				bc.fillStyle = rasterInk();
				bc.fillRect(0, 0, band.width, bandH);
			}

			ctx.save();
			if (!imgs[i]) ctx.globalAlpha = 0.28;
			ctx.drawImage(band, x - g.l, 0);
			ctx.restore();
			x += g.adv;
		});
		return bandH;
	}

	/**
	 * Собрать растр макета и отдать data URL.
	 *
	 * Порядок: дождаться шрифтов → догрузить изображения слотов → замерить
	 * высоту сухим прогоном по временному холсту → отрисовать набело.
	 * Двойной проход нужен, потому что высота зависит от переносов текста,
	 * а те — от метрик шрифта.
	 */
	function buildPreviewDataUrl(cb, wantPrint, measureOnly) {
		var t = tpl();
		if (!t) { cb('', null); return; }

		var sources = state.slots.map(function (s) {
			return s.poolIndex != null ? state.pool[s.poolIndex] : null;
		});

		var fontsReady = (document.fonts && document.fonts.load)
			// Второй аргумент у Horizon обязателен: unicode-range у обеих
			// гарнитур латинский, и на дефолтной пробе 'BESbswy' они бы
			// загрузились, а вот на кириллическом тексте — нет. Явная
			// латинская проба снимает зависимость от содержимого макета.
			? Promise.all([
				document.fonts.load('700 100px Oswald'),
				document.fonts.load('700 100px Horizon', 'AZ09'),
				document.fonts.load('700 100px Horizon', '\u0410\u042F'), // кириллица (своя, из глиф Horizon)
				document.fonts.load('700 100px "Horizon Outlined"', 'AZ09'),
				document.fonts.load('italic 40px ' + scriptFamily()),
				document.fonts.load('400 100px Montserrat', 'PA EST 2026'),
				document.fonts.load('700 100px Montserrat', 'EST 2026')
			]).catch(function () { return null; })
			: Promise.resolve(null);

		// только геометрия слотов растра — для редактора кадра.
		// Фото не нужны (на высоту макета они не влияют): вместо них
		// заглушки, которые drawCover лишь измеряет.
		if (measureOnly) {
			fontsReady.then(function () {
				state.slotGeo = {};
				try {
					paint(state.slots.map(function (s, i) {
						return { __probe: true, __slot: i, width: 1, height: 1 };
					}), true);
				} catch (e) { /* нет замера — редактор возьмёт пропорции из превью */ }
				RW = RW_BASE;
				rasterSqueeze = 0;
				cb(state.slotGeo);
			});
			return;
		}

		state.cropOut = {};
		fontsReady.then(function () {
			loadImages(sources, function (imgs) {
				try {
					// Тон применяется здесь, а не при отрисовке: обработка
					// одна на фото, а рисуется оно дважды — в сухом прогоне
					// для замера высоты и набело.
					// сначала ужимаем оригиналы под бюджет холста
					// (см. prepPhoto) — иначе 24–48 Мп фото с iPhone давали
					// пустой холст и пустой слот в макете заказа.
					var mono = 'mono' === state.styles.photo_tone;
					var budget = photoAreaBudget(imgs.filter(Boolean).length);
					imgs = imgs.map(function (img, i) {
						if (!img) return null;
						var out = prepPhoto(img, budget, mono);
						// Кадр и индекс слота едут вместе с картинкой: drawCover
						// вызывается из движков без знания о слотах.
						out.__fit = sources[i] && !isDefaultFit(sources[i].fit) ? sources[i].fit : null;
						out.__slot = i;
						return out;
					});
					var base = paint(imgs);
					if (!base.dataUrl || !wantPrint) {
						cb(base.dataUrl, null);
						return;
					}
					// Печатный файл — после схемы и отдельной попыткой:
					// упадёт по памяти — заказ уйдёт со схемой, как раньше.
					paintPrint(imgs, base.geo, function (print) {
						cb(base.dataUrl, print);
					});
				} catch (e) {
					RW = RW_BASE;
					rasterSqueeze = 0;
					cb('', null);
				}
			});
		});

		function loadImages(list, done) {
			var out = new Array(list.length);
			var left = list.length;
			if (!left) { done(out); return; }
			list.forEach(function (p, i) {
				if (!p || !p.url) { out[i] = null; if (!--left) done(out); return; }
				var img = new Image();
				img.onload = function () { out[i] = img; if (!--left) done(out); };
				img.onerror = function () { out[i] = null; if (!--left) done(out); };
				img.src = p.url;
			});
		}

		function drawAll(ctx, imgs) {
			if (t.engine === 'onlyyou')  return rasterOnlyYou(ctx, imgs);
			if (t.engine === 'poster')   return rasterPoster(ctx, imgs);
			if (t.engine === 'single')   return rasterSingle(ctx, imgs);
			if (t.engine === 'polaroid') return rasterPolaroid(ctx, imgs);
			if (t.engine === 'letters')  return rasterLetters(ctx, imgs);
			if (t.engine === 'papa')     return rasterPapa(ctx, imgs);
			return 0;
		}

		function paint(imgs, measureOnly) {
			// Сухой прогон: тот же код рисует в холст-заглушку только чтобы
			// узнать итоговую высоту. Иначе пришлось бы дублировать всю
			// арифметику раскладки отдельной функцией измерения.
			// Сдвиг на SLACK: часть чернил ложится ВЫШЕ нуля раскладки, и
			// без запаса замерить этот вынос было бы негде.
			function dry() {
				var probe = document.createElement('canvas');
				probe.width = RW;
				probe.height = RW * 3 + SLACK;
				var pctx = probe.getContext('2d');
				pctx.translate(0, SLACK);
				var hh = drawAll(pctx, imgs);
				// Холст получает эту полоску сверху, а ноль раскладки
				// опускается на неё — тогда верх букв встаёт ровно на
				// границу поля, а само поле остаётся равным padY.
				return { h: hh, over: (hh && isFinite(hh)) ? rasterTopOverhang(probe) : 0 };
			}

			// Предел печати 30 × 42: перебор уходит из области фото, и
			// прогон повторяется. Высота заголовка и низа от фото не
			// зависит, поэтому второго прохода хватает.
			rasterSqueeze = 0;
			var run = dry();
			if (!run.h || !isFinite(run.h) || run.h < 10) return { dataUrl: '', geo: null };
			var excess = run.h + run.over - RW * PRINT_MAX_ASPECT;
			if (excess > 1 && !printCapOff()) {
				rasterSqueeze = excess;
				run = dry();
			}
			var h = run.h;
			var over = run.over;
			if (measureOnly) { rasterSqueeze = 0; return null; }

			var padX = RW * 0.09;   // поля области печати на футболке
			var padY = RW * 0.10;
			var cv = document.createElement('canvas');
			cv.width = Math.round(RW + padX * 2);
			cv.height = Math.round(h + over + padY * 2);

			var ctx = cv.getContext('2d');
			ctx.fillStyle = rasterBg();
			ctx.fillRect(0, 0, cv.width, cv.height);
			ctx.translate(padX, padY + over);

			// Страховка второго уровня. textWidth() чинит выравнивание,
			// пока живы хоть какие-то метрики; если сломаются и они,
			// строка будет обрезана по границе макета, а не вывалится в
			// поля футболки. В заказе #412 имя ушло за край холста и
			// обрезалось на 55px правее печати — при обрезке оно бы
			// оборвалось ровно на границе, и перекос был бы виден сразу.
			//
			// Обводка заголовка (lineWidth = 0.004·RW) в габариты
			// укладывается: у крайних глиф есть боковые полуапроши, до
			// края печати штрих не достаёт.
			//
			// Отсечка — только по БОКАМ. Вертикальные границы здесь были
			// лишними: высота холста и так считается по раскладке, зато
			// они рубили выносные элементы — капитель заголовка сверху и
			// нижние выносные последней строки фразы снизу.
			ctx.save();
			ctx.beginPath();
			ctx.rect(0, -padY - over, RW, cv.height);
			ctx.clip();
			drawAll(ctx, imgs);
			ctx.restore();

			// Геометрия в долях ширины — печатный файл рисуется в другом
			// масштабе, но по тем же замерам, без повторного сухого прогона
			// на огромном холсте.
			var geo = { h: h / RW, over: over / RW, squeeze: rasterSqueeze / RW };
			rasterSqueeze = 0;

			var dataUrl = '';
			try { dataUrl = cv.toDataURL('image/jpeg', 0.88); } catch (e) { dataUrl = ''; }
			return { dataUrl: dataUrl, geo: geo };
		}

		/**
		 * Печатный файл: тот же макет в размере печати при 300 ppi.
		 *
		 * Ширина холста = ширина принта (30 см, у PAPA 25 см — printWidthCm
		 * шаблона), без полей цвета футболки; снизу запас 1% под выносные.
		 * Фон — цвет футболки, как в схеме: белые надписи на прозрачном или
		 * белом фоне печатник бы не увидел.
		 *
		 * Ограничение площади холста: iOS Safari не рисует больше ~16.7 Мп,
		 * поэтому там ширина уменьшается до влезающей (≈ 280 ppi у постера),
		 * на остальных — потолок 40 Мп. Фактический ppi уходит на сервер и
		 * прописывается в JPEG, чтобы файл открывался в нужном размере в см.
		 */
		function paintPrint(imgs, geo, done) {
			if (!geo) { done(null); return; }

			var cm = parseFloat(t.printWidthCm) || 30;
			var target = Math.round(cm / 2.54 * PRINT_PPI);
			var hK = geo.h + geo.over + PRINT_BOTTOM_K;   // высота / ширина
			var maxArea = isIOS() ? 16000000 : 40000000;
			var W = Math.min(target, Math.floor(Math.sqrt(maxArea / hK)));
			var H = Math.ceil(W * hK);

			var cv, ctx;
			try {
				RW = W;
				rasterSqueeze = geo.squeeze * RW;

				cv = document.createElement('canvas');
				cv.width = W;
				cv.height = H;
				ctx = cv.getContext('2d');
				if (!ctx) throw new Error('no ctx');

				// Фон не заливаем — печатный файл прозрачный.
				rasterClear = true;
				ctx.translate(0, geo.over * RW);
				ctx.save();
				ctx.beginPath();
				ctx.rect(0, -geo.over * RW, W, H);
				ctx.clip();
				drawAll(ctx, imgs);
				ctx.restore();
			} catch (e) {
				cv = null;
			}

			rasterClear = false;
			RW = RW_BASE;
			rasterSqueeze = 0;

			if (!cv || typeof cv.toBlob !== 'function') { done(null); return; }

			var ppi = Math.round(W / (cm / 2.54));
			var meta = { ppi: ppi, w: W, h: H };

			// PNG без потерь в 3–5 раз тяжелее JPEG. Не влез в лимит
			// сервера — подстраховка: JPEG на фоне футболки, как в 3.7.24.
			// Заказ уходит в любом случае.
			var limit = printLimit();
			function asJpeg() {
				try {
					var flat = document.createElement('canvas');
					flat.width = W;
					flat.height = H;
					var fc = flat.getContext('2d');
					fc.fillStyle = rasterBg();
					fc.fillRect(0, 0, W, H);
					fc.drawImage(cv, 0, 0);
					cv.width = 1; cv.height = 1; // освободить память холста
					flat.toBlob(function (blob) {
						done(blob && blob.size <= limit ? Object.assign({ blob: blob, type: 'jpeg' }, meta) : null);
					}, 'image/jpeg', 0.92);
				} catch (e) {
					done(null);
				}
			}
			try {
				cv.toBlob(function (blob) {
					if (blob && blob.size <= limit && 'image/png' === blob.type) {
						done(Object.assign({ blob: blob, type: 'png' }, meta));
						return;
					}
					asJpeg();
				}, 'image/png');
			} catch (e) {
				asJpeg();
			}
		}
	}

	var PRINT_PPI = 300;
	var PRINT_BOTTOM_K = 0.01;

	/**
	 * Потолок веса печатного файла. Сервер принимает до 30 МБ, плюс
	 * лимит PHP на файл (CFG.serverMax = wp_max_upload_size()) и общий
	 * лимит запроса — схема и данные заказа идут тем же POST, на них
	 * оставляем 2 МБ.
	 */
	function printLimit() {
		var cap = 30 * 1048576;
		var srv = parseInt(CFG.serverMax, 10) || 0;
		if (srv > 0) cap = Math.min(cap, srv - 2 * 1048576);
		return Math.max(1048576, cap);
	}

	function isIOS() {
		var ua = navigator.userAgent || '';
		return /iPad|iPhone|iPod/.test(ua) ||
			(/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
	}

	function submit() {
		if (state.busy || !isComplete()) return;

		state.busy = true;
		showError('');
		busyUi('Собираем макет…');

		// Сборка печатного файла занимает главный поток на 1–4 с. Даём
		// браузеру сначала отрисовать спиннер (двойной rAF = кадр точно
		// ушёл на экран), иначе кнопка «замерзала» без отклика.
		afterPaint(function () {
		buildPreviewDataUrl(function (dataUrl, print) {
			busyUi('Отправляем…');
			var fd = new FormData();
			fd.append('action', 'podval_tpl_add_to_cart');
			fd.append('_wpnonce', CFG.nonce);
			fd.append('product_id', CFG.productId);
			fd.append('tpl_data', JSON.stringify(buildPayload()));
			if (dataUrl) fd.append('tpl_preview', dataUrl);
			// Печатный файл — бинарником, а не data URL: 5–10 МБ строкой
			// base64 — лишняя треть трафика и пик памяти на телефоне.
			if (print && print.blob) {
				fd.append('tpl_print', print.blob, 'png' === print.type ? 'print.png' : 'print.jpg');
				fd.append('tpl_print_ppi', String(print.ppi));
			}

			fetch(CFG.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (res && res.success) {
						window.location.href = (res.data && res.data.cart_url) || CFG.cartUrl;
						return;
					}
					fail((res && res.data && res.data.message) || 'Не удалось добавить в корзину.');
				})
				.catch(function () { fail('Сбой сети. Попробуйте ещё раз.'); });
		}, true);
		});

		function fail(msg) {
			state.busy = false;
			busyUi('');
			showError(msg);
			renderTotal();
		}
	}

	function afterPaint(fn) {
		if (typeof window.requestAnimationFrame !== 'function') { window.setTimeout(fn, 30); return; }
		window.requestAnimationFrame(function () {
			window.requestAnimationFrame(function () { window.setTimeout(fn, 0); });
		});
	}

	/**
	 * Состояние «оформляем заказ» на кнопках: спиннер + этап.
	 * Пустая подпись — вернуть «В корзину». Обе кнопки — десктопная и
	 * липкая мобильная.
	 */
	function busyUi(label) {
		[el.submit, el.stickyBtn].forEach(function (btn) {
			if (!btn) return;
			var on = !!label;
			btn.classList.toggle('is-busy', on);
			btn.setAttribute('aria-busy', on ? 'true' : 'false');
			if (on) {
				btn.disabled = true;
				btn.innerHTML = '<span class="tplb-spin" aria-hidden="true"></span><span>' + esc(label) + '</span>';
			} else {
				btn.textContent = 'В корзину';
			}
		});
	}

	/* ───────────────────────── Инициализация ───────────────────────── */

	function init() {
		// Дефолты изделия
		Object.keys(CFG.garment).forEach(function (f) {
			state.garment[f] = CFG.garment[f].default;
		});

		// Выбор с карточки приезжает во фрагменте (#g_color=black&g_type=...).
		// Раньше это были обычные GET-параметры, но из-за них WP-Optimize
		// отказывался кешировать страницу целиком. Фрагмент до сервера не
		// доходит — HTML один на всех, а персональная часть читается здесь.
		// CFG.picked остаётся запасом для старых ссылок с ?g_* в query.
		var picked = CFG.picked && typeof CFG.picked === 'object' ? CFG.picked : {};
		var hash = window.location.hash.replace(/^#/, '');
		if (hash) {
			hash.split('&').forEach(function (pair) {
				var eq = pair.indexOf('=');
				if (eq < 1) return;
				var key = decodeURIComponent(pair.slice(0, eq));
				if (key.indexOf('g_') !== 0) return;
				// Object.create(null) не нужен: ключи ниже фильтруются по
				// списку полей изделия, до state.garment чужое не долетит.
				picked[key.slice(2)] = decodeURIComponent(pair.slice(eq + 1));
			});
		}
		CFG.picked = picked;

		// Поверх дефолтов — выбор, сделанный на карточке товара. Значения
		// приходят из URL, поэтому каждое сверяется со списком допустимых
		// опций: в адресной строке может оказаться что угодно, а невалидный
		// ключ уронил бы расчёт цены и надбавок.
		if (CFG.picked) {
			Object.keys(CFG.picked).forEach(function (f) {
				var def = CFG.garment[f];
				// hasOwnProperty, а не просто def.options[key]: ключи вроде
				// `__proto__` или `constructor` находятся в прототипе и
				// проходят обычную проверку на истинность. В сводке тогда
				// появлялось пустое значение, а цена считалась не по тому
				// изделию.
				if (def && def.options &&
					Object.prototype.hasOwnProperty.call(def.options, CFG.picked[f])) {
					state.garment[f] = CFG.picked[f];
				}
			});
		}

		renderBases();
		initUpload();
		initAccordion();

		// Шрифты макета подгружаются асинхронно — до этого замер ширины врёт.
		// Horizon запрашивается ЯВНО, а не через fonts.ready: у него
		// латинский unicode-range, и пока в макете нет ни одной латинской
		// буквы, браузер файл не тянет — fonts.ready успел бы разрешиться
		// раньше, чем шрифт вообще понадобился, и первый же подгон после
		// ввода имени считался бы по Oswald.
		if (document.fonts && document.fonts.ready) {
			if (document.fonts.load) {
				Promise.all([
					document.fonts.load('700 100px Horizon', 'AZ09'),
				document.fonts.load('700 100px Horizon', '\u0410\u042F'), // кириллица (своя, из глиф Horizon)
					document.fonts.load('700 100px "Horizon Outlined"', 'AZ09')
				]).then(fitPreviewText, function () { });
			}
			document.fonts.ready.then(fitPreviewText);
		}

		var rt = null;
		window.addEventListener('resize', function () {
			window.clearTimeout(rt);
			rt = window.setTimeout(fitPreviewText, 120);
		});

		initZoom();
		initCrop();
		watchPeekHeight();

		if (el.submit) el.submit.addEventListener('click', submit);
		if (el.stickyBtn) el.stickyBtn.addEventListener('click', submit);

		// «Назад» из корзины: страница возвращается из bfcache в состоянии
		// «Отправляем…» — снимаем его.
		window.addEventListener('pageshow', function (e) {
			if (e.persisted && state.busy) {
				state.busy = false;
				busyUi('');
				renderTotal();
			}
		});
		if (el.commentField) {
			el.commentField.addEventListener('input', function () {
				state.comment = el.commentField.value;
			});
		}

		if (el.laterInput) {
			el.laterInput.addEventListener('change', function () {
				state.photosLater = el.laterInput.checked;
				state.laterTouched = true;
				renderAll();
			});
		}

		applyPreset();

		// Изделие выбрано ещё на карточке — открываем со следующего шага.
		// Шаг «Футболка» остаётся доступным: передумать про размер можно,
		// не возвращаясь назад, но по умолчанию он свёрнут и помечен
		// пройденным, иначе конструктор начинается с уже сделанного выбора.
		if (CFG.picked && Object.keys(CFG.picked).length) {
			// markSeen() во время загрузки не срабатывает — она защищена
			// флагом booting, чтобы шаги не помечались пройденными сами
			// от рендеров при старте. Здесь пометка осмысленная: выбор
			// действительно сделан, просто не в конструкторе. Ставим прямо.
			state.seen.garment = true;

			// Первый видимый шаг ПОСЛЕ «Футболки», а не первый вообще.
			// У товара-лендинга основа и раскладка скрыты пресетом, и
			// firstVisibleStep() возвращал сам garment — конструктор
			// открывался на шаге, который только что заполнили с карточки.
			// Пропускаем всё, что уже отмечено пройденным: кроме «Футболки»
			// это может быть и «Основа», если карточка товара её задала.
			// Иначе конструктор открывался на шаге, за который человек уже
			// ответил нажатием на карточке.
			var rest = visibleSteps().filter(function (node) {
				return node.dataset.step !== 'garment' && !state.seen[node.dataset.step];
			});
			if (rest.length) openStep(rest[0].dataset.step);
		}

		booting = false;
		updateSteps();

		// Шаги собраны — показываем колонку. До этого момента она скрыта
		// (см. .tplb-steps:not(.is-ready) в style-tpl-builder.css): аккордеон
		// успевает закрыть «Футболку» и раскрыть «Основу» невидимым, и
		// перестроение не превращается в сдвиг на первом экране.
		var stepsBox = document.getElementById('tplb-steps');
		if (stepsBox) stepsBox.classList.add('is-ready');
	}

	/* ───────────────────────── Пресет товара ─────────────────────────
	   Товар может открывать конструктор на конкретной основе с готовыми
	   стилями (CFG.preset). Так «Футболка Polaroid» и «Футболка папе с фото» —
	   два товара со своими URL и карточками, но один движок: первый просто
	   стартует с dad_single + frame:polaroid и прячет выбор основы. */

	function applyPreset() {
		var preset = CFG.preset || {};
		var ids = Object.keys(CFG.templates);
		var start = (preset.tplId && CFG.templates[preset.tplId]) ? preset.tplId : ids[0];

		if (!start) return;

		// Автовыбор основы — пользователь сразу видит живой макет.
		selectBase(start);

		// Стили пресета поверх дефолтов шаблона (selectBase их только что сбросил).
		var styles = preset.styles || {};
		var applied = false;
		Object.keys(styles).forEach(function (key) {
			var def = CFG.templates[start].styles || {};
			if (def[key] && def[key].options && def[key].options[styles[key]]) {
				state.styles[key] = styles[key];
				applied = true;
			}
		});
		if (applied) {
			renderStyles();
			renderAll();
		}

		// Товар-лендинг: показываем только свою основу.
		if (preset.lock && preset.tplId) {
			step('base', false);
			var open = firstVisibleStep();
			if (open) openStep(open.dataset.step);
			renderAll();
			return;
		}

		// Основа задана карточкой товара, но не заперта. Считаем шаг
		// пройденным и сворачиваем: человек пришёл с карточки «Футболка
		// BOYFRIEND», выбор уже сделан за него — открывать этот шаг заново
		// значит просить подтвердить то, на что он и так нажал. Поменять
		// основу можно, шаг раскрывается как обычно.
		if (preset.tplId) {
			state.seen.base = true;
			var next = visibleSteps().filter(function (node) {
				return 'garment' !== node.dataset.step && 'base' !== node.dataset.step;
			});
			if (next.length) openStep(next[0].dataset.step);
			renderAll();
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();

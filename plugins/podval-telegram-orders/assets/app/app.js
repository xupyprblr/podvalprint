/* Mini App заказов. Без сборки, ES2017. */
(function () {
  'use strict';

  var CFG = window.PTG_APP || {};
  var tg = window.Telegram && window.Telegram.WebApp;
  var root = document.getElementById('app');
  var LS = 'ptg_app:';

  var state = {
    me: null,
    tabs: [],
    tab: 'work',
    q: '',
    sort: '',
    filters: [],
    showFilters: false,
    view: null,          // 'grid' | 'list' | null (по вкладке)
    list: null,          // последний ответ /orders
    items: [],
    page: 1,
    listStamp: 0,
    detailId: 0,
    detail: null
  };

  var FILTERS = [
    ['mine', 'Мои'], ['free', 'Без ответственного'], ['changed', 'С правками'],
    ['stale', 'Макет устарел'], ['pickup', 'Самовывоз'], ['delivery', 'Доставка']
  ];
  var SORTS = [['', 'По умолчанию'], ['new', 'Сначала новые'], ['old', 'Сначала старые'], ['wait', 'Дольше ждут'], ['sum', 'По сумме']];
  var GRID_TABS = ['ready', 'not-picked', 'in-print'];

  /* ── утилиты ─────────────────────────────────────────────── */

  function h(tag, attrs, kids) {
    var el = document.createElement(tag);
    if (attrs) {
      Object.keys(attrs).forEach(function (k) {
        var v = attrs[k];
        if (v === null || v === undefined || v === false) return;
        if (k === 'class') el.className = v;
        else if (k === 'text') el.textContent = v;
        else if (k === 'style') el.setAttribute('style', v);
        else if (k.slice(0, 2) === 'on') el.addEventListener(k.slice(2), v);
        else el.setAttribute(k, v === true ? '' : v);
      });
    }
    (kids || []).forEach(function (c) {
      if (c === null || c === undefined || c === false) return;
      el.appendChild(typeof c === 'string' || typeof c === 'number' ? document.createTextNode(String(c)) : c);
    });
    return el;
  }

  function money(v) {
    var n = Math.round(Number(v) * 100) / 100;
    return n.toLocaleString('ru-RU', { maximumFractionDigits: 2 }) + ' ' + (CFG.currency || 'Br');
  }

  function plural(n, a, b, c) {
    var m10 = n % 10, m100 = n % 100;
    if (m10 === 1 && m100 !== 11) return a;
    if (m10 >= 2 && m10 <= 4 && (m100 < 10 || m100 >= 20)) return b;
    return c;
  }

  function ago(ts, now) {
    var s = Math.max(0, (now || Date.now() / 1000) - ts);
    var d = Math.floor(s / 86400);
    if (d >= 1) return d + ' ' + plural(d, 'день', 'дня', 'дней');
    var hh = Math.floor(s / 3600);
    if (hh >= 1) return hh + ' ч';
    return Math.max(1, Math.floor(s / 60)) + ' мин';
  }

  function dt(ts) {
    var d = new Date(ts * 1000);
    return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' }) + ' ' +
      d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
  }

  function toast(text, act) {
    document.querySelectorAll('.toast').forEach(function (x) { x.remove(); });
    var t = h('div', { class: 'toast' + (act ? ' with-act' : '') }, [
      h('span', { text: text }),
      act ? h('button', { type: 'button', class: 'toast-act', onclick: function () { t.remove(); act.fn(); } }, [act.label]) : null
    ]);
    document.body.appendChild(t);
    setTimeout(function () { t.remove(); }, act ? 6000 : 2200);
  }

  function confirmBox(text, cb) {
    if (tg && tg.showConfirm && tg.isVersionAtLeast && tg.isVersionAtLeast('6.2')) tg.showConfirm(text, cb);
    else cb(window.confirm(text));
  }

  function alertBox(text) {
    if (tg && tg.showAlert && tg.isVersionAtLeast && tg.isVersionAtLeast('6.2')) tg.showAlert(text);
    else window.alert(text);
  }

  // Копирование — синхронно, внутри нажатия: после await вебвью
  // Android считает, что жест закончился, и буфер недоступен.
  function copyText(text) {
    var ok = false;
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0;';
    document.body.appendChild(ta);
    ta.select();
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    ta.remove();
    if (!ok && navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text);
      ok = true;
    }
    return ok;
  }

  function haptic(kind) {
    try { if (tg && tg.HapticFeedback) tg.HapticFeedback.notificationOccurred(kind); } catch (e) { /* нет */ }
  }

  function lsGet(k) { try { return JSON.parse(localStorage.getItem(LS + k)); } catch (e) { return null; } }
  function lsSet(k, v) { try { localStorage.setItem(LS + k, JSON.stringify(v)); } catch (e) { /* квота/приватный режим */ } }

  function openExternal(url) {
    if (!url) return;
    if (tg && /^https:\/\/t\.me\//.test(url) && tg.openTelegramLink) tg.openTelegramLink(url);
    else if (tg && tg.openLink) tg.openLink(url);
    else window.open(url, '_blank', 'noopener');
  }

  /* ── API ─────────────────────────────────────────────────── */

  function ApiError(status, code, data, message) { this.status = status; this.code = code; this.data = data || {}; this.message = message || ''; }

  function api(path, params, body) {
    var qs = params ? '?' + Object.keys(params).filter(function (k) {
      return params[k] !== '' && params[k] !== null && params[k] !== undefined;
    }).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&') : '';
    var headers = { 'X-TG-Init-Data': tg ? tg.initData : '' };
    if (body) headers['Content-Type'] = 'application/json';
    return fetch(CFG.rest + path + (qs === '?' ? '' : qs), {
      method: body ? 'POST' : 'GET',
      headers: headers,
      body: body ? JSON.stringify(body) : undefined,
      credentials: 'omit',
      cache: 'no-store'
    }).then(function (r) {
      return r.text().then(function (body) {
        var j = null;
        try { j = JSON.parse(body); } catch (e) {
          throw new ApiError(r.status, 'not_json', { body: body.slice(0, 120) });
        }
        if (!r.ok) throw new ApiError(r.status, j.code || 'error', j.data, j.message);
        return j;
      });
    }, function () {
      throw new ApiError(0, 'network');
    });
  }

  function fatal(err) {
    var box;
    if (err.code === 'ptg_app_forbidden') {
      box = [h('h2', { text: 'Нет доступа' }),
        h('div', { text: 'Впишите этот Telegram ID в свой профиль WordPress (поле «Telegram ID») и нужна роль с правом на заказы.' }),
        h('code', { text: String(err.data.tg_id || '') })];
    } else if (err.code === 'ptg_app_stale') {
      box = [h('h2', { text: 'Сессия истекла' }), h('div', { text: 'Закройте приложение и откройте его снова.' })];
    } else if (err.code === 'ptg_app_unauthorized') {
      box = [h('h2', { text: 'Откройте из Telegram' }), h('div', { text: 'Это приложение работает только внутри Telegram.' })];
    } else if (err.code === 'network') {
      box = [h('h2', { text: 'Нет связи' }), h('div', { text: 'Проверьте интернет и потяните обновить.' })];
    } else {
      box = [h('h2', { text: 'Ошибка ' + (err.status || '') }), h('div', { text: err.code + (err.data && err.data.body ? ': ' + err.data.body : '') })];
    }
    return h('div', { class: 'fatal' }, box);
  }

  /* ── список ──────────────────────────────────────────────── */

  function listKey() {
    return 'list:' + [state.tab, state.q, state.sort, state.filters.join(',')].join('|');
  }

  // Три режима загрузки списка:
  // - смена вкладки/фильтра/поиска пользователем: список очищается,
  //   крутится спиннер, показываем только свежие данные — иначе старая
  //   выборка подменяется новой, пока человек уже листает;
  // - фоновое обновление (раз в минуту, возврат в приложение, после
  //   действия): без спиннера, перерисовка только если данные изменились;
  // - первый запуск: сразу сохранённая выборка, поверх — свежая.
  var listSeq = 0;

  function listSig(d) {
    var c = Object.assign({}, d);
    delete c.now;
    return JSON.stringify(c);
  }

  function loadList(append, mode) {
    mode = mode || 'silent';
    // Долистали до второй страницы — фоновое обновление сбросило бы на первую.
    if (mode === 'silent' && !append && state.page > 1) return Promise.resolve();
    var page = append ? state.page + 1 : 1;
    var params = { tab: state.tab, q: state.q, sort: state.sort, f: state.filters.join(','), page: page };
    var key = listKey();
    var seq = ++listSeq;

    if (!append && mode === 'user') {
      state.items = [];
      state.listSig = '';
      state.loading = true;
      state.scrollReset = true;
      renderList();
    } else if (!append && mode === 'boot') {
      var cached = lsGet(key);
      if (cached) applyList(cached.data, false, cached.at);
      state.loading = !cached;
      renderList();
    }

    return api('orders', params).then(function (data) {
      if (seq !== listSeq && !append) return; // есть запрос новее
      if (key !== listKey()) return;
      state.loading = false;
      if (!append) lsSet(key, { at: Date.now(), data: data });
      if (!append && listSig(data) === state.listSig && state.page === 1) {
        state.listStamp = Date.now();
        var st = document.querySelector('.stamp');
        if (st) st.textContent = stampText();
        return; // ничего не поменялось — не трогаем экран
      }
      applyList(data, append, Date.now());
    }).catch(function (err) {
      if (seq !== listSeq && !append) return;
      state.loading = false;
      if (err.status === 401 || err.status === 403) { root.replaceChildren(fatal(err)); return; }
      var cached = !append && !state.items.length ? lsGet(key) : null;
      if (cached) {
        applyList(cached.data, false, cached.at);
        toast('Нет связи — показаны сохранённые данные');
      } else if (state.list) {
        toast(err.code === 'network' ? 'Нет связи' : 'Ошибка загрузки');
        renderList();
      } else {
        root.replaceChildren(fatal(err));
      }
    });
  }

  function reloadList() { loadList(false, 'user'); }

  function applyList(data, append, stamp) {
    state.list = data;
    state.counts = data.counts;
    state.page = data.page;
    state.items = append ? state.items.concat(data.items) : data.items;
    state.listSig = append ? '' : listSig(data);
    state.listStamp = stamp;
    renderList();
  }

  function stampText() {
    return state.listStamp ? 'обновлено ' + new Date(state.listStamp).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' }) : '';
  }

  function isGrid() {
    if (state.view) return state.view === 'grid';
    return !state.q && GRID_TABS.indexOf(state.tab) >= 0;
  }

  function thumbStyle(p) {
    return p && p.url ? "background-image:url('" + p.url.replace(/'/g, '%27') + "')" : null;
  }

  function flagsFor(o) {
    var f = [];
    if (o.stale) f.push(h('span', { class: 'flag bad', title: 'Макет требует перерисовки', text: 'макет' }));
    if (o.changed) f.push(h('span', { class: 'flag', title: 'Параметры изменены после оформления', text: '✏️' + o.changed }));
    if (o.photos) f.push(h('span', { class: 'flag', title: 'Ждём фото от клиента', text: '📷' }));
    if (o.mine) f.push(h('span', { class: 'flag mine', text: 'мой' }));
    if (o.reminded && (o.status.key === 'ready' || o.status.key === 'not-picked')) f.push(h('span', { class: 'flag old', title: 'Напоминали забрать', text: '🔔' + o.reminded }));
    if (o.legacy) f.push(h('span', { class: 'flag old', title: 'Заказ до запуска приложения, статус может быть неактуален', text: 'архив' }));
    return f.length ? h('span', { class: 'flags' }, f) : null;
  }

  function waitingLabel(o, now) {
    var days = Math.floor((now - o.status_at) / 86400);
    return h('span', { class: days >= 7 ? 'wait-long' : null, text: 'ждёт ' + ago(o.status_at, now) });
  }

  // Картинка заказа: одна — как раньше; несколько разных принтов —
  // коллаж, чтобы в списке было видно, что в заказе две разные вещи.
  function picture(o, cls) {
    var list = (o.previews && o.previews.length) ? o.previews : (o.preview ? [o.preview] : []);
    var qty = o.qty || 1;
    var badge = qty > 1 ? h('span', { class: 'qty-badge', text: '×' + qty }) : null;
    if (list.length <= 1) {
      var p = list[0];
      return h('div', { class: cls + (p && p.source === 'product' ? ' product' : ''), style: thumbStyle(p) },
        [p ? null : (o.service ? '🛠' : '👕'), badge]);
    }
    var n = Math.min(list.length, 4);
    return h('div', { class: cls + ' collage n' + n }, list.slice(0, 4).map(function (p) {
      return h('span', { class: 'cell' + (p.source === 'product' ? ' product' : ''), style: thumbStyle(p) });
    }).concat([badge]));
  }

  function linesEl(o, max) {
    var lines = o.lines && o.lines.length ? o.lines : [{ text: o.items || '—', qty: 1 }];
    var shown = lines.slice(0, max);
    var kids = shown.map(function (l) {
      return h('div', { class: 'ln' }, [l.text, l.qty > 1 ? h('b', { text: ' ×' + l.qty }) : null]);
    });
    if (lines.length > max) kids.push(h('div', { class: 'ln more-ln', text: 'ещё ' + (lines.length - max) }));
    return kids;
  }

  function quickBtn(o, extraCls) {
    if (!o.quick) return null;
    return h('button', {
      class: 'btn quick ' + (o.quick.hint ? 'primary' : 'ghost') + (extraCls ? ' ' + extraCls : ''),
      type: 'button', disabled: state.busy,
      onclick: function (e) { e.stopPropagation(); quickAction(o); }
    }, [o.quick.label]);
  }

  // Следующий шаг прямо из списка. Промах пальцем — частая вещь, поэтому
  // тост с «Отменить» на 6 секунд (сервер разрешает откат 2 минуты).
  function quickAction(o) {
    var from = o.quick.key;
    doActionFor(o.id, { action: 'status', to: o.quick.key }, null, function () {
      toast('#' + o.number + ' → ' + o.quick.label, { label: 'Отменить', fn: function () {
        doActionFor(o.id, { action: 'undo', from: from }, 'Отменено');
      } });
    });
  }

  function rowEl(o, now) {
    var waiting = o.status.key === 'ready' || o.status.key === 'not-picked';
    var open = function () { openDetail(o.id); };
    return h('div', { class: 'row' + (o.id === state.detailId ? ' active' : '') }, [
      h('button', { class: 'row-main', type: 'button', onclick: open }, [
        picture(o, 'thumb'),
        h('div', { class: 'main' }, [
          h('div', { class: 'l1' }, [
            h('span', { class: 'n', text: '#' + o.number }),
            h('span', { class: 'who', text: o.name || (o.username ? '@' + o.username : '—') }),
            flagsFor(o)
          ]),
          h('div', { class: 'l2' }, linesEl(o, 2))
        ]),
        h('div', { class: 'side' }, [
          h('div', { class: 'sum', text: money(o.total) }),
          h('div', { class: 'st' }, [
            (state.tab === 'work' || state.q || state.tab === 'other') ? o.status.emoji + ' ' : null,
            waiting ? waitingLabel(o, now) : ago(o.created, now)
          ])
        ])
      ]),
      o.quick ? h('div', { class: 'row-act' }, [quickBtn(o)]) : null
    ]);
  }

  function tileEl(o, now) {
    var waiting = o.status.key === 'ready' || o.status.key === 'not-picked';
    return h('div', { class: 'tile' + (o.id === state.detailId ? ' active' : '') }, [
      h('button', { class: 'tile-main', type: 'button', onclick: function () { openDetail(o.id); } }, [
        picture(o, 'img'),
        h('div', { class: 'cap' }, [
          h('div', { class: 'cap-top' }, [
            h('span', { class: 'n', text: '#' + o.number }),
            h('span', { class: 'num sum-sm', text: money(o.total) })
          ]),
          h('div', { class: 'who', text: o.name || (o.username ? '@' + o.username : '—') }),
          h('div', { class: 'lines' }, linesEl(o, 2)),
          waiting ? h('div', { class: 'sub' }, [waitingLabel(o, now)]) : null
        ])
      ]),
      o.quick ? h('div', { class: 'tile-act' }, [quickBtn(o, 'wide-q')]) : null
    ]);
  }

  var searchTimer = 0;

  function renderList() {
    var pane = document.querySelector('.pane-list');
    if (!pane) return;
    var counts = state.counts || (state.list && state.list.counts) || {};
    var now = (state.list && state.list.now) || Date.now() / 1000;

    var input = h('input', {
      type: 'search', placeholder: 'Номер, имя, телефон, @ник', value: state.q, enterkeyhint: 'search',
      'aria-label': 'Поиск заказов',
      oninput: function (e) {
        clearTimeout(searchTimer);
        var v = e.target.value.trim();
        searchTimer = setTimeout(function () {
          if (v === state.q) return;
          state.q = v; reloadList();
        }, 350);
      }
    });

    var hasFilters = state.filters.length > 0 || state.sort;
    var bar = h('div', { class: 'bar' }, [
      h('div', { class: 'search' }, [
        input,
        h('button', {
          class: 'icon-btn', type: 'button', 'aria-pressed': state.showFilters ? 'true' : 'false', 'aria-label': 'Фильтры',
          onclick: function () { state.showFilters = !state.showFilters; renderList(); }
        }, ['⚙︎', hasFilters ? h('span', { class: 'dot' }) : null]),
        h('button', {
          class: 'icon-btn', type: 'button', 'aria-label': 'Новый заказ',
          onclick: function () { openNew(); }
        }, ['＋']),
        h('button', {
          class: 'icon-btn', type: 'button', 'aria-label': 'Отчёт', 'aria-pressed': state.reportOpen ? 'true' : 'false',
          onclick: function () { if (state.reportOpen) closeDetail(); else openReport(); }
        }, ['📊']),
        h('button', {
          class: 'icon-btn', type: 'button', 'aria-label': isGrid() ? 'Списком' : 'Плиткой',
          onclick: function () { state.view = isGrid() ? 'list' : 'grid'; lsSet('view:' + state.tab, state.view); renderList(); }
        }, [isGrid() ? '☰' : '▦'])
      ])
    ]);

    var tabs = state.q ? h('div', { class: 'meta-line' }, [h('span', { text: 'Поиск по всем статусам' })]) :
      h('div', { class: 'tabs', role: 'tablist' }, state.tabs.map(function (t) {
        var n = counts[t.key];
        return h('button', {
          class: 'tab' + (n === 0 ? ' zero' : ''), role: 'tab', type: 'button',
          'aria-selected': t.key === state.tab ? 'true' : 'false',
          onclick: function () {
            if (t.key === state.tab) return;
            state.tab = t.key;
            state.view = lsGet('view:' + t.key);
            state.tabJump = true;
            lsSet('tab', t.key);
            reloadList();
          }
        }, [(t.emoji ? t.emoji + ' ' : '') + t.label, n !== undefined ? h('span', { class: 'c', text: n }) : null]);
      }));

    var avail = FILTERS.filter(function (f) {
      return (state.me && state.me.show_assignee) || (f[0] !== 'mine' && f[0] !== 'free');
    });
    var filters = state.showFilters ? h('div', { class: 'filters' }, avail.map(function (f) {
      var on = state.filters.indexOf(f[0]) >= 0;
      return h('button', {
        class: 'chip', type: 'button', 'aria-pressed': on ? 'true' : 'false',
        onclick: function () {
          state.filters = on ? state.filters.filter(function (x) { return x !== f[0]; }) : state.filters.concat(f[0]);
          lsSet('filters', state.filters); reloadList();
        }
      }, [f[1]]);
    }).concat([
      canAddHome() ? h('button', { class: 'chip', type: 'button', onclick: addHome }, ['📲 На главный экран']) : null,
      h('select', {
        'aria-label': 'Сортировка',
        onchange: function (e) { state.sort = e.target.value; lsSet('sort', state.sort); reloadList(); }
      }, SORTS.map(function (s) {
        return h('option', { value: s[0], selected: s[0] === state.sort ? 'selected' : null, text: s[1] });
      }))
    ])) : null;

    var info = h('div', { class: 'meta-line' }, [
      state.loading ? h('span', { class: 'loading-note' }, [h('span', { class: 'spin sm' }), 'Загрузка…'])
        : state.list ? h('span', { text: state.list.total + ' ' + plural(state.list.total, 'заказ', 'заказа', 'заказов') + (state.list.capped ? ' (последние)' : '') }) : null,
      h('span', { class: 'stamp', text: state.loading ? '' : stampText() })
    ]);

    var body;
    if (state.loading || !state.list) {
      body = h('div', { class: 'empty' }, [h('span', { class: 'spin' })]);
    } else if (!state.items.length) {
      body = h('div', { class: 'empty', text: state.q ? 'Ничего не нашлось' : 'Пусто' });
    } else {
      var grid = isGrid();
      body = h('div', { class: grid ? 'grid' : 'rows' }, state.items.map(function (o) {
        return grid ? tileEl(o, now) : rowEl(o, now);
      }));
    }

    var more = state.list && state.page < state.list.pages ? h('button', {
      class: 'more', type: 'button', text: 'Показать ещё',
      onclick: function (e) { e.target.disabled = true; loadList(true); }
    }) : null;

    var hadFocus = document.activeElement && document.activeElement.type === 'search';

    // Контейнер прокрутки не пересоздаётся: меняется только содержимое,
    // поэтому позиция и инерция прокрутки не сбрасываются при обновлении.
    var head = pane.querySelector(':scope > .list-head');
    var scroll = pane.querySelector(':scope > .scroll');
    if (!head || !scroll) {
      head = h('div', { class: 'list-head' });
      scroll = h('div', { class: 'scroll' });
      pane.replaceChildren(head, scroll);
    }
    var tabsScroll = head.querySelector('.tabs') ? head.querySelector('.tabs').scrollLeft : 0;
    var top = scroll.scrollTop;

    head.replaceChildren.apply(head, [bar, tabs, filters, info].filter(Boolean));
    scroll.replaceChildren.apply(scroll, [homeBanner(), returnChip(), activeStrip(now), body, more].filter(Boolean));

    if (state.scrollReset) { scroll.scrollTop = 0; state.scrollReset = false; }
    else scroll.scrollTop = top;

    var tabsEl = head.querySelector('.tabs');
    if (tabsEl) {
      tabsEl.scrollLeft = tabsScroll;
      // Выбранную вкладку подкручиваем в видимую область только при
      // смене вкладки и только внутри ленты — scrollIntoView тянул за
      // собой весь экран.
      if (state.tabJump) {
        var sel = tabsEl.querySelector('.tab[aria-selected="true"]');
        if (sel && (sel.offsetLeft < tabsEl.scrollLeft || sel.offsetLeft + sel.offsetWidth > tabsEl.scrollLeft + tabsEl.clientWidth)) {
          tabsEl.scrollLeft = sel.offsetLeft - 16;
        }
        state.tabJump = false;
      }
    }
    if (hadFocus) {
      var inp = head.querySelector('input[type=search]');
      inp.focus();
      inp.setSelectionRange(inp.value.length, inp.value.length);
    }
  }

  /* ── детали ──────────────────────────────────────────────── */

  function isWide() { return window.matchMedia('(min-width: 900px)').matches; }

  function openDetail(id) {
    state.reportOpen = false;
    state.newOpen = false;
    state.detailId = id;
    state.detail = null;
    document.querySelector('.shell').classList.add('has-detail');
    renderDetail();
    syncBackButton();
    document.querySelector('.pane-detail').scrollTop = 0;
    document.querySelectorAll('.row.active, .tile.active').forEach(function (el) { el.classList.remove('active'); });

    var cached = lsGet('order:' + id);
    if (cached) { state.detail = cached; renderDetail(); }
    lsSet('last', { id: id, number: cached ? cached.number : id, t: Date.now() });

    api('order/' + id).then(function (d) {
      if (state.detailId !== id) return;
      state.detail = d;
      lsSet('order:' + id, d);
      lsSet('last', { id: id, number: d.number, t: Date.now() });
      renderDetail();
    }).catch(function (err) {
      if (state.detailId !== id) return;
      if (err.status === 401 || err.status === 403) { root.replaceChildren(fatal(err)); return; }
      if (!state.detail) document.querySelector('.pane-detail').replaceChildren(fatal(err));
      else toast('Нет связи — показаны сохранённые данные');
    });
    renderList();
  }

  function closeDetail() {
    if (!state.reportOpen && !state.newOpen) lsSet('last', null); // закрыл сам — возвращать некуда
    state.reportOpen = false;
    state.newOpen = false;
    state.form = null;
    state.detailId = 0;
    state.detail = null;
    document.querySelector('.shell').classList.remove('has-detail');
    renderDetail();
    syncBackButton();
    renderList();
  }

  function syncBackButton() {
    if (!tg || !tg.BackButton) return;
    if ((state.detailId || state.reportOpen || state.newOpen) && !isWide()) tg.BackButton.show(); else tg.BackButton.hide();
  }

  function kv(k, v, cls) {
    if (v === '' || v === null || v === undefined) return null;
    return h('div', { class: 'kv' }, [h('div', { class: 'k', text: k }), h('div', { class: 'v' + (cls ? ' ' + cls : '') }, [v])]);
  }

  function section(title, kids) {
    kids = kids.filter(Boolean);
    if (!kids.length) return null;
    return h('div', { class: 'sec' }, [h('h3', { text: title })].concat(kids));
  }

  function renderDetail() {
    var pane = document.querySelector('.pane-detail');
    if (!pane) return;
    if (state.reportOpen) { renderReport(pane); return; }
    if (state.newOpen) { renderNew(pane); return; }
    if (!state.detailId) {
      pane.replaceChildren(h('div', { class: 'detail-empty', text: 'Выберите заказ' }));
      return;
    }
    var d = state.detail;
    var back = h('button', { class: 'detail-back', type: 'button', text: '‹ Заказы', onclick: closeDetail });
    if (!d) {
      pane.replaceChildren(h('div', { class: 'detail' }, [back, h('div', { class: 'empty', text: 'Загрузка…' })]));
      return;
    }
    var now = d.now || Date.now() / 1000;
    var waiting = d.status.key === 'ready' || d.status.key === 'not-picked';

    var head = h('div', { class: 'd-head' }, [
      h('div', { class: 'd-num', text: '#' + d.number }),
      h('span', { class: 'pill' + (d.status.negative ? ' neg' : '') }, [d.status.emoji + ' ' + d.status.label])
    ]);
    var sub = h('div', { class: 'd-sub' }, [
      'Создан ' + dt(d.created) + ' · в статусе ' + ago(d.status_at, now)
    ]);

    var banners = [];
    if (d.overrides.length) {
      banners.push(h('div', { class: 'banner bad' }, [
        h('b', { text: '🛑 Изменено после оформления' }),
        h('ul', {}, d.overrides.map(function (r) {
          return h('li', { text: r.label + ': ' + r.from + ' → ' + r.to + (r.delta ? ' (' + (r.delta > 0 ? '+' : '') + money(r.delta) + ')' : '') + (r.by ? ' — ' + r.by : '') });
        })),
        d.verified && d.verified.at ? h('div', { text: '✔ Сверено: ' + d.verified.by }) : null
      ]));
    }
    if (d.legacy) banners.push(h('div', { class: 'banner' }, [h('b', { text: 'Заказ до запуска приложения' }), 'Статус в WooCommerce тогда не вели — он может не совпадать с реальностью. Смена статуса вернёт заказ в рабочие вкладки.']));
    if (d.hint) {
      banners.push(h('div', { class: 'banner hint' }, [
        h('div', { text: 'Вы отправили клиенту изображение ' + ago(d.hint.t, now) + ' назад. Это эскиз?' }),
        h('button', { class: 'btn primary', type: 'button', disabled: state.busy, onclick: function () {
          doAction({ action: 'status', to: d.hint.to }, d.hint.label);
        } }, [d.hint.label])
      ]));
    }
    if (d.flags.stale) banners.push(h('div', { class: 'banner bad' }, [h('b', { text: 'Макет требует перерисовки' }), 'Цвет изменён, в печать по старому макету не отдавать.']));
    if (d.flags.photos) banners.push(h('div', { class: 'banner' }, [h('b', { text: 'Ждём фото от клиента' })]));

    var gallery = d.previews && d.previews.length > 1 ? h('div', { class: 'gallery' }, d.previews.map(function (p) {
      return h('a', { class: 'g-item', href: p.url, onclick: function (e) { e.preventDefault(); openExternal(p.url); } }, [
        h('img', { src: p.url, alt: p.name, loading: 'lazy' }),
        h('span', { class: 'src', text: p.name + (p.qty > 1 ? ' ×' + p.qty : '') + (p.source === 'product' ? ' · фото товара' : '') })
      ]);
    })) : null;
    var hero = gallery ? gallery : d.preview ? h('a', {
      class: 'hero', href: d.preview.url, onclick: function (e) { e.preventDefault(); openExternal(d.preview.url); }
    }, [
      h('img', { src: d.preview.url, alt: 'Макет заказа #' + d.number, loading: 'lazy' }),
      d.preview.source === 'product' ? h('span', { class: 'src', text: 'фото товара, не макет' }) :
        d.preview.source === 'artwork' ? h('span', { class: 'src', text: 'артворк клиента' }) : null
    ]) : null;

    var c = d.customer || {};
    var uname = d.client.username;
    var chatUrl = uname ? 'https://t.me/' + uname : '';
    var canPickupRemind = d.status.key === 'ready' || d.status.key === 'not-picked';

    var comms = [];
    // Клиент оставил только телефон: Telegram сам найдёт по нему аккаунт,
    // нам остаётся положить в буфер команду привязки.
    if (!d.client.linked && d.client.phone_chat) {
      comms.push(h('button', { class: 'btn', type: 'button', onclick: function () {
        copyText(d.client.link_cmd);
        toast('Команда ' + d.client.link_cmd + ' скопирована — вставьте её в чат');
        setTimeout(function () { openExternal(d.client.phone_chat); }, 400);
      } }, ['📲 Чат по номеру']));
    }
    if (d.greeting.show) {
      comms.push(d.client.linked
        ? h('button', { class: 'btn', type: 'button', disabled: state.busy, onclick: function () {
            doAction({ action: 'greet' }, 'Приветствие отправлено');
          } }, ['👋 Отправить приветствие'])
        : h('button', { class: 'btn', type: 'button', onclick: function () {
            copyText(d.greeting.text);
            toast('Приветствие скопировано');
            if (chatUrl) setTimeout(function () { openExternal(chatUrl); }, 400);
          } }, ['👋 Скопировать приветствие']));
    }
    if (canPickupRemind && d.pickup && d.pickup.ready) {
      comms.push(d.client.linked
        ? h('button', { class: 'btn', type: 'button', disabled: state.busy, onclick: function () {
            confirmBox('Отправить клиенту адрес' + (d.pickup.point ? ' и точку на карте' : '') + '?\n\n' + d.pickup.text, function (ok) {
              if (ok) doAction({ action: 'pickup' }, 'Адрес отправлен');
            });
          } }, ['📍 Отправить адрес'])
        : h('button', { class: 'btn', type: 'button', disabled: state.busy, onclick: function () {
            copyText(d.pickup.text);
            doAction({ action: 'pickup', copy: true }, 'Адрес скопирован — отправьте клиенту');
            if (chatUrl) setTimeout(function () { openExternal(chatUrl); }, 400);
          } }, ['📍 Скопировать адрес']));
    }
    if (canPickupRemind) {
      comms.push(d.client.linked
        ? h('button', { class: 'btn', type: 'button', disabled: state.busy, onclick: function () {
            confirmBox('Отправить клиенту напоминание от вашего имени?\n\n' + d.remind.text, function (ok) {
              if (ok) doAction({ action: 'remind' }, 'Напоминание отправлено');
            });
          } }, ['🔔 Напомнить забрать'])
        : h('button', { class: 'btn', type: 'button', disabled: state.busy, onclick: function () {
            copyText(d.remind.text);
            doAction({ action: 'remind', copy: true }, 'Текст скопирован — отправьте клиенту');
            if (chatUrl) setTimeout(function () { openExternal(chatUrl); }, 400);
          } }, ['🔔 Скопировать напоминание']));
    }
    var pickupInfo = canPickupRemind && d.pickup && d.pickup.at ? h('div', { class: 'next-hint', text:
      'Адрес отправлен ' + ago(d.pickup.at, now) + ' назад' + (d.pickup.by ? ' (' + d.pickup.by + ')' : '') }) : null;
    var remindInfo = canPickupRemind && d.remind.count ? h('div', { class: 'next-hint', text:
      'Напоминали ' + d.remind.count + ' ' + plural(d.remind.count, 'раз', 'раза', 'раз') +
      ', последний — ' + ago(d.remind.last, now) + ' назад' + (d.remind.by ? ' (' + d.remind.by + ')' : '') }) : null;

    var client = section('Клиент', [h('div', { class: 'card' }, [
      kv('Имя', c.name),
      kv('Telegram', uname ? h('a', { href: chatUrl, onclick: function (e) { e.preventDefault(); openExternal(chatUrl); }, text: '@' + uname + (d.client.linked ? '' : ' (чат не привязан)') }) : (d.client.linked ? 'привязан' : null)),
      kv('Телефон', c.phone ? h('a', {
        href: d.client.phone_chat || ('tel:' + c.phone.replace(/[^\d+]/g, '')),
        onclick: d.client.phone_chat ? function (e) { e.preventDefault(); openExternal(d.client.phone_chat); } : null,
        text: c.phone
      }) : null),
      kv('Почта', c.email),
      d.assignee ? kv('Ответственный', d.assignee.name ? d.assignee.name + (d.assignee.mine ? ' (вы)' : '') : 'не назначен') : null
    ]), comms.length ? h('div', { class: 'actions' }, comms) : null, pickupInfo, remindInfo]);

    var fieldMap = {};
    (d.fields || []).forEach(function (f) { fieldMap[f.key] = f; });

    var items = section('Позиции', [
      d.status.locked && d.items.some(function (it) { return it.editable; }) && d.fields && d.fields.length
        ? h('div', { class: 'next-hint', text: '🔒 Параметры заблокированы: заказ в печати. Чтобы менять, верните статус назад.' }) : null,
      h('div', { class: 'card' }, d.items.map(function (it) {
      var rows = [h('div', { class: 'item-title' }, [h('span', { text: it.name + (it.qty > 1 ? ' ×' + it.qty : '') }), h('span', { class: 'num', text: it.subtotal ? money(it.subtotal) : '' })])];
      var editable = it.editable && !d.status.locked;
      var seen = {};
      it.garment.forEach(function (g) {
        seen[g.key] = true;
        var val = g.overridden ? h('span', {}, [h('s', { text: g.from }), ' → ' + (g.emoji ? g.emoji + ' ' : '') + g.value]) : (g.emoji ? g.emoji + ' ' : '') + g.value;
        rows.push(editable && fieldMap[g.key] ? editRow(g.label, val, g.overridden, function () { openPicker(it, fieldMap[g.key], g); }) : kv(g.label, val, g.overridden ? 'chg' : ''));
      });
      if (editable) {
        (d.fields || []).forEach(function (f) {
          if (!seen[f.key]) rows.push(editRow(f.label, '—', false, function () { openPicker(it, f, null); }));
        });
      }
      it.texts.forEach(function (t) { rows.push(kv(t.label, t.value)); });
      it.details.forEach(function (t) { rows.push(kv(t.label, t.value)); });
      if (it.comment) rows.push(kv('Комментарий', it.comment));
      it.notices.forEach(function (n) { rows.push(h('div', { class: 'banner' + (n.level === 'error' ? ' bad' : ''), text: n.text })); });
      if (it.links.length) {
        rows.push(h('div', { class: 'links' }, it.links.map(function (l) {
          return h('a', { href: l.url, onclick: function (e) { e.preventDefault(); openExternal(l.url); }, text: l.label });
        })));
      }
      return h('div', {}, rows);
    }))]);

    var files = (d.files.length || d.chat_files.length) ? section('Файлы', [h('div', { class: 'links' },
      d.files.map(function (f) { return h('a', { href: f.url, onclick: function (e) { e.preventDefault(); openExternal(f.url); }, text: f.label }); })
        .concat(d.chat_files.map(function (f) { return h('a', { href: f.url, onclick: function (e) { e.preventDefault(); openExternal(f.url); }, text: '💬 ' + (f.name || f.kind || 'файл') }); })))]) : null;

    var money_ = section('Оплата и доставка', [h('div', { class: 'card' }, [
      kv('Сумма', h('b', { class: 'num', text: money(d.payment.total) })),
      kv('Оплата', d.payment.state_label
        ? h('span', {}, [d.payment.title + ' · ', h('b', { class: 'pay-' + d.payment.state, text: d.payment.state_label })])
        : d.payment.title),
      // 2.17.0: «после согласования» — оплату отмечает менеджер.
      d.payment.agreed ? h('button', { class: 'btn' + (d.payment.mark ? ' ghost' : '') + ' wide pay-mark', type: 'button', disabled: state.busy,
        onclick: function () { openPayMark(d); } }, [d.payment.mark ? '💳 Изменить отметку оплаты' : '💳 Отметить оплату']) : null,
      kv('Доставка', d.shipping.label + (d.shipping.address ? ' — ' + d.shipping.address : '')),
      kv('Комментарий', d.note)
    ])]);

    var actbar = statusBar(d);

    var actions = (d.tg_link || d.admin_url) ? h('div', { class: 'actions' }, [
      d.tg_link ? h('button', { class: 'btn', type: 'button', onclick: function () { openExternal(d.tg_link); } }, ['Карточка в группе']) : null,
      d.admin_url ? h('button', { class: 'btn', type: 'button', onclick: function () { openExternal(d.admin_url); } }, ['🗂 Админка']) : null
    ]) : null;

    var chat = d.chat.length ? section('Переписка', [h('div', { class: 'chat' }, d.chat.map(function (m) {
      return h('div', { class: 'msg ' + m.dir + (m.del ? ' del' : '') + (m.file && m.file.thumb ? ' has-img' : '') }, [
        chatFile(m),
        ((m.file ? '' : (m.med ? '[' + m.med + '] ' : '')) + (m.txt || '')) ? h('div', { class: 'msg-txt', text: (m.file ? '' : (m.med ? '[' + m.med + '] ' : '')) + (m.txt || '') }) : null,
        h('span', { class: 't', text: (m.who ? m.who + ' · ' : '') + dt(m.t) })
      ]);
    }))]) : null;

    var notes = d.notes.length ? section('История', [h('div', { class: 'card notes' }, d.notes.map(function (n) {
      return h('div', {}, [h('time', { text: dt(n.t) }), n.txt]);
    }))]) : null;

    var scrollTop = pane.scrollTop;
    pane.replaceChildren(h('div', { class: 'detail' + (actbar ? ' with-bar' : '') }, [back, head, sub].concat(banners, [hero, client, items, files, money_, actions, chat, notes])), actbar);
    pane.scrollTop = scrollTop;
  }

  function chatFile(m) {
    var f = m.file;
    if (!f) return null;
    var open = function (e) { e.preventDefault(); openExternal(f.url); };
    if (f.thumb) {
      return h('a', { class: 'msg-img', href: f.url, onclick: open }, [
        h('img', { src: f.thumb, alt: m.med || 'изображение', loading: 'lazy' })
      ]);
    }
    if (f.kind === 'voice') {
      return h('div', { class: 'msg-file' }, [
        h('audio', { controls: true, preload: 'none', src: f.url }),
        h('a', { href: f.url, onclick: open, text: 'голосовое — скачать' })
      ]);
    }
    return h('a', { class: 'msg-file', href: f.url, onclick: open, text: '📎 ' + (f.name || m.med || 'файл') });
  }

  function activeStrip(now) {
    var act = state.list && state.list.active;
    if (!act || !act.length || state.q) return null;
    return h('div', { class: 'active' }, [
      h('div', { class: 'active-title', text: 'Сейчас в переписке' })
    ].concat(act.map(function (o) {
      var c = o.chat;
      var line = c ? (c.dir === 'out' ? 'Вы: ' : '') + (c.med ? '[' + c.med + '] ' : '') + (c.txt || '') : '';
      return h('div', { class: 'act' + (o.hint ? ' hinted' : '') + (o.id === state.detailId ? ' active-row' : '') }, [
        h('button', { class: 'act-main', type: 'button', onclick: function () { openDetail(o.id); } }, [
          picture(o, 'thumb sm'),
          h('div', { class: 'act-text' }, [
            h('div', { class: 'act-l1' }, [
              h('b', { class: 'num', text: '#' + o.number }), ' ',
              h('span', { text: o.name || (o.username ? '@' + o.username : '') }),
              h('span', { class: 'st', title: o.status.label, text: ' ' + o.status.emoji })
            ]),
            h('div', { class: 'act-l2', text: (o.hint ? '📎 вы отправили изображение · ' : '') + line + (c ? ' · ' + ago(c.t, now) : '') })
          ])
        ]),
        quickBtn(o, 'act-btn')
      ]);
    })));
  }

  // Ярлык на главный экран (Bot API 8.0). Пункта в меню «⋮» Telegram
  // показывает не везде, а метод addToHomeScreen работает сам.
  function canAddHome() {
    return !!(tg && tg.addToHomeScreen && tg.isVersionAtLeast && tg.isVersionAtLeast('8.0')) &&
      (state.homeStatus === 'missed' || state.homeStatus === 'unknown');
  }

  function addHome() {
    try { tg.addToHomeScreen(); } catch (e) { alertBox('Этот клиент Telegram не умеет добавлять ярлык. Обновите приложение Telegram.'); }
  }

  function homeBanner() {
    if (!canAddHome() || state.homeStatus !== 'missed' || lsGet('home_hide') || state.q) return null;
    return h('div', { class: 'return' }, [
      h('button', { class: 'return-go', type: 'button', onclick: addHome }, ['📲 Добавить приложение на главный экран']),
      h('button', { class: 'return-x', type: 'button', 'aria-label': 'Скрыть', onclick: function () { lsSet('home_hide', 1); renderList(); } }, ['×'])
    ]);
  }

  function returnChip() {
    if (state.detailId || state.q) return null;
    var last = lsGet('last');
    if (!last || Date.now() - last.t > 30 * 60 * 1000) return null;
    return h('div', { class: 'return' }, [
      h('button', { class: 'return-go', type: 'button', onclick: function () { openDetail(last.id); } }, ['↩︎ Вернуться к заказу #' + last.number]),
      h('button', { class: 'return-x', type: 'button', 'aria-label': 'Скрыть', onclick: function () { lsSet('last', null); renderList(); } }, ['×'])
    ]);
  }

  /* ── новый заказ (2.12.0) ────────────────────────────────── */

  function openNew() {
    state.detailId = 0;
    state.detail = null;
    state.reportOpen = false;
    state.newOpen = true;
    state.form = { loading: true, ref: null, busy: false, pickup: true, client: '' };
    document.querySelector('.shell').classList.add('has-detail');
    document.querySelector('.pane-detail').scrollTop = 0;
    syncBackButton();
    renderList();
    renderDetail();
    api('new').then(function (d) {
      if (!state.newOpen) return;
      state.form.ref = d;
      state.form.loading = false;
      renderDetail();
    }).catch(function (err) {
      if (!state.newOpen) return;
      state.form.loading = false;
      if (err.status === 401 || err.status === 403) { root.replaceChildren(fatal(err)); return; }
      state.form.error = err.message || 'Не удалось загрузить форму';
      renderDetail();
    });
  }

  function field(label, node, hint) {
    return h('div', { class: 'f-row' }, [
      h('label', { class: 'f-label', text: label }),
      node,
      hint ? h('div', { class: 'next-hint', text: hint }) : null
    ]);
  }

  function selectEl(options, attrs) {
    return h('select', attrs || {}, options.map(function (o) {
      return h('option', { value: o.value, text: o.label, selected: o.selected ? 'selected' : null });
    }));
  }

  function renderNew(pane) {
    var f = state.form;
    var back = h('button', { class: 'detail-back', type: 'button', text: '‹ Заказы', onclick: closeDetail });

    if (f.loading || !f.ref) {
      pane.replaceChildren(h('div', { class: 'detail' }, [
        back,
        h('div', { class: 'empty' }, [f.error ? f.error : h('span', { class: 'spin' })])
      ]));
      return;
    }

    var ref = f.ref;
    f.garment = f.garment || {};
    f.sides = f.sides || [];
    var name = h('input', { type: 'text', placeholder: 'Имя клиента', value: f.name || '' });
    var nick = h('input', { type: 'text', placeholder: '@ник', value: f.nick || '' });
    var phone = h('input', { type: 'tel', placeholder: 'Телефон (не обязательно)', value: f.phone || '' });
    var qty = h('input', { type: 'number', min: '1', max: '50', value: String(f.qty || 1), inputmode: 'numeric' });
    var price = h('input', { type: 'text', inputmode: 'decimal', value: f.price || '' });
    var note = h('textarea', { rows: '2', placeholder: 'Комментарий к заказу' });

    var prod = selectEl(ref.products.map(function (p) {
      return { value: String(p.id), label: p.name + ' — ' + money(p.base || p.price), selected: String(p.id) === f.product };
    }), { onchange: function () { f.product = prod.value; syncPrice(); } });
    if (!f.product && ref.products.length) f.product = prod.value;

    function baseFor(p) {
      var rules = (ref.pricing && ref.pricing.base) || {};
      var hay = [(f.typeLabel || ''), (p ? p.name : '')];
      for (var i = 0; i < hay.length; i++) {
        var low = String(hay[i]).toLowerCase();
        if (!low) continue;
        for (var needle in rules) {
          if (low.indexOf(needle) >= 0) return rules[needle];
        }
      }
      return p ? (p.base || p.price) : 0;
    }

    function syncPrice() {
      if (f.manualPrice) return; // сумму правили руками — не перебиваем
      var p = ref.products.filter(function (x) { return String(x.id) === prod.value; })[0];
      var n = Math.max(1, Math.min(50, parseInt(qty.value, 10) || 1));
      var pr = ref.pricing || { side: 0, sleeve: 0 };
      var extra = Math.max(0, f.sides.length - 1) * pr.side + (f.sleeve ? pr.sleeve : 0);
      price.value = String(Math.round((baseFor(p) + extra) * n * 100) / 100);
    }
    price.addEventListener('input', function () { f.manualPrice = true; });
    qty.addEventListener('input', syncPrice);
    if (!price.value) syncPrice();

    // Печать бывает с нескольких сторон сразу, поэтому не список, а
    // флажки: спереди + сзади, спереди + рукав и так далее.
    var sideField = null;
    var garment = [];
    ref.fields.forEach(function (fl) {
      if (fl.key === 'side') { sideField = fl; return; }
      var sel = selectEl([{ value: '', label: '—' }].concat(fl.options.map(function (o) {
        return { value: o.slug, label: o.label, selected: f.garment[fl.key] === o.slug };
      })), { onchange: function () {
        f.garment[fl.key] = sel.value;
        f.typeLabel = fl.key === 'type' ? (sel.options[sel.selectedIndex] || {}).text : f.typeLabel;
        syncPrice();
      } });
      garment.push(h('div', { class: 'f-col' }, [h('label', { class: 'f-label', text: fl.label }), sel]));
    });

    function toggleSide(slug, on) {
      f.sides = f.sides.filter(function (x) { return x !== slug; });
      if (on) f.sides.push(slug);
      syncPrice();
    }

    var sideBoxes = sideField ? h('div', { class: 'boxes' }, sideField.options.map(function (o) {
      return h('label', { class: 'box' }, [
        h('input', { type: 'checkbox', checked: f.sides.indexOf(o.slug) >= 0 ? 'checked' : null,
          onchange: function (e) { toggleSide(o.slug, e.target.checked); } }),
        h('span', { text: o.label })
      ]);
    }).concat([
      h('label', { class: 'box' }, [
        h('input', { type: 'checkbox', checked: f.sleeve ? 'checked' : null,
          onchange: function (e) { f.sleeve = e.target.checked; syncPrice(); } }),
        h('span', { text: 'Рукав' })
      ])
    ])) : null;

    var pay = selectEl(ref.payments.map(function (p) {
      return { value: p.key, label: p.label, selected: f.pay === p.key };
    }), { onchange: function () { f.pay = pay.value; } });
    if (!f.pay) f.pay = pay.value;

    var ship = h('div', { class: 'seg' }, [
      h('button', { class: 'chip', type: 'button', 'aria-pressed': f.pickup ? 'true' : 'false', onclick: function () { f.pickup = true; renderDetail(); } }, ['Самовывоз']),
      h('button', { class: 'chip', type: 'button', 'aria-pressed': f.pickup ? 'false' : 'true', onclick: function () { f.pickup = false; renderDetail(); } }, ['Доставка'])
    ]);

    // Клиент из недавних чатов без заказа: переписка сразу приедет в
    // заказ вместе с тем, что успело накопиться до привязки.
    var clients = ref.clients.length ? h('div', { class: 'clients' }, ref.clients.map(function (c) {
      var hide = h('button', {
        class: 'client-x', type: 'button', 'aria-label': 'Убрать из списка',
        onclick: function (e) {
          e.stopPropagation();
          ref.clients = ref.clients.filter(function (x) { return x.key !== c.key; });
          if (f.client === c.key) f.client = '';
          renderDetail();
          api('clients/hide', null, { key: c.key }).catch(function () { toast('Не удалось убрать чат'); });
        }
      }, ['×']);
      return h('div', { class: 'client-row' }, [h('button', {
        class: 'client' + (f.client === c.key ? ' on' : ''), type: 'button',
        onclick: function () {
          if (f.client === c.key) { f.client = ''; }
          else {
            f.client = c.key;
            f.name = c.name || f.name;
            f.nick = c.username ? '@' + c.username : f.nick;
          }
          f.qty = qty.value; f.price = price.value; f.phone = phone.value; f.note = note.value;
          f.name = name.value || f.name; f.nick = nick.value || f.nick;
          renderDetail();
        }
      }, [
        h('div', { class: 'act-l1', text: (c.username ? '@' + c.username : c.name || 'без ника') }),
        h('div', { class: 'act-l2', text: (c.last || '') + ' · ' + ago(c.t, ref.now) })
      ]), hide]);
    })) : h('div', { class: 'next-hint', text: 'Недавних чатов без заказа нет. Заказ можно создать и привязать переписку позже командой /link в чате с клиентом.' });

    var submit = h('button', { class: 'btn primary wide', type: 'button', disabled: f.busy, onclick: function () {
      var body = {
        product_id: parseInt(prod.value, 10),
        qty: Math.max(1, Math.min(50, parseInt(qty.value, 10) || 1)),
        price: price.value,
        name: name.value.trim(),
        username: nick.value.trim(),
        phone: phone.value.trim(),
        payment: pay.value,
        pickup: f.pickup,
        note: note.value.trim(),
        garment: Object.assign({}, f.garment, { side: f.sides }),
        sleeve: !!f.sleeve,
        client_key: f.client || ''
      };
      if (!body.name && !body.username) { alertBox('Укажите имя или ник клиента'); return; }
      f.busy = true;
      renderDetail();
      api('new', null, body).then(function (d) {
        f.busy = false;
        state.newOpen = false;
        state.form = null;
        state.detailId = d.id;
        state.detail = d;
        lsSet('order:' + d.id, d);
        renderDetail();
        syncBackButton();
        haptic('success');
        toast('Заказ #' + d.number + ' создан');
        loadList(false, 'user');
      }).catch(function (err) {
        f.busy = false;
        renderDetail();
        if (err.status === 401 || err.status === 403) { root.replaceChildren(fatal(err)); return; }
        haptic('error');
        alertBox(err.message || 'Не получилось создать заказ');
      });
    } }, [f.busy ? 'Создаём…' : 'Создать заказ']);

    pane.replaceChildren(h('div', { class: 'detail form' }, [
      back,
      h('div', { class: 'd-head' }, [h('div', { class: 'd-num', text: 'Новый заказ' })]),
      h('div', { class: 'd-sub', text: 'Для тех, кто пришёл сразу в Telegram' }),
      section('Клиент', [clients, field('Имя', name), field('Ник', nick), field('Телефон', phone)]),
      section('Заказ', [
        field('Товар', prod),
        h('div', { class: 'f-grid' }, garment),
        sideBoxes ? field((sideField ? sideField.label : 'Печать') + ' — можно несколько', sideBoxes,
          'Вторая сторона +' + money((ref.pricing || {}).side || 0) + ', рукав +' + money((ref.pricing || {}).sleeve || 0)) : null,
        h('div', { class: 'f-grid' }, [
          h('div', { class: 'f-col' }, [h('label', { class: 'f-label', text: 'Количество' }), qty]),
          h('div', { class: 'f-col' }, [h('label', { class: 'f-label', text: 'Сумма' }), price])
        ]),
        field('Оплата', pay, 'Нужно для отчётов'),
        field('Получение', ship),
        field('Комментарий', note)
      ]),
      submit,
      h('div', { class: 'next-hint', text: 'Заказ создаётся в статусе «Получен», карточка уходит в группу. Картинкой заказа станет то, что отправите клиенту в переписке.' })
    ]));
  }

  /* ── отчёт (2.10.0) ──────────────────────────────────────── */

  function ymd(d) {
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }

  var PRESETS = [
    ['today', 'Сегодня'], ['7d', '7 дней'], ['month', 'Этот месяц'],
    ['prev', 'Прошлый месяц'], ['30d', '30 дней'], ['custom', 'Период']
  ];

  function presetRange(key) {
    var t = new Date();
    var d0 = new Date(t.getFullYear(), t.getMonth(), t.getDate());
    switch (key) {
      case 'today': return [ymd(d0), ymd(d0)];
      case '7d': return [ymd(new Date(d0.getTime() - 6 * 864e5)), ymd(d0)];
      case '30d': return [ymd(new Date(d0.getTime() - 29 * 864e5)), ymd(d0)];
      case 'prev': return [ymd(new Date(t.getFullYear(), t.getMonth() - 1, 1)), ymd(new Date(t.getFullYear(), t.getMonth(), 0))];
      default: return [ymd(new Date(t.getFullYear(), t.getMonth(), 1)), ymd(d0)];
    }
  }

  function openReport() {
    state.detailId = 0;
    state.detail = null;
    state.reportOpen = true;
    if (!state.report) {
      var preset = lsGet('report_preset') || 'month';
      var r = preset === 'custom' ? (lsGet('report_custom') || presetRange('month')) : presetRange(preset);
      state.report = { preset: preset, from: r[0], to: r[1], data: null, loading: false };
    }
    document.querySelector('.shell').classList.add('has-detail');
    document.querySelector('.pane-detail').scrollTop = 0;
    syncBackButton();
    renderList();
    loadReport();
  }

  function loadReport() {
    var r = state.report;
    var key = r.from + '|' + r.to;
    r.loading = true;
    r.key = key;
    renderDetail();
    api('report', { from: r.from, to: r.to }).then(function (d) {
      if (r.key !== key) return;
      r.data = d;
      r.loading = false;
      renderDetail();
    }).catch(function (err) {
      if (r.key !== key) return;
      r.loading = false;
      if (err.status === 401 || err.status === 403) { root.replaceChildren(fatal(err)); return; }
      r.error = err.message || (err.code === 'network' ? 'Нет связи' : 'Ошибка');
      renderDetail();
    });
  }

  function delta(cur, prev) {
    if (!prev) return null;
    var pct = Math.round((cur - prev) / prev * 100);
    if (!isFinite(pct)) return null;
    return h('span', { class: 'delta ' + (pct > 0 ? 'up' : pct < 0 ? 'down' : ''), text: (pct > 0 ? '+' : '') + pct + '%' });
  }

  function kpi(label, value, extra) {
    return h('div', { class: 'kpi' }, [
      h('div', { class: 'kpi-l', text: label }),
      h('div', { class: 'kpi-v num', text: value }),
      extra ? h('div', { class: 'kpi-x' }, extra) : null
    ]);
  }

  var PAY_COLORS = { cash: '#3ecf6e', terminal: '#4aa3ff', online: '#ff6a00', link: '#b58cff', other: '#8b8b92' };

  function seriesChart(series) {
    if (!series || !series.length) return null;
    var W = 640, H = 170, P = 22;
    var max = Math.max.apply(null, series.map(function (b) { return b.sum; }).concat([1]));
    var bw = (W - P) / series.length;
    var step = Math.ceil(series.length / 8);
    var ns = 'http://www.w3.org/2000/svg';
    var svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('viewBox', '0 0 ' + W + ' ' + (H + 20));
    svg.setAttribute('class', 'chart');
    svg.setAttribute('role', 'img');
    svg.setAttribute('aria-label', 'Выручка по ' + (series.length > 62 ? 'неделям' : 'дням'));
    series.forEach(function (b, i) {
      var bh = b.sum > 0 ? Math.max(2, (b.sum / max) * (H - 10)) : 0;
      var r = document.createElementNS(ns, 'rect');
      r.setAttribute('x', P + i * bw + bw * 0.15);
      r.setAttribute('y', H - bh);
      r.setAttribute('width', Math.max(1, bw * 0.7));
      r.setAttribute('height', bh);
      r.setAttribute('rx', Math.min(4, bw * 0.2));
      r.setAttribute('class', 'bar');
      var t = document.createElementNS(ns, 'title');
      t.textContent = b.label + ': ' + money(b.sum) + ', выдано ' + b.count;
      r.appendChild(t);
      svg.appendChild(r);
      if (i % step === 0) {
        var tx = document.createElementNS(ns, 'text');
        tx.setAttribute('x', P + i * bw + bw / 2);
        tx.setAttribute('y', H + 15);
        tx.setAttribute('text-anchor', 'middle');
        tx.setAttribute('class', 'axis');
        tx.textContent = b.label;
        svg.appendChild(tx);
      }
    });
    var base = document.createElementNS(ns, 'line');
    base.setAttribute('x1', P); base.setAttribute('x2', W); base.setAttribute('y1', H); base.setAttribute('y2', H);
    base.setAttribute('class', 'base');
    svg.appendChild(base);
    return svg;
  }

  function renderReport(pane) {
    var r = state.report;
    var back = h('button', { class: 'detail-back', type: 'button', text: '‹ Заказы', onclick: closeDetail });

    var chips = h('div', { class: 'filters rep-presets' }, PRESETS.map(function (p) {
      return h('button', { class: 'chip', type: 'button', 'aria-pressed': r.preset === p[0] ? 'true' : 'false', onclick: function () {
        r.preset = p[0];
        lsSet('report_preset', p[0]);
        if (p[0] !== 'custom') {
          var rr = presetRange(p[0]);
          r.from = rr[0]; r.to = rr[1];
          loadReport();
        } else {
          renderDetail();
        }
      } }, [p[1]]);
    }));

    var custom = r.preset === 'custom' ? h('div', { class: 'rep-custom' }, [
      h('input', { type: 'date', value: r.from, 'aria-label': 'С', onchange: function (e) { r.from = e.target.value; } }),
      h('span', { text: '—' }),
      h('input', { type: 'date', value: r.to, 'aria-label': 'По', onchange: function (e) { r.to = e.target.value; } }),
      h('button', { class: 'btn primary', type: 'button', onclick: function () {
        if (r.from > r.to) { var x = r.from; r.from = r.to; r.to = x; }
        lsSet('report_custom', [r.from, r.to]);
        loadReport();
      } }, ['Показать'])
    ]) : null;

    var content;
    var d = r.data;
    if (r.loading && !d) {
      content = h('div', { class: 'empty' }, [h('span', { class: 'spin' })]);
    } else if (r.error && !d) {
      content = h('div', { class: 'empty', text: r.error });
    } else if (d) {
      var c = d.cur, pv = d.prev;
      var kpis = h('div', { class: 'kpis' }, [
        kpi('Выручка', money(c.revenue), [delta(c.revenue, pv.revenue), pv.revenue ? ' к прошлому периоду' : null]),
        kpi('Выдано заказов', String(c.issued), [delta(c.issued, pv.issued), c.items ? ' · вещей ' + c.items : null]),
        kpi('Средний чек', c.issued ? money(c.avg) : '—', [delta(c.avg, pv.avg)]),
        kpi('Отказы', String(c.refused), [c.refused || c.issued ? c.refused_share + '% от закрытых' : null, c.not_picked ? ' · не забрали ' + c.not_picked : null])
      ]);

      var pays = c.payments.length ? section('Способы оплаты', [
        h('div', { class: 'stack' }, c.payments.map(function (p) {
          return h('span', { style: 'flex:' + Math.max(p.share, 0.5) + ';background:' + (PAY_COLORS[p.key] || '#888'), title: p.label + ' ' + p.share + '%' });
        })),
        h('div', { class: 'card' }, c.payments.map(function (p) {
          return h('div', { class: 'kv pay' }, [
            h('div', { class: 'k' }, [h('i', { class: 'dot-c', style: 'background:' + (PAY_COLORS[p.key] || '#888') }), p.label]),
            h('div', { class: 'v num' }, [h('b', { text: p.share + '%' }), ' · ' + money(p.sum) + ' · ' + p.count + ' ' + plural(p.count, 'заказ', 'заказа', 'заказов')])
          ]);
        })),
        h('div', { class: 'next-hint', text: 'По способу, выбранному при оформлении заказа.' })
      ]) : null;

      var chart = c.issued ? section('Выручка по ' + (c.series.length && d.days > 62 ? 'неделям' : 'дням'), [h('div', { class: 'card chart-wrap' }, [seriesChart(c.series)])]) : null;

      content = h('div', { class: r.loading ? 'loading' : null }, [
        kpis,
        c.issued ? null : h('div', { class: 'empty', text: 'За этот период выданных заказов нет' }),
        pays,
        chart,
        h('div', { class: 'next-hint rep-note', text: 'Учитываются заказы, прошедшие через приложение (с ' +
          new Date(d.since * 1000).toLocaleDateString('ru-RU') + '). Выручка — по дате выдачи, отказы — по дате отметки.' })
      ]);
    }

    var scrollTop = pane.scrollTop;
    pane.replaceChildren(h('div', { class: 'detail report' }, [
      back,
      h('div', { class: 'd-head' }, [h('div', { class: 'd-num', text: 'Отчёт' })]),
      chips, custom, content
    ]));
    pane.scrollTop = scrollTop;
  }

  /* ── действия ────────────────────────────────────────────── */

  function editRow(label, val, changed, onPick) {
    return h('button', { class: 'kv edit', type: 'button', disabled: state.busy, onclick: onPick }, [
      h('div', { class: 'k', text: label }),
      h('div', { class: 'v' + (changed ? ' chg' : '') }, [val]),
      h('span', { class: 'chev', 'aria-hidden': 'true', text: '›' })
    ]);
  }

  function statusBar(d) {
    var st = d.status;
    if (!st.next.length && !st.back && !st.refuse) return null;
    var busy = state.busy;
    var primary = st.next.map(function (n) {
      var isPrint = n.key === 'in-print';
      return h('button', { class: 'btn primary grow', type: 'button', disabled: busy, onclick: function () {
        if (isPrint && st.verify) {
          confirmBox(verifyText(d), function (ok) { if (ok) doAction({ action: 'status', to: n.key, verified: true }, n.label); });
          return;
        }
        doAction({ action: 'status', to: n.key }, n.label);
      } }, [n.label]);
    });
    var secondary = [];
    if (st.back) {
      secondary.push(h('button', { class: 'btn ghost', type: 'button', disabled: busy, title: 'Вернуть статус', onclick: function () {
        doAction({ action: 'status', to: st.back.key }, 'Возвращён: ' + st.back.label);
      } }, ['↩︎ ' + st.back.label]));
    }
    if (st.refuse) {
      secondary.push(h('button', { class: 'btn ghost bad', type: 'button', disabled: busy, onclick: function () {
        confirmBox('Отметить заказ #' + d.number + ' как «Отказались»?', function (ok) {
          if (ok) doAction({ action: 'status', to: 'refused' }, '🚫 Отказались');
        });
      } }, ['🚫 Отказ']));
    }
    return h('div', { class: 'actbar' }, [
      primary.length ? h('div', { class: 'actbar-row' }, primary) : null,
      secondary.length ? h('div', { class: 'actbar-row' }, secondary) : null
    ]);
  }

  function verifyText(d) {
    return 'В заказе правки параметров. Сверьте с принтом перед печатью:\n\n' +
      d.overrides.map(function (r) { return '• ' + r.label + ': ' + r.from + ' → ' + r.to; }).join('\n') +
      '\n\nВсё сверено — отправить в печать?';
  }

  function openPayMark(d) {
    var close = function () { sheet.remove(); };
    var cur = d.payment.mark || '';
    var sheet = h('div', { class: 'sheet-wrap', onclick: function (e) { if (e.target === sheet) close(); } }, [
      h('div', { class: 'sheet', role: 'dialog', 'aria-label': 'Оплата' }, [
        h('div', { class: 'sheet-head' }, [
          h('b', { text: 'Как оплатили?' }),
          h('span', { class: 'sheet-sub', text: 'Заказ #' + d.number + ' · ' + money(d.payment.total) })
        ]),
        h('div', { class: 'opts' }, d.payment.mark_opts.map(function (o) {
          return h('button', {
            class: 'opt' + (o.key === cur ? ' on' : ''), type: 'button',
            onclick: function () {
              close();
              if (o.key === cur) return;
              doAction({ action: 'pay_mark', mark: o.key }, '✅ Оплачено: ' + o.label.toLowerCase());
            }
          }, [o.label]);
        })),
        cur ? h('button', { class: 'btn ghost wide', type: 'button', onclick: function () {
          close();
          confirmBox('Снять отметку об оплате с заказа #' + d.number + '?', function (ok) {
            if (ok) doAction({ action: 'pay_mark', mark: '' }, 'Отметка снята');
          });
        } }, ['↩︎ Не оплачен']) : null,
        h('button', { class: 'btn wide', type: 'button', onclick: close }, ['Отмена'])
      ])
    ]);
    document.body.appendChild(sheet);
  }

  function openPicker(item, field, current) {
    var close = function () { sheet.remove(); };
    var cur = current ? current.slug : '';
    var ovr = (state.detail.overrides || []).filter(function (r) { return r.item_id === item.item_id && r.field === field.key; })[0];
    var sheet = h('div', { class: 'sheet-wrap', onclick: function (e) { if (e.target === sheet) close(); } }, [
      h('div', { class: 'sheet', role: 'dialog', 'aria-label': field.label }, [
        h('div', { class: 'sheet-head' }, [
          h('b', { text: field.label }),
          h('span', { class: 'sheet-sub', text: item.name })
        ]),
        h('div', { class: 'opts' + (field.key === 'size' ? ' opts-4' : '') }, field.options.map(function (o) {
          return h('button', {
            class: 'opt' + (o.slug === cur ? ' on' : ''), type: 'button',
            onclick: function () {
              close();
              if (o.slug === cur) return;
              doAction({ action: 'override', item_id: item.item_id, field: field.key, value: o.slug }, field.label + ' → ' + o.label);
            }
          }, [o.label]);
        })),
        field.key === 'color' ? h('div', { class: 'next-hint', text: 'Смена цвета помечает макет как устаревший.' }) : null,
        ovr ? h('button', { class: 'btn ghost wide', type: 'button', onclick: function () {
          close();
          doAction({ action: 'override_clear', item_id: item.item_id, field: field.key }, field.label + ': исходное');
        } }, ['↩︎ Вернуть «' + ovr.from + '»']) : null,
        h('button', { class: 'btn wide', type: 'button', onclick: close }, ['Отмена'])
      ])
    ]);
    document.body.appendChild(sheet);
  }

  function doAction(body, okText) {
    if (!state.detailId) return;
    doActionFor(state.detailId, body, okText);
  }

  function doActionFor(id, body, okText, onOk) {
    if (state.busy) return;
    var inDetail = id === state.detailId;
    state.busy = true;
    renderDetail();
    renderList();
    api('order/' + id + '/do', null, body).then(function (d) {
      state.busy = false;
      lsSet('order:' + id, d);
      if (state.detailId === id) { state.detail = d; }
      renderDetail();
      haptic('success');
      if (okText) toast(okText);
      if (onOk) onOk(d);
      loadList(false);
    }).catch(function (err) {
      state.busy = false;
      if (err.status === 401 || err.status === 403) { root.replaceChildren(fatal(err)); return; }
      haptic('error');
      renderList();
      if (err.code === 'ptg_need_verify' && !inDetail) {
        toast('Нужна сверка правок — открыл заказ');
        openDetail(id);
        return;
      }
      if (err.code === 'ptg_need_verify' && state.detail) {
        renderDetail();
        confirmBox(verifyText(state.detail), function (ok) {
          if (ok) doActionFor(id, Object.assign({}, body, { verified: true }), okText, onOk);
        });
        return;
      }
      if (err.code === 'ptg_stale_state') {
        toast(err.message || 'Статус уже изменился');
        if (inDetail) refreshDetail();
        loadList(false);
        return;
      }
      renderDetail();
      alertBox(err.code === 'network' ? 'Нет связи — действие не выполнено' : (err.message || 'Не получилось'));
    });
  }

  function refreshDetail() {
    var id = state.detailId;
    if (!id) return;
    api('order/' + id).then(function (d) {
      if (state.detailId !== id) return;
      state.detail = d;
      lsSet('order:' + id, d);
      renderDetail();
    }).catch(function () { /* оставляем, что есть */ });
  }

  /* ── старт ───────────────────────────────────────────────── */

  function shell() {
    root.replaceChildren(h('div', { class: 'shell' }, [
      h('div', { class: 'pane-list' }),
      h('div', { class: 'pane-detail' })
    ]));
    renderList();
    renderDetail();
  }

  function start() {
    if (!tg || !tg.initData) {
      root.replaceChildren(fatal(new ApiError(401, 'ptg_app_unauthorized')));
      return;
    }
    tg.ready();
    tg.expand();
    if (tg.BackButton) tg.BackButton.onClick(closeDetail);
    if (tg.checkHomeScreenStatus && tg.isVersionAtLeast && tg.isVersionAtLeast('8.0')) {
      try {
        tg.checkHomeScreenStatus(function (status) { state.homeStatus = status; renderList(); });
        tg.onEvent('homeScreenAdded', function () { state.homeStatus = 'added'; toast('Ярлык добавлен'); renderList(); });
      } catch (e) { /* старый клиент */ }
    }
    if (tg.setHeaderColor) { try { tg.setHeaderColor('bg_color'); } catch (e) { /* старые клиенты */ } }

    state.tab = lsGet('tab') || 'work';
    state.sort = lsGet('sort') || '';
    state.filters = lsGet('filters') || [];
    state.view = lsGet('view:' + state.tab);

    api('me').then(function (me) {
      state.me = me;
      state.tabs = me.tabs;
      if (!state.tabs.some(function (t) { return t.key === state.tab; })) state.tab = 'work';
      if (!me.show_assignee) state.filters = state.filters.filter(function (x) { return x !== 'mine' && x !== 'free'; });
      shell();
      loadList(false, 'boot');

      var m = /^o(\d+)$/.exec(me.start_param || '');
      if (m) openDetail(parseInt(m[1], 10));
    }).catch(function (err) {
      root.replaceChildren(fatal(err));
    });

    // Вернулись в приложение — обновляем список; открытое долго — раз в минуту.
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState !== 'visible' || !state.me) return;
      loadList(false);
      // Вернулись из чата с клиентом — заказ должен показать новые
      // сообщения и подсказку «вы отправили изображение».
      if (state.detailId) {
        refreshDetail();
        lsSet('last', { id: state.detailId, number: state.detail ? state.detail.number : state.detailId, t: Date.now() });
      }
    });
    setInterval(function () {
      if (document.visibilityState === 'visible' && state.me && !state.q) loadList(false);
    }, 60000);
    window.addEventListener('resize', syncBackButton);
  }

  start();
})();

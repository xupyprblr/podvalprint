/**
 * Мета-бокс «Лендинг услуги». Vanilla JS, без jQuery.
 *
 * Форма строится по схеме PodvalSLAdmin.schema (та же podval_sl_schema(), что
 * чистит данные на сервере). Состояние живёт в объекте state, любое изменение
 * сериализуется в скрытый #podval_sl_json — его и читает save_post_page.
 */
(function () {
  'use strict';

  var cfg = window.PodvalSLAdmin;
  var root = document.getElementById('msl-editor');
  var hidden = document.getElementById('podval_sl_json');
  if (!cfg || !root || !hidden) return;

  var state;
  try { state = JSON.parse(hidden.value) || {}; } catch (e) { state = {}; }

  function sync() { hidden.value = JSON.stringify(state); }

  function el(tag, attrs, children) {
    var n = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) {
      if (k === 'text') n.textContent = attrs[k];
      else if (k === 'class') n.className = attrs[k];
      else n.setAttribute(k, attrs[k]);
    });
    (children || []).forEach(function (c) { if (c) n.appendChild(c); });
    return n;
  }

  function defaultFor(f) {
    if (Object.prototype.hasOwnProperty.call(f, 'default')) return JSON.parse(JSON.stringify(f['default']));
    switch (f.type) {
      case 'group':
        var o = {};
        f.fields.forEach(function (s) { o[s.key] = defaultFor(s); });
        return o;
      case 'sections':
        return (cfg.sections || []).map(function (sec) { return { key: sec.key, on: true }; });
      case 'repeater': case 'keys': case 'text_list': case 'color_list': case 'id_list': return [];
      case 'bool': return false;
      case 'int': case 'id': case 'image': return 0;
      case 'select': return Object.keys(f.options)[0] || '';
    }
    return '';
  }

  function label(f, forId) {
    var l = el('label', { 'class': 'msl-label', text: f.label });
    if (forId) l.setAttribute('for', forId);
    return l;
  }
  function help(f) { return f.help ? el('p', { 'class': 'msl-help', text: f.help }) : null; }

  var uid = 0;

  /* Поле. obj — родительский объект, f.key — ключ в нём. */
  function field(f, obj) {
    if (obj[f.key] === undefined) obj[f.key] = defaultFor(f);
    var id = 'msl-f' + (++uid);
    var wrap = el('div', { 'class': 'msl-field msl-field--' + f.type });
    var input;

    switch (f.type) {
      case 'group':
        var fs = el('fieldset', { 'class': 'msl-group' }, [el('legend', { text: f.label })]);
        if (f.help) fs.appendChild(help(f));
        if (typeof obj[f.key] !== 'object' || Array.isArray(obj[f.key])) obj[f.key] = defaultFor(f);
        f.fields.forEach(function (s) { fs.appendChild(field(s, obj[f.key])); });
        return fs;

      case 'repeater':
        return repeater(f, obj);

      case 'sections':
        return sections(f, obj);

      case 'textarea': case 'html': case 'text_list':
        input = el('textarea', { id: id, rows: f.type === 'text_list' ? 4 : (f.type === 'html' ? 4 : 3) });
        input.value = f.type === 'text_list' ? (obj[f.key] || []).join('\n') : (obj[f.key] || '');
        input.addEventListener('input', function () {
          obj[f.key] = f.type === 'text_list'
            ? input.value.split('\n').map(function (s) { return s.trim(); }).filter(Boolean)
            : input.value;
          sync();
        });
        wrap.appendChild(label(f, id));
        wrap.appendChild(input);
        if (f.type === 'text_list') wrap.appendChild(el('p', { 'class': 'msl-help', text: 'По одному на строку.' }));
        break;

      case 'int': case 'id':
        input = el('input', { id: id, type: 'number', step: '1', min: f.type === 'id' ? '0' : '' });
        input.value = obj[f.key] || (f.type === 'int' ? 0 : '');
        input.addEventListener('input', function () { obj[f.key] = parseInt(input.value, 10) || 0; sync(); });
        wrap.appendChild(label(f, id));
        wrap.appendChild(input);
        break;

      case 'bool':
        input = el('input', { id: id, type: 'checkbox' });
        input.checked = !!obj[f.key];
        input.addEventListener('change', function () { obj[f.key] = input.checked; sync(); });
        var lb = el('label', { 'class': 'msl-check', 'for': id });
        lb.appendChild(input);
        lb.appendChild(document.createTextNode(' ' + f.label));
        wrap.appendChild(lb);
        break;

      case 'select':
        input = el('select', { id: id });
        Object.keys(f.options).forEach(function (k) {
          var o = el('option', { value: k, text: f.options[k] });
          if (String(obj[f.key]) === k) o.selected = true;
          input.appendChild(o);
        });
        input.addEventListener('change', function () { obj[f.key] = input.value; sync(); });
        wrap.appendChild(label(f, id));
        wrap.appendChild(input);
        break;

      case 'keys':
        wrap.appendChild(el('span', { 'class': 'msl-label', text: f.label }));
        var box = el('div', { 'class': 'msl-keys' });
        if (!Array.isArray(obj[f.key])) obj[f.key] = [];
        Object.keys(f.options).forEach(function (k) {
          var cb = el('input', { type: 'checkbox', value: k });
          cb.checked = obj[f.key].indexOf(k) !== -1;
          cb.addEventListener('change', function () {
            // Порядок ключей = порядок опций реестра, а не порядок кликов.
            obj[f.key] = Object.keys(f.options).filter(function (kk) {
              var c = box.querySelector('input[value="' + kk + '"]');
              return c && c.checked;
            });
            sync();
          });
          var l2 = el('label', { 'class': 'msl-check' });
          l2.appendChild(cb);
          l2.appendChild(document.createTextNode(' ' + f.options[k]));
          box.appendChild(l2);
        });
        wrap.appendChild(box);
        break;

      case 'image':
        wrap.appendChild(el('span', { 'class': 'msl-label', text: f.label }));
        var prev = el('div', { 'class': 'msl-img' });
        var pick = el('button', { type: 'button', 'class': 'button', text: 'Выбрать' });
        var clear = el('button', { type: 'button', 'class': 'button-link msl-danger', text: 'Убрать' });
        var drawPreview = function () {
          prev.innerHTML = '';
          var aid = parseInt(obj[f.key], 10) || 0;
          if (!aid) { prev.appendChild(el('span', { 'class': 'msl-noimg', text: 'нет фото' })); return; }
          prev.appendChild(el('span', { 'class': 'msl-noimg', text: 'ID ' + aid }));
          if (window.wp && wp.media && wp.media.attachment) {
            var att = wp.media.attachment(aid);
            att.fetch().then(function () {
              var j = att.toJSON();
              var url = (j.sizes && (j.sizes.thumbnail || j.sizes.medium)) ? (j.sizes.thumbnail || j.sizes.medium).url : j.url;
              if (url && parseInt(obj[f.key], 10) === aid) {
                prev.innerHTML = '';
                prev.appendChild(el('img', { src: url, alt: '' }));
              }
            });
          }
        };
        pick.addEventListener('click', function () {
          var frame = wp.media({ title: f.label, library: { type: 'image' }, button: { text: 'Выбрать' }, multiple: false });
          frame.on('select', function () {
            obj[f.key] = frame.state().get('selection').first().toJSON().id;
            sync();
            drawPreview();
          });
          frame.open();
        });
        clear.addEventListener('click', function () { obj[f.key] = 0; sync(); drawPreview(); });
        drawPreview();
        wrap.appendChild(el('div', { 'class': 'msl-img-row' }, [prev, pick, clear]));
        break;

      case 'color_list': case 'id_list':
        input = el('input', { id: id, type: 'text' });
        input.value = (obj[f.key] || []).join(', ');
        var sw = el('span', { 'class': 'msl-swatches' });
        var drawSw = function () {
          if (f.type !== 'color_list') return;
          sw.innerHTML = '';
          (obj[f.key] || []).forEach(function (c) { var s = el('i'); s.style.background = c; sw.appendChild(s); });
        };
        input.addEventListener('input', function () {
          var parts = input.value.split(/[\s,;]+/).map(function (s) { return s.trim(); }).filter(Boolean);
          obj[f.key] = f.type === 'id_list'
            ? parts.map(function (s) { return parseInt(s, 10) || 0; }).filter(Boolean)
            : parts.filter(function (s) { return /^#([0-9a-f]{3}){1,2}$/i.test(s); });
          sync();
          drawSw();
        });
        drawSw();
        wrap.appendChild(label(f, id));
        wrap.appendChild(el('div', { 'class': 'msl-inline' }, [input, sw]));
        break;

      default: // text, url, date
        input = el('input', { id: id, type: f.type === 'url' ? 'url' : (f.type === 'date' ? 'date' : 'text') });
        input.value = obj[f.key] || '';
        input.addEventListener('input', function () { obj[f.key] = input.value; sync(); });
        wrap.appendChild(label(f, id));
        wrap.appendChild(input);
    }
    if (f.help && f.type !== 'group') wrap.appendChild(help(f));
    return wrap;
  }

  function repeater(f, obj) {
    if (!Array.isArray(obj[f.key])) obj[f.key] = [];
    var wrap = el('div', { 'class': 'msl-repeater' });

    function draw() {
      wrap.innerHTML = '';
      var list = obj[f.key];
      wrap.appendChild(el('div', { 'class': 'msl-label', text: f.label + (f.max ? ' (до ' + f.max + ')' : '') }));
      if (f.help) wrap.appendChild(help(f));
      list.forEach(function (row, i) {
        var item = el('div', { 'class': 'msl-item' });
        var head = el('div', { 'class': 'msl-item__head' }, [el('strong', { text: '#' + (i + 1) })]);
        var up = el('button', { type: 'button', 'class': 'button-link', text: '↑', title: 'Выше' });
        var dn = el('button', { type: 'button', 'class': 'button-link', text: '↓', title: 'Ниже' });
        var rm = el('button', { type: 'button', 'class': 'button-link msl-danger', text: 'Удалить' });
        up.disabled = i === 0;
        dn.disabled = i === list.length - 1;
        up.addEventListener('click', function () { list.splice(i - 1, 0, list.splice(i, 1)[0]); sync(); draw(); });
        dn.addEventListener('click', function () { list.splice(i + 1, 0, list.splice(i, 1)[0]); sync(); draw(); });
        rm.addEventListener('click', function () { list.splice(i, 1); sync(); draw(); });
        head.appendChild(el('span', { 'class': 'msl-item__tools' }, [up, dn, rm]));
        item.appendChild(head);
        f.fields.forEach(function (s) { item.appendChild(field(s, row)); });
        wrap.appendChild(item);
      });
      if (!f.max || list.length < f.max) {
        var add = el('button', { type: 'button', 'class': 'button', text: '+ Добавить' });
        add.addEventListener('click', function () {
          list.push(defaultFor({ type: 'group', fields: f.fields }));
          sync();
          draw();
        });
        wrap.appendChild(add);
      }
    }
    draw();
    return wrap;
  }

  /* Порядок и видимость секций: ↑/↓ + галочка. Состав всегда из cfg.sections
     (PHP-реестр), поэтому новая секция из обновления темы появится и здесь. */
  function sections(f, obj) {
    var known = cfg.sections || [];
    var byKey = {};
    known.forEach(function (s) { byKey[s.key] = s; });

    var saved = Array.isArray(obj[f.key]) ? obj[f.key].filter(function (r) { return r && byKey[r.key]; }) : [];
    var seen = {};
    saved = saved.filter(function (r) { if (seen[r.key]) return false; seen[r.key] = 1; return true; });
    known.forEach(function (s, i) {
      if (seen[s.key]) return;
      // Как podval_sl_clean() в PHP: ставим после ближайшей предшествующей
      // соседки по коду, иначе — перед ближайшей последующей, иначе в конец.
      var prev = known.slice(0, i).reverse().find(function (p) { return seen[p.key]; });
      var at;
      if (prev) {
        at = saved.findIndex(function (r) { return r.key === prev.key; }) + 1;
      } else {
        var next = known.slice(i + 1).find(function (nx) { return seen[nx.key]; });
        at = next ? saved.findIndex(function (r) { return r.key === next.key; }) : saved.length;
        if (at < 0) at = saved.length;
      }
      saved.splice(at, 0, { key: s.key, on: true });
      seen[s.key] = 1;
    });
    var pinned = known.filter(function (s) { return s.pinned; }).map(function (s) { return s.key; });
    saved.sort(function (a, b) { return (pinned.indexOf(b.key) !== -1) - (pinned.indexOf(a.key) !== -1); });
    saved.forEach(function (r) { if (pinned.indexOf(r.key) !== -1) r.on = true; });
    obj[f.key] = saved;

    var wrap = el('div', { 'class': 'msl-sections' });

    function draw() {
      wrap.innerHTML = '';
      wrap.appendChild(el('div', { 'class': 'msl-label', text: f.label }));
      if (f.help) wrap.appendChild(help(f));
      var list = obj[f.key];
      var movable = list.filter(function (r) { return pinned.indexOf(r.key) === -1; });
      list.forEach(function (row) {
        var isPinned = pinned.indexOf(row.key) !== -1;
        var item = el('div', { 'class': 'msl-sec' + (row.on ? '' : ' is-off') + (isPinned ? ' is-pinned' : '') });

        var cb = el('input', { type: 'checkbox' });
        cb.checked = !!row.on;
        cb.disabled = isPinned;
        cb.addEventListener('change', function () { row.on = cb.checked; sync(); draw(); });
        var lab = el('label', { 'class': 'msl-sec__label' });
        lab.appendChild(cb);
        lab.appendChild(document.createTextNode(' ' + byKey[row.key].label));
        item.appendChild(lab);

        var tools = el('span', { 'class': 'msl-sec__tools' });
        if (isPinned) {
          tools.appendChild(el('span', { 'class': 'msl-sec__pin', text: 'всегда первый' }));
        } else {
          var i = list.indexOf(row);
          var up = el('button', { type: 'button', 'class': 'button-link', text: '↑', title: 'Выше' });
          var dn = el('button', { type: 'button', 'class': 'button-link', text: '↓', title: 'Ниже' });
          up.disabled = movable.indexOf(row) === 0;
          dn.disabled = movable.indexOf(row) === movable.length - 1;
          up.addEventListener('click', function () { swap(i, -1); });
          dn.addEventListener('click', function () { swap(i, 1); });
          tools.appendChild(up);
          tools.appendChild(dn);
        }
        item.appendChild(tools);
        wrap.appendChild(item);
      });
    }

    // Меняем местами только с соседней НЕзакреплённой секцией.
    function swap(i, dir) {
      var list = obj[f.key];
      for (var j = i + dir; j >= 0 && j < list.length; j += dir) {
        if (pinned.indexOf(list[j].key) !== -1) continue;
        var t = list[i]; list[i] = list[j]; list[j] = t;
        break;
      }
      sync();
      draw();
    }

    draw();
    return wrap;
  }

  function render() {
    root.innerHTML = '';
    cfg.schema.forEach(function (f, i) {
      var det = el('details', { 'class': 'msl-section' });
      if (i === 0) det.open = true;
      det.appendChild(el('summary', { text: f.label }));
      var body = el('div', { 'class': 'msl-section__body' });
      if (f.type === 'group') {
        if (typeof state[f.key] !== 'object' || Array.isArray(state[f.key])) state[f.key] = defaultFor(f);
        if (f.help) body.appendChild(help(f));
        f.fields.forEach(function (s) { body.appendChild(field(s, state[f.key])); });
      } else {
        body.appendChild(field(f, state));
      }
      det.appendChild(body);
      root.appendChild(det);
    });
    sync();
  }

  render();

  var box = root.closest('.msl-box');
  box.querySelector('.msl-example').addEventListener('click', function () {
    if (!cfg.example) return;
    if (!window.confirm('Заменить все поля мета-бокса структурой лендинга №1? SEO-текст в редакторе не меняется.')) return;
    state = JSON.parse(JSON.stringify(cfg.example));
    render();
  });
  box.querySelector('.msl-expand').addEventListener('click', function () {
    root.querySelectorAll('details').forEach(function (d) { d.open = true; });
  });
  box.querySelector('.msl-collapse').addEventListener('click', function () {
    root.querySelectorAll('details').forEach(function (d) { d.open = false; });
  });

  /* Подсказка «выберите шаблон» — и в классическом, и в блочном редакторе. */
  var notice = box.querySelector('.msl-inactive');
  function setActive(tpl) {
    var on = tpl === cfg.template;
    notice.hidden = on;
    box.classList.toggle('is-inactive', !on);
  }
  setActive(box.getAttribute('data-is-template') === '1' ? cfg.template : '');
  var sel = document.getElementById('page_template');
  if (sel) sel.addEventListener('change', function () { setActive(sel.value); });
  if (window.wp && wp.data && wp.data.subscribe && wp.data.select) {
    var last = null;
    wp.data.subscribe(function () {
      var ed = wp.data.select('core/editor');
      if (!ed || !ed.getEditedPostAttribute) return;
      var t = ed.getEditedPostAttribute('template');
      if (t !== undefined && t !== last) { last = t; setActive(t); }
    });
  }
})();

/*
 * dc-static.js — подменяет support.js холста и разворачивает .dc.html в обычный HTML.
 * Понимает то, чем пользуются макеты: {{путь}}, <sc-for>, <sc-if>, <dc-import>, <helmet>,
 * класс Component extends DCLogic с renderVals(). Обработчики событий выбрасываются —
 * результат статичный. Запускается из render.mjs, вручную открывать не нужно.
 */
(function () {
  class DCLogic {
    constructor(props) { this.props = props || {}; this.state = {}; }
    setState(s) { Object.assign(this.state, s); }
    forceUpdate() {}
  }
  window.DCLogic = DCLogic;

  const cache = {};

  function parse(text) {
    const xm = text.match(/<x-dc>([\s\S]*?)<\/x-dc>/);
    let tpl = xm ? xm[1] : '';
    const sm = text.match(/<script type="text\/x-dc"[^>]*>([\s\S]*?)<\/script>/);
    const code = sm ? sm[1] : 'class Component extends DCLogic { renderVals() { return {}; } }';
    let helmet = '';
    tpl = tpl.replace(/<helmet>([\s\S]*?)<\/helmet>/, (m, h) => { helmet = h; return ''; });
    // <template> переживает таблицы и SVG, а <sc-for> парсер выкинул бы из <tbody>
    tpl = tpl
      .replace(/<sc-for\b/g, '<template data-sc="for"').replace(/<\/sc-for>/g, '</template>')
      .replace(/<sc-if\b/g, '<template data-sc="if"').replace(/<\/sc-if>/g, '</template>');
    const Comp = new Function('DCLogic', code + '\n;return Component;')(DCLogic);
    const imports = [...new Set([...tpl.matchAll(/<dc-import[^>]*\bname="([^"]+)"/g)].map((m) => m[1]))];
    const t = document.createElement('template');
    t.innerHTML = tpl;
    return { frag: t.content, helmet, Comp, imports };
  }

  async function preload(entry) {
    for (const n of entry.imports) {
      if (cache[n]) continue;
      const res = await fetch(n + '.dc.html');
      cache[n] = parse(await res.text());
      await preload(cache[n]);
    }
  }

  function lit(p) {
    if (p === 'true') return { ok: true, v: true };
    if (p === 'false') return { ok: true, v: false };
    if (p === 'null') return { ok: true, v: null };
    if (/^-?\d+(\.\d+)?$/.test(p)) return { ok: true, v: Number(p) };
    if (/^(['"]).*\1$/.test(p)) return { ok: true, v: p.slice(1, -1) };
    return { ok: false };
  }
  function get(scope, path) {
    const p = path.trim();
    const l = lit(p);
    if (l.ok) return l.v;
    return p.split('.').reduce((o, k) => (o == null ? undefined : o[k]), scope);
  }
  const WHOLE = /^\s*\{\{([^}]+)\}\}\s*$/;
  const interp = (s, scope) => s.replace(/\{\{([^}]+)\}\}/g, (m, p) => { const v = get(scope, p); return v == null ? '' : String(v); });
  const camel = (s) => s.replace(/-([a-z])/g, (m, c) => c.toUpperCase());

  function renderChildren(parent, scope, out) {
    const kids = parent.content ? parent.content.childNodes : parent.childNodes;
    kids.forEach((n) => renderNode(n, scope, out));
  }

  function renderComponent(entry, props, out) {
    const inst = new entry.Comp(props);
    const vals = inst.renderVals() || {};
    entry.frag.childNodes.forEach((n) => renderNode(n, vals, out));
  }

  function renderNode(node, scope, out) {
    if (node.nodeType === 3) { out.push(document.createTextNode(interp(node.nodeValue, scope))); return; }
    if (node.nodeType !== 1) return;
    const el = node;
    const sc = el.localName === 'template' && el.getAttribute('data-sc');
    if (sc === 'for') {
      const m = (el.getAttribute('list') || '').match(WHOLE);
      const list = m ? get(scope, m[1]) : [];
      const as = el.getAttribute('as') || 'item';
      (list || []).forEach((item, i) => {
        const s = Object.create(scope);
        s[as] = item; s.$index = i;
        renderChildren(el, s, out);
      });
      return;
    }
    if (sc === 'if') {
      const m = (el.getAttribute('value') || '').match(WHOLE);
      if (m && get(scope, m[1])) renderChildren(el, scope, out);
      return;
    }
    if (el.localName === 'dc-import') {
      const entry = cache[el.getAttribute('name')];
      if (!entry) return;
      const props = {};
      for (const a of el.attributes) {
        if (a.name === 'name' || a.name.startsWith('hint-')) continue;
        const m = a.value.match(WHOLE);
        props[camel(a.name)] = m ? get(scope, m[1]) : interp(a.value, scope);
      }
      renderComponent(entry, props, out);
      return;
    }
    const copy = el.cloneNode(false);
    for (const a of [...copy.attributes]) {
      if (/^on/i.test(a.name)) { copy.removeAttribute(a.name); continue; }
      if (!a.value.includes('{{')) continue;
      const m = a.value.match(WHOLE);
      if (m) {
        const v = get(scope, m[1]);
        if (v === undefined || v === null || v === false) { copy.removeAttribute(a.name); continue; }
        if (v === true) { copy.setAttribute(a.name, a.name.startsWith('aria-') ? 'true' : ''); continue; }
        copy.setAttribute(a.name, String(v));
      } else {
        copy.setAttribute(a.name, interp(a.value, scope));
      }
    }
    const kids = [];
    renderChildren(el, scope, kids);
    kids.forEach((k) => copy.appendChild(k));
    out.push(copy);
  }

  async function run() {
    const text = await (await fetch(location.href)).text();
    const page = parse(text);
    await preload(page);
    const h = document.createElement('template');
    h.innerHTML = page.helmet;
    document.head.appendChild(h.content);
    const out = [];
    renderComponent(page, {}, out);
    document.querySelectorAll('x-dc, script').forEach((n) => n.remove());
    out.forEach((n) => document.body.appendChild(n));
    window.__dcDone = true;
  }
  window.addEventListener('DOMContentLoaded', () => { run().catch((e) => { window.__dcError = String(e && e.stack || e); }); });
})();

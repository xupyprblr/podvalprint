// Собирает статичные HTML-экраны из исходников холста.
//   node design/tools/render.mjs            — все артборды из design/canvas/canvas.json
//   node design/tools/render.mjs Home Cart  — только перечисленные
// Нужен Playwright с Chromium: npm i -D playwright && npx playwright install chromium
// Результат: design/screens/<Имя>.html и design/screens/index.html.
import { createServer } from 'node:http';
import { readFile, writeFile, mkdir } from 'node:fs/promises';
import { extname, join, dirname, normalize } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..');
const canvasDir = join(root, 'canvas');
const outDir = join(root, 'screens');
const shim = await readFile(join(here, 'dc-static.js'), 'utf8');
const canvas = JSON.parse(await readFile(join(canvasDir, 'canvas.json'), 'utf8'));
const system = JSON.parse(await readFile(join(root, 'system', 'design-system.json'), 'utf8'));

// /_blob/<id> холста → файл логотипа в design/system/assets/Logos
const blobToSource = {
  '73088573c99f68d0d49374a6ab274401': '5e4f436e6fc5f5abe34c0ca76bb55e90',
  'b51a2f61d21f660946dc8b6bdaf4dd64': '8241c0200e6718fb563b3e3c6279a468',
  '2116f86914ea7684007672f332837d46': '9a1190b88ac90226b49dede0e21acefb',
  '5cad86ab2f98ec6e35ffd860596d7b49': 'b13ce5ca5aef5885967c45601af04e58',
  'd8bc5bbabce122c5f38a2d36e664af36': '90d7f7d16a9a3293f6686937debbfc67',
  '97c88025f0aec84e6da44eb18f91e718': '9778c07945960e2226c25b559cb70c54',
  '30cc87e12c9de17d9a463c397d483873': 'c9c3418f8254e147becf07ce90ba0fe5',
  '5add9b74f9f89a9ed68a4ef398e57a1d': 'c8e9f072d29f7232e1a4a6301d84e9bc',
  'bd18647f129f152bc8e0604b50a269fc': '1ca357f8537ba5db1488a214b1d4a6ed',
  'da9bcf3cb3d40fa8b95089bee0a8544e': '285acfbdae2b6eeba588d7890068918b',
  '30928df06b73ac3d2f9f7382474af9af': '8a7bc5e758ab72f8699b904b7a2832e7',
  '536967daf6d5223868a77e364be98869': '1cad7e11ec1cce6071b38e1518c55001',
};
const sourceToName = {};
for (const [name, rec] of Object.entries(system.assetGroups.Logos.files)) sourceToName[rec.blob] = name;

const types = { '.html': 'text/html; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.js': 'text/javascript' };
const server = createServer(async (req, res) => {
  const path = decodeURIComponent(req.url.split('?')[0]);
  if (path.endsWith('/support.js')) { res.writeHead(200, { 'content-type': 'text/javascript' }); return res.end(shim); }
  const file = normalize(join(canvasDir, path));
  if (!file.startsWith(canvasDir)) { res.writeHead(403); return res.end(); }
  try {
    const body = await readFile(file);
    res.writeHead(200, { 'content-type': types[extname(file)] || 'application/octet-stream' });
    res.end(body);
  } catch { res.writeHead(404); res.end(); }
});
await new Promise((r) => server.listen(0, '127.0.0.1', r));
const base = `http://127.0.0.1:${server.address().port}/`;

const only = process.argv.slice(2);
const boards = canvas.order.filter((f) => !only.length || only.includes(f.replace('.dc.html', '')));
await mkdir(outDir, { recursive: true });
const browser = await chromium.launch();
const done = [];
for (const file of boards) {
  const b = canvas.boards[file];
  const page = await browser.newPage({ viewport: { width: b.w, height: 900 } });
  await page.goto(base + file);
  await page.waitForFunction(() => window.__dcDone || window.__dcError, null, { timeout: 30000 });
  const err = await page.evaluate(() => window.__dcError);
  if (err) { console.error('✗', file, err); await page.close(); continue; }
  let html = '<!doctype html>\n' + await page.evaluate(() => document.documentElement.outerHTML);
  html = html
    .replace('<head>', '<head><meta name="viewport" content="width=device-width, initial-scale=1">')
    .replace(/(href|src)="ds\/pp\//g, '$1="../canvas/ds/pp/')
    .replace(/\/_blob\/([0-9a-f]{32})/g, (m, id) => {
      const name = sourceToName[blobToSource[id]];
      return name ? `../system/assets/Logos/${name}` : m;
    });
  const name = file.replace('.dc.html', '');
  await writeFile(join(outDir, name + '.html'), html);
  done.push({ name, title: b.title || name, page: b.page, w: b.w });
  console.log('✓', name);
  await page.close();
}
await browser.close();
server.close();

if (!only.length) {
  const pages = canvas.pages || [];
  const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;');
  const sections = pages.map((p) => {
    const items = done.filter((d) => d.page === p.id)
      .map((d) => `<li><a href="${d.name}.html">${esc(d.title)}</a> <span>${d.w} px</span></li>`).join('\n');
    return `<section><h2>${esc(p.name)}</h2><ul>${items}</ul></section>`;
  }).join('\n');
  await writeFile(join(outDir, 'index.html'), `<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Подвальный Принт — экраны</title>
<link rel="stylesheet" href="../canvas/ds/pp/tokens.css"><link rel="stylesheet" href="../canvas/ds/pp/components/bundle.css">
<style>body{margin:0;padding:48px clamp(16px,5vw,80px)}h1{font:850 48px/1 var(--font-display);letter-spacing:-.03em;margin:0 0 8px}
h2{font:800 24px var(--font-display);margin:40px 0 12px}ul{list-style:none;padding:0;margin:0;display:grid;gap:6px}
li a{color:var(--ink);font-weight:600}li span{color:var(--ink-2);font-size:13px}p{color:var(--ink-2);max-width:640px}</style></head>
<body><h1>Подвальный Принт — экраны</h1>
<p>Статичные копии макетов с холста. Собраны из design/canvas командой <code>node design/tools/render.mjs</code>; руками не правятся.</p>
${sections}
</body></html>
`);
}

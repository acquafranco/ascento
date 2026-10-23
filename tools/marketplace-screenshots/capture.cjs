// Capturas reales del marketplace para /marketplace y la Welcome (base de demo local con
// tools/marketplace-screenshots/seed.php; datos ficticios). Ver docs/landing-capturas.md.
const puppeteer = require('puppeteer-core');
const OUT = process.argv[2]; const B = process.env.BASE_URL || 'http://127.0.0.1:8766'; const sleep = (ms) => new Promise(r => setTimeout(r, ms));
async function page(b, w, h, dpr, email = 'demo@empresa.test') {
  const ctx = await b.createBrowserContext(); const p = await ctx.newPage();
  await p.setViewport({ width: w, height: h, deviceScaleFactor: dpr });
  await p.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: 'light' }, { name: 'prefers-reduced-motion', value: 'reduce' }]);
  await p.evaluateOnNewDocument(() => { try { localStorage.setItem('theme', 'light'); } catch (e) {} });
  const errs = []; p.on('pageerror', e => errs.push(e.message)); p.on('console', m => { if (m.type() === 'error') errs.push(m.text()); });
  await p.goto(B + '/login', { waitUntil: 'networkidle2' }); await p.type('input[name=email]', email); await p.type('input[name=password]', 'Kiosco-Verde-8241');
  await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle2' }), p.$eval('input[name=email]', el => el.form.requestSubmit())]);
  p.errs = errs; return p;
}
const shot = async (p, name, q = 80) => { await sleep(900); await p.screenshot({ path: `${OUT}/${name}.jpg`, type: 'jpeg', quality: q }); console.log(name, p.url().replace(B, '')); };

// Recorte del área de contenido (sin menú lateral): desde `from` hasta `height` px.
async function region(p, name, from, height, q = 80, pad = 20) {
  await sleep(900);
  const box = await p.evaluate((sel) => { const el = document.querySelector(sel); const main = document.querySelector('.fi-main') || document.querySelector('main') || el.parentElement; const r = el.getBoundingClientRect(); const m = main.getBoundingClientRect(); return { x: m.left + window.scrollX, y: r.top + window.scrollY, w: m.width }; }, from);
  await p.screenshot({ path: `${OUT}/${name}.jpg`, type: 'jpeg', quality: q, clip: { x: box.x + pad, y: Math.max(0, box.y - Math.min(pad, 12)), width: box.w - 2 * pad, height }, captureBeyondViewport: true });
  console.log(name, p.url().replace(B, ''), Math.round(box.w - 2 * pad), height);
}
(async () => {
  const b = await puppeteer.launch({ executablePath: process.env.CHROME, headless: 'new' });
  const d = await page(b, 1440, 1400, 1);
  await d.goto(B + '/admin/repuestos', { waitUntil: 'networkidle2' });
  const ids = await d.$$eval('[data-mk-item]', els => els.map(e => e.getAttribute('data-mk-item')));
  await region(d, 'catalogo', '.mk-toolbar', 860);
  await d.goto(B + '/admin/repuestos?rubro=buttons', { waitUntil: 'networkidle2' }); await region(d, 'catalogo-filtros', '.mk-toolbar', 690);
  await d.goto(B + '/admin/repuestos?producto=' + ids[0], { waitUntil: 'networkidle2' }); await region(d, 'ficha', '.mk-detail', 640);
  console.log('desktop errors', d.errs);
  const m = await page(b, 390, 844, 2);
  await m.goto(B + '/admin/repuestos?producto=' + ids[0], { waitUntil: 'networkidle2' }); await region(m, 'ficha-movil', '.mk-detail', 720, 70, 0);
  console.log('mobile errors', m.errs);
  const sp = await page(b, 1280, 1300, 1, 'demo@norte.test');
  await sp.goto(B + '/proveedores/catalogo/' + ids[0], { waitUntil: 'networkidle2' }); await region(sp, 'proveedor-fotos', '[data-product-photos]', 468, 80, 0);
  console.log('supplier errors', sp.errs);
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });

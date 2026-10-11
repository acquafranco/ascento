/**
 * Capturas de la landing (carrusel "Vista previa"), siempre de la app REAL con
 * datos FICTICIOS de la base de demo (DemoValidationSeeder). Nunca producción.
 *
 * Uso (ver docs/landing-capturas.md):
 *   BASE_URL=http://127.0.0.1:8766 CHROME="/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" \
 *   OUT=storage/app/landing-raw node tools/landing-screenshots/capture.cjs
 *   php artisan landing:optimize-screenshots storage/app/landing-raw
 *
 * Requiere puppeteer-core en la máquina que captura (npm i -D puppeteer-core):
 * no es una dependencia de la app.
 */
const puppeteer = require('puppeteer-core');
const fs = require('fs');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8766';
const OUT = process.env.OUT || 'storage/app/landing-raw';
const PASSWORD = process.env.DEMO_PASSWORD || 'demo-ascento';
const wait = (ms) => new Promise((r) => setTimeout(r, ms));

// Teléfono: misma proporción en todas (carrusel). Escritorio: 16:10.
const PHONE = { width: 390, height: 760, deviceScaleFactor: 2, isMobile: true, hasTouch: true };
const DESKTOP = { width: 1440, height: 900, deviceScaleFactor: 1 };

const shots = [
    { who: 'lucas@demo-norte.test', vp: PHONE, path: '/demo-norte/dashboard', file: 'tecnico-inicio' },
    { who: 'lucas@demo-norte.test', vp: PHONE, path: '/demo-norte/work-orders', file: 'tecnico-trabajos' },
    { who: 'lucas@demo-norte.test', vp: PHONE, path: '/demo-norte/buildings', file: 'tecnico-visitas' },
    { who: 'lucas@demo-norte.test', vp: PHONE, path: '/demo-norte/reports/create', file: 'tecnico-reporte', before: async (p) => { await p.focus('#buildingSearch'); await wait(300); } },
    { who: 'admin@adm-rivadavia.test', vp: PHONE, path: '/portal', file: 'portal-inicio' },
    { who: 'admin@adm-rivadavia.test', vp: PHONE, path: '/portal/documentos', file: 'portal-documentos' },
    { who: 'admin@demo-norte.test', vp: DESKTOP, path: '/admin/dashboard', file: 'admin-inicio' },
    { who: 'admin@demo-norte.test', vp: DESKTOP, path: '/admin/mapa', file: 'admin-mapa', settle: 3500 },
    { who: 'admin@demo-norte.test', vp: DESKTOP, path: '/admin/quotes/1', file: 'admin-presupuesto' },
];

// Avisos que se descartan con un toque (activar push, ayudas): fuera de la foto.
const HIDE = `[x-data="pushNotifications"], .asc-tip, #asc-live, .wa-launch { display: none !important; }`;

(async () => {
    fs.mkdirSync(OUT, { recursive: true });
    const browser = await puppeteer.launch({ executablePath: process.env.CHROME, headless: 'new' });
    const sessions = {};
    for (const shot of shots) {
        const key = shot.who + (shot.vp === PHONE ? ':m' : ':d');
        if (!sessions[key]) {
            const ctx = await browser.createBrowserContext();
            const page = await ctx.newPage();
            await page.setViewport(shot.vp);
            const login = shot.who.includes('adm-rivadavia') ? '/portal/ingresar' : '/login';
            await page.goto(BASE + login, { waitUntil: 'networkidle2' });
            await page.type('input[name=email]', shot.who);
            await page.type('input[name=password]', PASSWORD);
            await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('button[type=submit]')]);
            sessions[key] = page;
        }
        const page = sessions[key];
        await page.goto(BASE + shot.path, { waitUntil: 'networkidle2' });
        await page.addStyleTag({ content: HIDE });
        if (shot.before) await shot.before(page);
        await wait(shot.settle || 700);
        await page.screenshot({ path: `${OUT}/${shot.file}.png` });
        console.log('ok', shot.file);
    }
    await browser.close();
})();

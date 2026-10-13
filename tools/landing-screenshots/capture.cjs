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
    // Etapas 6 a 8.
    { who: 'admin@demo-norte.test', vp: DESKTOP, path: '/admin/reparaciones', file: 'admin-reparaciones' },
    { who: 'admin@demo-norte.test', vp: DESKTOP, path: '/admin/paracaidas', file: 'admin-paracaidas' },
    { who: 'admin@demo-norte.test', vp: DESKTOP, path: '/admin/indicadores', file: 'admin-indicadores', settle: 2000 },
    // QR público de Torre Libertador: el enlace se toma del panel (el token es aleatorio) y se abre sin sesión.
    { who: null, vp: PHONE, file: 'qr-publico', resolve: async (browser) => {
        const ctx = await browser.createBrowserContext();
        const admin = await ctx.newPage();
        await login(admin, 'admin@demo-norte.test');
        await admin.goto(BASE + '/admin/qr-publicos', { waitUntil: 'networkidle2' });
        const details = await admin.evaluate(() => [...document.querySelectorAll('a[href*="edificio="]')].map((a) => a.href));
        let link = null;
        for (const detail of details) { // el primer edificio con un QR activo
            await admin.goto(detail, { waitUntil: 'networkidle2' });
            link = await admin.evaluate(() => [...document.querySelectorAll('a[href*="/qr/"]')].map((a) => a.href)[0] || null);
            if (link) break;
        }
        await ctx.close();
        if (!link) throw new Error('No hay ningún QR activo en la demo.');
        return link;
    } },
];

async function login(page, who) {
    const path = who.includes('adm-rivadavia') ? '/portal/ingresar' : '/login';
    await page.goto(BASE + path, { waitUntil: 'networkidle2' });
    await page.type('input[name=email]', who);
    await page.type('input[name=password]', PASSWORD);
    // La protección contra bots del login rechaza envíos instantáneos (Etapa 2).
    await wait(3500);
    // El formulario del login (no otro botón de la página, como el aviso de cookies).
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.evaluate(() => document.querySelector('input[name=email]').form.requestSubmit())]);
}

// Avisos que se descartan con un toque (activar push, ayudas): fuera de la foto.
const HIDE = `[x-data="pushNotifications"], .asc-tip, #asc-live, .wa-launch { display: none !important; }`;

(async () => {
    fs.mkdirSync(OUT, { recursive: true });
    const browser = await puppeteer.launch({ executablePath: process.env.CHROME, headless: 'new' });
    const sessions = {};
    for (const shot of shots) {
        const key = (shot.who || 'publico') + (shot.vp === PHONE ? ':m' : ':d');
        if (!sessions[key]) {
            const ctx = await browser.createBrowserContext();
            const page = await ctx.newPage();
            await page.setViewport(shot.vp);
            if (shot.who) await login(page, shot.who);
            sessions[key] = page;
        }
        const page = sessions[key];
        const url = shot.resolve ? await shot.resolve(browser) : BASE + shot.path;
        await page.goto(url, { waitUntil: 'networkidle2' });
        await page.addStyleTag({ content: HIDE });
        if (shot.before) await shot.before(page);
        await wait(shot.settle || 700);
        await page.screenshot({ path: `${OUT}/${shot.file}.png` });
        console.log('ok', shot.file);
    }
    await browser.close();
})();

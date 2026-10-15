/**
 * Ubicación del técnico (voluntaria). Solo corre si el técnico la activó en
 * Ascento (configuración en <script id="ascento-location">) y el navegador
 * ya dio permiso. Nunca pide permiso sola: eso pasa solo con el botón del
 * perfil (requestPermission).
 *
 * Límite honesto: el navegador solo entrega posiciones mientras Ascento está
 * abierto y en pantalla. Con la pantalla bloqueada o la app en segundo plano,
 * iOS y Android dejan de entregarlas: no se simula nada, la última posición
 * queda con su hora y el mapa la marca como desactualizada.
 */
const STATE_KEY = 'ascento.location.reported';

function config() {
    const node = document.getElementById('ascento-location');
    if (!node) return null;
    try { return JSON.parse(node.textContent); } catch (e) { return null; }
}

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function post(url, body) {
    return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify(body),
    });
}

function distance(a, b) {
    const R = 6371000;
    const rad = (d) => (d * Math.PI) / 180;
    const dLat = rad(b.lat - a.lat);
    const dLng = rad(b.lng - a.lng);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a.lat)) * Math.cos(rad(b.lat)) * Math.sin(dLng / 2) ** 2;
    return 2 * R * Math.asin(Math.sqrt(h));
}

export async function permissionState() {
    if (!window.isSecureContext) return 'insecure';
    if (!('geolocation' in navigator)) return 'unsupported';
    try {
        const status = await navigator.permissions?.query({ name: 'geolocation' });
        return status?.state ?? 'unknown';
    } catch (e) {
        return 'unknown';
    }
}

let tracker = null;

function createTracker(cfg) {
    let watchId = null;
    let last = null; // { lat, lng, at }
    let stopped = false;
    let lastReport = {};

    const emit = (state) => document.dispatchEvent(new CustomEvent('ascento-location', { detail: state }));

    // Un mismo aviso (permiso o problema) se informa como mucho cada 5 minutos.
    const report = (payload) => {
        const key = JSON.stringify(payload);
        const now = Date.now();
        if (lastReport[key] && now - lastReport[key] < 300000) return;
        lastReport[key] = now;
        post(cfg.reportUrl, payload).catch(() => {});
    };

    const stop = (reason) => {
        stopped = true;
        if (watchId !== null) navigator.geolocation.clearWatch(watchId);
        watchId = null;
        emit({ state: 'stopped', reason });
    };

    const send = async (pos) => {
        const point = { lat: pos.coords.latitude, lng: pos.coords.longitude, at: Date.now() };
        const due = !last || point.at - last.at >= cfg.interval * 1000 || distance(last, point) > 150;
        if (!due || stopped) return;
        // Sin conexión no se manda ni se guarda para después: no se inventa una posición.
        if (navigator.onLine === false) { emit({ state: 'offline' }); return; }
        last = point;
        try {
            const res = await post(cfg.storeUrl, { latitude: point.lat, longitude: point.lng, accuracy: Math.round(pos.coords.accuracy ?? 0) });
            if (res.status === 401 || res.status === 419) return stop('session');
            if (res.status === 409) return stop('disabled');
            if (res.status === 429) { last = { ...point, at: point.at + 60000 }; return; }
            emit({ state: res.ok ? 'sent' : 'error', at: new Date() });
        } catch (e) {
            emit({ state: 'offline' });
        }
    };

    const fail = (err) => {
        if (err.code === 1) { report({ permission: 'denied' }); return stop('denied'); }
        report({ error: err.code === 3 ? 'timeout' : 'unavailable' });
        emit({ state: 'error', code: err.code });
    };

    const watch = () => {
        if (stopped || watchId !== null || document.visibilityState !== 'visible') return;
        watchId = navigator.geolocation.watchPosition(send, fail, { enableHighAccuracy: false, maximumAge: 30000, timeout: 30000 });
        emit({ state: 'watching' });
    };

    // Con la página oculta no se pide nada (el navegador tampoco lo permite de forma fiable).
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') { watch(); return; }
        if (watchId !== null) { navigator.geolocation.clearWatch(watchId); watchId = null; }
    });

    return { watch, stop, isStopped: () => stopped };
}

export async function startTracking() {
    const cfg = config();
    if (!cfg || !cfg.enabled) return 'disabled';
    const state = await permissionState();
    if (state === 'insecure' || state === 'unsupported') {
        post(cfg.reportUrl, { error: state === 'insecure' ? 'insecure' : 'unsupported', permission: 'unsupported' }).catch(() => {});
        return state;
    }
    if (state === 'denied') {
        try { if (sessionStorage.getItem(STATE_KEY) !== 'denied') { sessionStorage.setItem(STATE_KEY, 'denied'); post(cfg.reportUrl, { permission: 'denied' }).catch(() => {}); } } catch (e) {}
        return state;
    }
    // «prompt» (o sin forma de saberlo): no se pide permiso sin que el técnico toque el botón.
    let granted = state === 'granted';
    if (state === 'unknown') { try { granted = sessionStorage.getItem(STATE_KEY) === 'granted'; } catch (e) {} }
    if (!granted) return state;
    try { sessionStorage.setItem(STATE_KEY, 'granted'); } catch (e) {}
    tracker ??= createTracker(cfg);
    tracker.watch();
    return 'granted';
}

/**
 * Botón «Permitir ubicación» (acción explícita del técnico): el navegador
 * muestra su pedido de permiso. Devuelve 'granted' o el motivo.
 */
export function requestPermission() {
    return new Promise((resolve) => {
        if (!window.isSecureContext) return resolve('insecure');
        if (!('geolocation' in navigator)) return resolve('unsupported');
        navigator.geolocation.getCurrentPosition(
            () => { try { sessionStorage.setItem(STATE_KEY, 'granted'); } catch (e) {} resolve('granted'); },
            (err) => resolve(err.code === 1 ? 'denied' : (err.code === 3 ? 'timeout' : 'unavailable')),
            { enableHighAccuracy: false, maximumAge: 0, timeout: 30000 },
        );
    });
}

export function stopTracking() {
    tracker?.stop('disabled');
}

window.AscentoLocation = { startTracking, requestPermission, stopTracking, permissionState };

export function initTechLocation() {
    if (config()?.enabled) startTracking();
}

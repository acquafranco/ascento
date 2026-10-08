/**
 * Notificaciones push del técnico (Web Push estándar + service worker).
 *
 * La configuración la pone el layout en <meta name="ascento-push"> solo
 * para técnicos: clave pública VAPID (es pública por diseño) y las URLs.
 * La clave privada nunca sale del servidor.
 *
 * Regla: ninguna espera puede dejar la pantalla "colgada". Todo lo que
 * depende del navegador (service worker, permiso, suscripción) tiene un
 * tiempo máximo y, si se pasa, se explica qué hacer.
 */

const OWNER_KEY = 'ascento-push-user';
const SYNC_KEY = 'ascento-push-synced-at';

export function pushConfig() {
    const meta = document.querySelector('meta[name="ascento-push"]');
    if (!meta) return null;

    try {
        return JSON.parse(meta.content);
    } catch (error) {
        return null;
    }
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

function base64ToUint8Array(base64) {
    const padding = '='.repeat((4 - (base64.length % 4)) % 4);
    const raw = atob((base64 + padding).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from(raw, (char) => char.charCodeAt(0));
}

function sameKey(subscription, vapidKey) {
    const current = subscription.options?.applicationServerKey;
    if (!current) return true; // el navegador no lo expone: asumimos que coincide

    const a = new Uint8Array(current);
    const b = base64ToUint8Array(vapidKey);
    return a.length === b.length && a.every((value, i) => value === b[i]);
}

class TimeoutError extends Error {}

function withTimeout(promise, ms, message) {
    let timer;
    return Promise.race([
        promise,
        new Promise((_, reject) => {
            timer = setTimeout(() => reject(new TimeoutError(message)), ms);
        }),
    ]).finally(() => clearTimeout(timer));
}

/**
 * Navegador / dispositivo. En iPhone y iPad TODOS los navegadores usan el
 * motor de Safari, pero solo Safari permite instalar Ascento en la pantalla
 * de inicio y recibir notificaciones desde ahí.
 */
export function environment() {
    const ua = navigator.userAgent || '';
    const isIOS = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const otherIOSBrowser = /CriOS|FxiOS|EdgiOS|OPiOS|GSA\/|YaBrowser|DuckDuckGo|Instagram|FBAN|FBAV/.test(ua);
    const standalone = window.matchMedia?.('(display-mode: standalone)').matches || navigator.standalone === true;
    const supported = window.isSecureContext
        && 'serviceWorker' in navigator
        && 'PushManager' in window
        && 'Notification' in window;

    return { isIOS, isIOSSafari: isIOS && !otherIOSBrowser, standalone, supported };
}

async function send(url, method, body) {
    const response = await fetch(url, {
        method,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: body ? JSON.stringify(body) : undefined,
    });

    if (!response.ok) {
        const data = await response.json().catch(() => ({}));
        const error = new Error(data.message || `HTTP ${response.status}`);
        error.status = response.status;
        throw error;
    }

    return response.json().catch(() => ({}));
}

let registrationPromise = null;

/** Registra el service worker una sola vez por página (y con tiempo máximo). */
function registration() {
    registrationPromise ??= (async () => {
        const reg = await navigator.serviceWorker.register('/sw.js', { scope: '/' });

        if (!reg.active) {
            await withTimeout(navigator.serviceWorker.ready, 10000, 'sw-timeout');
        }

        return reg;
    })().catch((error) => {
        registrationPromise = null; // permite reintentar
        throw error;
    });

    return registrationPromise;
}

async function subscribe(reg, vapidKey) {
    let subscription = await reg.pushManager.getSubscription();

    if (subscription && !sameKey(subscription, vapidKey)) {
        await subscription.unsubscribe();
        subscription = null;
    }

    return subscription ?? withTimeout(
        reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: base64ToUint8Array(vapidKey) }),
        15000,
        'subscribe-timeout',
    );
}

async function saveSubscription(config, subscription) {
    const data = subscription.toJSON();
    const encodings = window.PushManager?.supportedContentEncodings ?? ['aes128gcm'];

    return send(config.storeUrl, 'POST', {
        endpoint: data.endpoint,
        keys: data.keys,
        contentEncoding: encodings.includes('aes128gcm') ? 'aes128gcm' : 'aesgcm',
    });
}

/**
 * Al cargar cada página: deja el service worker registrado de antemano (así
 * "Activar" no tiene que esperarlo) y, si ESTE usuario ya activó las
 * notificaciones acá, re-envía la suscripción cada 12 h. Nunca pide permiso.
 */
export async function silentSync() {
    const config = pushConfig();
    const { supported } = environment();

    if (!config?.vapidPublicKey || !supported) return;

    try {
        const reg = await registration();

        if (Notification.permission !== 'granted') return;
        if (localStorage.getItem(OWNER_KEY) !== String(config.userId)) return;

        const lastSync = Number(localStorage.getItem(SYNC_KEY) || 0);
        if (Date.now() - lastSync < 12 * 3600 * 1000) return;

        await saveSubscription(config, await subscribe(reg, config.vapidPublicKey));
        localStorage.setItem(SYNC_KEY, String(Date.now()));
    } catch (error) {
        // Silencioso: la tarjeta de notificaciones muestra el estado real.
    }
}

/**
 * Componente Alpine de la tarjeta "Notificaciones".
 *
 * Estados: loading | unconfigured | unsupported | ios-safari | ios-other |
 *          default | asking | denied | subscribed | error
 */
export function pushNotificationsComponent() {
    return {
        state: 'loading',
        busy: false,
        message: '',
        promptHint: false,

        async init() {
            const config = pushConfig();
            const env = environment();

            if (!config?.vapidPublicKey) return (this.state = 'unconfigured');
            if (env.isIOS && !env.standalone) return (this.state = env.isIOSSafari ? 'ios-safari' : 'ios-other');
            if (!env.supported) return (this.state = 'unsupported');
            if (Notification.permission === 'denied') return (this.state = 'denied');

            // Nunca más de 4 s en "Revisando…".
            this.state = 'default';

            try {
                const reg = await withTimeout(registration(), 4000, 'sw-timeout');
                const subscription = await reg.pushManager.getSubscription();
                const mine = localStorage.getItem(OWNER_KEY) === String(config.userId);

                if (subscription && mine && Notification.permission === 'granted') {
                    this.state = 'subscribed';
                }
            } catch (error) {
                // Se reintenta al tocar "Activar".
            }
        },

        async enable() {
            const config = pushConfig();
            this.busy = true;
            this.message = '';
            this.promptHint = false;

            // Si el cartel del navegador no aparece (Chrome a veces lo
            // "silencia" y muestra solo un iconito), explicamos dónde está.
            const hintTimer = setTimeout(() => { this.promptHint = true; }, 4000);

            try {
                // Lo PRIMERO dentro del toque: iOS y Chrome lo exigen.
                const permission = await Notification.requestPermission();
                clearTimeout(hintTimer);
                this.promptHint = false;

                if (permission !== 'granted') {
                    this.state = permission === 'denied' ? 'denied' : 'default';
                    if (permission !== 'denied') {
                        this.message = 'No elegiste ninguna opción. Tocá "Activar notificaciones" y elegí "Permitir".';
                    }
                    return;
                }

                const reg = await registration();
                const subscription = await subscribe(reg, config.vapidPublicKey);

                await saveSubscription(config, subscription);
                localStorage.setItem(OWNER_KEY, String(config.userId));
                localStorage.setItem(SYNC_KEY, String(Date.now()));

                this.state = 'subscribed';
                this.message = 'Listo. Te vamos a avisar cuando te asignen una orden.';
            } catch (error) {
                this.state = 'error';
                this.message = explain(error);
            } finally {
                clearTimeout(hintTimer);
                this.busy = false;
            }
        },

        async disable() {
            const config = pushConfig();
            this.busy = true;

            try {
                const reg = await withTimeout(registration(), 8000, 'sw-timeout');
                const subscription = await reg.pushManager.getSubscription();

                if (subscription) {
                    await send(config.destroyUrl, 'DELETE', { endpoint: subscription.endpoint });
                    await subscription.unsubscribe();
                }

                localStorage.removeItem(OWNER_KEY);
                localStorage.removeItem(SYNC_KEY);
                this.state = 'default';
                this.message = 'Desactivaste las notificaciones en este dispositivo.';
            } catch (error) {
                this.message = 'No pudimos desactivarlas. Intentá de nuevo.';
            } finally {
                this.busy = false;
            }
        },

        async test() {
            const config = pushConfig();
            this.busy = true;

            try {
                await send(config.testUrl, 'POST');
                this.message = 'Te mandamos una notificación de prueba. Debería llegarte en unos segundos.';
            } catch (error) {
                this.message = error.status === 429
                    ? 'Esperá un minuto antes de pedir otra prueba.'
                    : 'No pudimos enviar la prueba.';
            } finally {
                this.busy = false;
            }
        },
    };
}

function explain(error) {
    if (error instanceof TimeoutError || error?.message === 'sw-timeout') {
        return 'El navegador tardó demasiado en preparar las notificaciones. Cerrá y volvé a abrir Ascento e intentá de nuevo.';
    }
    if (error?.status === 422) {
        return 'Este navegador usa un servicio de notificaciones que Ascento no admite. Probá con Chrome.';
    }
    if (error?.status === 419) {
        return 'Tu sesión venció. Recargá la página e intentá de nuevo.';
    }
    if (error?.name === 'NotAllowedError') {
        return 'El navegador no permitió las notificaciones. Revisá los permisos del sitio (candado junto a la dirección).';
    }
    if (error?.name === 'AbortError' || error?.message === 'subscribe-timeout') {
        return 'El servicio de notificaciones del navegador no respondió. Revisá tu conexión e intentá de nuevo.';
    }
    return 'No pudimos activar las notificaciones. Revisá tu conexión e intentá de nuevo.';
}

/** El service worker pide navegar cuando no puede hacerlo él mismo. */
export function listenForNavigation() {
    navigator.serviceWorker?.addEventListener('message', (event) => {
        if (event.data?.type !== 'ascento:navigate') return;

        const url = new URL(event.data.url, window.location.origin);
        if (url.origin === window.location.origin) window.location.assign(url.href);
    });
}

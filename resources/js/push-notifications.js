/**
 * Notificaciones push del técnico (Web Push estándar + service worker).
 *
 * La configuración la pone el layout en <meta name="ascento-push"> solo
 * para técnicos: clave pública VAPID (es pública por diseño) y las URLs.
 * La clave privada nunca sale del servidor.
 */

const OWNER_KEY = 'ascento-push-user';

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

export function environment() {
    const ua = navigator.userAgent || '';
    const isIOS = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const standalone = window.matchMedia?.('(display-mode: standalone)').matches || navigator.standalone === true;
    const supported = window.isSecureContext
        && 'serviceWorker' in navigator
        && 'PushManager' in window
        && 'Notification' in window;

    return { isIOS, standalone, supported };
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

async function registration() {
    const reg = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
    await navigator.serviceWorker.ready;
    return reg;
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
 * Sincronización silenciosa en cada carga: si ESTE usuario ya activó las
 * notificaciones en este dispositivo, re-envía la suscripción (por si el
 * navegador la renovó o cambió la clave VAPID). Nunca pide permiso.
 */
export async function silentSync() {
    const config = pushConfig();
    const { supported } = environment();

    if (!config?.vapidPublicKey || !supported || Notification.permission !== 'granted') return;
    if (localStorage.getItem(OWNER_KEY) !== String(config.userId)) return;

    try {
        const reg = await registration();
        let subscription = await reg.pushManager.getSubscription();

        if (subscription && !sameKey(subscription, config.vapidPublicKey)) {
            await subscription.unsubscribe();
            subscription = null;
        }

        subscription ??= await reg.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: base64ToUint8Array(config.vapidPublicKey),
        });

        await saveSubscription(config, subscription);
    } catch (error) {
        // Silencioso: la tarjeta de notificaciones muestra el estado real.
    }
}

/**
 * Componente Alpine de la tarjeta "Notificaciones".
 *
 * Estados: loading | unconfigured | unsupported | ios-install | default |
 *          denied | subscribed | error
 */
export function pushNotificationsComponent() {
    return {
        state: 'loading',
        busy: false,
        message: '',

        async init() {
            const config = pushConfig();
            const env = environment();

            if (!config?.vapidPublicKey) return (this.state = 'unconfigured');
            if (env.isIOS && !env.standalone) return (this.state = 'ios-install');
            if (!env.supported) return (this.state = 'unsupported');
            if (Notification.permission === 'denied') return (this.state = 'denied');

            try {
                const reg = await registration();
                const subscription = await reg.pushManager.getSubscription();
                const mine = localStorage.getItem(OWNER_KEY) === String(config.userId);

                this.state = subscription && mine && Notification.permission === 'granted'
                    ? 'subscribed'
                    : 'default';
            } catch (error) {
                this.state = 'default';
            }
        },

        async enable() {
            const config = pushConfig();
            this.busy = true;
            this.message = '';

            try {
                // Tiene que ocurrir dentro del toque del usuario (iOS lo exige).
                const permission = await Notification.requestPermission();

                if (permission !== 'granted') {
                    this.state = permission === 'denied' ? 'denied' : 'default';
                    return;
                }

                const reg = await registration();
                let subscription = await reg.pushManager.getSubscription();

                if (subscription && !sameKey(subscription, config.vapidPublicKey)) {
                    await subscription.unsubscribe();
                    subscription = null;
                }

                subscription ??= await reg.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: base64ToUint8Array(config.vapidPublicKey),
                });

                await saveSubscription(config, subscription);
                localStorage.setItem(OWNER_KEY, String(config.userId));

                this.state = 'subscribed';
                this.message = 'Listo. Te vamos a avisar cuando te asignen una orden.';
            } catch (error) {
                this.state = 'error';
                this.message = error.status === 422
                    ? 'Este navegador usa un servicio de notificaciones que Ascento no admite. Probá con Chrome.'
                    : 'No pudimos activar las notificaciones. Revisá tu conexión e intentá de nuevo.';
            } finally {
                this.busy = false;
            }
        },

        async disable() {
            const config = pushConfig();
            this.busy = true;

            try {
                const reg = await registration();
                const subscription = await reg.pushManager.getSubscription();

                if (subscription) {
                    await send(config.destroyUrl, 'DELETE', { endpoint: subscription.endpoint });
                    await subscription.unsubscribe();
                }

                localStorage.removeItem(OWNER_KEY);
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

/** El service worker pide navegar cuando no puede hacerlo él mismo. */
export function listenForNavigation() {
    navigator.serviceWorker?.addEventListener('message', (event) => {
        if (event.data?.type !== 'ascento:navigate') return;

        const url = new URL(event.data.url, window.location.origin);
        if (url.origin === window.location.origin) window.location.assign(url.href);
    });
}

/*
 * Service worker de Ascento: SOLO notificaciones push.
 *
 * No cachea páginas ni intercepta requests (no hay handler de "fetch"):
 * la app sigue funcionando exactamente igual que sin service worker.
 */

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    let payload = {};

    try {
        payload = event.data ? event.data.json() : {};
    } catch (error) {
        payload = { title: 'Ascento', body: event.data ? event.data.text() : '' };
    }

    const title = payload.title || 'Ascento';

    const options = {
        body: payload.body || '',
        icon: payload.icon || '/images/pwa/icon-192.png',
        badge: payload.badge || '/images/pwa/badge-96.png',
        tag: payload.tag,
        renotify: Boolean(payload.renotify && payload.tag),
        requireInteraction: Boolean(payload.requireInteraction),
        actions: Array.isArray(payload.actions) ? payload.actions : [],
        data: payload.data || {},
        lang: 'es-AR',
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

/** Solo se navega a rutas de este mismo sitio (nunca a un dominio externo). */
function safeUrl(raw) {
    try {
        const url = new URL(raw || '/', self.location.origin);
        return url.origin === self.location.origin ? url.href : self.location.origin + '/';
    } catch (error) {
        return self.location.origin + '/';
    }
}

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const target = safeUrl(event.notification.data && event.notification.data.url);

    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

        // Si Ascento ya está abierto, se reutiliza esa pestaña/ventana.
        for (const client of windows) {
            if (new URL(client.url).origin === self.location.origin && 'focus' in client) {
                await client.focus();
                try {
                    if ('navigate' in client) {
                        const navigated = await client.navigate(target);
                        if (navigated) return navigated;
                    }
                } catch (error) {
                    // Pestaña no controlada por este SW: que navegue la página.
                }
                client.postMessage({ type: 'ascento:navigate', url: target });
                return undefined;
            }
        }

        return self.clients.openWindow(target);
    })());
});

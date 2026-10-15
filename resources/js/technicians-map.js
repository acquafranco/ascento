/**
 * Mapa de técnicos (Mapa → pestaña «Técnicos»).
 *
 * - Los datos llegan del servidor (Livewire: technicianLocations), una sola
 *   consulta para todos los técnicos, cada `refreshSeconds` mientras la
 *   pestaña está visible. No se inventan movimientos entre una consulta y otra.
 * - Las posiciones desactualizadas se dibujan en gris, con su hora.
 * - Todo texto de la base se inserta con textContent (nunca innerHTML).
 */
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import 'leaflet.markercluster';
import 'leaflet.markercluster/dist/MarkerCluster.css';
import 'leaflet.markercluster/dist/MarkerCluster.Default.css';
import '../css/technicians-map.css';

const ARGENTINA = [[-55.1, -73.6], [-21.7, -53.6]];

function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = String(text);
    return node;
}

function avatar(tech, className) {
    const box = el('span', className);
    box.setAttribute('aria-hidden', 'true');
    if (tech.photo) {
        const img = document.createElement('img');
        img.src = tech.photo;
        img.alt = '';
        img.loading = 'lazy';
        img.addEventListener('error', () => { img.remove(); box.textContent = tech.initials; });
        box.append(img);
    } else {
        box.textContent = tech.initials;
    }
    return box;
}

function init(shell) {
    if (shell.dataset.ready) return;
    shell.dataset.ready = '1';

    const { initial, config } = JSON.parse(shell.querySelector('[data-technicians-payload]').textContent);
    const list = shell.querySelector('[data-tm-list]');
    const summary = shell.querySelector('[data-tm-summary]');
    const updated = shell.querySelector('[data-tm-updated]');
    const error = shell.querySelector('[data-tm-error]');
    const empty = shell.querySelector('[data-tm-map-empty]');
    const wireId = shell.closest('[wire\\:id]')?.getAttribute('wire:id');

    const map = L.map(shell.querySelector('[data-technicians-map]'), { zoomControl: true, scrollWheelZoom: true });
    L.tileLayer(config.tileUrl, {
        maxZoom: config.maxZoom,
        attribution: config.attribution,
        r: config.retina && window.devicePixelRatio > 1 ? '@2x' : '',
        crossOrigin: true,
    }).addTo(map);
    map.fitBounds(ARGENTINA);

    const cluster = L.markerClusterGroup({ showCoverageOnHover: false, maxClusterRadius: 40, spiderfyOnMaxZoom: true });
    map.addLayer(cluster);

    const markers = new Map(); // id → { layer, circle }
    let selected = null;
    let fitted = false;

    const pinIcon = (tech) => {
        const pin = avatar(tech, `tm-pin ${tech.fresh ? '' : 'is-stale'} ${selected === tech.id ? 'is-selected' : ''}`);
        return L.divIcon({ className: '', html: pin.outerHTML, iconSize: [36, 36], iconAnchor: [18, 18], popupAnchor: [0, -18] });
    };

    const popup = (tech) => {
        const root = el('div', 'tm-popup');
        root.append(el('strong', null, tech.name), el('span', null, tech.statusLabel));
        if (tech.locatedLabel) root.append(el('div', null, `Última posición: ${tech.locatedLabel}`));
        if (tech.position?.approximate) root.append(el('div', null, `Aproximada (±${tech.position.accuracy} m)`));
        return root;
    };

    const select = (id, center) => {
        selected = id;
        list.querySelectorAll('.tm-card').forEach((c) => c.classList.toggle('is-selected', Number(c.dataset.id) === id));
        list.querySelectorAll('.tm-card').forEach((c) => c.setAttribute('aria-pressed', Number(c.dataset.id) === id ? 'true' : 'false'));
        render(current, false);
        const m = markers.get(id);
        if (m && center) {
            cluster.zoomToShowLayer(m.layer, () => { map.setView(m.layer.getLatLng(), Math.max(map.getZoom(), 15)); m.layer.openPopup(); });
        }
    };

    let current = initial;

    function render(data, rebuildList = true) {
        const techs = data.technicians;
        const located = techs.filter((t) => t.position);
        const online = techs.filter((t) => t.fresh).length;
        summary.textContent = techs.length === 0
            ? 'Tu empresa no tiene técnicos cargados.'
            : `${online} en línea · ${located.length - online} con ubicación desactualizada · ${techs.length - located.length} sin ubicación para mostrar`;
        updated.textContent = `Datos al ${new Date(data.at).toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit', second: '2-digit' })} · se actualiza cada ${config.refreshSeconds} s`;
        empty.hidden = located.length > 0;

        if (rebuildList) {
            list.replaceChildren(...techs.map((tech) => {
                const li = el('li');
                const card = el('button', `tm-card is-${tech.status} ${selected === tech.id ? 'is-selected' : ''}`);
                card.type = 'button';
                card.dataset.id = tech.id;
                card.setAttribute('aria-pressed', selected === tech.id ? 'true' : 'false');
                const body = el('span', 'tm-body');
                const status = el('span', 'tm-status');
                status.append(el('span', 'tm-dot'), document.createTextNode(tech.statusLabel));
                body.append(el('span', 'tm-name', tech.name), status);
                body.append(el('span', 'tm-time', tech.locatedLabel ? `Última posición: ${tech.locatedLabel}` : 'Sin posición registrada'));
                card.append(avatar(tech, 'tm-avatar'), body);
                card.addEventListener('click', () => select(tech.id, true));
                li.append(card);
                return li;
            }));
        }

        // Marcadores: se agregan, mueven o quitan según los datos nuevos (sin recrear el mapa).
        const seen = new Set();
        located.forEach((tech) => {
            seen.add(tech.id);
            const latlng = [tech.position.lat, tech.position.lng];
            let m = markers.get(tech.id);
            if (!m) {
                const layer = L.marker(latlng, { icon: pinIcon(tech), title: tech.name, keyboard: true });
                layer.on('click', () => select(tech.id, false));
                cluster.addLayer(layer);
                m = { layer, circle: null };
                markers.set(tech.id, m);
            } else {
                m.layer.setLatLng(latlng);
                m.layer.setIcon(pinIcon(tech));
            }
            m.layer.bindPopup(popup(tech));
            if (m.circle) { map.removeLayer(m.circle); m.circle = null; }
            if (tech.position.approximate) {
                m.circle = L.circle(latlng, { radius: tech.position.accuracy, color: '#94A3B8', weight: 1, fillOpacity: 0.08 }).addTo(map);
            }
        });
        markers.forEach((m, id) => {
            if (!seen.has(id)) { cluster.removeLayer(m.layer); if (m.circle) map.removeLayer(m.circle); markers.delete(id); }
        });

        if (!fitted && located.length) {
            fitted = true;
            map.fitBounds(L.latLngBounds(located.map((t) => [t.position.lat, t.position.lng])).pad(0.3), { maxZoom: 15 });
        }
    }

    render(initial);
    setTimeout(() => map.invalidateSize(), 150);

    // Actualización periódica, solo con la pestaña visible y sin superponer pedidos.
    let busy = false;
    const refresh = async () => {
        if (busy || document.visibilityState !== 'visible') return;
        const component = wireId ? window.Livewire?.find(wireId) : null;
        if (!component) return;
        busy = true;
        try {
            current = await component.call('technicianLocations');
            error.hidden = true;
            render(current);
        } catch (e) {
            error.hidden = false;
        } finally {
            busy = false;
        }
    };
    setInterval(refresh, Math.max(10, config.refreshSeconds) * 1000);
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') refresh(); });
}

function boot() {
    document.querySelectorAll('[data-technicians-shell]').forEach(init);
}

document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', boot) : boot();
document.addEventListener('livewire:navigated', boot);

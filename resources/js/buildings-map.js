/**
 * Mapa de edificios (Filament: App\Filament\Pages\BuildingsMap).
 *
 * - Lee los puntos del JSON embebido por el servidor: no llama a ninguna API
 *   de geocodificación. Los mosaicos se piden directo al CDN (Geoapify con
 *   la key pública restringida por dominio, u OpenStreetMap).
 * - Todo texto que viene de la base se inserta con textContent (nunca
 *   innerHTML) para evitar XSS desde nombres de calles/clientes.
 */
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import 'leaflet.markercluster';
import 'leaflet.markercluster/dist/MarkerCluster.css';
import 'leaflet.markercluster/dist/MarkerCluster.Default.css';
import '../css/buildings-map.css';

const ARGENTINA = [[-55.1, -73.6], [-21.7, -53.6]];

function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = String(text);
    return node;
}

function normalize(value) {
    return String(value ?? '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase();
}

const SAFE_COLOR = /^#[0-9a-fA-F]{6}$/;

// "color" = color asignado por el admin; "status" = estado del mantenimiento del mes.
let colorMode = 'color';

function pinIcon(marker) {
    const classes = ['bm-pin'];
    if (!marker.active) classes.push('is-inactive');

    // El color viene de la paleta del servidor; igual se valida el formato.
    const raw = colorMode === 'status' ? marker.statusColor : marker.color;
    const color = SAFE_COLOR.test(raw ?? '') ? raw : '';

    return L.divIcon({
        className: '',
        html: `<span class="${classes.join(' ')}"${color ? ` style="background:${color}"` : ''}></span>`,
        iconSize: [26, 26],
        iconAnchor: [13, 26],
        popupAnchor: [0, -24],
    });
}

function initMap(container) {
    if (container.dataset.ready) return;
    container.dataset.ready = '1';

    const shell = container.closest('.bm-map-shell');
    const payload = JSON.parse(shell.querySelector('[data-map-payload]').textContent);
    const { config } = payload;
    const markersById = new Map();

    const map = L.map(container, {
        zoomControl: true,
        scrollWheelZoom: true,
        tap: true,
    });

    L.tileLayer(config.tileUrl, {
        maxZoom: config.maxZoom,
        attribution: config.attribution,
        // {r} = "@2x" en pantallas de alta densidad (Geoapify lo soporta).
        r: config.retina && window.devicePixelRatio > 1 ? '@2x' : '',
        crossOrigin: true,
    }).addTo(map);

    const cluster = L.markerClusterGroup({
        showCoverageOnHover: false,
        maxClusterRadius: 50,
        spiderfyOnMaxZoom: true,
        chunkedLoading: true,
    });
    map.addLayer(cluster);

    /* ---------------------------------------------------------------
     | Popup
     --------------------------------------------------------------- */

    function popupContent(marker) {
        const root = el('div', 'bm-popup');

        root.append(el('div', 'bm-popup-title', marker.title));

        if (marker.client) {
            const client = el('div', 'bm-popup-client');
            client.append(el('span', 'bm-popup-label', 'Cliente'), el('span', null, marker.client));
            root.append(client);
        }

        if (marker.area) root.append(el('div', 'bm-popup-meta', marker.area));
        if (marker.zone) root.append(el('div', 'bm-popup-meta', `Zona: ${marker.zone}`));

        const status = el('div', 'bm-popup-meta');
        const dot = el('span');
        dot.style.cssText = `display:inline-block;width:.6rem;height:.6rem;border-radius:999px;margin-right:.35rem;background:${SAFE_COLOR.test(marker.statusColor ?? '') ? marker.statusColor : '#CBD5E1'}`;
        status.append(dot, document.createTextNode(`Mantenimiento del mes: ${marker.statusLabel ?? '—'}`));
        root.append(status);
        if (marker.technicians) root.append(el('div', 'bm-popup-meta', `Técnico: ${marker.technicians}`));

        const units = [];
        if (marker.elevators) units.push(`${marker.elevators} ${marker.elevators === 1 ? 'ascensor' : 'ascensores'}`);
        if (marker.freight) units.push(`${marker.freight} montacargas`);
        if (units.length) root.append(el('div', 'bm-popup-meta', units.join(' · ')));

        if (!marker.active) root.append(el('div', 'bm-popup-badge', 'Inactivo'));
        if (marker.manual) root.append(el('div', 'bm-popup-note', 'Ubicación marcada a mano'));

        const actions = el('div', 'bm-popup-actions');

        const open = el('a', 'bm-popup-link', 'Abrir edificio');
        open.href = config.editUrl.replace('__ID__', encodeURIComponent(marker.id));
        actions.append(open);

        const fix = el('button', 'bm-popup-button', 'Corregir ubicación');
        fix.type = 'button';
        fix.addEventListener('click', () => {
            map.closePopup();
            startPlacing(marker.id, marker.title, [marker.lat, marker.lng]);
        });
        actions.append(fix);

        root.append(actions);

        return root;
    }

    function addMarker(marker) {
        const existing = markersById.get(marker.id);
        if (existing) cluster.removeLayer(existing.layer);

        const layer = L.marker([marker.lat, marker.lng], {
            icon: pinIcon(marker),
            title: marker.title,
            alt: marker.title,
            keyboard: true,
        });
        layer.bindPopup(() => popupContent(marker), { maxWidth: 280, minWidth: 200 });

        markersById.set(marker.id, { data: marker, layer });

        return layer;
    }

    cluster.addLayers(payload.markers.map(addMarker));

    /* ---------------------------------------------------------------
     | Encuadre
     --------------------------------------------------------------- */

    function fitVisible() {
        const bounds = cluster.getBounds();

        if (bounds.isValid()) {
            map.fitBounds(bounds, { padding: [40, 40], maxZoom: 16 });
        } else {
            map.fitBounds(ARGENTINA);
        }
    }

    fitVisible();

    // El contenedor puede cambiar de tamaño (sidebar de Filament, rotación del celular).
    new ResizeObserver(() => map.invalidateSize()).observe(container);

    /* ---------------------------------------------------------------
     | Filtros (en el navegador: los datos ya están cargados)
     --------------------------------------------------------------- */

    const searchInput = document.querySelector('[data-map-search]');
    const clientSelect = document.querySelector('[data-map-client]');
    const colorSelect = document.querySelector('[data-map-color]');
    const zoneSelect = document.querySelector('[data-map-zone]');
    const techSelect = document.querySelector('[data-map-technician]');
    const statusSelect = document.querySelector('[data-map-status]');
    const modeSelect = document.querySelector('[data-map-mode]');
    const legends = document.querySelectorAll('[data-map-legend]');

    // Colorear por color asignado o por estado del mes: se redibujan los pines y la leyenda.
    function applyMode() {
        colorMode = modeSelect?.value === 'status' ? 'status' : 'color';
        for (const { data, layer } of markersById.values()) layer.setIcon(pinIcon(data));
        legends.forEach((legend) => { legend.style.display = legend.dataset.mapLegend === colorMode ? 'contents' : 'none'; });
    }

    function applyFilters() {
        const term = normalize(searchInput?.value).trim();
        const clientId = clientSelect?.value ?? '';
        const colorKey = colorSelect?.value ?? '';
        const zone = zoneSelect?.value ?? '';
        const techId = techSelect?.value ?? '';
        const status = statusSelect?.value ?? '';

        const visible = [];
        for (const { data, layer } of markersById.values()) {
            const matchesClient = (!clientId || String(data.clientId) === clientId)
                && (!colorKey || data.colorKey === colorKey)
                && (!zone || data.zone === zone)
                && (!techId || (data.technicianIds ?? []).map(String).includes(techId))
                && (!status || data.status === status);
            const matchesTerm = !term || normalize(`${data.title} ${data.client} ${data.area} ${data.zone ?? ''} ${data.technicians ?? ''}`).includes(term);
            if (matchesClient && matchesTerm) visible.push(layer);
        }

        cluster.clearLayers();
        cluster.addLayers(visible);
        if (visible.length) fitVisible();
    }

    let searchTimer;
    searchInput?.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(applyFilters, 200);
    });
    clientSelect?.addEventListener('change', applyFilters);
    colorSelect?.addEventListener('change', applyFilters);
    [zoneSelect, techSelect, statusSelect].forEach((select) => select?.addEventListener('change', applyFilters));
    modeSelect?.addEventListener('change', applyMode);
    document.querySelector('[data-map-fit]')?.addEventListener('click', fitVisible);

    /* ---------------------------------------------------------------
     | Modo "marcar en el mapa" (corrección manual)
     --------------------------------------------------------------- */

    const bar = shell.querySelector('[data-map-place-bar]');
    const barText = shell.querySelector('[data-map-place-text]');
    const saveButton = shell.querySelector('[data-map-place-save]');
    const cancelButton = shell.querySelector('[data-map-place-cancel]');

    let placing = null; // { id, title, temp }

    function setTempMarker(latlng) {
        if (placing.temp) {
            placing.temp.setLatLng(latlng);
        } else {
            placing.temp = L.marker(latlng, {
                draggable: true,
                autoPan: true,
                icon: L.divIcon({ className: '', html: '<span class="bm-pin is-placing"></span>', iconSize: [30, 30], iconAnchor: [15, 30] }),
            }).addTo(map);
        }

        saveButton.disabled = false;
        barText.textContent = `Arrastrá el punto si hace falta y guardá la ubicación de ${placing.title}.`;
    }

    function startPlacing(id, title, latlng = null) {
        stopPlacing();

        placing = { id: Number(id), title, temp: null };
        container.classList.add('is-placing');
        bar.hidden = false;
        saveButton.disabled = true;
        barText.textContent = `Tocá el mapa donde está ${title}.`;

        if (latlng) {
            setTempMarker(latlng);
            map.setView(latlng, Math.max(map.getZoom(), 17));
        }

        shell.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function stopPlacing() {
        if (placing?.temp) map.removeLayer(placing.temp);
        placing = null;
        container.classList.remove('is-placing');
        bar.hidden = true;
    }

    map.on('click', (event) => {
        if (placing) setTempMarker(event.latlng);
    });

    cancelButton.addEventListener('click', stopPlacing);

    saveButton.addEventListener('click', async () => {
        if (!placing?.temp) return;

        const { lat, lng } = placing.temp.getLatLng();
        const wireId = shell.closest('[wire\\:id]')?.getAttribute('wire:id');
        const component = wireId ? window.Livewire?.find(wireId) : null;
        if (!component) return;

        saveButton.disabled = true;

        try {
            const marker = await component.call('placeBuilding', placing.id, lat, lng);
            if (marker) {
                stopPlacing();
                cluster.addLayer(addMarker(marker));
                shell.querySelector('[data-map-empty]')?.remove();
            } else {
                saveButton.disabled = false;
            }
        } catch (error) {
            console.error('[buildings-map] No se pudo guardar la ubicación', error);
            saveButton.disabled = false;
        }
    });

    // Puntos nuevos que se ubicaron en segundo plano (wire:poll del servidor).
    window.addEventListener('buildings-map-markers', (event) => {
        const markers = event.detail?.markers ?? [];
        if (!markers.length) return;

        const firstTime = markersById.size === 0;
        cluster.addLayers(markers.map(addMarker));
        shell.querySelector('[data-map-empty]')?.remove();
        if (firstTime) fitVisible();
    });

    // Botones "Marcar en el mapa" de la lista (Livewire la re-renderiza:
    // por eso delegación de eventos y no listeners directos).
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-map-place]');
        if (!button) return;

        event.preventDefault();
        startPlacing(button.dataset.mapPlace, button.dataset.mapPlaceTitle);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && placing) stopPlacing();
    });
}

function boot() {
    document.querySelectorAll('[data-buildings-map]').forEach(initMap);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}
document.addEventListener('livewire:navigated', boot);

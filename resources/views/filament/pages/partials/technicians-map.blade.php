{{--
    Pestaña «Técnicos» del Mapa: tarjeta y marcador de cada técnico con el
    estado real de su ubicación. Nunca muestra una posición vieja como actual:
    las desactualizadas van en gris con su hora. Se actualiza sola cada
    {{ config('location.map_refresh_seconds') }} s mientras la pestaña está a la vista (una sola consulta
    para todos los técnicos).
--}}
@vite(['resources/js/technicians-map.js'])
@php $initial = $this->technicianLocations(); @endphp

<details class="bm-help" open style="margin-bottom:1rem;border:1px solid rgba(127,127,127,.25);border-radius:.75rem;padding:.75rem 1rem;font-size:.875rem;">
    <summary style="cursor:pointer;font-weight:600;">Cómo leer esta vista</summary>
    <ul style="margin:.6rem 0 0;padding-left:1.1rem;line-height:1.55;list-style:disc;">
        <li>Solo aparecen en el mapa los técnicos que <strong>activaron su ubicación</strong> en su perfil y dieron permiso en su celular. Nadie más puede activarla por ellos.</li>
        <li>La posición se actualiza <strong>solo mientras el técnico tiene Ascento abierto en pantalla</strong>. Con el celular bloqueado no llegan posiciones nuevas.</li>
        <li><strong>En línea</strong>: posición de los últimos {{ intdiv((int) config('location.fresh_seconds'), 60) }} minutos. <strong>Desactualizada</strong>: es la última que llegó, con su hora (en gris).</li>
        <li>Se guarda solo la última posición, no un recorrido. Se borra si el técnico la desactiva o cierra sesión.</li>
    </ul>
</details>

<div wire:ignore class="tm-shell" data-technicians-shell>
    <script type="application/json" data-technicians-payload>@json(['initial' => $initial, 'config' => $this->technicianMapConfig()])</script>

    <div class="tm-top">
        <p class="tm-summary" data-tm-summary role="status" aria-live="polite"></p>
        <p class="tm-updated" data-tm-updated></p>
    </div>
    <p class="tm-error" data-tm-error hidden role="alert">No pudimos actualizar las ubicaciones. Volvemos a intentar en unos segundos.</p>

    <div class="tm-layout">
        <ul class="tm-list" data-tm-list role="list" aria-label="Técnicos"></ul>
        <div class="tm-map-wrap">
            <div class="tm-map" data-technicians-map role="region" aria-label="Mapa de técnicos"></div>
            <div class="tm-map-empty" data-tm-map-empty hidden>
                <p><strong>Ningún técnico tiene una ubicación para mostrar.</strong></p>
                <p>Cada técnico la activa desde su perfil, en «Ubicación», y da permiso en su celular.</p>
            </div>
        </div>
    </div>
</div>

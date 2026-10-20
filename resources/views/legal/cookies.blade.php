{{--
    Política de cookies: describe SOLO lo que Ascento usa hoy (auditado en el
    código el 19/10/2026: cookies de Laravel, claves de localStorage /
    sessionStorage de resources/js y de Filament, y los servicios que cargan
    algunas páginas). Si se agrega una categoría opcional (config/cookies.php),
    el aviso pide consentimiento y esta página debe actualizarse.
--}}
@extends('legal.layout')

@php
    $session = config('session.cookie');
    $minutes = (int) config('session.lifetime');
@endphp

@section('title', 'Política de Cookies')
@section('description', 'Qué cookies y datos del navegador usa Ascento, para qué y cómo controlarlos.')
@section('active', 'cookies')
@section('kicker', 'Cookies')
@section('heading', 'Política de Cookies')
@section('lead', 'Qué cookies y qué datos del navegador usa Ascento, para qué sirven y cómo podés controlarlos.')

@section('content')
    <x-legal.section n="01" title="Resumen" id="resumen">
        <p>Ascento usa <strong>solo cookies estrictamente necesarias</strong> para iniciar sesión, mantenerla y proteger los formularios. <strong>No usamos cookies de publicidad ni de analítica</strong> y no hacemos seguimiento entre sitios.</p>
    </x-legal.section>

    <x-legal.section n="02" title="Cookies que usamos" id="cookies">
        <div class="overflow-x-auto rounded-2xl border border-[#14171C]/10 bg-white">
            <table class="w-full text-sm leading-6">
                <thead class="bg-[#12151C] text-white text-left">
                    <tr><th class="px-4 py-3 font-semibold">Nombre</th><th class="px-4 py-3 font-semibold">Para qué</th><th class="px-4 py-3 font-semibold">Duración</th></tr>
                </thead>
                <tbody class="divide-y divide-[#14171C]/10 align-top">
                    <tr><td class="px-4 py-3"><code>{{ $session }}</code></td><td class="px-4 py-3">Mantener tu sesión iniciada. No es accesible desde JavaScript.</td><td class="px-4 py-3">{{ $minutes }} minutos sin actividad</td></tr>
                    <tr><td class="px-4 py-3"><code>XSRF-TOKEN</code></td><td class="px-4 py-3">Proteger los formularios contra envíos falsificados (CSRF).</td><td class="px-4 py-3">Igual que la sesión</td></tr>
                    <tr><td class="px-4 py-3"><code>remember_web_…</code></td><td class="px-4 py-3">Solo si tildás «Recordarme»: seguir conectado en este dispositivo.</td><td class="px-4 py-3">Hasta que cierres sesión (como máximo, 400 días)</td></tr>
                </tbody>
            </table>
        </div>
        <p>Son necesarias para que el servicio funcione, por eso no se pueden desactivar desde Ascento. Podés borrarlas desde tu navegador: en ese caso se cierra tu sesión.</p>
        <p>La página pública del QR no necesita cookies para reportar un problema: funciona aunque las bloquees.</p>
    </x-legal.section>

    <x-legal.section n="03" title="Datos guardados en tu navegador (no son cookies)" id="almacenamiento">
        <p>Para recordar preferencias y no perder lo que estás escribiendo, Ascento guarda algunos datos en el almacenamiento de tu navegador. No se envían a terceros.</p>
        <ul>
            <li><code>ascento.cookies</code>: que ya viste el aviso de cookies.</li>
            <li><code>ascento.draft.…</code>: borradores del remito y del reporte del técnico (solo el texto; no fotos ni firmas), para no perderlos si se corta la señal. Se borran al enviarlos, al cerrar sesión o a los 7 días.</li>
            <li><code>ascento-push-…</code> y <code>ascento.portal.push-later</code>: el estado de las notificaciones de este dispositivo, si las activaste o elegiste «Ahora no».</li>
            <li><code>ascento.mapHelpClosed</code>: si cerraste la ayuda del mapa.</li>
            <li><code>theme</code>, <code>isOpen</code>, <code>isOpenDesktop</code> y <code>collapsedGroups</code>: en el panel, el tema claro u oscuro y cómo dejaste el menú.</li>
            <li>Solo mientras la pestaña está abierta: <code>ascento-seen-pending</code>, para actualizar el contador de avisos, y <code>ascento.location.reported</code>, en la app del técnico, si diste o negaste el permiso de ubicación (cuando la empresa usa esa función).</li>
        </ul>
        <p>Podés borrarlos desde la configuración de tu navegador («Borrar datos de navegación» o «Datos de sitios»).</p>
    </x-legal.section>

    <x-legal.section n="04" title="Servicios de terceros que cargan algunas páginas" id="terceros">
        <p>Las tipografías, estilos y scripts de Ascento se sirven desde nuestro propio servidor. Algunas funciones conectan tu navegador con otros servicios; no ponen cookies de Ascento, pero esos servicios reciben tu dirección IP, como cualquier sitio que visitás:</p>
        <ul>
            <li><strong>Mapa del panel de la empresa:</strong> las imágenes del mapa vienen de Geoapify o de OpenStreetMap, según la configuración.</li>
            <li><strong>Notificaciones del navegador,</strong> solo si las activás: las entrega el servicio de tu navegador (por ejemplo, de Google, Apple o Mozilla).</li>
            <li><strong>Enlaces externos,</strong> solo si los tocás: por ejemplo, «Cómo llegar» abre Google Maps y «Compartir por WhatsApp» abre WhatsApp.</li>
        </ul>
    </x-legal.section>

    <x-legal.section n="05" title="Si cambiamos algo" id="cambios">
        <p>Si en el futuro sumamos alguna herramienta opcional (por ejemplo, de analítica), te la vamos a pedir antes de usarla y vas a poder rechazarla sin dejar de usar Ascento. Podés revisar tu elección en cualquier momento: <button type="button" class="font-semibold text-[#C24800] underline underline-offset-2" data-cookie-preferences>Preferencias de cookies</button>.</p>
        <p>Más información sobre cómo tratamos tus datos en la <a href="{{ route('legal.privacy') }}">Política de Privacidad</a>.</p>
    </x-legal.section>
@endsection

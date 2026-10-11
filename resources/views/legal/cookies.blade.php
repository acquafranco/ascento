{{--
    Política de cookies: describe SOLO lo que Ascento usa hoy (auditado en el
    código). Borrador: pendiente de revisión legal antes de darlo por final.
--}}
@php
    $session = config('session.cookie');
    $minutes = (int) config('session.lifetime');
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Política de cookies · Ascento</title>
    @include('partials.brand-head')
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #F7F7F4; color: #11161D; font: 16px/1.6 system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; }
        main { max-width: 760px; margin: 0 auto; padding: 32px 16px 64px; }
        a { color: #B84A00; }
        .top { display: flex; align-items: center; gap: 10px; text-decoration: none; color: inherit; font-weight: 700; }
        .top img { width: 32px; height: 32px; border-radius: 8px; }
        h1 { font-size: 30px; line-height: 1.2; margin: 28px 0 6px; }
        h2 { font-size: 19px; margin: 32px 0 8px; }
        .draft { margin: 16px 0 0; padding: 12px 14px; border-radius: 12px; background: #FFF4EC; border: 1px solid #FFC9A3; color: #7A3300; font-size: 14px; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; background: #fff; border-radius: 12px; overflow: hidden; }
        th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid #E6E8EC; vertical-align: top; }
        th { background: #11161D; color: #fff; font-weight: 600; }
        code { font-size: 13px; background: #F4F5F7; padding: 1px 5px; border-radius: 5px; }
        button.link { background: none; border: 0; padding: 0; color: #B84A00; font: inherit; text-decoration: underline; cursor: pointer; }
    </style>
</head>
<body>
<main>
    <a href="/" class="top"><img src="{{ asset('images/brand/logo-128.png') }}" alt="">Ascento</a>

    <h1>Política de cookies</h1>
    <p style="margin:0;color:#5B6472">Última actualización: {{ \Carbon\Carbon::parse('2026-10-11')->translatedFormat('j \d\e F \d\e Y') }}</p>
    <p class="draft"><strong>Borrador pendiente de revisión legal.</strong> Describe lo que Ascento usa hoy, verificado en el sistema.</p>

    <h2>Resumen</h2>
    <p>Ascento usa <strong>solo cookies estrictamente necesarias</strong> para iniciar sesión, mantenerla y proteger los formularios. <strong>No usamos cookies de publicidad ni de analítica</strong> y no hacemos seguimiento entre sitios.</p>

    <h2>Cookies que usamos</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Nombre</th><th>Para qué</th><th>Duración</th></tr></thead>
            <tbody>
                <tr><td><code>{{ $session }}</code></td><td>Mantener tu sesión iniciada. No es accesible desde JavaScript.</td><td>{{ $minutes }} minutos sin actividad</td></tr>
                <tr><td><code>XSRF-TOKEN</code></td><td>Proteger los formularios contra envíos falsificados (CSRF).</td><td>Igual que la sesión</td></tr>
                <tr><td><code>remember_web_…</code></td><td>Solo si tildás "Recordarme": seguir conectado en este dispositivo.</td><td>Hasta cerrar sesión</td></tr>
            </tbody>
        </table>
    </div>
    <p>Son necesarias para que el servicio funcione, por eso no se pueden desactivar desde Ascento. Podés borrarlas desde tu navegador: en ese caso se cierra tu sesión.</p>

    <h2>Datos guardados en tu navegador (no son cookies)</h2>
    <p>Para recordar preferencias, Ascento guarda pocos datos en el almacenamiento local de tu navegador. No se envían a ningún servidor de terceros.</p>
    <ul>
        <li><code>ascento.cookies</code>: que ya viste este aviso.</li>
        <li><code>ascento-push-*</code>: el estado de las notificaciones de este dispositivo, si las activaste o elegiste "Ahora no".</li>
        <li><code>ascento.mapHelpClosed</code>: si cerraste la ayuda del mapa.</li>
    </ul>

    <h2>Servicios de terceros que cargan algunas páginas</h2>
    <p>Algunas páginas descargan recursos de estos proveedores. No ponen cookies de Ascento, pero tu navegador se conecta con ellos (como con cualquier sitio):</p>
    <ul>
        <li>Tipografías de Google Fonts y Bunny Fonts.</li>
        <li>Estilos y componentes desde cdn.tailwindcss.com y cdn.jsdelivr.net (páginas públicas).</li>
        <li>Mapas de OpenStreetMap (solo en el panel de la empresa).</li>
        <li>Notificaciones push del navegador, solo si las activás: las entrega el servicio de tu navegador (por ejemplo Google o Apple).</li>
    </ul>

    <h2>Si cambiamos algo</h2>
    <p>Si en el futuro sumamos alguna herramienta opcional (por ejemplo, de analítica), te lo vamos a pedir antes de usarla y vas a poder rechazarla sin dejar de usar Ascento. Podés revisar tu elección en cualquier momento: <button type="button" class="link" data-cookie-preferences>Preferencias de cookies</button>.</p>

    <h2>Contacto</h2>
    <p>Consultas: <a href="mailto:{{ config('app.support_email') }}">{{ config('app.support_email') }}</a>. Ver también la <a href="{{ route('legal.privacy') }}">Política de privacidad</a>.</p>
</main>
@include('partials.cookie-notice')
</body>
</html>

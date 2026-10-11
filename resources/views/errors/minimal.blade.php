{{--
    Páginas de error con la marca de Ascento (reemplaza el diseño genérico de
    Laravel para 401, 403, 404, 419, 429, 500 y 503). Sin datos técnicos.
--}}
@php
    $code = trim($__env->yieldContent('code'));
    $messages = [
        '401' => ['Necesitás ingresar', 'Iniciá sesión para ver esta página.'],
        '402' => ['Pago requerido', 'Revisá el estado de tu suscripción.'],
        '403' => ['No tenés acceso', 'Tu usuario no tiene permiso para ver esta página.'],
        '404' => ['No encontramos esta página', 'Puede que el enlace esté mal escrito o que el contenido ya no exista.'],
        '419' => ['La página venció', 'Pasó mucho tiempo sin actividad. Volvé atrás, recargá e intentá de nuevo.'],
        '429' => ['Demasiados intentos', 'Esperá un minuto y volvé a intentar.'],
        '500' => ['Algo salió mal', 'Tuvimos un problema al procesar tu pedido. Probá de nuevo en unos minutos.'],
        '503' => ['Estamos haciendo mantenimiento', 'Ascento vuelve a estar disponible en unos minutos.'],
    ];
    [$title, $text] = $messages[$code] ?? [trim($__env->yieldContent('message')) ?: 'Algo salió mal', ''];
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }} · Ascento</title>
    @include('partials.brand-head')
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px 16px;
            background: #F7F7F4; color: #12151C; font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; }
        .card { width: 100%; max-width: 440px; text-align: center; }
        .logo { width: 56px; height: 56px; border-radius: 14px; }
        .code { margin: 22px 0 4px; font-size: 13px; font-weight: 700; letter-spacing: .14em; color: #C24800; }
        h1 { margin: 0 0 10px; font-size: 26px; line-height: 1.2; }
        p { margin: 0 0 24px; color: #5B6070; line-height: 1.5; }
        a.btn { display: inline-block; padding: 11px 20px; border-radius: 10px; background: #FF6A1A; color: #12151C; font-weight: 700; text-decoration: none; }
        a.btn:focus-visible { outline: 3px solid #12151C; outline-offset: 2px; }
    </style>
</head>
<body>
    <main class="card">
        <img class="logo" src="{{ asset('images/brand/logo-128.png') }}" alt="Ascento" width="56" height="56">
        <div class="code">ERROR {{ $code }}</div>
        <h1>{{ $title }}</h1>
        @if($text)<p>{{ $text }}</p>@endif
        @unless($code === '503')
            <a class="btn" href="{{ url('/') }}">Ir al inicio</a>
        @endunless
    </main>
</body>
</html>

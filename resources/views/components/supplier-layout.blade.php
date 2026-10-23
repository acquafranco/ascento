@props(['title' => 'Proveedores'])
@php
    $user = auth()->user();
    $supplier = $user?->supplier;
    // Catálogo, pedidos, plan y destacados: solo si la cuenta vende productos (todas, salvo una anterior «solo servicios»).
    $tabs = array_merge([['suppliers.home', 'Inicio', route('suppliers.home')]],
        $supplier?->sellsProducts() === false ? [] : [
            ['suppliers.products.*', 'Mi catálogo', route('suppliers.products.index')],
            ['suppliers.requests.*', 'Pedidos', route('suppliers.requests.index')],
            ['suppliers.billing*', 'Plan', route('suppliers.billing')],
            ['suppliers.featured*', 'Destacados', route('suppliers.featured')],
        ], [
            ['suppliers.profile.*', 'Mi perfil', route('suppliers.profile.edit')],
        ]);
    $unreadCount = $user ? $user->unreadNotifications()->count() : 0;
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('partials.brand-head')
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} · Ascento Proveedores</title>
    @if($user)
        {{-- Push del proveedor: clave PÚBLICA VAPID y URLs del área (componente compartido resources/js/push-notifications.js). --}}
        <meta name="ascento-push" content="{{ json_encode([
            'userId' => $user->id,
            'vapidPublicKey' => config('webpush.vapid.public_key'),
            'storeUrl' => route('suppliers.push.store'),
            'destroyUrl' => route('suppliers.push.destroy'),
            'testUrl' => route('suppliers.push.test'),
            'successMessage' => 'Listo. Te vamos a avisar en este dispositivo cuando llegue un pedido o cambie tu cuenta.',
        ]) }}">
        <link rel="manifest" href="/manifest.webmanifest">
        @vite(['resources/js/portal.js'])
    @endif
    <style>
        :root { --dark: #11161D; --orange: #FF6600; --orange-ink: #B34700; --light: #F7F7F4; --line: #E4E2DA; --ink: #11161D; --muted: #5F6472; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; background: var(--light); color: var(--ink); line-height: 1.45; -webkit-font-smoothing: antialiased; }
        a { color: inherit; }
        :focus-visible { outline: 3px solid var(--orange); outline-offset: 2px; }
        .s-top { background: var(--dark); color: #fff; }
        .s-top-in, .s-nav-in, .s-main { max-width: 1080px; margin: 0 auto; }
        .s-top-in { padding: 12px 16px; display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .s-brand { display: flex; align-items: center; gap: 10px; text-decoration: none; min-width: 0; }
        .s-brand img { width: 34px; height: 34px; }
        .s-brand b { display: block; font-size: 15px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .s-brand small { display: block; color: #A9ADB8; font-size: 12px; }
        .s-out { background: rgba(255,255,255,.06); color: #fff; border: 1px solid rgba(255,255,255,.16); border-radius: 10px; padding: 7px 11px; font: inherit; font-size: 13px; cursor: pointer; }
        .s-nav { background: var(--dark); border-top: 1px solid rgba(255,255,255,.08); }
        .s-nav-in { padding: 0 8px; display: flex; gap: 2px; overflow-x: auto; scrollbar-width: none; }
        .s-nav a { color: #C9CCD4; text-decoration: none; font-size: 14px; padding: 11px 12px; border-bottom: 3px solid transparent; white-space: nowrap; }
        .s-nav a[aria-current] { color: #fff; border-bottom-color: var(--orange); font-weight: 600; }
        .s-main { padding: 22px 16px 56px; }
        .s-h1 { font-size: 23px; margin: 0 0 4px; letter-spacing: -.01em; }
        .s-sub { color: var(--muted); margin: 0 0 18px; font-size: 14px; }
        .s-card { background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 16px; }
        .s-card + .s-card, .s-stack > * + * { margin-top: 14px; }
        .s-grid > .s-card { margin-top: 0; }
        .s-grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); }
        .s-h2 { font-size: 16px; margin: 0 0 10px; }
        .s-muted { color: var(--muted); font-size: 13px; }
        .s-tag { display: inline-block; font-size: 12px; padding: 2px 9px; border-radius: 999px; background: #EEF0F4; color: #3D4250; font-weight: 600; white-space: nowrap; }
        .s-tag.ok { background: #E3F4EA; color: #17663A; } .s-tag.warn { background: #FFF1E6; color: #A84300; }
        .s-tag.bad { background: #FDE8E8; color: #9B1C1C; } .s-tag.info { background: #E8EEFD; color: #1E3A8A; }
        .s-btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; background: var(--orange); color: var(--dark); border: 0; border-radius: 10px; padding: 10px 15px; font: inherit; font-weight: 700; font-size: 14px; cursor: pointer; text-decoration: none; min-height: 42px; }
        .s-btn:hover { background: #FF7A22; }
        .s-btn.secondary { background: #fff; color: var(--ink); border: 1px solid #D3D1C8; font-weight: 600; }
        .s-btn.danger { background: #fff; color: #9B1C1C; border: 1px solid #F3C4C4; font-weight: 600; }
        .s-link { color: var(--orange-ink); font-weight: 600; text-decoration: none; }
        .s-link:hover { text-decoration: underline; }
        .s-notice { border-radius: 14px; padding: 14px 16px; border: 1px solid; margin-bottom: 16px; font-size: 14px; }
        .s-notice.warn { background: #FFF6EE; border-color: #FFD3B0; }
        .s-notice.bad { background: #FDF0F0; border-color: #F3C4C4; }
        .s-notice.ok { background: #EEF8F2; border-color: #BFE5CD; }
        .s-form label.s-label { display: block; font-size: 13px; font-weight: 600; margin: 0 0 4px; }
        .s-form .s-field { margin-bottom: 14px; }
        .s-form input[type=text], .s-form input[type=number], .s-form input[type=email], .s-form input[type=tel], .s-form input[type=url], .s-form select, .s-form textarea {
            width: 100%; border: 1px solid #CFCCC2; border-radius: 10px; padding: 10px 12px; font: inherit; font-size: 15px; background: #fff; color: var(--ink); }
        .s-form textarea { min-height: 96px; }
        .s-hint { color: var(--muted); font-size: 12px; margin-top: 4px; }
        .s-err { color: #9B1C1C; font-size: 13px; margin-top: 4px; }
        .s-cols { display: grid; gap: 0 14px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
        .s-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .s-table th { text-align: left; font-size: 12px; color: var(--muted); font-weight: 600; padding: 8px; border-bottom: 1px solid var(--line); }
        .s-table td { padding: 10px 8px; border-bottom: 1px solid #F0EEE7; vertical-align: top; }
        .s-scroll { overflow-x: auto; }
        .s-row { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
        .s-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .s-photos { display: grid; gap: 10px; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); }
        .s-photos img { width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 10px; border: 1px solid var(--line); display: block; }
        .s-stat b { display: block; font-size: 24px; }
        .s-foot { text-align: center; font-size: 12px; color: #8C8F98; margin-top: 36px; }
        [x-cloak] { display: none !important; }
        .s-bell { position: relative; display: inline-flex; align-items: center; gap: 6px; background: rgba(255,255,255,.06); color: #fff; border: 1px solid rgba(255,255,255,.16); border-radius: 10px; padding: 7px 10px; font-size: 13px; text-decoration: none; }
        .s-count { background: var(--orange); color: var(--dark); border-radius: 999px; font-size: 11px; font-weight: 800; padding: 0 6px; min-width: 18px; text-align: center; line-height: 18px; }
        .s-push { display: flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap: wrap; }
        .s-list { list-style: none; margin: 0; padding: 0; }
        .s-list li + li { border-top: 1px solid #F0EEE7; }
        .s-item { display: flex; justify-content: space-between; gap: 12px; padding: 12px 0; text-decoration: none; }
        .s-event { border-left: 3px solid var(--line); padding: 2px 0 2px 10px; margin: 0 0 10px; }
        .s-event.note { border-left-color: #8DA2FB; } .s-event.incident { border-left-color: #F98080; } .s-event.resolved { border-left-color: #31C48D; }
        @media (max-width: 640px) { .s-brand small { display: none; } .s-table.s-stack-sm thead { display: none; } .s-table.s-stack-sm td { display: block; border: 0; padding: 4px 0; } .s-table.s-stack-sm tr { display: block; border-bottom: 1px solid var(--line); padding: 10px 0; } }
        @media (prefers-reduced-motion: reduce) { * { transition: none !important; animation: none !important; } }
    </style>
</head>
<body>
    <a href="#contenido" style="position:absolute;left:-9999px" onfocus="this.style.left='8px'">Saltar al contenido</a>
    <header class="s-top">
        <div class="s-top-in">
            <a class="s-brand" href="{{ route('suppliers.home') }}">
                <img src="{{ asset('images/brand/logo-64.png') }}" alt="">
                <span style="min-width:0"><b>{{ $supplier?->name ?? 'Ascento' }}</b><small>Ascento · Proveedores de repuestos</small></span>
            </a>
            <span style="display:flex; gap:8px; align-items:center">
                <a class="s-bell" href="{{ route('suppliers.notifications.index') }}" aria-label="Avisos">🔔 <span class="s-count" data-unread-count @if($unreadCount === 0) hidden @endif>{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span></a>
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="s-out" type="submit">Salir</button></form>
            </span>
        </div>
        <nav class="s-nav" aria-label="Secciones"><div class="s-nav-in">
            @foreach($tabs as [$pattern, $label, $url])
                <a href="{{ $url }}" @if(request()->routeIs($pattern)) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </div></nav>
    </header>
    <main id="contenido" class="s-main">
        @if(session('status'))
            <div class="s-notice ok" role="status">{{ session('status') }}</div>
        @endif
        @foreach(['estado', 'suscripcion', 'destacado', 'comentario', 'incidencia'] as $field)
            @if($errors->has($field))
                <div class="s-notice bad" role="alert">{{ $errors->first($field) }}</div>
            @endif
        @endforeach
        {{ $slot }}
        <p class="s-foot">Ascento no cobra comisiones ni procesa los pagos de los repuestos: el pago, el envío y la entrega se coordinan directamente entre proveedor y comprador.
            · <a href="{{ route('legal.terms') }}#repuestos">Términos</a> · <a href="{{ route('legal.privacy') }}">Privacidad</a></p>
    </main>
    @if($user)
        @include('partials.realtime-inbox', ['countUrl' => route('suppliers.notifications.count'), 'inboxUrl' => route('suppliers.notifications.index')])
    @endif
</body>
</html>

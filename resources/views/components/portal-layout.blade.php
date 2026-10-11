@props(['company' => null, 'title' => 'Portal de clientes', 'bell' => true, 'nav' => true])
@php
    $company = $company ?? ($portalCompany ?? null);
    $user = auth()->user();
    $showBell = $bell && $user?->isClientUser();
    $memberships = $portalMemberships ?? collect();
    $unreadCount = $showBell ? $user->unreadNotifications()->count() : 0;
    $tabs = [
        ['portal.home', 'Inicio', route('portal.home')],
        ['portal.documents', 'Documentos', route('portal.documents')],
        ['portal.visits:maintenance', 'Mantenimientos', route('portal.visits', 'maintenance')],
        ['portal.visits:inspection', 'Inspecciones', route('portal.visits', 'inspection')],
        ['portal.notifications', 'Avisos', route('portal.notifications')],
    ];
    $current = request()->route()?->getName().(request()->route('type') ? ':'.request()->route('type') : '');
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('partials.brand-head')
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} · {{ $company?->name ?? 'Ascento' }}</title>
    @if($showBell)
        {{-- Push del cliente: clave PÚBLICA VAPID y URLs del portal (resources/js/portal.js). --}}
        <meta name="ascento-push" content="{{ json_encode([
            'userId' => $user->id,
            'vapidPublicKey' => config('webpush.vapid.public_key'),
            'storeUrl' => route('portal.push.store'),
            'destroyUrl' => route('portal.push.destroy'),
            'testUrl' => route('portal.push.test'),
            'successMessage' => 'Listo. Te vamos a avisar cuando '.($company?->name ?? 'tu empresa').' comparta algo de tus edificios.',
        ]) }}">
        <link rel="manifest" href="/manifest.webmanifest">
        @vite(['resources/js/portal.js'])
    @endif
    <style>
        :root { --dark: #12151C; --orange: #FF6A1A; --orange-ink: #C24800; --light: #F7F7F4; --line: #E6E4DC; --ink: #1F2430; --muted: #6B7080; }
        * { box-sizing: border-box; }
        [x-cloak] { display: none !important; }
        body { margin: 0; font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; background: var(--light); color: var(--ink); line-height: 1.45; -webkit-font-smoothing: antialiased; }
        a { color: inherit; }
        :focus-visible { outline: 3px solid var(--orange); outline-offset: 2px; }
        .p-top { background: var(--dark); color: #F7F7F4; }
        .p-top-in { max-width: 1040px; margin: 0 auto; padding: 14px 16px; display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .p-brand { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .p-brand img { height: 36px; width: auto; max-width: 120px; border-radius: 8px; background: #fff; padding: 2px; }
        .p-brand img.p-ascento { background: none; padding: 0; width: 36px; }
        .p-brand b { display: block; font-size: 15px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .p-brand small { display: block; color: #A9ADB8; font-size: 12px; }
        .p-actions { display: flex; align-items: center; gap: 8px; }
        .p-btn-ghost { display: inline-flex; align-items: center; gap: 6px; background: rgba(255,255,255,.06); color: #F7F7F4; border: 1px solid rgba(255,255,255,.14); border-radius: 10px; padding: 7px 10px; font-size: 13px; text-decoration: none; cursor: pointer; font-family: inherit; }
        .p-btn-ghost:hover { background: rgba(255,255,255,.12); }
        .p-count { background: var(--orange); color: var(--dark); border-radius: 999px; font-size: 11px; font-weight: 800; padding: 0 6px; min-width: 18px; text-align: center; line-height: 18px; }
        .p-switch { background: rgba(255,255,255,.06); color: #F7F7F4; border: 1px solid rgba(255,255,255,.14); border-radius: 10px; padding: 7px 8px; font-size: 13px; max-width: 170px; }
        .p-switch option { color: #1F2430; }
        .p-nav { background: var(--dark); border-top: 1px solid rgba(255,255,255,.08); }
        .p-nav-in { max-width: 1040px; margin: 0 auto; padding: 0 8px; display: flex; gap: 2px; overflow-x: auto; scrollbar-width: none; }
        .p-nav a { color: #C9CCD4; text-decoration: none; font-size: 14px; padding: 11px 12px; border-bottom: 3px solid transparent; white-space: nowrap; }
        .p-nav a[aria-current] { color: #fff; border-bottom-color: var(--orange); font-weight: 600; }
        .p-main { max-width: 1040px; margin: 0 auto; padding: 22px 16px 56px; }
        .p-h1 { font-size: 23px; margin: 0 0 4px; letter-spacing: -.01em; }
        .p-sub { color: var(--muted); margin: 0 0 18px; font-size: 14px; }
        .p-back { display: inline-block; margin-bottom: 12px; font-size: 14px; color: var(--muted); text-decoration: none; }
        .p-grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); }
        .p-card { background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 16px; text-decoration: none; display: block; }
        a.p-card:hover { border-color: var(--orange); box-shadow: 0 6px 18px -10px rgba(18,21,28,.35); }
        .p-card h3 { margin: 0 0 4px; font-size: 16px; }
        .p-muted { color: var(--muted); font-size: 13px; }
        .p-sec { margin-top: 26px; }
        .p-sec-head { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; margin-bottom: 8px; }
        .p-sec h2 { font-size: 16px; margin: 0; }
        .p-link { color: var(--orange-ink); font-size: 14px; font-weight: 600; text-decoration: none; }
        .p-list { list-style: none; margin: 0; padding: 0; background: #fff; border: 1px solid var(--line); border-radius: 14px; overflow: hidden; }
        .p-list li { border-top: 1px solid #F0EEE7; }
        .p-list li:first-child { border-top: 0; }
        .p-row { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 12px 16px; text-decoration: none; }
        a.p-row:hover { background: #FBFAF6; }
        .p-row-main { min-width: 0; display: flex; gap: 12px; align-items: center; }
        .p-row-title { font-weight: 600; font-size: 14px; display: block; }
        .p-row-text { color: var(--muted); font-size: 13px; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 62ch; }
        .p-row-side { text-align: right; flex-shrink: 0; }
        .p-tag { display: inline-block; font-size: 12px; padding: 2px 9px; border-radius: 999px; background: #EEF0F4; color: #3D4250; white-space: nowrap; font-weight: 600; }
        .p-tag.ok { background: #E3F4EA; color: #17663A; }
        .p-tag.warn { background: #FFF1E6; color: #A84300; }
        .p-tag.bad { background: #FDE8E8; color: #9B1C1C; }
        .p-tag.info { background: #E8EEFD; color: #1E3A8A; }
        .p-empty { background: #fff; border: 1px dashed #D6D3C8; border-radius: 14px; padding: 18px; color: var(--muted); font-size: 14px; }
        .p-photos { display: grid; gap: 10px; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); }
        .p-photos img { width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 12px; border: 1px solid var(--line); }
        .p-text { white-space: pre-line; background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 16px; }
        .p-foot { text-align: center; font-size: 12px; color: #9A9DA6; margin-top: 36px; }
        .p-btn { display: inline-flex; align-items: center; gap: 6px; background: var(--orange); color: var(--dark); border: 0; border-radius: 10px; padding: 9px 14px; font-weight: 700; font-size: 14px; cursor: pointer; text-decoration: none; font-family: inherit; }
        .p-btn.secondary { background: #fff; color: var(--ink); border: 1px solid var(--line); font-weight: 600; }
        .p-filters { display: grid; gap: 10px; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 14px; }
        .p-filters label { display: block; font-size: 12px; color: var(--muted); margin-bottom: 4px; font-weight: 600; }
        .p-filters input, .p-filters select { width: 100%; border: 1px solid #D6D3C8; border-radius: 9px; padding: 8px 10px; font-size: 14px; background: #fff; font-family: inherit; color: var(--ink); }
        .p-filter-actions { display: flex; gap: 8px; align-items: end; }
        .p-tabs { display: flex; gap: 6px; overflow-x: auto; margin: 14px 0 12px; scrollbar-width: none; }
        .p-tabs a { text-decoration: none; font-size: 13px; padding: 7px 12px; border-radius: 999px; border: 1px solid var(--line); background: #fff; white-space: nowrap; }
        .p-tabs a[aria-current] { background: var(--dark); color: #fff; border-color: var(--dark); }
        .p-tabs b { font-weight: 700; margin-left: 4px; opacity: .7; }
        .p-pager { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-top: 14px; font-size: 13px; color: var(--muted); }
        .p-stats { display: grid; gap: 10px; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); margin-top: 10px; }
        .p-stat { background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 10px 12px; text-decoration: none; }
        .p-stat b { display: block; font-size: 20px; }
        .p-icon { width: 34px; height: 34px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; background: #FFF1E6; color: var(--orange-ink); flex-shrink: 0; font-size: 16px; }
        details.p-acc { background: #fff; border: 1px solid var(--line); border-radius: 14px; }
        details.p-acc > summary { cursor: pointer; padding: 12px 16px; font-weight: 600; list-style: none; display: flex; justify-content: space-between; }
        details.p-acc > summary::-webkit-details-marker { display: none; }
        details.p-acc[open] > summary { border-bottom: 1px solid #F0EEE7; }
        .p-push { background: #fff; border: 1px solid var(--line); border-left: 4px solid var(--orange); border-radius: 14px; padding: 14px 16px; display: flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap: wrap; }
        @media (max-width: 640px) {
            .p-brand small { display: none; }
            .p-hide-sm { display: none; }
            .p-row-text { max-width: 26ch; }
            .p-has-switch .p-brand > span { display: none; }
            details.p-filter-box:not([open]) { margin-bottom: 4px; }
        }
        details.p-filter-box > summary { cursor: pointer; font-weight: 600; font-size: 14px; margin-bottom: 8px; list-style: none; }
        details.p-filter-box > summary::-webkit-details-marker { display: none; }
    </style>
</head>
<body>
    <a href="#contenido" style="position:absolute;left:-9999px" onfocus="this.style.left='8px'">Saltar al contenido</a>
    <header class="p-top {{ $nav && $memberships->unique('company_id')->count() > 1 ? 'p-has-switch' : '' }}">
        <div class="p-top-in">
            <a class="p-brand" href="{{ $nav ? route('portal.home') : '#' }}" style="text-decoration:none">
                @if($company?->logo)
                    <img src="{{ asset('storage/'.$company->logo) }}" alt="">
                @else
                    <img class="p-ascento" src="{{ asset('images/brand/logo-64.png') }}" alt="">
                @endif
                <span style="min-width:0"><b>{{ $company?->name ?? 'Ascento' }}</b><small>Portal de clientes</small></span>
            </a>
            <div class="p-actions">
                @if($nav && $memberships->unique('company_id')->count() > 1)
                    <form method="POST" action="{{ route('portal.switch-company') }}">
                        @csrf
                        <label for="p-company" style="position:absolute;left:-9999px">Empresa</label>
                        <select id="p-company" name="company" class="p-switch" onchange="this.form.submit()">
                            @foreach($memberships->unique('company_id') as $m)
                                <option value="{{ $m->company_id }}" @selected($m->company_id === $company?->id)>{{ $m->company?->name }}</option>
                            @endforeach
                        </select>
                        <noscript><button class="p-btn-ghost" type="submit">Cambiar</button></noscript>
                    </form>
                @endif
                @if($showBell)
                    <a class="p-btn-ghost" href="{{ route('portal.notifications') }}" aria-label="Avisos">
                        <span aria-hidden="true">🔔</span><span class="p-hide-sm">Avisos</span>
                        <span class="p-count" data-unread-count @if($unreadCount === 0) hidden @endif>{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
                    </a>
                @endif
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="p-btn-ghost" type="submit">Salir</button>
                </form>
            </div>
        </div>
        @if($nav)
            <nav class="p-nav" aria-label="Secciones del portal">
                <div class="p-nav-in">
                    @foreach($tabs as [$name, $label, $url])
                        <a href="{{ $url }}" @if($current === $name || ($name === 'portal.home' && $current === 'portal.building')) aria-current="page" @endif>{{ $label }}</a>
                    @endforeach
                </div>
            </nav>
        @endif
    </header>
    <main class="p-main" id="contenido">
        {{ $slot }}
        <p class="p-foot">Información compartida por {{ $company?->name }} mediante Ascento.</p>
    </main>
    @if($showBell)
        @include('partials.realtime-inbox', ['countUrl' => route('portal.notifications.count'), 'inboxUrl' => route('portal.notifications')])
    @endif
</body>
</html>

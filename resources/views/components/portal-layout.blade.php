@props(['company' => null, 'title' => 'Portal de clientes', 'bell' => true])
@php($showBell = $bell && auth()->user()?->isClientUser())
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} · {{ $company?->name ?? 'Ascento' }}</title>
    <style>
        :root { --accent: {{ $company?->primary_color ?: '#1d4ed8' }}; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; background: #f5f6f8; color: #1f2937; line-height: 1.45; }
        a { color: inherit; }
        .p-top { background: #fff; border-bottom: 1px solid #e5e7eb; }
        .p-top-in { max-width: 960px; margin: 0 auto; padding: 14px 16px; display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .p-brand { display: flex; align-items: center; gap: 10px; font-weight: 700; }
        .p-brand img { max-height: 36px; max-width: 120px; }
        .p-brand small { display: block; font-weight: 400; color: #6b7280; font-size: 12px; }
        .p-out { background: none; border: 1px solid #d1d5db; border-radius: 8px; padding: 6px 10px; font-size: 13px; cursor: pointer; }
        .p-main { max-width: 960px; margin: 0 auto; padding: 20px 16px 48px; }
        .p-h1 { font-size: 22px; margin: 0 0 4px; }
        .p-sub { color: #6b7280; margin: 0 0 18px; font-size: 14px; }
        .p-back { display: inline-block; margin-bottom: 12px; font-size: 14px; color: #4b5563; text-decoration: none; }
        .p-grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); }
        .p-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px 16px; text-decoration: none; display: block; }
        a.p-card:hover { border-color: var(--accent); }
        .p-card h3 { margin: 0 0 4px; font-size: 16px; }
        .p-muted { color: #6b7280; font-size: 13px; }
        .p-sec { margin-top: 24px; }
        .p-sec h2 { font-size: 16px; margin: 0 0 8px; }
        .p-list { list-style: none; margin: 0; padding: 0; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; }
        .p-list li { padding: 11px 14px; border-top: 1px solid #f3f4f6; display: flex; justify-content: space-between; gap: 10px; font-size: 14px; }
        .p-list li:first-child { border-top: 0; }
        .p-list a { text-decoration: none; font-weight: 600; }
        .p-tag { display: inline-block; font-size: 12px; padding: 1px 8px; border-radius: 999px; background: #eef2ff; color: #3730a3; white-space: nowrap; }
        .p-tag.warn { background: #fef3c7; color: #92400e; }
        .p-empty { background: #fff; border: 1px dashed #d1d5db; border-radius: 12px; padding: 16px; color: #6b7280; font-size: 14px; }
        .p-photos { display: grid; gap: 10px; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); }
        .p-photos img { width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 10px; border: 1px solid #e5e7eb; }
        .p-text { white-space: pre-line; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px 16px; }
        .p-actions { display: flex; align-items: center; gap: 8px; }
        .p-bell { position: relative; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d1d5db; border-radius: 8px; padding: 6px 10px; font-size: 13px; text-decoration: none; }
        .p-count { background: #dc2626; color: #fff; border-radius: 999px; font-size: 11px; font-weight: 700; padding: 0 6px; min-width: 18px; text-align: center; }
        .p-foot { text-align: center; font-size: 12px; color: #9ca3af; margin-top: 32px; }
    </style>
</head>
<body>
    <header class="p-top">
        <div class="p-top-in">
            <div class="p-brand">
                @if($company?->logo)<img src="{{ asset('storage/'.$company->logo) }}" alt="">@endif
                <span>{{ $company?->name }}<small>Portal de clientes</small></span>
            </div>
            <div class="p-actions">
                @if($showBell)
                    @php($unreadCount = auth()->user()->unreadNotifications()->count())
                    <a class="p-bell" href="{{ route('portal.notifications') }}" aria-label="Avisos">
                        <span aria-hidden="true">🔔</span> Avisos
                        <span class="p-count" data-unread-count @if($unreadCount === 0) hidden @endif>{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
                    </a>
                @endif
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="p-out" type="submit">Salir</button>
                </form>
            </div>
        </div>
    </header>
    <main class="p-main">
        {{ $slot }}
        <p class="p-foot">Información compartida por {{ $company?->name }} mediante Ascento.</p>
    </main>
    @if($showBell)
        @include('partials.notification-poll', ['url' => route('portal.notifications.count')])
    @endif
</body>
</html>

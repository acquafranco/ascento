{{--
    Marco de las páginas públicas del catálogo de repuestos (/marketplace/repuestos, fichas y
    perfiles de proveedores). Mismo estilo que la página comercial /marketplace. Cada página define:
    title, description, canonical, robots (opcional), og_image (opcional) y schema (JSON-LD, opcional).
--}}
@php
    $mkUser = auth()->user();
    $mkBuyer = \App\Support\Marketplace\BuyerAccess::canBrowse($mkUser);
    $mkHome = $mkUser?->homeUrl();
    $arrow = '<svg width="15" height="15" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M3 8H13M13 8L9 4M13 8L9 12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') | Ascento</title>
    <meta name="description" content="@yield('description')">
    <link rel="canonical" href="@yield('canonical')">
    <meta name="robots" content="@yield('robots', 'index, follow')">

    @include('partials.brand-head')

    <meta property="og:site_name" content="Ascento">
    <meta property="og:title" content="@yield('title') | Ascento">
    <meta property="og:description" content="@yield('description')">
    <meta property="og:image" content="@yield('og_image', 'https://ascento.online/images/brand/og-1200x630.jpg')">
    <meta property="og:url" content="@yield('canonical')">
    <meta property="og:type" content="@yield('og_type', 'website')">
    <meta property="og:locale" content="es_AR">
    <meta name="twitter:card" content="summary_large_image">
    @hasSection('schema')
        <script type="application/ld+json">@yield('schema')</script>
    @endif

    <style>
        html { scrollbar-gutter: stable; }
        .font-tabular { font-variant-numeric: tabular-nums; }
        ::selection { background: #FFE8D6; color: #11161D; }
        :focus-visible { outline: 2px solid #2E4FBE; outline-offset: 2px; border-radius: 4px; }
        [x-cloak] { display: none !important; }
        .no-scrollbar { scrollbar-width: none; }
        .no-scrollbar::-webkit-scrollbar { display: none; }

        /* Carga: una cabina recorre la barra superior mientras llega la página siguiente. */
        .mk-loading { position: fixed; inset: 0 0 auto 0; height: 3px; z-index: 60; pointer-events: none; opacity: 0; transition: opacity .2s ease; }
        .mk-loading::after { content: ""; position: absolute; top: 0; left: 0; height: 3px; width: 22%; border-radius: 0 3px 3px 0; background: #FF6600; animation: mk-cabin 1.1s cubic-bezier(.45, 0, .2, 1) infinite; }
        .is-loading .mk-loading { opacity: 1; }
        .is-loading [data-results] { opacity: .45; transition: opacity .2s ease; }
        @keyframes mk-cabin { from { transform: translateX(-100%); } to { transform: translateX(460%); } }

        /* Foto: fondo neutro mientras carga; aparece con un fundido. */
        .mk-photo { background: linear-gradient(180deg, #F1F1EC, #E9E9E3); }
        .mk-photo img { transition: opacity .35s ease, transform .5s ease; }
        .mk-card:hover .mk-photo img { transform: scale(1.03); }

        @media (prefers-reduced-motion: reduce) {
            .mk-loading::after { animation: none; width: 100%; }
            .mk-photo img, .is-loading [data-results] { transition: none; }
            .mk-card:hover .mk-photo img { transform: none; }
        }
    </style>
    @vite(['resources/css/public.css', 'resources/js/public.js'])
</head>
<body class="bg-paper text-ink font-body antialiased">
    <div class="mk-loading" aria-hidden="true"></div>

    <header x-data="{ open: false }" class="sticky top-0 z-50 border-b border-ink/10 bg-paper/90 backdrop-blur">
        <nav class="mx-auto flex h-16 max-w-7xl items-center justify-between gap-4 px-4 sm:px-8" aria-label="Principal">
            <a href="{{ route('marketplace') }}" class="flex shrink-0 items-center gap-2.5">
                <img src="{{ asset('images/brand/logo-128.png') }}" alt="Ascento" width="32" height="32" class="h-8 w-8 rounded-md object-contain">
                <span class="font-display text-[17px] font-semibold tracking-tight">Ascento</span>
                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-700">Repuestos</span>
            </a>

            <div class="hidden items-center gap-7 text-sm font-medium text-ink/70 lg:flex">
                <a href="{{ route('marketplace.catalog') }}" class="transition-colors hover:text-ink @if(request()->routeIs('marketplace.catalog', 'marketplace.product')) text-ink @endif">Catálogo</a>
                <a href="{{ route('marketplace') }}#como-funciona" class="transition-colors hover:text-ink">Cómo funciona</a>
                <a href="{{ route('marketplace') }}#proveedores" class="transition-colors hover:text-ink">Para proveedores</a>
                <a href="{{ url('/') }}" class="transition-colors hover:text-ink">Gestión con Ascento</a>
            </div>

            <div class="hidden items-center gap-3 lg:flex">
                @guest
                    <a href="{{ route('suppliers.register') }}" class="text-sm font-semibold text-ink/70 transition-colors hover:text-ink">Publicar mi catálogo</a>
                    <a href="{{ route('login') }}" class="inline-flex items-center gap-1.5 rounded-full bg-graphite py-2.5 pl-4 pr-3.5 text-sm font-semibold text-white shadow-card transition-all duration-200 hover:-translate-y-0.5 hover:shadow-cardHover">Entrar {!! $arrow !!}</a>
                @else
                    @if ($mkHome)
                        <a href="{{ $mkHome }}" class="inline-flex items-center gap-1.5 rounded-full bg-graphite py-2.5 pl-4 pr-3.5 text-sm font-semibold text-white shadow-card transition-all duration-200 hover:-translate-y-0.5 hover:shadow-cardHover">Ir a mi cuenta {!! $arrow !!}</a>
                    @endif
                @endguest
            </div>

            <button type="button" @click="open = !open" class="-mr-2 p-2 text-ink lg:hidden" aria-label="Abrir menú" :aria-expanded="open.toString()" aria-expanded="false">
                <svg x-show="!open" width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 7H20M4 12H20M4 17H20" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                <svg x-show="open" x-cloak width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 6L18 18M6 18L18 6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
            </button>
        </nav>
        <div x-show="open" x-cloak x-transition class="border-t border-ink/10 bg-paper px-4 pb-5 pt-1 lg:hidden">
            <div class="flex flex-col gap-1 text-[15px] font-medium">
                <a href="{{ route('marketplace.catalog') }}" class="py-2.5 text-ink/75">Catálogo</a>
                <a href="{{ route('marketplace') }}#como-funciona" class="py-2.5 text-ink/75">Cómo funciona</a>
                <a href="{{ route('marketplace') }}#proveedores" class="py-2.5 text-ink/75">Para proveedores</a>
                <a href="{{ url('/') }}" class="py-2.5 text-ink/75">Gestión con Ascento</a>
                <div class="flex flex-col gap-2 pt-3 min-[380px]:flex-row">
                    @guest
                        <a href="{{ route('login') }}" class="flex-1 rounded-full bg-graphite px-4 py-2.5 text-center text-sm font-semibold text-white">Entrar</a>
                        <a href="{{ route('suppliers.register') }}" class="flex-1 rounded-full border border-ink/15 px-4 py-2.5 text-center text-sm font-semibold">Publicar mi catálogo</a>
                    @else
                        @if ($mkHome)
                            <a href="{{ $mkHome }}" class="flex-1 rounded-full bg-graphite px-4 py-2.5 text-center text-sm font-semibold text-white">Ir a mi cuenta</a>
                        @endif
                    @endguest
                </div>
            </div>
        </div>
    </header>

    <main>
        @yield('content')
    </main>

    <footer class="border-t border-white/10 bg-graphite text-white/60">
        <div class="mx-auto max-w-7xl px-4 py-12 sm:px-8">
            <div class="grid gap-10 sm:grid-cols-2 lg:grid-cols-4">
                <div class="lg:col-span-2">
                    <img src="{{ asset('images/brand/logo-128.png') }}" alt="Ascento" width="28" height="28" class="h-7 w-7 rounded-md object-contain">
                    <p class="mt-4 max-w-sm text-sm leading-relaxed">Marketplace de repuestos de Ascento. Ascento no vende los productos ni cobra comisiones: el precio, el pago, la factura y la entrega se acuerdan con cada proveedor.</p>
                </div>
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-white/40">Marketplace</div>
                    <ul class="mt-4 space-y-2.5 text-sm">
                        <li><a href="{{ route('marketplace.catalog') }}" class="transition-colors hover:text-white">Catálogo de repuestos</a></li>
                        <li><a href="{{ route('marketplace') }}" class="transition-colors hover:text-white">Cómo funciona</a></li>
                        <li><a href="{{ route('suppliers.register') }}" class="transition-colors hover:text-white">Publicar mi catálogo</a></li>
                        <li><a href="{{ url('/') }}" class="transition-colors hover:text-white">Gestión para tu empresa</a></li>
                    </ul>
                </div>
                <div>
                    <div class="text-xs font-semibold uppercase tracking-wide text-white/40">Legal</div>
                    <ul class="mt-4 space-y-2.5 text-sm">
                        <li><a href="{{ route('legal.terms') }}" class="transition-colors hover:text-white">Términos y Condiciones</a></li>
                        <li><a href="{{ route('legal.privacy') }}" class="transition-colors hover:text-white">Política de Privacidad</a></li>
                        <li><a href="{{ route('legal.cookies') }}" class="transition-colors hover:text-white">Política de cookies</a></li>
                        <li><button type="button" data-cookie-preferences class="transition-colors hover:text-white">Preferencias de cookies</button></li>
                    </ul>
                </div>
            </div>
            <div class="mt-10 border-t border-white/10 pt-6 text-xs text-white/35">© {{ date('Y') }} Ascento. Los productos, precios y compatibilidades los publica cada proveedor; Ascento no los verifica.</div>
        </div>
    </footer>

    <script>
        // Carga: al enviar un filtro o abrir otra página del catálogo, la barra muestra que está llegando.
        (function () {
            const start = () => document.documentElement.classList.add('is-loading');
            document.querySelectorAll('form[data-loading]').forEach((f) => f.addEventListener('submit', start));
            document.querySelectorAll('a[data-loading]').forEach((a) => a.addEventListener('click', (e) => { if (!e.metaKey && !e.ctrlKey && !e.shiftKey) start(); }));
            window.addEventListener('pageshow', () => document.documentElement.classList.remove('is-loading'));
            // Filtros: se aplican solos al cambiar (el botón «Aplicar» queda para quien no usa JavaScript).
            document.querySelectorAll('[data-autosubmit]').forEach((el) => el.addEventListener('change', () => { start(); el.form.requestSubmit ? el.form.requestSubmit() : el.form.submit(); }));
        })();
    </script>
    @stack('scripts')
    @include('partials.cookie-notice')
</body>
</html>

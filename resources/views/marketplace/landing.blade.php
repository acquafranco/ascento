{{--
    Marketplace de repuestos: página comercial pública (/marketplace). La Welcome sigue
    presentando la gestión y enlaza acá desde su sección «Marketplace de repuestos».

    Sin lógica propia: los botones van a las rutas reales (catálogo del panel, registro de
    proveedores, ingreso) y los números salen de la configuración comercial (límite gratuito;
    Premium solo si se puede contratar de verdad). Las capturas son del marketplace real con
    datos ficticios (docs/marketplace-comercial.md, «Capturas»). Nada de promesas de ventas.
--}}
@php
    $mkSettings = \App\Models\Marketplace\MarketplaceSettings::current();
    $mkFree = $mkSettings->freeLimit();
    $mkPremiumOnSale = $mkSettings->sellsPremium() && $mkSettings->chargesIn('AR') && \App\Support\Marketplace\SalesReadiness::gatewayReady();
    $mkPremium = $mkPremiumOnSale
        ? 'Si necesitás más, Premium amplía el límite hasta '.number_format($mkSettings->premiumLimit(), 0, ',', '.').' productos por '.\App\Support\Marketplace\Money::format((float) $mkSettings->premium_price, $mkSettings->pricing_currency ?: 'ARS').' por mes.'
        : null;
    $mkPhotos = \App\Support\Marketplace\Catalog::MAX_PHOTOS;
    $mkCategories = count(\App\Support\Marketplace\Catalog::CATEGORIES);
    // Catálogo sin cuenta (si el SuperAdmin no lo apagó); si no, el del panel, que pide ingresar.
    $mkPublic = $mkSettings->publicCatalog();
    $catalogUrl = $mkPublic ? route('marketplace.catalog') : \App\Filament\Pages\Marketplace\PartsCatalog::getUrl(panel: 'ascensores_app');
    $mkCounts = $mkPublic ? \App\Http\Controllers\Marketplace\PublicCatalogController::categoryCounts() : [];
    $mkLatest = $mkPublic ? \App\Models\Marketplace\SupplierProduct::visible()->with(['supplier:id,name,slug', 'photos'])->reorder()->latest('supplier_products.published_at')->limit(8)->get() : collect();
    $loginUrl = route('login');
    $supplierUrl = route('suppliers.register');
    $arrow = '<svg width="15" height="15" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M3 8H13M13 8L9 4M13 8L9 12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
@endphp
<!DOCTYPE html>
<html lang="es" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>Marketplace de repuestos para ascensores | Ascento</title>
    <meta name="description" content="Las empresas de ascensores encuentran repuestos, comparan opciones y piden cotizaciones a proveedores del sector. Los proveedores publican su catálogo gratis en Ascento.">
    <link rel="canonical" href="{{ route('marketplace') }}">

    @include('partials.brand-head')

    <meta property="og:title" content="Marketplace de repuestos | Ascento">
    <meta property="og:description" content="El punto de encuentro entre las empresas de ascensores y los proveedores de repuestos.">
    <meta property="og:image" content="https://ascento.online/images/brand/og-1200x630.jpg">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:url" content="{{ route('marketplace') }}">
    <meta property="og:type" content="website">
    <meta name="twitter:card" content="summary_large_image">

    <style>
        html { scrollbar-gutter: stable; }
        body { -webkit-font-smoothing: antialiased; }
        .font-tabular { font-variant-numeric: tabular-nums; }

        [data-reveal] { opacity: 0; transform: translateY(14px); transition: opacity .6s ease, transform .6s ease; }
        [data-reveal].is-visible { opacity: 1; transform: none; }

        /* Hueco del ascensor en la portada: la cabina recorre los pisos y el indicador muestra dónde está. */
        .mk-shaft { --step: 3.5rem; position: relative; width: 2.75rem; height: calc(var(--step) * 4 + 2.75rem); }
        .mk-shaft::before { content: ""; position: absolute; left: 50%; top: 0; bottom: 0; width: 1px; background: rgba(17, 22, 29, .14); }
        .mk-shaft-floor { position: absolute; left: 0; right: 0; height: 1px; background: rgba(17, 22, 29, .12); }
        .mk-shaft-floor span { position: absolute; right: calc(100% + .5rem); top: -.45rem; font: 600 10px/1 ui-monospace, SFMono-Regular, Menlo, monospace; color: rgba(17, 22, 29, .38); letter-spacing: .06em; }
        .mk-car { position: absolute; left: 50%; bottom: 0; width: 1.75rem; height: 2.25rem; margin-left: -.875rem; border-radius: .45rem; background: #11161D; box-shadow: 0 6px 16px -6px rgba(17, 22, 29, .45);
                  transform: translateY(calc(var(--at, 0) * var(--step) * -1)); transition: transform 1.6s cubic-bezier(.45, 0, .2, 1); }
        .mk-car::after { content: ""; position: absolute; inset: .45rem .55rem; border-radius: .2rem; border-left: 2px solid #FF6600; border-right: 2px solid #FF6600; opacity: .9; }

        /* Recorrido de «Cómo funciona»: la línea se llena al aparecer y cada parada se enciende en orden. */
        .mk-route-line { transform: scaleY(0); transform-origin: top; transition: transform 1.4s cubic-bezier(.45, 0, .2, 1) .15s; }
        .is-visible .mk-route-line { transform: scaleY(1); }
        .mk-stop { transition: background-color .4s ease, color .4s ease, border-color .4s ease; }
        .is-visible .mk-stop { background: #FF6600; border-color: #FF6600; color: #11161D; }

        /* Vistas del catálogo: fundido suave entre capturas. */
        .mk-view { transition: opacity .45s ease, transform .45s ease; }

        @media (prefers-reduced-motion: reduce) {
            [data-reveal] { opacity: 1; transform: none; transition: none; }
            .mk-car, .mk-route-line, .mk-stop, .mk-view { transition: none; }
            .mk-route-line { transform: none; }
        }

        ::selection { background: #FFE8D6; color: #11161D; }
        :focus-visible { outline: 2px solid #2E4FBE; outline-offset: 2px; border-radius: 4px; }
        [x-cloak] { display: none !important; }
    </style>
    @vite(['resources/css/public.css', 'resources/js/public.js'])
</head>
<body class="bg-paper text-ink font-body antialiased">

    {{-- ============ NAVBAR ============ --}}
    <header x-data="{ open: false, scrolled: false }" @scroll.window="scrolled = window.scrollY > 12"
            class="fixed inset-x-0 top-0 z-50 transition-colors duration-300"
            :class="scrolled ? 'bg-paper/90 backdrop-blur border-b border-ink/10' : 'bg-transparent border-b border-transparent'">
        <nav class="mx-auto max-w-7xl px-5 sm:px-8 h-16 flex items-center justify-between">
            <a href="{{ url('/') }}" class="flex items-center gap-2.5 shrink-0">
                <img src="{{ asset('images/brand/logo-128.png') }}" alt="Ascento" class="rounded-md h-8 w-8 object-contain">
                <span class="font-display font-semibold text-[17px] tracking-tight">Ascento</span>
                <span class="hidden sm:inline rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-700">Repuestos</span>
            </a>

            <div class="hidden lg:flex items-center gap-8 text-sm font-medium text-ink/70">
                <a href="{{ $catalogUrl }}" class="hover:text-ink transition-colors" data-nav-catalog>Catálogo</a>
                <a href="#como-funciona" class="hover:text-ink transition-colors">Cómo funciona</a>
                <a href="#proveedores" class="hover:text-ink transition-colors">Proveedores</a>
                <a href="#faq" class="hover:text-ink transition-colors">Preguntas</a>
                <a href="{{ url('/') }}" class="hover:text-ink transition-colors">Gestión con Ascento</a>
            </div>

            <div class="hidden lg:flex items-center gap-3">
                <a href="{{ $loginUrl }}"
                   class="inline-flex items-center gap-1.5 rounded-full bg-graphite text-white text-sm font-semibold pl-4 pr-3.5 py-2.5 shadow-card hover:shadow-cardHover hover:-translate-y-0.5 transition-all duration-200">
                    Entrar {!! $arrow !!}
                </a>
            </div>

            <button @click="open = !open" class="lg:hidden p-2 -mr-2 text-ink" aria-label="Abrir menú" :aria-expanded="open">
                <svg x-show="!open" width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M4 7H20M4 12H20M4 17H20" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                <svg x-show="open" x-cloak width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M6 6L18 18M6 18L18 6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
            </button>
        </nav>

        <div x-show="open" x-cloak x-transition class="lg:hidden bg-paper border-b border-ink/10 px-5 pb-5 pt-1">
            <div class="flex flex-col gap-1 text-[15px] font-medium">
                <a href="{{ $catalogUrl }}" class="py-2.5 text-ink/75">Catálogo</a>
                <a @click="open=false" href="#como-funciona" class="py-2.5 text-ink/75">Cómo funciona</a>
                <a @click="open=false" href="#proveedores" class="py-2.5 text-ink/75">Proveedores</a>
                <a @click="open=false" href="#faq" class="py-2.5 text-ink/75">Preguntas</a>
                <a href="{{ url('/') }}" class="py-2.5 text-ink/75">Gestión con Ascento</a>
                <div class="flex gap-2 pt-3">
                    <a href="{{ $loginUrl }}" class="flex-1 text-center rounded-full bg-graphite text-white px-4 py-2.5 text-sm font-semibold">Entrar</a>
                </div>
            </div>
        </div>
    </header>

    {{-- ============ RIEL DEL ASCENSOR (solo pantallas grandes): acompaña el recorrido por la página ============ --}}
    <div class="hidden xl:flex flex-col items-center fixed left-8 top-1/2 -translate-y-1/2 z-40" aria-hidden="true">
        <span id="railLabel" class="mb-3 font-mono text-[11px] tracking-wider text-ink/50 font-tabular">PB</span>
        <div class="relative h-[360px] w-px bg-ink/10">
            <div id="railFill" class="absolute top-0 left-0 w-px bg-amber-500 transition-all duration-300 ease-out" style="height:0%"></div>
        </div>
    </div>

    <main>
        {{-- ============ PORTADA ============ --}}
        <section id="inicio" class="relative overflow-hidden pt-24 pb-16 sm:pt-32 sm:pb-24 xl:pt-36" data-floor="PB">
            <div class="pointer-events-none absolute inset-0 -z-10 [background:radial-gradient(60%_60%_at_85%_0%,rgba(255,102,0,0.08),transparent_60%),radial-gradient(50%_50%_at_0%_25%,rgba(46,79,190,0.05),transparent_60%)]"></div>

            <div class="mx-auto max-w-7xl px-5 sm:px-8">
                <div class="grid items-center gap-12 lg:grid-cols-[0.95fr_1.1fr] lg:gap-12">
                    <div data-reveal>
                        <span class="inline-flex items-center gap-2 rounded-full bg-white border border-ink/10 shadow-sm px-3.5 py-1.5 text-xs font-semibold tracking-wide text-ink/70">
                            <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500"></span>
                            Marketplace de repuestos de Ascento
                        </span>

                        <h1 class="mt-5 sm:mt-6 font-display font-semibold text-[2rem] leading-[1.1] sm:text-[2.6rem] sm:leading-[1.08] xl:text-[2.85rem] tracking-tight text-ink">
                            El punto de encuentro entre las empresas de ascensores
                            <span class="text-ink/40">y los proveedores de repuestos<span class="text-amber-500">.</span></span>
                        </h1>

                        <p class="mt-5 sm:mt-6 text-base sm:text-lg text-ink/60 max-w-xl leading-relaxed">
                            Descubrí productos, explorá catálogos y conectá con proveedores del sector. Si comercializás repuestos, publicá tu catálogo y hacé que tus productos sean más fáciles de encontrar.
                        </p>

                        <div class="mt-7 sm:mt-9 flex flex-col min-[420px]:flex-row min-[420px]:flex-wrap items-stretch min-[420px]:items-center gap-3">
                            <a href="{{ $catalogUrl }}" data-cta-explore
                               class="inline-flex items-center justify-center gap-2 rounded-full bg-graphite text-white font-semibold px-6 py-3.5 shadow-card hover:shadow-cardHover hover:-translate-y-0.5 transition-all duration-200">
                                Explorar repuestos {!! $arrow !!}
                            </a>
                            <a href="{{ $supplierUrl }}" data-cta-publish
                               class="inline-flex items-center justify-center gap-2 rounded-full bg-amber-500 text-graphite font-semibold px-6 py-3.5 hover:bg-amber-400 hover:-translate-y-0.5 transition-all duration-200">
                                Publicar mi catálogo
                            </a>
                            <a href="{{ $loginUrl }}" data-cta-login
                               class="inline-flex items-center justify-center whitespace-nowrap px-2 py-3.5 text-sm font-semibold text-ink/65 hover:text-ink transition-colors">
                                Ya tengo cuenta
                            </a>
                        </div>
                        @if ($mkPublic)
                            <form method="GET" action="{{ route('marketplace.catalog') }}" role="search" class="mt-6 flex max-w-lg gap-2" data-hero-search>
                                <label for="mk-hero-q" class="sr-only">Buscar repuestos</label>
                                <input id="mk-hero-q" type="search" name="q" maxlength="120" placeholder="Buscá por nombre, marca, modelo o código"
                                       class="min-w-0 flex-1 rounded-full border border-ink/15 bg-white px-5 py-3 text-sm shadow-sm outline-none transition focus:border-rail-500 focus:ring-4 focus:ring-rail-100">
                                <button type="submit" class="shrink-0 rounded-full border border-ink/15 bg-white px-5 py-3 text-sm font-semibold transition hover:border-ink/35">Buscar</button>
                            </form>
                        @endif
                        <p class="mt-4 text-xs leading-relaxed text-ink/45 max-w-md">
                            @if ($mkPublic)
                                Explorá el catálogo sin cuenta. Para pedir cotizaciones, entrá con la cuenta de tu empresa. Publicar es gratis, hasta {{ $mkFree }} productos.
                            @else
                                Las empresas exploran el catálogo con su cuenta de Ascento. Publicar es gratis, hasta {{ $mkFree }} productos.
                            @endif
                        </p>
                    </div>

                    {{-- Ficha real de un repuesto (datos de ejemplo) y el ascensor que recorre los pisos. --}}
                    <div data-reveal style="transition-delay:.1s" class="relative flex items-end gap-5 sm:gap-7">
                        <div class="hidden sm:flex flex-col items-center gap-3 pb-6" aria-hidden="true">
                            <div class="rounded-xl bg-graphite px-3 py-2 text-center shadow-card">
                                <div class="font-mono text-[9px] uppercase tracking-wider text-white/40">Piso</div>
                                <div class="mt-0.5 flex items-center justify-center gap-1 font-mono text-xl font-semibold leading-none text-amber-500 font-tabular">
                                    <span data-shaft-arrow class="text-[11px] text-amber-500/70">▲</span><span data-shaft-digit>PB</span>
                                </div>
                            </div>
                            <div class="mk-shaft ml-6" data-shaft>
                                @foreach (['4', '3', '2', '1', 'PB'] as $k => $f)
                                    <div class="mk-shaft-floor" style="bottom: calc(var(--step) * {{ 4 - $k }})"><span>{{ $f }}</span></div>
                                @endforeach
                                <div class="mk-car" data-shaft-car></div>
                            </div>
                        </div>

                        <figure class="relative min-w-0 flex-1">
                            <div class="overflow-hidden rounded-2xl border border-ink/10 bg-white shadow-cardHover">
                                <div class="flex items-center gap-1.5 border-b border-ink/10 bg-paper px-4 py-2.5" aria-hidden="true">
                                    <span class="h-2 w-2 rounded-full bg-ink/15"></span>
                                    <span class="h-2 w-2 rounded-full bg-ink/15"></span>
                                    <span class="h-2 w-2 rounded-full bg-ink/15"></span>
                                    <span class="ml-2 truncate font-mono text-[10px] text-ink/40">ascento.online/admin/repuestos</span>
                                </div>
                                <img src="{{ asset('images/marketplace/ficha.jpg') }}" alt="Ficha de un repuesto en Ascento: galería de fotos, precio, marca, modelo, código, proveedor y compatibilidad declarada"
                                     width="1080" height="640" fetchpriority="high" decoding="async" class="block h-auto w-full">
                            </div>
                            <figcaption class="mt-3 text-xs text-ink/40">Captura del marketplace real con datos de ejemplo.</figcaption>
                        </figure>
                    </div>
                </div>
            </div>
        </section>

        {{-- ============ FRANJA ============ --}}
        <section class="border-y border-ink/10 bg-white/60">
            <div class="mx-auto max-w-7xl px-5 sm:px-8 py-6 flex flex-wrap items-center justify-center lg:justify-between gap-x-10 gap-y-3 text-center text-ink/40 text-sm font-medium tracking-wide">
                <span>{{ $mkCategories }} rubros, de máquinas y puertas a botoneras y seguridad</span>
                <span>Proveedores revisados por Ascento antes de publicar</span>
                <span>Sin comisiones sobre las ventas</span>
            </div>
        </section>

        {{-- ============ EXPLORAR: rubros con productos y lo último publicado (datos reales, sin cuenta) ============ --}}
        @if ($mkPublic && ($mkCounts || $mkLatest->isNotEmpty()))
            <section id="explorar" class="py-20 sm:py-24" data-floor="PB" data-explore>
                <div class="mx-auto max-w-7xl px-5 sm:px-8">
                    <div data-reveal class="flex flex-wrap items-end justify-between gap-4">
                        <div class="max-w-2xl">
                            <span class="font-mono text-xs tracking-widest text-amber-600 uppercase">Planta baja · Explorá el catálogo</span>
                            <h2 class="mt-3 font-display font-semibold text-3xl sm:text-4xl tracking-tight">Recién publicados por los proveedores.</h2>
                        </div>
                        <a href="{{ route('marketplace.catalog') }}" class="inline-flex items-center gap-2 text-sm font-semibold text-ink/70 hover:text-ink">Ver todo el catálogo {!! $arrow !!}</a>
                    </div>
                    @if ($mkCounts)
                        <div data-reveal class="mt-7 flex flex-wrap gap-2" data-explore-categories>
                            @foreach (\App\Support\Marketplace\Catalog::CATEGORIES as $key => $label)
                                @if (($mkCounts[$key] ?? 0) > 0)
                                    <a href="{{ route('marketplace.catalog', ['rubro' => $key]) }}" class="rounded-full border border-ink/15 bg-white px-3.5 py-1.5 text-xs font-semibold text-ink/70 transition hover:border-amber-500 hover:text-ink">{{ $label }} <span class="font-normal opacity-60">{{ $mkCounts[$key] }}</span></a>
                                @endif
                            @endforeach
                        </div>
                    @endif
                    @if ($mkLatest->isNotEmpty())
                        <div data-reveal class="mt-8 grid gap-4 min-[480px]:grid-cols-2 lg:grid-cols-4">
                            @foreach ($mkLatest as $product)
                                @include('marketplace.public.partials.card', ['product' => $product])
                            @endforeach
                        </div>
                    @endif
                </div>
            </section>
        @endif

        {{-- ============ EL CATÁLOGO POR DENTRO (capturas reales) ============ --}}
        @php
            $views = [
                ['catalogo', 'Catálogo', 'images/marketplace/catalogo.jpg', 860, 'Catálogo de repuestos: buscador, filtros y tarjetas de productos de varios proveedores', 'Los productos de todos los proveedores, en un mismo lugar', 'Cada tarjeta muestra la foto principal, el rubro, la marca, el modelo y el código, el proveedor, el precio o «A cotizar» y la disponibilidad declarada.'],
                ['filtros', 'Búsqueda y filtros', 'images/marketplace/catalogo-filtros.jpg', 690, 'Catálogo filtrado por rubro, con el contador de filtros activos y el botón para limpiarlos', 'Encontrá lo que buscás en segundos', 'Buscá por nombre, marca, modelo, código o equipo compatible, y filtrá por rubro, marca, disponibilidad y precio. Guardá favoritos y compará productos lado a lado.'],
                ['ficha', 'Ficha y galería', 'images/marketplace/ficha.jpg', 640, 'Ficha de un repuesto con galería de fotos, datos del producto, proveedor y compatibilidad declarada', 'Toda la información antes de pedir', 'Galería de hasta '.$mkPhotos.' fotos, precio de referencia, datos técnicos, el proveedor que lo publica y la compatibilidad que declara. Desde ahí lo sumás a tu pedido de cotización.'],
            ];
        @endphp
        <section id="capturas" class="py-24 sm:py-28" data-floor="01">
            <div class="mx-auto max-w-7xl px-5 sm:px-8">
                <div data-reveal class="max-w-2xl">
                    <span class="font-mono text-xs tracking-widest text-amber-600 uppercase">Piso 01 · El catálogo por dentro</span>
                    <h2 class="mt-3 font-display font-semibold text-3xl sm:text-4xl tracking-tight">Así se ve el marketplace en Ascento.</h2>
                    <p class="mt-4 text-ink/60 leading-relaxed">Pantallas reales del catálogo que usan las empresas, dentro de su panel de Ascento.</p>
                </div>

                <div data-reveal class="mt-10 grid gap-8 lg:grid-cols-[0.8fr_1.6fr] lg:items-start" x-data="{ view: 'catalogo' }">
                    <div role="tablist" aria-label="Vistas del marketplace" class="flex flex-wrap gap-2 lg:flex-col lg:flex-nowrap lg:gap-3">
                        @foreach ($views as $i => [$key, $tab, , , , $title, $text])
                            <button type="button" role="tab" id="tab-{{ $key }}" aria-controls="view-{{ $key }}" data-view-tab="{{ $key }}"
                                    @click="view = '{{ $key }}'" :aria-selected="view === '{{ $key }}'" aria-selected="{{ $i === 0 ? 'true' : 'false' }}"
                                    class="rounded-2xl border px-4 py-3 text-left transition-all duration-300 lg:p-5"
                                    :class="view === '{{ $key }}' ? 'border-amber-500 bg-white shadow-card' : 'border-ink/10 bg-transparent hover:bg-white/70'">
                                <span class="flex items-center gap-2 text-sm font-semibold">
                                    <span class="font-mono text-[11px] text-amber-600">0{{ $i + 1 }}</span>{{ $tab }}
                                </span>
                                <span class="mt-2 hidden text-sm leading-relaxed text-ink/60 lg:block"><strong class="block font-display text-[15px] font-semibold text-ink">{{ $title }}</strong>{{ $text }}</span>
                            </button>
                        @endforeach
                    </div>

                    <div>
                        <div class="overflow-hidden rounded-2xl border border-ink/10 bg-white shadow-cardHover">
                            <div class="flex items-center gap-1.5 border-b border-ink/10 bg-paper px-4 py-2.5" aria-hidden="true">
                                <span class="h-2 w-2 rounded-full bg-ink/15"></span>
                                <span class="h-2 w-2 rounded-full bg-ink/15"></span>
                                <span class="h-2 w-2 rounded-full bg-ink/15"></span>
                                <span class="ml-2 truncate font-mono text-[10px] text-ink/40">ascento.online/admin/repuestos</span>
                            </div>
                            <div class="relative aspect-[1080/760] bg-[#fafafa]">
                                @foreach ($views as $i => [$key, $tab, $src, $h, $alt])
                                    <img src="{{ asset($src) }}" alt="{{ $alt }}" width="1080" height="{{ $h }}" loading="lazy" decoding="async"
                                         id="view-{{ $key }}" role="tabpanel" aria-labelledby="tab-{{ $key }}" data-view="{{ $key }}"
                                         class="mk-view absolute inset-0 h-full w-full object-contain object-top {{ $i === 0 ? '' : 'opacity-0' }}"
                                         :class="view === '{{ $key }}' ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-2 pointer-events-none'">
                                @endforeach
                            </div>
                        </div>
                        @foreach ($views as [$key, , , , , $title, $text])
                            <p class="mt-4 text-sm leading-relaxed text-ink/60 lg:hidden" x-show="view === '{{ $key }}'" @if (! $loop->first) x-cloak @endif><strong class="text-ink">{{ $title }}.</strong> {{ $text }}</p>
                        @endforeach
                        <p class="mt-4 text-xs text-ink/40">Capturas del marketplace real de Ascento con datos de ejemplo: la empresa, los proveedores, las marcas y las imágenes de los productos son ficticios.</p>
                    </div>
                </div>
            </div>
        </section>

        {{-- ============ CÓMO FUNCIONA ============ --}}
        @php
            $routes = [
                ['empresas', 'Para empresas de ascensores', [
                    ['Explorá los repuestos', 'Buscá entre los catálogos de los proveedores y filtrá por rubro, marca, disponibilidad y precio.'],
                    ['Revisá la información y las fotos', 'Compará precio publicado, disponibilidad, compatibilidad declarada y la galería de cada producto.'],
                    ['Pedí una cotización', 'Armá el pedido y envialo al proveedor desde Ascento. Seguís su estado y recibís la respuesta en tu cuenta.'],
                    ['Acordá con el proveedor', 'El pago, la factura, el envío y la entrega se acuerdan directamente entre tu empresa y el proveedor.'],
                ]],
                ['proveedores', 'Para proveedores de repuestos', [
                    ['Creá tu cuenta', 'Te registrás como proveedor y Ascento revisa la cuenta antes de que tus productos se vean en el catálogo.'],
                    ['Publicá tus productos', 'Cargá cada repuesto con hasta '.$mkPhotos.' fotos, marca, modelo, código, precio o «A cotizar» y compatibilidad.'],
                    ['Mantené tu catálogo al día', 'Actualizá precios y disponibilidad, ordená las fotos y pausá lo que no tengas.'],
                    ['Recibí pedidos de cotización', 'Te avisamos en tu área y por correo cuando una empresa te pide una cotización, y la respondés desde ahí.'],
                ]],
            ];
        @endphp
        <section id="como-funciona" class="py-24 sm:py-28 bg-graphite text-white" data-floor="02">
            <div class="mx-auto max-w-7xl px-5 sm:px-8">
                <div data-reveal class="max-w-2xl">
                    <span class="font-mono text-xs tracking-widest text-amber-500 uppercase">Piso 02 · Cómo funciona</span>
                    <h2 class="mt-3 font-display font-semibold text-3xl sm:text-4xl tracking-tight">Un recorrido simple para cada lado.</h2>
                </div>

                <div class="mt-14 grid gap-12 lg:grid-cols-2 lg:gap-16">
                    @foreach ($routes as [$key, $title, $steps])
                        <div data-reveal data-route="{{ $key }}">
                            <h3 class="font-display text-lg font-semibold text-amber-500">{{ $title }}</h3>
                            <ol class="relative mt-6 space-y-7 pl-14">
                                <span class="absolute left-[1.05rem] top-2 bottom-2 w-px bg-white/10" aria-hidden="true"></span>
                                <span class="mk-route-line absolute left-[1.05rem] top-2 bottom-2 w-px bg-amber-500" aria-hidden="true"></span>
                                @foreach ($steps as $n => [$stepTitle, $stepText])
                                    <li class="relative">
                                        <span class="mk-stop absolute -left-14 top-0 flex h-[2.15rem] w-[2.15rem] items-center justify-center rounded-lg border border-white/15 bg-graphite2 font-mono text-xs font-semibold text-white/60"
                                              style="transition-delay: {{ 0.3 + $n * 0.35 }}s" aria-hidden="true">{{ $n === 0 ? 'PB' : $n }}</span>
                                        <h4 class="font-display text-[15px] font-semibold">{{ $stepTitle }}</h4>
                                        <p class="mt-1 text-sm leading-relaxed text-white/55">{{ $stepText }}</p>
                                    </li>
                                @endforeach
                            </ol>
                        </div>
                    @endforeach
                </div>

                <p data-reveal class="mt-14 max-w-3xl text-sm leading-relaxed text-white/45">
                    Ascento conecta a empresas y proveedores: no vende los repuestos, no cobra comisiones por las ventas ni procesa sus pagos. Una cotización no es una compra. Precio, disponibilidad y compatibilidad los declara cada proveedor y Ascento no los verifica.
                </p>
            </div>
        </section>

        {{-- ============ PARA PROVEEDORES ============ --}}
        <section id="proveedores" class="py-24 sm:py-28" data-floor="03">
            <div class="mx-auto max-w-7xl px-5 sm:px-8">
                <div class="grid items-center gap-12 lg:grid-cols-[1fr_1.05fr] lg:gap-16">
                    <div data-reveal>
                        <span class="font-mono text-xs tracking-widest text-amber-600 uppercase">Piso 03 · Para proveedores</span>
                        <h2 class="mt-3 font-display font-semibold text-3xl sm:text-4xl tracking-tight">Tu catálogo, presentado como se merece.</h2>
                        <p class="mt-4 text-ink/60 leading-relaxed">Las empresas que mantienen ascensores buscan repuestos en Ascento. Mostrales tus productos con una presentación profesional y respondé sus pedidos desde tu área.</p>

                        <ul class="mt-7 grid gap-x-6 gap-y-3.5 sm:grid-cols-2">
                            @foreach ([
                                'Catálogo digital con tus productos.',
                                'Hasta '.$mkPhotos.' fotos por repuesto, con una principal y galería en la ficha.',
                                'Marca, modelo y código cuando los cargás.',
                                'Una ficha clara y profesional para cada producto.',
                                'Cuenta revisada por Ascento: las empresas saben con quién hablan.',
                                'Sin comisiones sobre tus ventas: el precio y el cobro los manejás vos.',
                            ] as $line)
                                <li class="flex gap-2.5 text-sm leading-relaxed text-ink/75">
                                    <svg class="mt-0.5 shrink-0" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M3 8.5L6.2 11.5L13 4.5" stroke="#B84700" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    <span>{{ $line }}</span>
                                </li>
                            @endforeach
                        </ul>

                        <div class="mt-8 rounded-2xl border border-amber-500/30 bg-amber-100/50 p-5" data-supplier-plan>
                            <p class="font-display font-semibold">Gratis: hasta {{ $mkFree }} productos publicados a la vez.</p>
                            <p class="mt-1 text-sm leading-relaxed text-ink/65">Sin tarjeta. Los borradores y los productos pausados no cuentan.@if ($mkPremium) {{ $mkPremium }}@endif</p>
                        </div>

                        <div class="mt-7 flex flex-col gap-3 sm:flex-row sm:items-center">
                            <a href="{{ $supplierUrl }}" class="inline-flex items-center justify-center gap-2 rounded-full bg-graphite px-6 py-3.5 font-semibold text-white shadow-card transition-all duration-200 hover:-translate-y-0.5 hover:shadow-cardHover">
                                Publicar mi catálogo {!! $arrow !!}
                            </a>
                            <a href="{{ route('legal.terms') }}#proveedores" class="px-2 py-2 text-sm font-semibold text-ink/60 hover:text-ink transition-colors">Condiciones para proveedores</a>
                        </div>
                    </div>

                    <figure data-reveal style="transition-delay:.1s" class="relative sm:pb-16">
                        <div class="overflow-hidden rounded-2xl border border-ink/10 bg-white shadow-cardHover sm:mr-[16%]">
                            <div class="flex items-center gap-1.5 border-b border-ink/10 bg-paper px-4 py-2.5" aria-hidden="true">
                                <span class="h-2 w-2 rounded-full bg-ink/15"></span>
                                <span class="h-2 w-2 rounded-full bg-ink/15"></span>
                                <span class="h-2 w-2 rounded-full bg-ink/15"></span>
                                <span class="ml-2 truncate font-mono text-[10px] text-ink/40">ascento.online/proveedores/catalogo</span>
                            </div>
                            <img src="{{ asset('images/marketplace/proveedor-fotos.jpg') }}" alt="Área del proveedor: fotos de un producto con la principal marcada, botones para ordenarlas, quitarlas y subir más"
                                 width="1080" height="468" loading="lazy" decoding="async" class="block h-auto w-full">
                        </div>
                        <div class="absolute bottom-0 right-0 hidden w-[28%] max-w-[200px] overflow-hidden rounded-[1.4rem] border-4 border-graphite bg-graphite shadow-cardHover sm:block">
                            <img src="{{ asset('images/marketplace/ficha-movil.jpg') }}" alt="Así ven las empresas la ficha de tu producto en el celular"
                                 width="780" height="1440" loading="lazy" decoding="async" class="block aspect-[9/15] w-full rounded-[1.1rem] object-cover object-top">
                        </div>
                        <figcaption class="mt-3 text-xs text-ink/40 sm:mr-[34%]">Área del proveedor: elegí la foto principal, ordená y subí varias a la vez. A la derecha, la ficha en el celular. Capturas reales con datos de ejemplo.</figcaption>
                    </figure>
                </div>
            </div>
        </section>

        {{-- ============ PREGUNTAS ============ --}}
        @php
            $faqs = [
                ['¿Cuánto cuesta publicar mi catálogo?', 'Publicar es gratis, hasta '.$mkFree.' productos publicados a la vez y sin tarjeta. Los borradores y los productos pausados no cuentan.'.($mkPremium ? ' '.$mkPremium : '')],
                ['¿Ascento cobra comisión por las ventas?', 'No. Ascento no vende los repuestos, no cobra comisiones por las ventas ni procesa sus pagos. El precio, el pago, la factura, el envío y la garantía se acuerdan entre la empresa y el proveedor.'],
                ['¿Quién verifica la información de los productos?', 'Cada proveedor declara el precio, la disponibilidad y la compatibilidad de sus productos; Ascento no los verifica. Lo que sí revisa Ascento es la cuenta de cada proveedor antes de que sus productos se vean en el catálogo.'],
                $mkPublic
                    ? ['¿Necesito una cuenta para ver los repuestos?', 'No. Cualquiera puede explorar el catálogo, buscar y ver las fichas. Para pedir cotizaciones y guardar favoritos hace falta la cuenta de una empresa de ascensores: el dueño y quienes tengan permiso sobre Stock.']
                    : ['¿Cómo entra una empresa al catálogo?', 'Desde su cuenta de Ascento: el dueño de la cuenta y quienes tengan permiso sobre Stock ven el catálogo y, según su permiso, piden cotizaciones.'],
            ];
        @endphp
        <section id="faq" class="py-24 sm:py-28 bg-white border-y border-ink/10" data-floor="04">
            <div class="mx-auto max-w-3xl px-5 sm:px-8">
                <div data-reveal class="text-center">
                    <span class="font-mono text-xs tracking-widest text-amber-600 uppercase">Piso 04 · Preguntas</span>
                    <h2 class="mt-3 font-display font-semibold text-3xl sm:text-4xl tracking-tight">Lo que conviene saber.</h2>
                </div>
                <div class="mt-12 divide-y divide-ink/10 border-y border-ink/10">
                    @foreach ($faqs as [$q, $a])
                        <details class="group" @if ($loop->first) open @endif>
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 py-5 text-left [&::-webkit-details-marker]:hidden">
                                <span class="font-medium text-[15px]">{{ $q }}</span>
                                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" class="shrink-0 transition-transform duration-200 group-open:rotate-45" aria-hidden="true"><path d="M8 2V14M2 8H14" stroke="#11161D" stroke-width="1.5" stroke-linecap="round"/></svg>
                            </summary>
                            <p class="pb-5 text-sm text-ink/60 leading-relaxed max-w-xl">{{ $a }}</p>
                        </details>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ============ CIERRE: una llamada para cada público ============ --}}
        <section id="empezar" class="py-24 sm:py-28 bg-graphite text-white" data-floor="05">
            <div class="mx-auto max-w-6xl px-5 sm:px-8">
                <div data-reveal class="text-center">
                    <span class="font-mono text-xs tracking-widest text-amber-500 uppercase">Última parada</span>
                    <h2 class="mt-3 font-display font-semibold text-3xl sm:text-5xl tracking-tight">¿De qué lado del mostrador estás?</h2>
                </div>
                <div class="mt-12 grid gap-6 md:grid-cols-2">
                    <article data-reveal class="flex flex-col rounded-3xl border border-white/10 bg-graphite2 p-8" data-close-companies>
                        <span class="font-mono text-[11px] uppercase tracking-widest text-white/40">Empresas de ascensores</span>
                        <h3 class="mt-3 font-display text-2xl font-semibold">¿Necesitás un repuesto?</h3>
                        <p class="mt-3 flex-1 text-white/55 leading-relaxed">Buscalo entre los catálogos de los proveedores y pedí cotización desde tu cuenta de Ascento.</p>
                        <div class="mt-7 flex flex-col gap-3 sm:flex-row sm:items-center">
                            <a href="{{ $catalogUrl }}" class="inline-flex items-center justify-center gap-2 rounded-full bg-white px-6 py-3.5 font-semibold text-graphite transition-transform duration-200 hover:-translate-y-0.5">Explorar repuestos {!! $arrow !!}</a>
                            <a href="{{ route('register') }}" class="px-2 py-2 text-sm font-semibold text-white/65 hover:text-white transition-colors">Crear cuenta de empresa</a>
                        </div>
                    </article>
                    <article data-reveal style="transition-delay:.08s" class="flex flex-col rounded-3xl border border-amber-500/40 bg-amber-500 p-8 text-graphite" data-close-suppliers>
                        <span class="font-mono text-[11px] uppercase tracking-widest text-graphite/60">Proveedores de repuestos</span>
                        <h3 class="mt-3 font-display text-2xl font-semibold">¿Vendés repuestos para ascensores?</h3>
                        <p class="mt-3 flex-1 text-graphite/75 leading-relaxed">Publicá tu catálogo gratis, hasta {{ $mkFree }} productos, y mostralo a las empresas del sector.</p>
                        <div class="mt-7 flex flex-col gap-3 sm:flex-row sm:items-center">
                            <a href="{{ $supplierUrl }}" class="inline-flex items-center justify-center gap-2 rounded-full bg-graphite px-6 py-3.5 font-semibold text-white transition-transform duration-200 hover:-translate-y-0.5">Publicar mi catálogo {!! $arrow !!}</a>
                            <a href="{{ $loginUrl }}" class="px-2 py-2 text-sm font-semibold text-graphite/70 hover:text-graphite transition-colors">Ya tengo cuenta</a>
                        </div>
                    </article>
                </div>
            </div>
        </section>
    </main>

    {{-- ============ FOOTER ============ --}}
    <footer class="bg-graphite text-white/60 border-t border-white/10">
        <div class="mx-auto max-w-7xl px-5 sm:px-8 py-14">
            <div class="grid sm:grid-cols-2 lg:grid-cols-5 gap-10">
                <div class="lg:col-span-2">
                    <img src="{{ asset('images/brand/logo-128.png') }}" alt="Ascento" class="rounded-md h-7 w-7 object-contain">
                    <p class="mt-4 text-sm leading-relaxed max-w-xs">El sistema de gestión para empresas de mantenimiento de ascensores, con su marketplace de repuestos.</p>
                </div>
                <div>
                    <div class="text-xs font-semibold text-white/40 uppercase tracking-wide">Ascento</div>
                    <ul class="mt-4 space-y-2.5 text-sm">
                        <li><a href="{{ url('/') }}" class="hover:text-white transition-colors">Gestión para tu empresa</a></li>
                        <li><a href="{{ url('/') }}#planes" class="hover:text-white transition-colors">Planes</a></li>
                        <li><a href="#como-funciona" class="hover:text-white transition-colors">Cómo funciona el marketplace</a></li>
                        <li><a href="#faq" class="hover:text-white transition-colors">Preguntas</a></li>
                    </ul>
                </div>
                <div>
                    <div class="text-xs font-semibold text-white/40 uppercase tracking-wide">Legal</div>
                    <ul class="mt-4 space-y-2.5 text-sm">
                        <li><a href="{{ route('legal.terms') }}" class="hover:text-white transition-colors">Términos y Condiciones</a></li>
                        <li><a href="{{ route('legal.privacy') }}" class="hover:text-white transition-colors">Política de Privacidad</a></li>
                        <li><a href="{{ route('legal.data-deletion') }}" class="hover:text-white transition-colors">Eliminación de Datos</a></li>
                        <li><a href="{{ route('legal.cookies') }}" class="hover:text-white transition-colors">Política de cookies</a></li>
                        <li><button type="button" data-cookie-preferences class="hover:text-white transition-colors">Preferencias de cookies</button></li>
                    </ul>
                </div>
                <div>
                    <div class="text-xs font-semibold text-white/40 uppercase tracking-wide">Contacto</div>
                    <ul class="mt-4 space-y-2.5 text-sm">
                        <li><a href="mailto:{{ config('app.support_email') }}" class="hover:text-white transition-colors">{{ config('app.support_email') }}</a></li>
                        <li>Buenos Aires, Argentina</li>
                    </ul>
                </div>
            </div>
            <div class="mt-12 pt-6 border-t border-white/10 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-white/35">
                <span>© {{ date('Y') }} Ascento. Todos los derechos reservados.</span>
                <span>Hecho para empresas de mantenimiento de ascensores.</span>
            </div>
        </div>
    </footer>

    <script>
        // Aparición al hacer scroll.
        const revealObserver = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    revealObserver.unobserve(entry.target);
                }
            });
        }, { threshold: 0.15 });
        document.querySelectorAll('[data-reveal]').forEach((el) => revealObserver.observe(el));

        // Ascensor de la portada: va de piso en piso sin apuro; el indicador cambia al llegar.
        // Con «reducir movimiento» queda quieto en PB.
        (function () {
            const shaft = document.querySelector('[data-shaft]');
            if (!shaft || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
            const car = shaft.querySelector('[data-shaft-car]');
            const digit = document.querySelector('[data-shaft-digit]');
            const arrow = document.querySelector('[data-shaft-arrow]');
            const floors = ['PB', '1', '2', '3', '4'];
            let at = 0;
            setInterval(() => {
                let next = at;
                while (next === at) next = Math.floor(Math.random() * floors.length);
                arrow.textContent = next > at ? '▲' : '▼';
                at = next;
                car.style.setProperty('--at', at);
                setTimeout(() => { digit.textContent = floors[at]; }, 1500);
            }, 4200);
        })();

        // Riel lateral: avanza con el scroll y muestra el piso de la sección visible.
        (function () {
            const fill = document.getElementById('railFill');
            const label = document.getElementById('railLabel');
            const floors = Array.from(document.querySelectorAll('[data-floor]'));
            if (!fill || !floors.length) return;
            const update = () => {
                const max = document.documentElement.scrollHeight - window.innerHeight;
                fill.style.height = (max > 0 ? Math.min(1, window.scrollY / max) * 100 : 0) + '%';
                let current = floors[0];
                for (const section of floors) if (section.getBoundingClientRect().top < window.innerHeight * 0.5) current = section;
                label.textContent = current.getAttribute('data-floor');
            };
            window.addEventListener('scroll', update, { passive: true });
            update();
        })();
    </script>
    @include('partials.cookie-notice')
</body>
</html>

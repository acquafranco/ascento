<!DOCTYPE html>
<html lang="es" class="scroll-smooth">
<head>
     <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>Ascento — Software de gestión para empresas de mantenimiento de ascensores</title>
        <meta name="description" content="Ascento es la plataforma para administrar clientes, edificios, técnicos, órdenes de trabajo, mantenimientos e inspecciones de tu empresa de ascensores, todo en un solo lugar.">

        {{-- Favicon (ícono de la pestaña) --}}
        @include('partials.brand-head')

        {{-- WhatsApp / redes sociales --}}
        <meta property="og:title" content="Ascento | Gestión para empresas de ascensores">
        <meta property="og:description" content="Toda la gestión de tu empresa de ascensores, en un solo lugar.">
        <meta property="og:image" content="https://ascento.online/images/brand/og-1200x630.jpg">
        <meta property="og:image:secure_url" content="https://ascento.online/images/brand/og-1200x630.jpg">
        <meta property="og:image:type" content="image/jpeg">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:image:alt" content="Ascento">
        <meta property="og:url" content="https://ascento.online/">
        <meta property="og:type" content="website">

        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="Ascento | Gestión para empresas de ascensores">
        <meta name="twitter:description" content="Toda la gestión de tu empresa de ascensores, en un solo lugar.">
        <meta name="twitter:image" content="https://ascento.online/images/brand/og-1200x630.jpg">



    <style>
        html { scrollbar-gutter: stable; }
        body { -webkit-font-smoothing: antialiased; }
        .font-tabular { font-variant-numeric: tabular-nums; }

        /* Reveal-on-scroll */
        [data-reveal] { opacity: 0; transform: translateY(14px); transition: opacity .6s ease, transform .6s ease; }
        [data-reveal].is-visible { opacity: 1; transform: translateY(0); }

        /* Shaft rail dot pulse for the active floor */
        .rail-dot { transition: background-color .25s ease, transform .25s ease, box-shadow .25s ease; }
        .rail-dot.is-active { transform: scale(1.35); box-shadow: 0 0 0 4px rgba(255,106,26,0.16); }

        @media (prefers-reduced-motion: reduce) {
            [data-reveal] { opacity: 1; transform: none; transition: none; }
            * { animation-duration: 0.001ms !important; animation-iteration-count: 1 !important; transition-duration: 0.001ms !important; }
        }

        /* Puertas de ascensor sobre la ilustración de la portada: se abren una sola vez. */
        .elevator-doors { position: absolute; inset: 0; z-index: 5; display: flex; overflow: hidden; border-radius: inherit; pointer-events: none; }
        .elevator-doors span { flex: 1; background: linear-gradient(90deg, #1A212B 0%, #232B36 45%, #1A212B 100%); animation: door-open-left .95s cubic-bezier(.65, 0, .35, 1) .45s forwards; }
        .elevator-doors span:first-child { box-shadow: inset -1px 0 0 rgba(255, 102, 0, .55); }
        .elevator-doors span:last-child { box-shadow: inset 1px 0 0 rgba(255, 102, 0, .55); animation-name: door-open-right; }
        @keyframes door-open-left { to { transform: translateX(-101%); } }
        @keyframes door-open-right { to { transform: translateX(101%); } }

        /* Carruseles: sin barra de desplazamiento visible (se mueven con el dedo, la rueda o los botones). */
        .no-scrollbar { scrollbar-width: none; }
        .no-scrollbar::-webkit-scrollbar { display: none; }

        @media (prefers-reduced-motion: reduce) {
            .elevator-doors { display: none; }
        }

        ::selection { background: #FFE8D6; color: #14171C; }
        :focus-visible { outline: 2px solid #2E4FBE; outline-offset: 2px; border-radius: 4px; }
    </style>
    {{-- Estilos, tipografías y Alpine compilados con Vite (sin CDN). Al final del head, como el CDN:
         el orden frente a los estilos propios de la página no cambia. --}}
    @vite(['resources/css/public.css', 'resources/js/public.js'])
</head>
<body class="bg-paper text-ink font-body antialiased">

    {{-- ============ NAVBAR ============ --}}
    <header x-data="{ open: false, scrolled: false }" @scroll.window="scrolled = window.scrollY > 12"
            class="fixed inset-x-0 top-0 z-50 transition-colors duration-300"
            :class="scrolled ? 'bg-paper/90 backdrop-blur border-b border-ink/10' : 'bg-transparent border-b border-transparent'">
        <nav class="mx-auto max-w-7xl px-5 sm:px-8 h-16 flex items-center justify-between">
            <a href="/" class="flex items-center gap-2.5 shrink-0">
               <img src="{{ asset('images/brand/logo-128.png') }}" alt="Ascento" class="rounded-md h-8 w-8 object-contain">
                <span class="font-display font-semibold text-[17px] tracking-tight">Ascento</span>
            </a>

            <div class="hidden lg:flex items-center gap-8 text-sm font-medium text-ink/70">
                <a href="#beneficios" class="hover:text-ink transition-colors">Beneficios</a>
                <a href="#como-funciona" class="hover:text-ink transition-colors">Cómo funciona</a>
                <a href="#planes" class="hover:text-ink transition-colors">Planes</a>
                <a href="#faq" class="hover:text-ink transition-colors">Preguntas frecuentes</a>
            </div>

            <div class="hidden lg:flex items-center gap-3">
                <button type="button" data-whatsapp-open class="text-sm font-semibold text-ink/70 hover:text-ink transition-colors">Hablemos</button>
                <a href="{{ Route::has('login') ? route('login') : '/login' }}"
                   class="inline-flex items-center gap-1.5 rounded-full bg-graphite text-white text-sm font-semibold pl-4 pr-3.5 py-2.5 shadow-card hover:shadow-cardHover hover:-translate-y-0.5 transition-all duration-200">
                    Entrar
                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M3 8H13M13 8L9 4M13 8L9 12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </a>
            </div>

            <button @click="open = !open" class="lg:hidden p-2 -mr-2 text-ink" aria-label="Abrir menú">
                <svg x-show="!open" width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M4 7H20M4 12H20M4 17H20" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                <svg x-show="open" x-cloak width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M6 6L18 18M6 18L18 6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
            </button>
        </nav>

        <div x-show="open" x-cloak x-transition class="lg:hidden bg-paper border-b border-ink/10 px-5 pb-5 pt-1">
            <div class="flex flex-col gap-1 text-[15px] font-medium">
                <a @click="open=false" href="#beneficios" class="py-2.5 text-ink/75">Beneficios</a>
                <a @click="open=false" href="#como-funciona" class="py-2.5 text-ink/75">Cómo funciona</a>
                <a @click="open=false" href="#planes" class="py-2.5 text-ink/75">Planes</a>
                <a @click="open=false" href="#faq" class="py-2.5 text-ink/75">Preguntas frecuentes</a>
                <button type="button" data-whatsapp-open @click="open=false" class="py-2.5 text-left text-ink/75">Hablemos por WhatsApp</button>
                <div class="flex gap-2 pt-3">
                    <a href="{{ Route::has('login') ? route('login') : '/login' }}" class="flex-1 text-center rounded-full bg-graphite text-white px-4 py-2.5 text-sm font-semibold">Entrar</a>
                </div>
            </div>
        </div>
    </header>

    {{-- ============ VERTICAL SHAFT RAIL (desktop only) ============ --}}
    <div class="hidden xl:flex flex-col items-center fixed left-8 top-1/2 -translate-y-1/2 z-40" aria-hidden="true">
        <div class="relative h-[420px] w-px bg-ink/10">
            <div id="railFill" class="absolute top-0 left-0 w-px bg-amber-500 transition-all duration-300 ease-out" style="height:0%"></div>
            <template x-for="(f, i) in ['PB','01','02','03','04','05']" :key="i"></template>
        </div>
        <div class="absolute flex flex-col items-center gap-1" style="top:-2.75rem">
            <span id="railLabel" class="font-mono text-[11px] tracking-wider text-ink/50 font-tabular">PB</span>
        </div>
    </div>

    <main>
        {{-- ============ HERO ============ --}}
        <section class="relative overflow-hidden pt-24 pb-16 sm:pt-32 sm:pb-24 xl:pt-40 xl:pb-28" data-floor="PB" id="inicio">
            <div class="pointer-events-none absolute inset-0 -z-10 [background:radial-gradient(60%_60%_at_80%_0%,rgba(255,106,26,0.07),transparent_60%),radial-gradient(50%_50%_at_0%_20%,rgba(46,79,190,0.06),transparent_60%)]"></div>

            <div class="mx-auto max-w-7xl px-5 sm:px-8">
                <div class="grid lg:grid-cols-[1.05fr_0.95fr] gap-14 lg:gap-10 items-center">
                    <div data-reveal>
                        <span class="inline-flex items-center gap-2 rounded-full bg-white border border-ink/10 shadow-sm px-3.5 py-1.5 text-xs font-semibold tracking-wide text-ink/70">
                            <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500"></span>
                            Software para empresas de mantenimiento de ascensores
                        </span>

                        <h1 class="mt-5 sm:mt-6 font-display font-semibold text-[2.25rem] leading-[1.08] sm:text-6xl sm:leading-[1.05] lg:text-[3.25rem] xl:text-6xl tracking-tight text-ink">
                            Gestioná tu empresa de ascensores
                            <span class="block text-ink/40">desde un solo lugar<span class="text-amber-500">.</span></span>
                        </h1>

                        <p class="mt-4 sm:mt-6 text-base sm:text-lg text-ink/60 max-w-xl leading-relaxed">
                            Clientes, edificios, técnicos y órdenes de trabajo en tiempo real. Ascento centraliza cada mantenimiento, inspección y reclamo, piso por piso, para que nada se pierda entre planillas.
                        </p>

                        <div class="mt-6 sm:mt-9 flex flex-col min-[360px]:flex-row gap-3">
                            <a href="{{ Route::has('login') ? route('login') : '/login' }}"
                               class="inline-flex items-center justify-center gap-2 rounded-full bg-graphite text-white font-semibold px-5 sm:px-6 py-3.5 shadow-card hover:shadow-cardHover hover:-translate-y-0.5 transition-all duration-200">
                                Entrar
                                <svg width="15" height="15" viewBox="0 0 16 16" fill="none"><path d="M3 8H13M13 8L9 4M13 8L9 12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </a>
                            <a href="#prueba-gratis"
                               class="inline-flex items-center justify-center gap-2 rounded-full border border-ink/15 bg-white font-semibold px-5 sm:px-6 py-3.5 text-ink hover:border-ink/30 hover:-translate-y-0.5 transition-all duration-200">
                                Probar gratis 30 días
                            </a>
                        </div>

                        <div class="mt-6 sm:mt-9 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-ink/50">
                            @php
                                $fromPrice = \App\Models\SubscriptionPlan::offered()->min('price');
                            @endphp
                            @if ($fromPrice)
                                <a href="#planes" class="font-semibold text-ink hover:text-amber-600 transition-colors" data-price-anchor>Desde ${{ number_format((float) $fromPrice, 0, ',', '.') }}/mes</a>
                            @endif
                            <span class="flex items-center gap-2"><svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M3 8.5L6.2 11.5L13 4.5" stroke="#2E4FBE" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>Sin tarjeta de crédito</span>
                            <span class="flex items-center gap-2"><svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M3 8.5L6.2 11.5L13 4.5" stroke="#2E4FBE" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>Empezá a organizar tu empresa desde el primer día</span>
                        </div>
                    </div>

                    {{-- Floor indicator + mockup --}}
                    <div data-reveal style="transition-delay:.1s" class="relative">
                        <div class="relative mx-auto max-w-sm rounded-2xl bg-graphite p-5 shadow-cardHover">
                            {{-- Puertas de cabina que se abren una vez al cargar (solo la ilustración; el texto no espera). --}}
                            <div class="elevator-doors" aria-hidden="true"><span></span><span></span></div>
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <span class="h-2 w-2 rounded-full bg-amber-500"></span>
                                    <span class="font-mono text-[11px] tracking-wider text-white/50 uppercase">Indicador de posición</span>
                                </div>
                                <span class="font-mono text-[11px] text-white/30">EN SERVICIO</span>
                            </div>

                            <div class="mt-5 flex items-end justify-between rounded-xl bg-graphite2 border border-white/5 p-6">
                                <div>
                                    <div id="floorDigit" class="font-mono font-semibold text-7xl text-amber-500 font-tabular leading-none">PB</div>
                                    <div class="mt-3 text-xs text-white/40 tracking-wide">Ascendiendo</div>
                                </div>
                                <div class="flex flex-col items-end gap-4">
                                    {{-- Placa de marca del indicador: el logo real de Ascento (decorativo; la marca ya se lee en la barra superior). --}}
                                    <img src="{{ asset('images/brand/logo-128.png') }}" alt="" width="40" height="40" data-indicator-logo
                                         class="h-10 w-10 rounded-[10px] ring-1 ring-white/10 shadow-[0_0_18px_rgba(255,106,26,0.18)]">
                                    <div class="flex flex-col-reverse gap-1.5">
                                        <div class="h-1.5 w-8 rounded-full bg-white/10"></div>
                                        <div class="h-1.5 w-8 rounded-full bg-white/10"></div>
                                        <div class="h-1.5 w-8 rounded-full bg-white/10"></div>
                                        <div class="h-1.5 w-8 rounded-full bg-amber-500"></div>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4 grid grid-cols-2 gap-3">
                                <div class="rounded-xl bg-graphite2 border border-white/5 p-4">
                                    <div class="text-[11px] text-white/40">En proceso</div>
                                    <div class="mt-1 font-display text-2xl font-semibold text-white">18</div>
                                </div>
                                <div class="rounded-xl bg-graphite2 border border-white/5 p-4">
                                    <div class="text-[11px] text-white/40">Completadas</div>
                                    <div class="mt-1 font-display text-2xl font-semibold text-white">6</div>
                                </div>
                            </div>
                        </div>

                        <div class="hidden sm:block absolute -right-6 -bottom-8 w-44 rounded-xl bg-white border border-ink/10 shadow-card p-4 rotate-3">
                            <div class="flex items-center gap-2">
                                <span class="h-6 w-6 rounded-md bg-rail-100 flex items-center justify-center text-rail-500">
                                    <svg width="13" height="13" viewBox="0 0 16 16" fill="none"><path d="M2 13V5.5L8 2L14 5.5V13H2Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
                                </span>
                                <span class="text-xs font-semibold">Edificio Alsina 1420</span>
                            </div>
                            <div class="mt-2 text-[11px] text-ink/50">Mantenimiento completado</div>
                            <div class="mt-1 h-1.5 w-full rounded-full bg-ink/5"><div class="h-1.5 w-full rounded-full bg-amber-500"></div></div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        {{-- ============ LOGO / TRUST STRIP ============ --}}
        <section class="border-y border-ink/10 bg-white/60">
            <div class="mx-auto max-w-7xl px-5 sm:px-8 py-6 flex flex-wrap items-center justify-center sm:justify-between gap-x-10 gap-y-3 text-ink/35 text-sm font-medium tracking-wide">
                <span>Para empresas de mantenimiento de ascensores</span>
                <span>En la computadora y en el celular</span>
                <span>Los datos de cada empresa, separados y privados</span>
                <div class="hidden sm:flex items-center gap-8 font-display">
                    <!-- <span>Elevatek</span><span>SubeCorp</span><span>NivelPro</span><span>Ascensores del Sur</span><span>VertiMant</span> -->
                </div>
            </div>
        </section>

        {{-- ============ DESDE CUALQUIER LUGAR ============ --}}
        {{-- Argumento comercial (beneficio, no funciones): cada idea se apoya en algo que el sistema hace hoy
             (centro de atención, remitos firmados, documentos por edificio, panel desde el navegador). --}}
        <section id="desde-cualquier-lugar" class="relative overflow-hidden bg-graphite py-20 text-white sm:py-28">
            <div class="pointer-events-none absolute inset-0 [background:radial-gradient(55%_60%_at_100%_0%,rgba(255,106,26,0.16),transparent_60%)]" aria-hidden="true"></div>
            <div class="relative mx-auto max-w-7xl px-5 sm:px-8">
                <div class="grid items-center gap-12 lg:grid-cols-[1fr_1.05fr] lg:gap-16">
                    <div data-reveal>
                        <span class="font-mono text-xs uppercase tracking-widest text-amber-500">Desde cualquier lugar</span>
                        <h2 class="mt-3 font-display text-3xl font-semibold tracking-tight sm:text-[2.75rem] sm:leading-[1.1]">
                            Tu empresa en orden, <span class="text-amber-500">aunque no estés en la oficina.</span>
                        </h2>
                        <p class="mt-5 max-w-xl text-base leading-relaxed text-white/65 sm:text-lg">
                            Lo que hoy está repartido entre papeles, planillas y mensajes queda en un solo lugar. Abrís Ascento desde tu casa, la oficina o donde estés, y ves cómo está tu empresa.
                        </p>

                        <ul class="mt-8 grid gap-x-8 gap-y-6 sm:grid-cols-2">
                            @foreach ([
                                ['Más tranquilidad', 'Lo pendiente, lo vencido y lo que necesita tu atención, a la vista apenas entrás.', 'M8 14C11.3 14 14 11.3 14 8C14 4.7 11.3 2 8 2C4.7 2 2 4.7 2 8C2 11.3 4.7 14 8 14ZM5.5 8.2L7.2 9.9L10.6 6.3'],
                                ['Menos llamados', 'Ves qué trabajo se hizo y quién lo hizo, con el remito firmado, sin perseguir a nadie por teléfono.', 'M3.5 2.5H6L7 5.5L5.5 6.5C6.2 8 8 9.8 9.5 10.5L10.5 9L13.5 10V12.5C13.5 13.1 13.1 13.5 12.5 13.5C7.5 13.5 2.5 8.5 2.5 3.5C2.5 2.9 2.9 2.5 3.5 2.5Z'],
                                ['Todo en su lugar', 'Remitos, reportes y presupuestos guardados con su edificio, no en cuadernos ni en chats.', 'M2.5 4.5H6.5L8 6H13.5V12.5H2.5V4.5Z'],
                                ['Tiempo para crecer', 'Menos horas buscando información y más tiempo para atender clientes y sumar edificios.', 'M2.5 12.5L6 9L8.5 11.5L13.5 6.5M10 6.5H13.5V10'],
                            ] as [$title, $text, $icon])
                                <li class="flex gap-3.5">
                                    <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-amber-500/15 text-amber-500" aria-hidden="true">
                                        <svg width="17" height="17" viewBox="0 0 16 16" fill="none"><path d="{{ $icon }}" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    </span>
                                    <div>
                                        <h3 class="font-display text-[15px] font-semibold">{{ $title }}</h3>
                                        <p class="mt-1 text-sm leading-relaxed text-white/55">{{ $text }}</p>
                                    </div>
                                </li>
                            @endforeach
                        </ul>

                        <div class="mt-10 flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-3">
                            <a href="{{ Route::has('register') ? route('register') : '/register' }}" data-anywhere-cta
                               class="inline-flex items-center justify-center gap-2 rounded-full bg-amber-500 px-6 py-3.5 font-semibold text-graphite transition-all duration-200 hover:-translate-y-0.5 hover:bg-amber-400">
                                Probalo gratis 30 días
                                <svg width="15" height="15" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M3 8H13M13 8L9 4M13 8L9 12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </a>
                            <a href="#demo" class="inline-flex items-center justify-center px-2 py-3 text-sm font-semibold text-white/70 transition-colors hover:text-white">Ver cómo se ve el panel</a>
                        </div>
                        <p class="mt-3 text-center text-xs text-white/40 sm:text-left">Sin tarjeta de crédito.</p>
                    </div>

                    {{-- Captura real del panel (datos de ejemplo), en una ventana de navegador. --}}
                    <div data-reveal style="transition-delay:.1s" class="relative">
                        <div class="overflow-hidden rounded-2xl border border-white/10 bg-white shadow-[0_30px_80px_-30px_rgba(0,0,0,0.7)]">
                            <div class="flex items-center gap-1.5 border-b border-ink/10 bg-paper px-4 py-2.5" aria-hidden="true">
                                <span class="h-2 w-2 rounded-full bg-ink/15"></span>
                                <span class="h-2 w-2 rounded-full bg-ink/15"></span>
                                <span class="h-2 w-2 rounded-full bg-ink/15"></span>
                                <span class="ml-2 font-mono text-[10px] text-ink/40">ascento.online/admin</span>
                            </div>
                            <img src="{{ asset('images/landing/admin-inicio.jpg') }}" alt="Panel de Ascento: pendientes, en curso y completados del día"
                                 width="1280" height="800" loading="lazy" decoding="async" class="block aspect-[16/10] w-full object-cover object-top">
                        </div>
                        <div class="absolute -bottom-5 left-4 hidden items-center gap-2.5 rounded-xl border border-ink/10 bg-white px-3.5 py-2.5 text-ink shadow-card sm:flex" aria-hidden="true">
                            <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-amber-100 text-amber-600">
                                <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M2.5 7.5L8 3L13.5 7.5M4 6.5V13H12V6.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </span>
                            <span class="text-xs font-semibold">Desde tu casa, la oficina o el celular</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        {{-- ============ BENEFICIOS ============ --}}
        <section id="beneficios" class="py-24 sm:py-32" data-floor="01">
            <div class="mx-auto max-w-7xl px-5 sm:px-8">
                <div data-reveal class="max-w-2xl">
                    <span class="font-mono text-xs tracking-widest text-amber-600 uppercase">Piso 01 · Beneficios</span>
                    <h2 class="mt-3 font-display font-semibold text-3xl sm:text-4xl tracking-tight">Todo lo que hoy administrás en cuadernos y planillas, en un solo panel.</h2>
                </div>

                @php
                        // Solo funciones disponibles hoy. La etiqueta de plan sale de la configuración real
                        // (SubscriptionPlan::cheapestWith): sin etiqueta = en todos los planes.
                        $F = \App\Enums\PlanFeature::class;
                        $offered = \App\Models\SubscriptionPlan::offered();
                        // null = en todos los planes; false = ningún plan la incluye (no se anuncia).
                        // Una tarjeta que anuncia varias funciones cuenta solo los planes que tienen todas.
                        $badge = function (\App\Enums\PlanFeature|array|null $features) use ($offered): string|false|null {
                            $features = is_array($features) ? $features : array_filter([$features]);
                            if ($features === [] || $offered->every(fn ($p) => $p->allowsAll($features))) {
                                return null;
                            }
                            $from = \App\Models\SubscriptionPlan::cheapestWith(...$features);

                            return $from === null ? false : ($from->is($offered->last()) ? 'Plan '.$from->shortName() : 'Desde '.$from->shortName());
                        };
                        $benefits = collect([
['Clientes, edificios y ascensores', 'Cada consorcio con sus edificios y equipos, con el legajo técnico y el historial de cada ascensor.', 'M2 13V5.5L8 2L14 5.5V13H2ZM6 13V9H10V13', null],
['Agenda de mantenimientos e inspecciones', 'Qué edificio toca este mes, quién lo tiene y si ya se hizo. Lo vencido aparece primero.', 'M3 3H13V13H3V3ZM3 6H13M6 2V4M10 2V4', $F::Agenda],
['Técnicos desde el celular', 'Cada técnico ve solo lo suyo, firma el remito en el momento y carga reportes con fotos.', 'M5 1.5H11V14.5H5V1.5ZM7.5 12.5H8.5', null],
['Órdenes y remitos digitales', 'El trabajo cierra con el remito firmado, que compartís con el cliente en PDF o por enlace.', 'M4 2H10L13 5V14H4V2ZM10 2V5H13', null],
['QR público del edificio', 'Residentes y visitantes ven, sin cuenta, solo lo que la empresa elige publicar.', 'M3 3H7V7H3V3ZM9 3H13V7H9V3ZM3 9H7V13H3V9ZM9.5 9.5H10.5M12 9V13M9 12H10', $F::PublicQr],
['Pruebas de paracaídas', 'Registro de cada prueba y aviso de las próximas o vencidas según cada equipo.', 'M8 2L13 4V8C13 11 10.8 13.2 8 14C5.2 13.2 3 11 3 8V4L8 2ZM6 8L7.5 9.5L10.5 6.5', $F::ParachuteTests],
['Componentes en reparación', 'Qué está en el taller, con qué proveedor y cuándo vuelve, con el historial de cada cambio.', 'M10 3L13 6L7 12H4V9L10 3ZM9 4L12 7', $F::Repairs],
['Avisos en tiempo real y tareas', 'Campanita y push en el celular, y tareas con responsable, vencimiento y recordatorio.', 'M8 2C5.8 2 4 3.8 4 6V9L2.5 11H13.5L12 9V6C12 3.8 10.2 2 8 2ZM6.5 13C6.8 13.9 7.3 14.3 8 14.3C8.7 14.3 9.2 13.9 9.5 13', $F::Tasks],
['Mapa y centro de atención', 'Todos los edificios en el mapa y lo que necesita tu atención hoy.', 'M8 8C9.7 8 11 6.7 11 5C11 3.3 9.7 2 8 2C6.3 2 5 3.3 5 5C5 6.7 6.3 8 8 8ZM3 14C3 11.2 5.2 9 8 9C10.8 9 13 11.2 13 14', [$F::Map, $F::AttentionCenter]],
['Stock, servicios y cobranzas', 'Repuestos con aviso de mínimo, abonos con su frecuencia de cobro y lo que te deben.', 'M3 5L8 2L13 5V11L8 14L3 11V5ZM3 5L8 8L13 5M8 8V14', null],
['Presupuestos profesionales', 'Con tu logo, número y total, en PDF y con un enlace seguro para el cliente.', 'M3 3H13V13H3V3ZM3 6.5H13M6.5 6.5V13', $F::Quotes],
['Portal para tus clientes', 'Cada consorcio ve lo que le compartís de sus edificios y quién los atiende.', 'M2 6L8 2L14 6V14H2V6ZM6 14V9H10V14', $F::ClientPortal],
['Historial de contactos', 'Cada llamado, visita o correo con el cliente, y quiénes llevan tiempo sin contacto.', 'M3 3H13V10H6L3 13V3Z', $F::ClientContacts],
['Video en los reportes', 'Un video por reporte para mostrar la falla tal como se ve.', 'M2 4H11V12H2V4ZM11 7L14 5V11L11 9', $F::ReportVideos],
['Análisis e indicadores', 'Qué ascensores fallan seguido y cómo evoluciona tu empresa: técnicos, edificios y presupuestos.', 'M3 13V9M7 13V5M11 13V7M2 13.5H14', [$F::FailureAnalysis, $F::CompanyIndicators]],
['Fotos de antes y después', 'Fotos de cada trabajo y reportes de inspección con fotos.', 'M2 5H5L6 3H10L11 5H14V13H2V5ZM8 11C9.1 11 10 10.1 10 9C10 7.9 9.1 7 8 7C6.9 7 6 7.9 6 9C6 10.1 6.9 11 8 11Z', $F::WorkPhotos],
                        ])->map(fn ($b) => [$b[0], $b[1], $b[2], $badge($b[3])])
                            ->reject(fn ($b) => $b[3] === false) // ningún plan la incluye: no se anuncia
                            ->values();
                    @endphp

                @php
                    // Tres grupos para recorrerlos sin una lista interminable (sobre todo en el celular).
                    // Cada función figura en un solo grupo; las que ningún plan incluye ya se quitaron arriba.
                    $byTitle = $benefits->keyBy(0);
                    $benefitGroups = [
                        ['Mantenimiento y trabajo en la calle', 'Del edificio a la oficina: agenda, técnicos, remitos y el registro de cada trabajo.',
                            ['Agenda de mantenimientos e inspecciones', 'Técnicos desde el celular', 'Órdenes y remitos digitales', 'Fotos de antes y después', 'Video en los reportes', 'Pruebas de paracaídas', 'Componentes en reparación']],
                        ['Clientes y edificios', 'Tu cartera ordenada y lo que cada cliente puede ver.',
                            ['Clientes, edificios y ascensores', 'Portal para tus clientes', 'QR público del edificio', 'Presupuestos profesionales', 'Historial de contactos']],
                        ['Control de la empresa', 'Avisos, números y lo que necesita tu atención hoy.',
                            ['Avisos en tiempo real y tareas', 'Mapa y centro de atención', 'Stock, servicios y cobranzas', 'Análisis e indicadores']],
                    ];
                @endphp

                <div class="mt-10 space-y-10">
                    @foreach ($benefitGroups as $g => [$groupTitle, $groupText, $titles])
                        @php
                            $items = collect($titles)->map(fn ($t) => $byTitle->get($t))->filter()->values();
                        @endphp
                        @continue($items->isEmpty())
                        <div data-reveal data-carousel role="region" aria-roledescription="carrusel" aria-labelledby="funciones-{{ $g }}">
                            <div class="flex items-end justify-between gap-4">
                                <div class="flex items-start gap-3.5">
                                    {{-- Botón de piso: el número del grupo --}}
                                    <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full border-2 border-amber-500/70 bg-white font-mono text-sm font-semibold text-amber-600 shadow-sm font-tabular" aria-hidden="true">{{ $g + 1 }}</span>
                                    <div>
                                        <p id="funciones-{{ $g }}" class="font-display font-semibold text-lg tracking-tight">{{ $groupTitle }}</p>
                                        <p class="mt-0.5 text-sm text-ink/55">{{ $groupText }}</p>
                                    </div>
                                </div>
                                <div class="hidden shrink-0 gap-2 sm:flex" data-carousel-controls>
                                    <button type="button" data-carousel-prev aria-label="Anteriores de {{ $groupTitle }}"
                                            class="flex h-10 w-10 items-center justify-center rounded-full border border-ink/15 bg-white text-ink transition hover:border-ink/35 disabled:cursor-default disabled:opacity-30">
                                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M10 3L5 8L10 13" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    </button>
                                    <button type="button" data-carousel-next aria-label="Siguientes de {{ $groupTitle }}"
                                            class="flex h-10 w-10 items-center justify-center rounded-full border border-ink/15 bg-white text-ink transition hover:border-ink/35 disabled:cursor-default disabled:opacity-30">
                                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M6 3L11 8L6 13" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    </button>
                                </div>
                            </div>

                            <ul data-carousel-track tabindex="0" aria-label="{{ $groupTitle }}"
                                class="no-scrollbar mt-5 -mx-5 flex snap-x snap-mandatory scroll-px-5 gap-4 overflow-x-auto px-5 pb-4 pt-1 sm:mx-0 sm:scroll-px-0 sm:px-0">
                                @foreach ($items as $b)
                                    <li class="flex w-[80%] shrink-0 snap-start sm:w-[calc((100%-1rem)/2)] lg:w-[calc((100%-3rem)/4)]">
                                        <div class="group w-full rounded-2xl bg-white border border-ink/10 p-5 shadow-card hover:shadow-cardHover hover:-translate-y-0.5 transition-all duration-300">
                                            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-100 text-amber-600 group-hover:bg-graphite group-hover:text-amber-500 transition-colors duration-300">
                                                <svg width="18" height="18" viewBox="0 0 16 16" fill="none"><path d="{{ $b[2] }}" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                            </div>
                                            <h3 class="mt-4 font-display font-semibold text-[15px]">{{ $b[0] }}</h3>
                @if($b[3])<span class="mt-1 inline-block rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-700">{{ $b[3] }}</span>@endif
                                            <p class="mt-1.5 text-sm text-ink/55 leading-relaxed">{{ $b[1] }}</p>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                            <div data-carousel-dots class="mt-1 flex h-2 items-center justify-center gap-1.5" aria-hidden="true"></div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ============ COMO FUNCIONA ============ --}}
        <section id="como-funciona" class="py-24 sm:py-32 bg-graphite text-white" data-floor="02">
            <div class="mx-auto max-w-7xl px-5 sm:px-8">
                <div data-reveal class="max-w-2xl">
                    <span class="font-mono text-xs tracking-widest text-amber-500 uppercase">Piso 02 · Cómo funciona</span>
                    <h2 class="mt-3 font-display font-semibold text-3xl sm:text-4xl tracking-tight">De cero a operando, en tres subidas.</h2>
                </div>

                <div class="mt-16 relative">
                    <div class="hidden lg:block absolute left-0 right-0 top-8 h-px bg-white/10"></div>
                    <div class="grid lg:grid-cols-3 gap-10 lg:gap-6">
                        @php
                            $steps = [
                                ['PB', 'Registrá tu empresa', 'Creá tu cuenta y completá los datos de tu empresa de mantenimiento.'],
                                ['1', 'Cargá edificios y técnicos', 'Sumá tus edificios, ascensores, clientes y el equipo técnico que va a operar el sistema.'],
                                ['2', 'Empezá a administrar', 'Generá órdenes, programá mantenimientos y seguí cada trabajo en tiempo real.'],
                            ];
                        @endphp
                        @foreach ($steps as $i => $s)
                            <div data-reveal style="transition-delay: {{ $i * 100 }}ms" class="relative">
                                <div class="flex h-16 w-16 items-center justify-center rounded-2xl bg-graphite2 border border-white/10 font-mono text-xl text-amber-500 font-tabular">{{ $s[0] }}</div>
                                <h3 class="mt-6 font-display font-semibold text-lg">{{ $s[1] }}</h3>
                                <p class="mt-2 text-sm text-white/50 leading-relaxed max-w-sm">{{ $s[2] }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>

        {{-- ============ VISTA PREVIA / DEMO ============ --}}
        <section id="demo" class="py-24 sm:py-32" data-floor="03">
            <div class="mx-auto max-w-7xl px-5 sm:px-8">
                <div data-reveal class="max-w-2xl mx-auto text-center">
                    <span class="font-mono text-xs tracking-widest text-amber-600 uppercase">Piso 03 · Vista previa</span>
                    <h2 class="mt-3 font-display font-semibold text-3xl sm:text-4xl tracking-tight">Un panel pensado para el día a día del mantenimiento.</h2>
                </div>

               <div data-reveal class="mt-14 rounded-3xl bg-white border border-ink/10 shadow-cardHover overflow-hidden">
                    <div class="flex items-center gap-2 px-5 py-3.5 border-b border-ink/10 bg-ink/[0.02]">
                        <span class="h-2.5 w-2.5 rounded-full bg-ink/15"></span>
                        <span class="h-2.5 w-2.5 rounded-full bg-ink/15"></span>
                        <span class="h-2.5 w-2.5 rounded-full bg-ink/15"></span>
                        <span class="ml-3 font-mono text-[11px] text-ink/35">Ascento.online</span>
                    </div>

                    <div class="relative py-8 sm:py-10 bg-paper">
                        <!-- Fades laterales -->
                        <div class="pointer-events-none absolute inset-y-0 left-0 w-16 sm:w-24 z-10 bg-gradient-to-r from-paper to-transparent"></div>
                        <div class="pointer-events-none absolute inset-y-0 right-0 w-16 sm:w-24 z-10 bg-gradient-to-l from-paper to-transparent"></div>

                        <div class="overflow-hidden" data-marquee>
                            {{-- Capturas REALES de la app con datos ficticios de demo (tools/landing-screenshots, docs/landing-capturas.md). --}}
                            @php
                                $phoneShots = [
                                    ['tecnico-inicio', 'App del técnico', 'Lo próximo que tiene que hacer'],
                                    ['tecnico-trabajos', 'Órdenes de trabajo', 'Tomar y finalizar con el remito'],
                                    ['tecnico-visitas', 'Visitas del mes', 'Mantenimientos e inspecciones'],
                                    ['tecnico-reporte', 'Nuevo reporte', 'Edificio, fotos y video'],
                                    ['portal-inicio', 'Portal del cliente', 'Lo que la empresa comparte'],
                                    ['portal-documentos', 'Documentos del cliente', 'Remitos, reportes y presupuestos'],
                                    ['qr-publico', 'QR del edificio', 'Lo que ve quien escanea'],
                                ];
                            @endphp
                            {{-- Se mueve solo (también con el cursor encima) y se puede arrastrar con el mouse o el dedo.
                                 La lista va dos veces para que el loop sea continuo; la copia queda fuera de la
                                 lectura y del teclado (aria-hidden + inert). Ver el script al final de la página. --}}
                            <div class="flex w-max gap-5 sm:gap-6 cursor-grab select-none touch-pan-y will-change-transform" data-marquee-track>
                                @foreach (array_merge($phoneShots, $phoneShots) as $i => [$file, $title, $caption])
                                    <figure class="shrink-0 w-[180px] sm:w-[210px] lg:w-[230px]" @if($i >= count($phoneShots)) aria-hidden="true" inert data-marquee-copy @endif>
                                        <div class="rounded-[22px] overflow-hidden border border-ink/10 shadow-card bg-white aspect-[640/1247]">
                                            <img src="{{ asset('images/landing/'.$file.'.jpg') }}" alt="{{ $title }}: {{ mb_strtolower($caption) }}"
                                                 width="640" height="1247" loading="lazy" decoding="async" draggable="false" class="block w-full h-full object-cover object-top pointer-events-none">
                                        </div>
                                        <figcaption class="mt-3 px-1">
                                            <span class="block text-sm font-semibold text-ink">{{ $title }}</span>
                                            <span class="block text-xs text-ink/50">{{ $caption }}</span>
                                        </figcaption>
                                    </figure>
                                @endforeach
                            </div>
                        </div>
                        <div class="relative z-20 mt-5 flex justify-center gap-2">
                            <button type="button" data-marquee-prev aria-label="Captura anterior"
                                    class="flex h-9 w-9 items-center justify-center rounded-full border border-ink/15 bg-white text-ink transition hover:border-ink/35">
                                <svg width="15" height="15" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M10 3L5 8L10 13" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                            <button type="button" data-marquee-next aria-label="Captura siguiente"
                                    class="flex h-9 w-9 items-center justify-center rounded-full border border-ink/15 bg-white text-ink transition hover:border-ink/35">
                                <svg width="15" height="15" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M6 3L11 8L6 13" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                        </div>
                    </div>
                </div>

                {{-- Panel de la empresa (escritorio): una captura grande por vez y las seis pantallas
                     siempre a la vista como pestañas, en vez de seis tarjetas una debajo de otra. --}}
                @php
                    $panelShots = [
                        ['admin-inicio', 'Panel de la empresa', 'Pendientes, en curso y completados, al día.'],
                        ['admin-mapa', 'Mapa de edificios', 'Toda la cartera, por zona, técnico y estado del mes.'],
                        ['admin-presupuesto', 'Presupuestos', 'Documento listo para enviar por correo con PDF.'],
                        ['admin-reparaciones', 'Componentes en reparación', 'Qué está en el taller y cuándo vuelve.'],
                        ['admin-paracaidas', 'Pruebas de paracaídas', 'Próximas, vencidas y al día, por ascensor.'],
                        ['admin-indicadores', 'Indicadores', 'La operación de tu empresa, mes a mes.'],
                    ];
                @endphp
                <div class="mt-10 sm:mt-16 lg:mx-auto lg:max-w-[64rem]" data-reveal data-panel-tour x-data="{ tab: 0, count: {{ count($panelShots) }} }">
                    <div class="max-w-2xl">
                        <h3 class="font-display font-semibold text-xl sm:text-2xl lg:text-3xl tracking-tight">El panel de Ascento, en la computadora.</h3>
                        <p class="mt-2 hidden text-ink/60 sm:block">Lo que usa la oficina todos los días, y lo que podés revisar vos desde casa. Elegí una pantalla para verla.</p>
                    </div>

                    <div class="mt-5 grid gap-4 lg:mt-8 lg:grid-cols-[16rem_minmax(0,46rem)] lg:gap-8 lg:items-start">
                        <div role="tablist" aria-label="Pantallas del panel" aria-orientation="vertical"
                             class="no-scrollbar -mx-5 flex gap-2 overflow-x-auto px-5 lg:mx-0 lg:flex-col lg:gap-1.5 lg:overflow-visible lg:px-0"
                             @keydown.arrow-down.prevent="tab = (tab + 1) % count; $nextTick(() => $el.querySelectorAll('[role=tab]')[tab].focus())"
                             @keydown.arrow-right.prevent="tab = (tab + 1) % count; $nextTick(() => $el.querySelectorAll('[role=tab]')[tab].focus())"
                             @keydown.arrow-up.prevent="tab = (tab + count - 1) % count; $nextTick(() => $el.querySelectorAll('[role=tab]')[tab].focus())"
                             @keydown.arrow-left.prevent="tab = (tab + count - 1) % count; $nextTick(() => $el.querySelectorAll('[role=tab]')[tab].focus())">
                            @foreach ($panelShots as $i => [$file, $title, $caption])
                                <button type="button" role="tab" id="panel-tab-{{ $i }}" aria-controls="panel-shot-{{ $i }}"
                                        aria-selected="{{ $i === 0 ? 'true' : 'false' }}" tabindex="{{ $i === 0 ? '0' : '-1' }}"
                                        :aria-selected="tab === {{ $i }} ? 'true' : 'false'" :tabindex="tab === {{ $i }} ? 0 : -1"
                                        @click="tab = {{ $i }}; $el.scrollIntoView({ block: 'nearest', inline: 'nearest' })"
                                        class="group shrink-0 rounded-xl border px-4 py-2.5 text-left transition-colors {{ $i === 0 ? 'border-amber-500 bg-white shadow-card' : 'border-ink/10 bg-white/60' }}"
                                        :class="tab === {{ $i }} ? 'border-amber-500 bg-white shadow-card' : 'border-ink/10 bg-white/60 hover:border-ink/25'">
                                    <span class="flex items-center gap-2.5">
                                        <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $i === 0 ? 'bg-amber-500' : 'bg-ink/20' }}" :class="tab === {{ $i }} ? 'bg-amber-500' : 'bg-ink/20'"></span>
                                        <span class="block whitespace-nowrap text-sm font-semibold text-ink lg:whitespace-normal">{{ $title }}</span>
                                    </span>
                                    <span class="mt-0.5 hidden pl-4 text-xs text-ink/50 lg:block">{{ $caption }}</span>
                                </button>
                            @endforeach
                        </div>

                        <div class="rounded-2xl border border-ink/10 bg-white shadow-cardHover overflow-hidden">
                            <div class="hidden items-center gap-1.5 border-b border-ink/10 bg-ink/[0.02] px-4 py-2.5 lg:flex" aria-hidden="true">
                                <span class="h-2 w-2 rounded-full bg-ink/15"></span>
                                <span class="h-2 w-2 rounded-full bg-ink/15"></span>
                                <span class="h-2 w-2 rounded-full bg-ink/15"></span>
                                <span class="ml-2 font-mono text-[10px] text-ink/35">ascento.online/admin</span>
                            </div>
                            @foreach ($panelShots as $i => [$file, $title, $caption])
                                <figure id="panel-shot-{{ $i }}" role="tabpanel" aria-labelledby="panel-tab-{{ $i }}"
                                        x-show="tab === {{ $i }}" @if ($i > 0) style="display: none" @endif>
                                    <div class="aspect-[16/10] bg-paper">
                                        <img src="{{ asset('images/landing/'.$file.'.jpg') }}" alt="{{ $title }}: {{ mb_strtolower($caption) }}"
                                             width="1280" height="800" loading="lazy" decoding="async" class="block w-full h-full object-cover object-top">
                                    </div>
                                    {{-- En el celular el nombre ya está en la pestaña elegida: abajo, solo qué muestra. --}}
                                    <figcaption class="border-t border-ink/10 px-4 py-2.5 text-xs text-ink/55 lg:hidden">{{ $caption }}</figcaption>
                                </figure>
                            @endforeach
                        </div>
                    </div>
                </div>
                <p class="mt-6 text-center text-xs text-ink/40">Capturas de la aplicación real con datos de ejemplo.</p>

            </div>
        </section>

        {{-- ============ PLANES ============ --}}
        @php
            $plans = \App\Models\SubscriptionPlan::offered();
        @endphp
        <section id="planes" class="py-24 sm:py-32 bg-white border-y border-ink/10" data-floor="04">
            <div class="mx-auto max-w-7xl px-5 sm:px-8">
                <div data-reveal class="max-w-2xl mx-auto text-center">
                    <span class="font-mono text-xs tracking-widest text-amber-600 uppercase">Piso 04 · Planes</span>
                    <h2 class="mt-3 font-display font-semibold text-3xl sm:text-4xl tracking-tight">Un plan para cada tamaño de empresa de ascensores.</h2>
                    @if ($plans->isNotEmpty())
                        <p class="mt-4 text-ink/60">
                            Desde <strong class="text-ink">{{ $plans->first()->formattedPrice() }}/mes</strong>. Probalo gratis 30 días, sin tarjeta.
                        </p>
                    @endif
                </div>

                {{-- Las tres tarjetas con la misma altura; el recomendado se destaca con color, borde y
                     etiqueta, no con un tamaño mayor. --}}
                <div class="mt-14 grid gap-6 lg:grid-cols-3 lg:items-stretch">
                    @foreach ($plans as $plan)
                        @php
                            $featured = $plan->is_recommended;
                        @endphp
                        <div data-reveal
                             data-plan-card="{{ $plan->slug }}"
                             class="relative flex flex-col rounded-3xl p-8 {{ $featured
                                ? 'bg-graphite text-white border-2 border-amber-500 shadow-cardHover'
                                : 'bg-white border-2 border-ink/10 shadow-card' }}">

                            @if ($featured)
                                <span class="absolute -top-3.5 left-1/2 -translate-x-1/2 inline-flex items-center gap-2 rounded-full bg-amber-500 text-graphite text-xs font-bold px-4 py-1.5 uppercase tracking-wider">
                                    Recomendado
                                </span>
                            @endif

                            <h3 class="font-display font-semibold text-2xl tracking-tight">{{ $plan->shortName() }}</h3>
                            <p class="mt-2 text-sm lg:min-h-[2.5rem] {{ $featured ? 'text-white/60' : 'text-ink/55' }}">{{ $plan->description }}</p>

                            <div class="mt-6 font-display text-4xl font-semibold tracking-tight">
                                {{ $plan->formattedPrice() }}
                                <span class="text-base font-medium {{ $featured ? 'text-white/45' : 'text-ink/45' }}">/mes</span>
                            </div>

                            <ul class="mt-7 space-y-3 text-sm flex-1">
                                @foreach ($plan->highlights() as $line)
                                    <li class="flex gap-2.5">
                                        <svg class="mt-0.5 shrink-0" width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M3 8.5L6.2 11.5L13 4.5" stroke="{{ $featured ? '#F59E0B' : '#2E4FBE' }}" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                        <span class="{{ $featured ? 'text-white/85' : 'text-ink/75' }}">{{ $line }}</span>
                                    </li>
                                @endforeach
                                <li class="flex gap-2.5">
                                    <svg class="mt-0.5 shrink-0" width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M3 8.5L6.2 11.5L13 4.5" stroke="{{ $featured ? '#F59E0B' : '#2E4FBE' }}" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    <span class="{{ $featured ? 'text-white/85' : 'text-ink/75' }}">Mantenimientos, inspecciones, órdenes, remitos digitales e historial</span>
                                </li>
                            </ul>

                            {{-- La prueba no se elige por plan (siempre es con TRIAL_PLAN): el botón lleva a la
                                 comparativa, y la prueba se explica una sola vez debajo de las tarjetas. --}}
                            <a href="#comparativa"
                               class="mt-8 inline-flex items-center justify-center rounded-full font-semibold px-6 py-3.5 transition-all duration-200 hover:-translate-y-0.5 {{ $featured
                                    ? 'bg-amber-500 text-graphite hover:bg-amber-400'
                                    : 'border border-ink/15 text-ink hover:border-ink/30' }}">
                                Ver qué incluye
                            </a>
                        </div>
                    @endforeach
                </div>

                @if ($plans->isNotEmpty())
                    @php
                        $trialPlanName = \App\Models\SubscriptionPlan::findBySlug(\App\Models\SubscriptionPlan::TRIAL_PLAN)?->shortName() ?? 'Empresa';
                    @endphp
                    <div data-reveal class="mx-auto mt-8 flex max-w-3xl flex-col items-center gap-4 rounded-2xl border border-amber-500/30 bg-amber-50 px-6 py-5 text-center sm:flex-row sm:text-left">
                        <p class="flex-1 text-sm text-ink/70">
                            <strong class="text-ink">La prueba gratis es la misma para todos:</strong>
                            {{ \App\Models\SubscriptionPlan::TRIAL_DAYS }} días con todas las funciones del plan {{ $trialPlanName }}, sin tarjeta. Al terminar elegís el plan desde tu cuenta.
                        </p>
                        <a href="#prueba-gratis" class="inline-flex shrink-0 items-center gap-2 rounded-full bg-graphite px-5 py-2.5 text-sm font-semibold text-white transition-transform duration-200 hover:-translate-y-0.5">
                            Probar gratis {{ \App\Models\SubscriptionPlan::TRIAL_DAYS }} días
                        </a>
                    </div>

                    <div data-reveal class="mt-12 scroll-mt-24" id="comparativa">
                        <h3 class="text-center font-display font-semibold text-2xl tracking-tight">Comparativa completa</h3>
                        <p class="mt-2 text-center text-sm text-ink/50">Sale de la configuración real de cada plan: lo que ves acá es lo que el sistema habilita.</p>
                        <div class="mt-6 overflow-x-auto rounded-2xl border border-ink/10">
                            <table class="w-full min-w-[640px] text-sm">
                                <thead class="bg-graphite text-white">
                                    <tr>
                                        <th class="px-4 py-3 text-left font-semibold">Función</th>
                                        @foreach ($plans as $plan)
                                            <th class="px-4 py-3 text-center font-semibold">{{ $plan->shortName() }}<span class="block text-xs font-normal text-white/60">{{ $plan->formattedPrice() }}/mes</span></th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-ink/10 bg-white">
                                    @foreach (\App\Models\SubscriptionPlan::comparisonRows($plans) as [$label, $values])
                                        <tr>
                                            <td class="px-4 py-2.5 text-ink/80">{{ $label }}</td>
                                            @foreach ($plans as $plan)
                                                @php
                                                    $v = $values[$plan->slug] ?? false;
                                                @endphp
                                                <td class="px-4 py-2.5 text-center">
                                                    @if (is_string($v))
                                                        <span class="font-medium text-ink">{{ $v }}</span>
                                                    @elseif ($v)
                                                        <span class="text-amber-600 font-bold" aria-label="Incluido">✓</span>
                                                    @else
                                                        <span class="text-ink/25" aria-label="No incluido">—</span>
                                                    @endif
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif

                <p class="mt-10 text-center text-sm text-ink/50">
                    Precios en pesos argentinos, por mes. Se paga con Mercado Pago y podés cambiar de plan o cancelar cuando quieras.
                </p>
            </div>
        </section>

        {{-- ============ PRUEBA GRATIS ============ --}}
        <section id="prueba-gratis" class="py-24 sm:py-28" data-floor="05">
            <div class="mx-auto max-w-5xl px-5 sm:px-8">
                <div data-reveal class="rounded-3xl bg-amber-500 px-8 py-14 sm:px-16 sm:py-16 text-center relative overflow-hidden">
                    <div class="absolute inset-0 opacity-[0.08] [background-image:repeating-linear-gradient(0deg,#12151C_0,#12151C_1px,transparent_1px,transparent_10px)]"></div>
                    <div class="relative">
                        <span class="font-mono text-xs tracking-widest text-graphite/70 uppercase">Piso 05 · Prueba gratuita</span>
                        <h2 class="mt-3 font-display font-semibold text-3xl sm:text-4xl tracking-tight text-graphite">Probá el sistema gratis durante 30 días.</h2>
                        <p class="mt-4 text-graphite/70 max-w-lg mx-auto">Sin tarjeta de crédito. Sin compromiso. Configurás tu empresa y empezás a operar el mismo día.</p>
                        <a href="{{ Route::has('register') ? route('register') : '/register' }}"
                           class="mt-8 inline-flex items-center gap-2 rounded-full bg-graphite text-white font-semibold px-7 py-3.5 hover:-translate-y-0.5 transition-transform duration-200">
                            Empezar prueba gratuita
                            <svg width="15" height="15" viewBox="0 0 16 16" fill="none"><path d="M3 8H13M13 8L9 4M13 8L9 12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </a>
                    </div>
                </div>
            </div>
        </section>

        {{-- ============ FAQ ============ --}}
        <section id="faq" class="py-24 sm:py-32 bg-white border-t border-ink/10" data-floor="06">
            <div class="mx-auto max-w-3xl px-5 sm:px-8">
                <div data-reveal class="text-center">
                    <span class="font-mono text-xs tracking-widest text-amber-600 uppercase">Piso 06 · Preguntas frecuentes</span>
                    <h2 class="mt-3 font-display font-semibold text-3xl sm:text-4xl tracking-tight">Lo que suelen preguntarnos.</h2>
                </div>

                <div class="mt-12 divide-y divide-ink/10 border-t border-b border-ink/10" x-data="{ openIndex: 0 }">
                    @php
                        // De la configuración real: plan de la prueba gratis y desde qué plan está el portal.
                        $trialPlan = \App\Models\SubscriptionPlan::findBySlug(\App\Models\SubscriptionPlan::TRIAL_PLAN)?->shortName() ?? 'Empresa';
                        $portalCheapest = \App\Models\SubscriptionPlan::cheapestWith(\App\Enums\PlanFeature::ClientPortal);
                        $portalFrom = $portalCheapest && ! $portalCheapest->is(\App\Models\SubscriptionPlan::offered()->first()) ? $portalCheapest->shortName() : null;
                        $faqs = [
['¿Para quién es Ascento?', 'Para empresas de mantenimiento de ascensores. La oficina trabaja en el panel desde la computadora, los técnicos desde el celular y '.($portalFrom ? 'desde el plan '.$portalFrom.' ' : '').'tus clientes pueden entrar a su propio portal.'],
['¿Necesito instalar algo?', 'No. Ascento funciona desde el navegador, en la computadora y en el celular. Los técnicos pueden agregarlo a la pantalla de inicio como una app.'],
['¿Mis técnicos pueden usarlo desde el celular?', 'Sí. Cada técnico tiene su usuario, ve solo lo que tiene asignado, firma remitos y carga informes con fotos desde el teléfono.'],
['¿Mis clientes pueden ver la información?', ($portalFrom ? 'Desde el plan '.$portalFrom.', cada' : 'Cada').' consorcio o administración entra al portal con su usuario y ve solo lo que vos le compartís de sus edificios.'],
['¿Qué muestra el QR del edificio?', 'Solo lo que la empresa elige publicar: estado informado de cada ascensor, último mantenimiento e inspección registrados, pruebas de paracaídas y componentes en reparación. Sin datos del cliente ni montos. Es información administrativa, no una certificación de seguridad.'],
['¿Cómo me entero de lo que pasa?', 'Con avisos dentro de Ascento que llegan en el momento, sin recargar, y notificaciones push en el celular si las activás.'],
['¿Qué pasa con mis datos?', 'Son tuyos: en todos los planes podés descargarlos en Excel (con fotos y documentos) cuando quieras. Además, Ascento hace copias de seguridad de toda la plataforma.'],
['¿Cómo funciona la prueba gratis?', 'Te registrás y usás Ascento 30 días con todas las funciones del plan '.$trialPlan.', sin tarjeta y sin elegir un plan antes. Al terminar, para seguir usándolo elegís el plan que te sirva desde tu cuenta y lo pagás con Mercado Pago.'],
['¿Puedo cambiar de plan o cancelar?', 'Sí. No hay permanencia mínima: cambiás de plan o cancelás desde tu cuenta.'],
['¿Cómo pido una demostración o ayuda?', 'Escribinos '.(\App\Support\Sales\SalesAssistant::displayNumber() ? 'por WhatsApp desde «Hablemos» o ' : '').'a '.config('app.support_email').': coordinamos una demostración y te ayudamos a poner en marcha tu empresa.'],
];
                    @endphp
                    @foreach ($faqs as $i => $f)
                        <div>
                            <button @click="openIndex = openIndex === {{ $i }} ? -1 : {{ $i }}"
                                    class="w-full flex items-center justify-between gap-4 py-5 text-left">
                                <span class="font-medium text-[15px]">{{ $f[0] }}</span>
                                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" class="shrink-0 transition-transform duration-200" :class="openIndex === {{ $i }} ? 'rotate-45' : ''">
                                    <path d="M8 2V14M2 8H14" stroke="#14171C" stroke-width="1.5" stroke-linecap="round"/>
                                </svg>
                            </button>
                            <div x-show="openIndex === {{ $i }}" x-collapse>
                                <p class="pb-5 text-sm text-ink/55 leading-relaxed max-w-xl">{{ $f[1] }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ============ CTA FINAL ============ --}}
        <section class="py-24 sm:py-32 bg-graphite text-white" data-floor="07" id="contacto">
            <div class="mx-auto max-w-4xl px-5 sm:px-8 text-center" data-reveal>
                <span class="font-mono text-xs tracking-widest text-amber-500 uppercase">Última parada</span>
                <h2 class="mt-3 font-display font-semibold text-3xl sm:text-5xl tracking-tight">Empezá a organizar tu empresa hoy.</h2>
                <p class="mt-5 text-white/55 max-w-xl mx-auto">Sumá a tu equipo, cargá tus edificios y llevá el control de cada ascensor desde un solo lugar.</p>
                <div class="mt-9 flex flex-col sm:flex-row items-center justify-center gap-3">
                    <a href="{{ Route::has('register') ? route('register') : '/register' }}"
                       class="inline-flex items-center gap-2 rounded-full bg-amber-500 text-graphite font-semibold px-8 py-4 hover:bg-amber-400 hover:-translate-y-0.5 transition-all duration-200">
                        Crear cuenta gratis
                        <svg width="15" height="15" viewBox="0 0 16 16" fill="none"><path d="M3 8H13M13 8L9 4M13 8L9 12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </a>
                    <button type="button" data-whatsapp-open
                            class="inline-flex items-center gap-2 rounded-full border border-white/20 text-white font-semibold px-7 py-4 hover:bg-white/10 transition-colors">
                        ¿Dudas? Hablemos por WhatsApp
                    </button>
                </div>
            </div>
        </section>
    </main>

    {{-- ============ FOOTER ============ --}}
    <footer class="bg-graphite text-white/60 border-t border-white/10">
        <div class="mx-auto max-w-7xl px-5 sm:px-8 py-14">
            <div class="grid sm:grid-cols-2 lg:grid-cols-5 gap-10">
                <div class="lg:col-span-2">
                    <div class="flex items-center gap-2.5">
                        <img src="{{ asset('images/brand/logo-128.png') }}" alt="Ascento" class="rounded-md h-7 w-7 object-contain">
                    </div>
                    <p class="mt-4 text-sm leading-relaxed max-w-xs">El sistema de gestión para empresas de mantenimiento de ascensores.</p>
                </div>
                <div>
                    <div class="text-xs font-semibold text-white/40 uppercase tracking-wide">Producto</div>
                    <ul class="mt-4 space-y-2.5 text-sm">
                        <li><a href="#beneficios" class="hover:text-white transition-colors">Beneficios</a></li>
                        <li><a href="#como-funciona" class="hover:text-white transition-colors">Cómo funciona</a></li>
                        <li><a href="#planes" class="hover:text-white transition-colors">Planes</a></li>
                        <li><a href="#faq" class="hover:text-white transition-colors">Preguntas frecuentes</a></li>
                    </ul>
                </div>
                <div>
                    <div class="text-xs font-semibold text-white/40 uppercase tracking-wide">Legal</div>
                    <ul class="mt-4 space-y-2.5 text-sm">
                        <li><a href="{{ Route::has('legal.terms') ? route('legal.terms') : '/legal/terminos' }}" class="hover:text-white transition-colors">Términos y Condiciones</a></li>
                        <li><a href="{{ Route::has('legal.privacy') ? route('legal.privacy') : '/legal/privacidad' }}" class="hover:text-white transition-colors">Política de Privacidad</a></li>
                        <li><a href="{{ Route::has('legal.data-deletion') ? route('legal.data-deletion') : '/legal/eliminacion-de-datos' }}" class="hover:text-white transition-colors">Eliminación de Datos</a></li>
                        <li><a href="{{ route('legal.cookies') }}" class="hover:text-white transition-colors">Política de cookies</a></li>
                        <li><button type="button" data-cookie-preferences class="hover:text-white transition-colors">Preferencias de cookies</button></li>
                    </ul>
                </div>
                <div>
                    <div class="text-xs font-semibold text-white/40 uppercase tracking-wide">Contacto</div>
                    <ul class="mt-4 space-y-2.5 text-sm">
                        <li><a href="mailto:{{ config('app.support_email') }}" class="hover:text-white transition-colors">{{ config('app.support_email') }}</a></li>
                        @if ($waDisplay = \App\Support\Sales\SalesAssistant::displayNumber())
                            <li><button type="button" data-whatsapp-open data-wa-number class="hover:text-white transition-colors">WhatsApp: {{ $waDisplay }}</button></li>
                        @endif
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
        // Scroll-reveal
        const revealEls = document.querySelectorAll('[data-reveal]');
        const revealObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    revealObserver.unobserve(entry.target);
                }
            });
        }, { threshold: 0.15 });
        revealEls.forEach(el => revealObserver.observe(el));

        // Hero floor-indicator count-up animation
        const floorDigit = document.getElementById('floorDigit');
        if (floorDigit) {
            const sequence = ['PB', '1', '2', '3', '4', '5', '6', '7', '8'];
            let idx = 0;
            setInterval(() => {
                idx = (idx + 1) % sequence.length;
                floorDigit.textContent = sequence[idx];
            }, 1400);
        }

        // Vertical shaft rail: scroll-spy across sections with data-floor
        const floorSections = Array.from(document.querySelectorAll('[data-floor]'));
        const railFill = document.getElementById('railFill');
        const railLabel = document.getElementById('railLabel');
        const floorNames = {
            'PB': 'PB', '01': '01', '02': '02', '03': '03', '04': '04', '05': '05', '06': '06', '07': '07'
        };

        function updateRail() {
            if (!railFill || floorSections.length === 0) return;
            const scrollTop = window.scrollY;
            const docHeight = document.documentElement.scrollHeight - window.innerHeight;
            const progress = Math.min(1, Math.max(0, scrollTop / docHeight));
            railFill.style.height = (progress * 100) + '%';

            let current = floorSections[0];
            for (const sec of floorSections) {
                const rect = sec.getBoundingClientRect();
                if (rect.top < window.innerHeight * 0.5) current = sec;
            }
            const floor = current.getAttribute('data-floor');
            if (railLabel && floor) railLabel.textContent = floorNames[floor] || floor;
        }
        window.addEventListener('scroll', updateRail, { passive: true });
        updateRail();

        // Carruseles de funciones: desplazamiento nativo (dedo, rueda, teclado) con botones e indicadores.
        document.querySelectorAll('[data-carousel]').forEach((root) => {
            const track = root.querySelector('[data-carousel-track]');
            const prev = root.querySelector('[data-carousel-prev]');
            const next = root.querySelector('[data-carousel-next]');
            const controls = root.querySelector('[data-carousel-controls]');
            const dotsBox = root.querySelector('[data-carousel-dots]');
            const items = Array.from(track.children);
            let dots = [];

            const step = () => items.length > 1 ? items[1].offsetLeft - items[0].offsetLeft : track.clientWidth;
            const positions = () => Math.max(1, items.length - Math.round(track.clientWidth / step()) + 1);
            const current = () => Math.min(positions() - 1, Math.round(track.scrollLeft / step()));
            const go = (i) => track.scrollTo({ left: i * step(), behavior: 'smooth' });

            function build() {
                const n = positions();
                dotsBox.innerHTML = '';
                dots = n > 1 ? Array.from({ length: n }, (_, i) => {
                    const dot = document.createElement('button');
                    dot.type = 'button';
                    dot.tabIndex = -1;
                    dot.className = 'h-1.5 rounded-full transition-all duration-300';
                    dot.addEventListener('click', () => go(i));
                    dotsBox.appendChild(dot);
                    return dot;
                }) : [];
                if (controls) controls.classList.toggle('sm:flex', n > 1);
                update();
            }

            function update() {
                const i = current();
                const last = positions() - 1;
                dots.forEach((dot, k) => { dot.className = 'h-1.5 rounded-full transition-all duration-300 ' + (k === i ? 'w-5 bg-amber-500' : 'w-1.5 bg-ink/25'); });
                if (prev) prev.disabled = track.scrollLeft <= 2;
                if (next) next.disabled = i >= last && track.scrollLeft + track.clientWidth >= track.scrollWidth - 2;
            }

            prev?.addEventListener('click', () => go(Math.max(0, current() - 1)));
            next?.addEventListener('click', () => go(Math.min(positions() - 1, current() + 1)));
            track.addEventListener('scroll', () => requestAnimationFrame(update), { passive: true });
            window.addEventListener('resize', build);
            build();
        });

        // Capturas de la app: se mueven solas sin frenarse con el cursor encima; se arrastran con el
        // mouse o el dedo. Durante el gesto el movimiento automático se detiene y retoma desde donde
        // quedó (sin volver al principio). La lista está dos veces: el loop envuelve sin saltos.
        (function () {
            const root = document.querySelector('[data-marquee]');
            if (!root) return;
            const track = root.querySelector('[data-marquee-track]');
            const figures = Array.from(track.children);
            const half = figures.length / 2;
            const reduce = window.matchMedia('(prefers-reduced-motion: reduce)');
            const SPEED = 45;        // px por segundo
            const RESUME_MS = 1200;  // pausa después de arrastrar o usar los botones

            let offset = 0, last = null, resumeAt = 0;
            let drag = null;         // { id, x, offset }
            let glide = null;        // { from, to, start }

            const period = () => figures[half].offsetLeft - figures[0].offsetLeft;
            const itemStep = () => figures[1].offsetLeft - figures[0].offsetLeft;
            const wrap = (value) => { const p = period(); return p > 0 ? ((value % p) + p) % p : 0; };
            const render = () => { track.style.transform = 'translate3d(' + (-offset) + 'px, 0, 0)'; };

            function tick(now) {
                const dt = last === null ? 0 : Math.min(now - last, 50);
                last = now;
                if (glide) {
                    const t = Math.min(1, (now - glide.start) / 450);
                    const eased = 1 - Math.pow(1 - t, 3);
                    offset = wrap(glide.from + (glide.to - glide.from) * eased);
                    if (t === 1) { glide = null; resumeAt = now + RESUME_MS; }
                    render();
                } else if (!drag && !reduce.matches && now >= resumeAt) {
                    offset = wrap(offset + SPEED * dt / 1000);
                    render();
                }
                requestAnimationFrame(tick);
            }

            track.addEventListener('pointerdown', (e) => {
                if (e.pointerType === 'mouse' && e.button !== 0) return;
                glide = null;
                drag = { id: e.pointerId, x: e.clientX, offset };
                track.setPointerCapture(e.pointerId);
                track.classList.replace('cursor-grab', 'cursor-grabbing');
            });
            track.addEventListener('pointermove', (e) => {
                if (!drag || e.pointerId !== drag.id) return;
                offset = wrap(drag.offset - (e.clientX - drag.x));
                render();
            });
            const release = (e) => {
                if (!drag || e.pointerId !== drag.id) return;
                drag = null;
                resumeAt = performance.now() + RESUME_MS;
                track.classList.replace('cursor-grabbing', 'cursor-grab');
            };
            ['pointerup', 'pointercancel', 'lostpointercapture'].forEach((type) => track.addEventListener(type, release));

            const nudge = (direction) => {
                const s = itemStep();
                const base = Math.round(offset / s) * s; // alinear a una captura
                glide = { from: offset, to: base + direction * s, start: performance.now() };
            };
            document.querySelector('[data-marquee-prev]')?.addEventListener('click', () => nudge(-1));
            document.querySelector('[data-marquee-next]')?.addEventListener('click', () => nudge(1));

            requestAnimationFrame(tick);
        })();
    </script>
    @include('partials.whatsapp-contact')
    @include('partials.cookie-notice')
</body>
</html>

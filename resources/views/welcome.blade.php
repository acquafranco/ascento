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

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">

        <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        ink: '#14171C',
                        paper: '#F7F7F4',
                        graphite: '#12151C',
                        graphite2: '#1B1F29',
                        amber: { 100: '#FFE8D6', 400: '#FF8A3D', 500: '#FF6A1A', 600: '#E85A0A', 700: '#C24800' },
                        rail: { 100: '#E7ECFB', 400: '#5A78D6', 500: '#2E4FBE', 600: '#233D99' },
                    },
                    fontFamily: {
                        display: ['"Space Grotesk"', 'sans-serif'],
                        body: ['Inter', 'sans-serif'],
                        mono: ['"JetBrains Mono"', 'monospace'],
                    },
                    boxShadow: {
                        card: '0 1px 2px rgba(20,23,28,0.04), 0 8px 24px -8px rgba(20,23,28,0.10)',
                        cardHover: '0 4px 10px rgba(20,23,28,0.06), 0 20px 40px -12px rgba(20,23,28,0.18)',
                    },
                }
            }
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>

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

        ::selection { background: #FFE8D6; color: #14171C; }
        :focus-visible { outline: 2px solid #2E4FBE; outline-offset: 2px; border-radius: 4px; }
    </style>
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
        <section class="relative overflow-hidden pt-32 pb-20 sm:pt-40 sm:pb-28" data-floor="PB" id="inicio">
            <div class="pointer-events-none absolute inset-0 -z-10 [background:radial-gradient(60%_60%_at_80%_0%,rgba(255,106,26,0.07),transparent_60%),radial-gradient(50%_50%_at_0%_20%,rgba(46,79,190,0.06),transparent_60%)]"></div>

            <div class="mx-auto max-w-7xl px-5 sm:px-8">
                <div class="grid lg:grid-cols-[1.05fr_0.95fr] gap-14 lg:gap-10 items-center">
                    <div data-reveal>
                        <span class="inline-flex items-center gap-2 rounded-full bg-white border border-ink/10 shadow-sm px-3.5 py-1.5 text-xs font-semibold tracking-wide text-ink/70">
                            <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                            Software para empresas de mantenimiento de ascensores
                        </span>

                        <h1 class="mt-6 font-display font-semibold text-[2.5rem] leading-[1.08] sm:text-6xl sm:leading-[1.05] tracking-tight text-ink">
                            Gestioná tu empresa de ascensores
                            <span class="block text-ink/40">desde un solo lugar<span class="text-amber-500">.</span></span>
                        </h1>

                        <p class="mt-6 text-lg text-ink/60 max-w-xl leading-relaxed">
                            Clientes, edificios, técnicos y órdenes de trabajo en tiempo real. Ascento centraliza cada mantenimiento, inspección y reclamo, piso por piso, para que nada se pierda entre planillas.
                        </p>

                        <div class="mt-9 flex flex-col sm:flex-row gap-3">
                            <a href="{{ Route::has('login') ? route('login') : '/login' }}"
                               class="inline-flex items-center justify-center gap-2 rounded-full bg-graphite text-white font-semibold px-6 py-3.5 shadow-card hover:shadow-cardHover hover:-translate-y-0.5 transition-all duration-200">
                                Entrar
                                <svg width="15" height="15" viewBox="0 0 16 16" fill="none"><path d="M3 8H13M13 8L9 4M13 8L9 12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </a>
                            <a href="#prueba-gratis"
                               class="inline-flex items-center justify-center gap-2 rounded-full border border-ink/15 bg-white font-semibold px-6 py-3.5 text-ink hover:border-ink/30 hover:-translate-y-0.5 transition-all duration-200">
                                Probar gratis 30 días
                            </a>
                        </div>

                        <div class="mt-9 flex items-center gap-6 text-sm text-ink/50">
                            @php
                                $fromPrice = \App\Models\SubscriptionPlan::offered()->min('price');
                            @endphp
                            @if ($fromPrice)
                                <a href="#planes" class="font-semibold text-ink hover:text-amber-600 transition-colors" data-price-anchor>Desde ${{ number_format((float) $fromPrice, 0, ',', '.') }}/mes</a>
                            @endif
                            <span class="flex items-center gap-2"><svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M3 8.5L6.2 11.5L13 4.5" stroke="#2E4FBE" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>Sin tarjeta de crédito</span>
                            <span class="flex items-center gap-2"><svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M3 8.5L6.2 11.5L13 4.5" stroke="#2E4FBE" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>Configuración en minutos</span>
                        </div>
                    </div>

                    {{-- Floor indicator + mockup --}}
                    <div data-reveal style="transition-delay:.1s" class="relative">
                        <div class="relative mx-auto max-w-sm rounded-2xl bg-graphite p-5 shadow-cardHover">
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
                                <div class="flex flex-col-reverse gap-1.5">
                                    <div class="h-1.5 w-8 rounded-full bg-white/10"></div>
                                    <div class="h-1.5 w-8 rounded-full bg-white/10"></div>
                                    <div class="h-1.5 w-8 rounded-full bg-white/10"></div>
                                    <div class="h-1.5 w-8 rounded-full bg-amber-500"></div>
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
                <span>Usado por empresas de mantenimiento en toda la región</span>
                <div class="hidden sm:flex items-center gap-8 font-display">
                    <!-- <span>Elevatek</span><span>SubeCorp</span><span>NivelPro</span><span>Ascensores del Sur</span><span>VertiMant</span> -->
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

                <div class="mt-14 grid sm:grid-cols-2 lg:grid-cols-4 gap-5">
                    @php
                        $benefits = [
['Clientes, edificios y ascensores', 'Cada consorcio con sus edificios, equipos y contactos, con el legajo técnico y el historial de cada ascensor.', 'M2 13V5.5L8 2L14 5.5V13H2ZM6 13V9H10V13', null],
['Agenda de mantenimientos e inspecciones', 'Qué edificio toca este mes, quién lo tiene y si ya se hizo. Lo vencido y lo no realizado aparece primero.', 'M3 3H13V13H3V3ZM3 6H13M6 2V4M10 2V4', null],
['Técnicos desde el celular', 'Cada técnico ve solo lo suyo, firma el remito en el momento y carga informes con fotos.', 'M5 1.5H11V14.5H5V1.5ZM7.5 12.5H8.5', null],
['Órdenes de trabajo y remitos firmados', 'Asignás un reclamo y le llega al técnico al instante; cierra con el remito firmado y los materiales usados.', 'M4 2H10L13 5V14H4V2ZM10 2V5H13', null],
['Avisos en tiempo real', 'Campanita y avisos que llegan sin recargar la página, y notificaciones push en el celular para técnicos, admins y clientes.', 'M8 2C5.8 2 4 3.8 4 6V9L2.5 11H13.5L12 9V6C12 3.8 10.2 2 8 2ZM6.5 13C6.8 13.9 7.3 14.3 8 14.3C8.7 14.3 9.2 13.9 9.5 13', null],
['Mapa y centro de atención', 'Todos los edificios en el mapa y una lista de lo que necesita tu atención: vencimientos, fallas repetidas, stock bajo.', 'M8 8C9.7 8 11 6.7 11 5C11 3.3 9.7 2 8 2C6.3 2 5 3.3 5 5C5 6.7 6.3 8 8 8ZM3 14C3 11.2 5.2 9 8 9C10.8 9 13 11.2 13 14', null],
['Stock, servicios y cobranzas', 'Repuestos con aviso de mínimo, abonos con su frecuencia de cobro y el seguimiento de lo que te deben.', 'M3 5L8 2L13 5V11L8 14L3 11V5ZM3 5L8 8L13 5M8 8V14', null],
['Exportación y backups', 'Descargás los datos de tu empresa en Excel cuando quieras. Además, Ascento hace copias de seguridad de toda la plataforma.', 'M8 2V10M8 10L5 7M8 10L11 7M3 13H13', null],
['Presupuestos profesionales', 'Documento con tu logo, número y total, PDF y envío por correo con un enlace seguro que vence.', 'M3 3H13V13H3V3ZM3 6.5H13M6.5 6.5V13', 'Profesional y Empresa'],
['Portal para tus clientes', 'Cada consorcio entra con su usuario y ve solo lo que le compartís de sus edificios: remitos, informes, presupuestos y documentos.', 'M2 6L8 2L14 6V14H2V6ZM6 14V9H10V14', 'Profesional y Empresa'],
['Video en los informes', 'Un video por informe para mostrar la falla tal como se ve en la sala de máquinas.', 'M2 4H11V12H2V4ZM11 7L14 5V11L11 9', 'Profesional y Empresa'],
['Análisis e indicadores', 'Fallas que se repiten, evolución de tu empresa y comparativas para decidir con datos.', 'M3 13V9M7 13V5M11 13V7M2 13.5H14', 'Profesional y Empresa'],
];
                    @endphp

                    @foreach ($benefits as $i => $b)
                        <div data-reveal style="transition-delay: {{ $i * 60 }}ms"
                             class="group rounded-2xl bg-white border border-ink/10 p-6 shadow-card hover:shadow-cardHover hover:-translate-y-1 transition-all duration-300">
                            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-amber-100 text-amber-600 group-hover:bg-graphite group-hover:text-amber-500 transition-colors duration-300">
                                <svg width="18" height="18" viewBox="0 0 16 16" fill="none"><path d="{{ $b[2] }}" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </div>
                            <h3 class="mt-4 font-display font-semibold text-[15px]">{{ $b[0] }}</h3>
                @if($b[3])<span class="mt-1 inline-block rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-700">{{ $b[3] }}</span>@endif
                            <p class="mt-1.5 text-sm text-ink/55 leading-relaxed">{{ $b[1] }}</p>
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
                                ['PB', 'Registrá tu empresa', 'Creá tu cuenta y configurá los datos de tu empresa de mantenimiento en minutos.'],
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

                        <div class="overflow-hidden">
                            <div class="flex w-max animate-marquee gap-5 sm:gap-6">
                                @php
                                    $shots = [
                                        'screenshot-1.jpg',
                                        'screenshot-2.jpg',
                                        'screenshot-3.jpg',
                                        'screenshot-4.jpg',
                                        'screenshot-5.jpg',
                                        'screenshot-6.jpg',
                                        'screenshot-7.jpg',
                                    ];
                                @endphp

                                {{-- Duplicamos la lista para que el loop sea infinito y sin cortes --}}
                                @foreach (array_merge($shots, $shots) as $shot)
                                    <div class="shrink-0 w-[170px] sm:w-[200px] lg:w-[220px] rounded-2xl overflow-hidden border border-ink/10 shadow-card bg-white">
                                        <img
                                            src="{{ asset('images/' . $shot) }}"
                                            alt="Captura de la app Ascento"
                                            width="640"
                                            height="1141"
                                            loading="lazy"
                                            class="w-full h-full object-cover"
                                        >
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

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

                <div class="mt-14 grid gap-6 lg:grid-cols-3 lg:items-center">
                    @foreach ($plans as $plan)
                        @php
                            $featured = $plan->is_recommended;
                        @endphp
                        <div data-reveal
                             data-plan-card="{{ $plan->slug }}"
                             class="relative flex flex-col rounded-3xl p-8 {{ $featured
                                ? 'bg-graphite text-white border-2 border-amber-500 shadow-cardHover lg:py-12 lg:-my-4'
                                : 'bg-white border border-ink/10 shadow-card' }}">

                            @if ($featured)
                                <span class="absolute -top-3.5 left-1/2 -translate-x-1/2 inline-flex items-center gap-2 rounded-full bg-amber-500 text-graphite text-xs font-bold px-4 py-1.5 uppercase tracking-wider">
                                    Recomendado
                                </span>
                            @endif

                            <h3 class="font-display font-semibold text-2xl tracking-tight">{{ $plan->shortName() }}</h3>
                            <p class="mt-2 text-sm {{ $featured ? 'text-white/60' : 'text-ink/55' }}">{{ $plan->description }}</p>

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
                                    <span class="{{ $featured ? 'text-white/85' : 'text-ink/75' }}">Mantenimientos, órdenes de trabajo, mapa e historial</span>
                                </li>
                            </ul>

                            <a href="#prueba-gratis"
                               class="mt-8 inline-flex items-center justify-center rounded-full font-semibold px-6 py-3.5 transition-all duration-200 hover:-translate-y-0.5 {{ $featured
                                    ? 'bg-amber-500 text-graphite hover:bg-amber-400'
                                    : 'border border-ink/15 text-ink hover:border-ink/30' }}">
                                Probar gratis 30 días
                            </a>
                        </div>
                    @endforeach
                </div>

                @if ($plans->isNotEmpty())
                    <div data-reveal class="mt-16">
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
                        $faqs = [
['¿Necesito instalar algo?', 'No. Ascento funciona desde el navegador, en la computadora y en el celular. Los técnicos pueden agregarlo a la pantalla de inicio como una app.'],
['¿Mis técnicos pueden usarlo desde el celular?', 'Sí. Cada técnico tiene su usuario, ve solo lo que tiene asignado, firma remitos y carga informes con fotos desde el teléfono.'],
['¿Mis clientes pueden ver la información?', 'En los planes Profesional y Empresa, cada consorcio o administración entra al portal con su usuario y ve solo lo que vos le compartís de sus edificios.'],
['¿Cómo me entero de lo que pasa?', 'Con avisos dentro de Ascento que llegan en el momento, sin recargar, y notificaciones push en el celular si las activás.'],
['¿Qué pasa con mis datos?', 'Son tuyos: en todos los planes podés descargarlos en Excel (con fotos y documentos) cuando quieras. Además, Ascento hace copias de seguridad de toda la plataforma.'],
['¿Hay prueba gratis?', 'Sí. 30 días con todas las funciones del plan Profesional, sin tarjeta. Al terminar elegís el plan que te sirva.'],
['¿Puedo cambiar de plan o cancelar?', 'Sí. No hay permanencia mínima: cambiás de plan o cancelás desde tu cuenta.'],
['¿Cómo pido ayuda?', 'Escribinos a '.config('app.support_email').' y te ayudamos a poner en marcha tu empresa.'],
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
                <a href="{{ Route::has('register') ? route('register') : '/register' }}"
                   class="mt-9 inline-flex items-center gap-2 rounded-full bg-amber-500 text-graphite font-semibold px-8 py-4 hover:bg-amber-400 hover:-translate-y-0.5 transition-all duration-200">
                    Entrar
                    <svg width="15" height="15" viewBox="0 0 16 16" fill="none"><path d="M3 8H13M13 8L9 4M13 8L9 12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </a>
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


                <style>
                    @keyframes marquee {
                        from { transform: translateX(0); }
                        to   { transform: translateX(-50%); }
                    }
                    .animate-marquee {
                        animation: marquee 35s linear infinite;
                    }
                    .animate-marquee:hover {
                        animation-play-state: paused;
                    }
                </style>


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
    </script>
</body>
</html>

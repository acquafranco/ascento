<!DOCTYPE html>
<html lang="es" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ascento — Software de gestión para empresas de mantenimiento de ascensores</title>
    <meta name="description" content="Ascento es la plataforma para administrar clientes, edificios, técnicos, órdenes de trabajo, mantenimientos e inspecciones de tu empresa de ascensores, todo en un solo lugar.">
    <meta name="theme-color" content="#12151C">

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
               <img src="{{ asset('images/logo.png') }}" alt="Ascento" class="rounded-md h-8 w-8 object-contain">
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
                            Organizá toda tu empresa de ascensores<span class="text-amber-500">.</span>
                            <span class="block text-ink/40">Desde un solo lugar.</span>
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
                                Probar gratis 15 días
                            </a>
                        </div>

                        <div class="mt-9 flex items-center gap-6 text-sm text-ink/50">
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
                            ['Órdenes en tiempo real', 'Cada trabajo se actualiza al instante, desde que se asigna hasta que se cierra.', 'M3 8H13M13 8L9 4M13 8L9 12'],
                            ['Técnicos siempre conectados', 'Tu equipo carga avances y fotos desde el celular, en el momento.', 'M8 2C5 2 2.5 4.5 2.5 7.5C2.5 11 8 14 8 14C8 14 13.5 11 13.5 7.5C13.5 4.5 11 2 8 2Z'],
                            ['Historial completo', 'Cada ascensor guarda su trazabilidad: mantenimientos, fallas y repuestos.', 'M3 3H13V13H3V3ZM3 6.5H13M6.5 6.5V13'],
                            ['Edificios organizados', 'Todos los edificios, con sus ascensores y contactos, siempre a mano.', 'M2 13V5.5L8 2L14 5.5V13H2ZM6 13V9H10V13'],
                            ['Mantenimientos mensuales', 'Programá y controlá los mantenimientos sin depender de la memoria de nadie.', 'M8 2V5M8 11V14M2 8H5M11 8H14'],
                            ['Reclamos organizados', 'Cada reclamo queda registrado, asignado y con seguimiento hasta resolverse.', 'M3 3H13V10H6L3 13V10H3V3Z'],
                            ['Clientes centralizados', 'Toda la información comercial y de contacto de tus clientes, en un lugar.', 'M8 8C9.7 8 11 6.7 11 5C11 3.3 9.7 2 8 2C6.3 2 5 3.3 5 5C5 6.7 6.3 8 8 8ZM3 14C3 11.2 5.2 9 8 9C10.8 9 13 11.2 13 14'],
                            ['Desde cualquier dispositivo', 'Computadora, tablet o celular: la misma información, siempre sincronizada.', 'M4 2H12V13H4V2ZM7 11.5H9'],
                        ];
                    @endphp

                    @foreach ($benefits as $i => $b)
                        <div data-reveal style="transition-delay: {{ $i * 60 }}ms"
                             class="group rounded-2xl bg-white border border-ink/10 p-6 shadow-card hover:shadow-cardHover hover:-translate-y-1 transition-all duration-300">
                            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-amber-100 text-amber-600 group-hover:bg-graphite group-hover:text-amber-500 transition-colors duration-300">
                                <svg width="18" height="18" viewBox="0 0 16 16" fill="none"><path d="{{ $b[2] }}" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </div>
                            <h3 class="mt-4 font-display font-semibold text-[15px]">{{ $b[0] }}</h3>
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
                    <div class="p-5 sm:p-8 grid sm:grid-cols-2 lg:grid-cols-5 gap-4">
                        <div class="rounded-2xl bg-paper border border-ink/10 p-5 lg:col-span-2">
                            <div class="text-xs font-semibold text-ink/40 uppercase tracking-wide">Clientes</div>
                            <div class="mt-4 space-y-3">
                                @foreach (['Consorcio Belgrano 880','Torre Puerto Norte','Edificio San Martín 210'] as $c)
                                <div class="flex items-center justify-between text-sm">
                                    <span class="text-ink/70">{{ $c }}</span>
                                    <span class="h-1.5 w-1.5 rounded-full bg-rail-500"></span>
                                </div>
                                @endforeach
                            </div>
                        </div>
                        <div class="rounded-2xl bg-paper border border-ink/10 p-5">
                            <div class="text-xs font-semibold text-ink/40 uppercase tracking-wide">Edificios</div>
                            <div class="mt-4 font-display text-3xl font-semibold">42</div>
                            <div class="mt-1 text-xs text-ink/45">activos este mes</div>
                        </div>
                        <div class="rounded-2xl bg-graphite text-white p-5">
                            <div class="text-xs font-semibold text-white/40 uppercase tracking-wide">Órdenes de trabajo</div>
                            <div class="mt-4 space-y-2.5">
                                <div class="flex items-center justify-between text-xs"><span>OT-1042 · Reparación cable</span><span class="text-amber-500">En curso</span></div>
                                <div class="flex items-center justify-between text-xs"><span>OT-1041 · Inspección anual</span><span class="text-white/40">Pendiente</span></div>
                                <div class="flex items-center justify-between text-xs"><span>OT-1039 · Mantenimiento</span><span class="text-emerald-400">Cerrada</span></div>
                            </div>
                        </div>
                        <div class="rounded-2xl bg-paper border border-ink/10 p-5">
                            <div class="text-xs font-semibold text-ink/40 uppercase tracking-wide">Técnicos</div>
                            <div class="mt-4 flex -space-x-2">
                                @for ($i = 0; $i < 5; $i++)
                                <span class="h-8 w-8 rounded-full bg-rail-100 border-2 border-white flex items-center justify-center text-[10px] font-semibold text-rail-600">T{{ $i+1 }}</span>
                                @endfor
                            </div>
                            <div class="mt-3 text-xs text-ink/45">6 en ruta ahora</div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        {{-- ============ PLANES ============ --}}
        <section id="planes" class="py-24 sm:py-32 bg-white border-y border-ink/10" data-floor="04">
            <div class="mx-auto max-w-7xl px-5 sm:px-8">
                <div data-reveal class="max-w-2xl mx-auto text-center">
                    <span class="font-mono text-xs tracking-widest text-amber-600 uppercase">Piso 04 · Planes</span>
                    <h2 class="mt-3 font-display font-semibold text-3xl sm:text-4xl tracking-tight">Un plan para cada tamaño de empresa.</h2>
                </div>

                <div class="mt-14 grid lg:grid-cols-3 gap-6 items-stretch">
                    <div data-reveal class="rounded-2xl border border-ink/10 bg-paper p-8 flex flex-col">
                        <h3 class="font-display font-semibold text-lg">Plan Inicial</h3>
                        <p class="mt-1.5 text-sm text-ink/55">Ideal para empresas pequeñas que están dando sus primeros pasos.</p>
                        <div class="mt-6 font-display text-3xl font-semibold">Desde $100.000<span class="text-base font-medium text-ink/40">/mes</span></div>
                        <ul class="mt-6 space-y-2.5 text-sm text-ink/65 flex-1">
                            <li class="flex gap-2"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" class="shrink-0 mt-0.5"><path d="M3 8.5L6.2 11.5L13 4.5" stroke="#2E4FBE" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>Hasta 15 edificios</li>
                            <li class="flex gap-2"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" class="shrink-0 mt-0.5"><path d="M3 8.5L6.2 11.5L13 4.5" stroke="#2E4FBE" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>3 técnicos incluidos</li>
                            <li class="flex gap-2"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" class="shrink-0 mt-0.5"><path d="M3 8.5L6.2 11.5L13 4.5" stroke="#2E4FBE" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>Órdenes y remitos digitales</li>
                        </ul>
                        <a href="#contacto" class="mt-8 text-center rounded-full border border-ink/15 font-semibold py-3 hover:border-ink/30 transition-colors">Contactar</a>
                    </div>

                    <div data-reveal style="transition-delay:.08s" class="relative rounded-2xl border-2 border-amber-500 bg-graphite text-white p-8 flex flex-col lg:-my-3 lg:py-11 shadow-cardHover">
                        <span class="absolute -top-3 left-8 rounded-full bg-amber-500 text-graphite text-xs font-bold px-3 py-1">Recomendado</span>
                        <h3 class="font-display font-semibold text-lg">Plan Profesional</h3>
                        <p class="mt-1.5 text-sm text-white/55">Para empresas en crecimiento que necesitan control total.</p>
                        <div class="mt-6 font-display text-3xl font-semibold">Desde $220.000<span class="text-base font-medium text-white/40">/mes</span></div>
                        <ul class="mt-6 space-y-2.5 text-sm text-white/70 flex-1">
                            <li class="flex gap-2"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" class="shrink-0 mt-0.5"><path d="M3 8.5L6.2 11.5L13 4.5" stroke="#FF6A1A" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>Edificios ilimitados</li>
                            <li class="flex gap-2"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" class="shrink-0 mt-0.5"><path d="M3 8.5L6.2 11.5L13 4.5" stroke="#FF6A1A" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>10 técnicos incluidos</li>
                            <li class="flex gap-2"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" class="shrink-0 mt-0.5"><path d="M3 8.5L6.2 11.5L13 4.5" stroke="#FF6A1A" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>Inspecciones y reclamos</li>
                            <li class="flex gap-2"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" class="shrink-0 mt-0.5"><path d="M3 8.5L6.2 11.5L13 4.5" stroke="#FF6A1A" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>Soporte prioritario</li>
                        </ul>
                        <a href="#contacto" class="mt-8 text-center rounded-full bg-amber-500 text-graphite font-semibold py-3 hover:bg-amber-400 transition-colors">Contactar</a>
                    </div>

                    <div data-reveal style="transition-delay:.16s" class="rounded-2xl border border-ink/10 bg-paper p-8 flex flex-col">
                        <h3 class="font-display font-semibold text-lg">Plan Empresarial</h3>
                        <p class="mt-1.5 text-sm text-ink/55">Para empresas con muchos técnicos y operación a gran escala.</p>
                        <div class="mt-6 font-display text-3xl font-semibold">Consultar</div>
                        <ul class="mt-6 space-y-2.5 text-sm text-ink/65 flex-1">
                            <li class="flex gap-2"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" class="shrink-0 mt-0.5"><path d="M3 8.5L6.2 11.5L13 4.5" stroke="#2E4FBE" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>Técnicos ilimitados</li>
                            <li class="flex gap-2"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" class="shrink-0 mt-0.5"><path d="M3 8.5L6.2 11.5L13 4.5" stroke="#2E4FBE" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>Integraciones a medida</li>
                            <li class="flex gap-2"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" class="shrink-0 mt-0.5"><path d="M3 8.5L6.2 11.5L13 4.5" stroke="#2E4FBE" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>Onboarding acompañado</li>
                        </ul>
                        <a href="#contacto" class="mt-8 text-center rounded-full border border-ink/15 font-semibold py-3 hover:border-ink/30 transition-colors">Contactar</a>
                    </div>
                </div>
            </div>
        </section>

        {{-- ============ PRUEBA GRATIS ============ --}}
        <section id="prueba-gratis" class="py-24 sm:py-28" data-floor="05">
            <div class="mx-auto max-w-5xl px-5 sm:px-8">
                <div data-reveal class="rounded-3xl bg-amber-500 px-8 py-14 sm:px-16 sm:py-16 text-center relative overflow-hidden">
                    <div class="absolute inset-0 opacity-[0.08] [background-image:repeating-linear-gradient(0deg,#12151C_0,#12151C_1px,transparent_1px,transparent_10px)]"></div>
                    <div class="relative">
                        <span class="font-mono text-xs tracking-widest text-graphite/70 uppercase">Piso 05 · Prueba gratuita</span>
                        <h2 class="mt-3 font-display font-semibold text-3xl sm:text-4xl tracking-tight text-graphite">Probá el sistema gratis durante 15 días.</h2>
                        <p class="mt-4 text-graphite/70 max-w-lg mx-auto">Sin tarjeta de crédito. Sin compromiso. Configurás tu empresa y empezás a operar el mismo día.</p>
                        <a href="{{ Route::has('login') ? route('login') : '/login' }}"
                           class="mt-8 inline-flex items-center gap-2 rounded-full bg-graphite text-white font-semibold px-7 py-3.5 hover:-translate-y-0.5 transition-transform duration-200">
                            Empezar prueba gratuita
                            <svg width="15" height="15" viewBox="0 0 16 16" fill="none"><path d="M3 8H13M13 8L9 4M13 8L9 12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </a>
                    </div>
                </div>
            </div>
        </section>

        {{-- ============ TESTIMONIO ============ --}}
        <section class="py-20">
            <div class="mx-auto max-w-3xl px-5 sm:px-8 text-center" data-reveal>
                <svg width="28" height="22" viewBox="0 0 28 22" fill="none" class="mx-auto text-amber-500"><path d="M0 22V13.8C0 6 4.6 1.2 11 0L12.4 3.4C8.4 4.6 6.2 7.4 6 11.2H11V22H0ZM16 22V13.8C16 6 20.6 1.2 27 0L28.4 3.4C24.4 4.6 22.2 7.4 22 11.2H27V22H16Z" fill="currentColor" opacity="0.85"/></svg>
                <p class="mt-6 font-display text-xl sm:text-2xl leading-snug text-ink/85">
                    Desde que implementamos Ascento redujimos muchísimo el tiempo administrativo y tenemos un mejor control de los mantenimientos de cada edificio.
                </p>
                <div class="mt-6 text-sm text-ink/50">Gerente de Operaciones — empresa de mantenimiento de ascensores</div>
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
                            ['¿Necesito instalar algo?', 'No. Ascento funciona directamente desde el navegador, tanto en la oficina como en el campo.'],
                            ['¿Puedo acceder desde el celular?', 'Sí. La plataforma está pensada mobile first, para que tus técnicos la usen desde cualquier teléfono.'],
                            ['¿Mis técnicos pueden usarlo?', 'Sí. Cada técnico tiene su propio acceso para ver sus órdenes, cargar avances y firmar remitos.'],
                            ['¿Se hace backup de la información?', 'Sí. Toda la información de clientes, edificios y trabajos se respalda de forma automática.'],
                            ['¿Puedo cancelar cuando quiera?', 'Sí. No hay permanencia mínima: podés dar de baja tu cuenta cuando lo necesites.'],
                            ['¿Incluye soporte?', 'Sí. Todos los planes incluyen soporte para ayudarte a poner en marcha tu operación.'],
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
                <a href="{{ Route::has('login') ? route('login') : '/login' }}"
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
            <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-10">
                <div>
                    <div class="flex items-center gap-2.5">
                        <img src="{{ asset('images/logo.png') }}" alt="Ascento" class="rounded-md h-7 w-7 object-contain">
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
                    <div class="text-xs font-semibold text-white/40 uppercase tracking-wide">Contacto</div>
                    <ul class="mt-4 space-y-2.5 text-sm">
                        <li><a href="mailto:hola@ascento.com" class="hover:text-white transition-colors">contacto@ascento.com</a></li>
                        <li><a href="tel:+541100000000" class="hover:text-white transition-colors">+54 11 0000-0000</a></li>
                        <li>Buenos Aires, Argentina</li>
                    </ul>
                </div>
                <div>
                    <div class="text-xs font-semibold text-white/40 uppercase tracking-wide">Seguinos</div>
                    <div class="mt-4 flex gap-3">
                        <a href="#" aria-label="LinkedIn" class="flex h-9 w-9 items-center justify-center rounded-full bg-white/5 hover:bg-white/10 transition-colors">
                            <svg width="15" height="15" viewBox="0 0 16 16" fill="none"><path d="M2 5H4.2V14H2V5ZM3.1 1.5C3.85 1.5 4.4 2.05 4.4 2.75C4.4 3.45 3.85 4 3.1 4C2.4 4 1.85 3.45 1.85 2.75C1.85 2.05 2.4 1.5 3.1 1.5ZM6.2 5H8.3V6.1C8.6 5.5 9.4 4.85 10.6 4.85C13 4.85 13.5 6.4 13.5 8.45V14H11.3V8.9C11.3 7.8 11 7.05 10 7.05C9.05 7.05 8.5 7.7 8.5 8.9V14H6.2V5Z" fill="currentColor"/></svg>
                        </a>
                        <a href="#" aria-label="Instagram" class="flex h-9 w-9 items-center justify-center rounded-full bg-white/5 hover:bg-white/10 transition-colors">
                            <svg width="15" height="15" viewBox="0 0 16 16" fill="none"><rect x="1.5" y="1.5" width="13" height="13" rx="4" stroke="currentColor" stroke-width="1.3"/><circle cx="8" cy="8" r="3.2" stroke="currentColor" stroke-width="1.3"/><circle cx="12" cy="4" r="0.9" fill="currentColor"/></svg>
                        </a>
                    </div>
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
    </script>
</body>
</html>

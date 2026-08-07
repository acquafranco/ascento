<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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


    <main class="max-w-3xl mx-auto px-6 py-14">

        <h1 class="text-3xl font-bold mb-2">Instrucciones para la Eliminación de Datos</h1>
        <p class="text-sm text-[#14171C]/50 mb-10">Última actualización: {{ now()->translatedFormat('d \d\e F \d\e Y') }}</p>

        <div class="prose prose-neutral max-w-none space-y-8">

            <section>
                <p class="text-[#14171C]/80 leading-relaxed">
                    Si querés que eliminemos tus datos personales de Ascento, incluidos los datos asociados a la
                    integración con WhatsApp Business, seguí alguno de los siguientes métodos.
                </p>
            </section>

            <section>
                <h2 class="text-xl font-semibold mb-2">Opción 1: Solicitud por correo electrónico</h2>
                <p class="text-[#14171C]/80 leading-relaxed">
                    Enviá un correo a
                    <a href="mailto:[email protected]" style="color:#FF6A1A;">[email protected]</a>
                    desde la dirección de correo asociada a tu cuenta, con el asunto "Eliminación de datos", e
                    indicando:
                </p>
                <ul class="list-disc pl-5 text-[#14171C]/80 leading-relaxed space-y-1 mt-2">
                    <li>Nombre completo</li>
                    <li>Correo electrónico registrado</li>
                    <li>Empresa (tenant) a la que pertenece tu cuenta</li>
                    <li>Número de WhatsApp, si solicitás eliminar datos de esa integración</li>
                </ul>
                <p class="text-[#14171C]/80 leading-relaxed mt-2">
                    Procesaremos la solicitud dentro de un plazo máximo de 15 días hábiles y te confirmaremos por
                    correo una vez completada la eliminación.
                </p>
            </section>

            <section>
                <h2 class="text-xl font-semibold mb-2">Opción 2: Desde tu cuenta</h2>
                <p class="text-[#14171C]/80 leading-relaxed">
                    Si tenés acceso a tu cuenta, podés solicitar la baja directamente desde
                    <strong>Perfil → Eliminar mi cuenta</strong>, o pedirle al administrador de tu empresa que
                    gestione la baja de tu usuario.
                </p>
            </section>

            <section>
                <h2 class="text-xl font-semibold mb-2">Qué se elimina</h2>
                <p class="text-[#14171C]/80 leading-relaxed">
                    Al procesar tu solicitud, eliminamos o anonimizamos: datos de perfil (nombre, correo, teléfono),
                    historial de mensajes de WhatsApp asociados a tu contacto, y cualquier dato personal identificable
                    que no debamos conservar por obligación legal o contable (por ejemplo, registros de facturación,
                    que se conservan por el plazo exigido por la normativa impositiva argentina).
                </p>
            </section>

            <section>
                <h2 class="text-xl font-semibold mb-2">Contacto</h2>
                <p class="text-[#14171C]/80 leading-relaxed">
                    Ante cualquier duda sobre este proceso, escribinos a
                    <a href="mailto:[email protected]" style="color:#FF6A1A;">[email protected]</a>.
                </p>
            </section>

        </div>
    </main>

    <footer class="border-t border-black/[0.06] py-8">
        <div class="max-w-3xl mx-auto px-6 flex flex-wrap gap-4 text-sm text-[#14171C]/50">
            <a href="{{ route('legal.terms') }}" class="hover:text-[#14171C]">Términos y Condiciones</a>
            <a href="{{ route('legal.privacy') }}" class="hover:text-[#14171C]">Política de Privacidad</a>
            <a href="{{ route('legal.data-deletion') }}" class="hover:text-[#14171C]">Eliminación de Datos</a>
        </div>
    </footer>

</body>
</html>

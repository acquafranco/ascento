<!DOCTYPE html>
<html lang="es">
 <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('partials.brand-head')
    <title>Ascento — Software de gestión para empresas de mantenimiento de ascensores</title>
    <meta name="description" content="Ascento es la plataforma para administrar clientes, edificios, técnicos, órdenes de trabajo, mantenimientos e inspecciones de tu empresa de ascensores, todo en un solo lugar.">

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
        body {
            -webkit-font-smoothing: antialiased;
        }

        ::selection {
            background: #FFE8D6;
            color: #14171C;
        }

        :focus-visible {
            outline: 2px solid #2E4FBE;
            outline-offset: 2px;
            border-radius: 4px;
        }

        input[type="checkbox"] {
            accent-color: #FF6A1A;
        }
    </style>
    {{-- Estilos y tipografías compilados con Vite (sin CDN). Al final del head, como el CDN:
         el orden frente a los estilos propios de la página no cambia. --}}
    @vite('resources/css/public.css')
</head>

<body class="bg-paper text-ink font-body antialiased min-h-screen">

    <div class="relative min-h-screen flex flex-col items-center justify-center px-5 py-12 overflow-hidden">

        <div class="pointer-events-none absolute inset-0 -z-10 [background:radial-gradient(55%_55%_at_85%_0%,rgba(255,106,26,0.08),transparent_60%),radial-gradient(45%_45%_at_5%_100%,rgba(46,79,190,0.07),transparent_60%)]"></div>

        {{-- Brand mark --}}
        <a href="/" class="flex items-center gap-2.5 mb-8">
            <img
                src="{{ asset('images/brand/logo-128.png') }}"
                alt="Ascento"
                class="rounded-md h-9 w-9 object-contain"
            >

            <span class="font-display font-semibold text-lg tracking-tight text-ink">
                Ascento
            </span>
        </a>

        {{-- Card --}}
        <div class="w-full max-w-lg rounded-3xl bg-white border border-ink/10 shadow-cardHover px-6 py-8 sm:px-10 sm:py-10">
            {{ $slot }}
        </div>

        <a href="/" class="mt-8 text-xs font-medium text-ink/40 hover:text-ink/70 transition-colors flex items-center gap-1.5">
            <svg width="12" height="12" viewBox="0 0 16 16" fill="none">
                <path
                    d="M13 8H3M3 8L7 4M3 8L7 12"
                    stroke="currentColor"
                    stroke-width="1.6"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                />
            </svg>
            Volver al inicio
        </a>

        {{-- Contacto con Ascento: no en las pantallas del portal (el cliente habla con su empresa de ascensores). --}}
        @unless(request()->routeIs('portal.*'))
            <button type="button" data-whatsapp-open class="mt-3 text-xs font-medium text-ink/40 hover:text-ink/70 transition-colors">¿Necesitás ayuda? Escribinos por WhatsApp</button>
        @endunless

    </div>

    @unless(request()->routeIs('portal.*'))
        @include('partials.whatsapp-contact', ['launcher' => false])
    @endunless
    @include('partials.cookie-notice')
</body>
</html>

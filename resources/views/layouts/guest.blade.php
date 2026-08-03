<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Ascento') }}</title>

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
    <style>
        body { -webkit-font-smoothing: antialiased; }
        ::selection { background: #FFE8D6; color: #14171C; }
        :focus-visible { outline: 2px solid #2E4FBE; outline-offset: 2px; border-radius: 4px; }
        input[type="checkbox"] { accent-color: #FF6A1A; }
    </style>
</head>
<body class="bg-paper text-ink font-body antialiased min-h-screen">

    <div class="relative min-h-screen flex flex-col items-center justify-center px-5 py-12 overflow-hidden">
        <div class="pointer-events-none absolute inset-0 -z-10 [background:radial-gradient(55%_55%_at_85%_0%,rgba(255,106,26,0.08),transparent_60%),radial-gradient(45%_45%_at_5%_100%,rgba(46,79,190,0.07),transparent_60%)]"></div>

        {{-- Brand mark --}}
       <a href="/" class="flex items-center gap-2.5 mb-8">
            <img src="{{ asset('images/logo.png') }}" alt="Ascento" class="rounded-md h-9 w-9 object-contain">
            <span class="font-display font-semibold text-lg tracking-tight text-ink">Ascento</span>
        </a>

        {{-- Card --}}
        <div class="w-full max-w-lg rounded-3xl bg-white border border-ink/10 shadow-cardHover px-6 py-8 sm:px-10 sm:py-10">
            {{ $slot }}
        </div>

        <a href="/" class="mt-8 text-xs font-medium text-ink/40 hover:text-ink/70 transition-colors flex items-center gap-1.5">
            <svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M13 8H3M3 8L7 4M3 8L7 12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Volver al inicio
        </a>
    </div>
</body>
</html>

{{--
    Plantilla común de las páginas legales: mismo encabezado, la misma
    identidad visual y el mismo pie en términos, privacidad, cookies y
    eliminación de datos. Los datos del titular salen de config/legal.php
    (nunca se inventan: si faltan, se muestran como pendientes).

    Cada página define: title, description, kicker, heading, lead, active
    (terms | privacy | cookies | data-deletion) y content.
--}}
@php
    $legal = config('legal');
    $email = $legal['email'];
    $pending = fn (?string $value, string $label) => filled($value) ? e($value) : '<span class="rounded bg-[#FFF4EC] px-1.5 text-[#7A3300]">['.e($label).': pendiente de completar]</span>';
    $links = [
        'terms' => ['Términos y Condiciones', route('legal.terms')],
        'privacy' => ['Privacidad', route('legal.privacy')],
        'cookies' => ['Cookies', route('legal.cookies')],
        'data-deletion' => ['Eliminación de datos', route('legal.data-deletion')],
    ];
    $active = trim($__env->yieldContent('active'));
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    @include('partials.brand-head')
    <title>@yield('title') — Ascento</title>
    <meta name="description" content="@yield('description')">
    {{-- Estilos y scripts propios (sin CDN): resources/css/public.css --}}
    @vite(['resources/css/public.css', 'resources/js/public.js'])
    <style>
        html { scrollbar-gutter: stable; }
        ::selection { background: #FFE8D6; color: #14171C; }
        :focus-visible { outline: 2px solid #2E4FBE; outline-offset: 2px; border-radius: 4px; }
        [x-cloak] { display: none !important; }
        @media print { header, footer, .no-print { display: none !important; } main { padding-top: 0 !important; } }
    </style>
</head>
<body class="bg-[#F7F7F4] text-[#14171C] font-body antialiased">

<header x-data="{ open: false, scrolled: false }" @scroll.window="scrolled = window.scrollY > 12"
        class="fixed inset-x-0 top-0 z-50 transition-all duration-300"
        :class="scrolled ? 'bg-[#F7F7F4]/90 backdrop-blur-xl border-b border-[#14171C]/10' : 'bg-[#F7F7F4]'">
    <nav class="mx-auto max-w-7xl px-5 sm:px-8 h-16 flex items-center justify-between" aria-label="Principal">
        <a href="/" class="flex items-center gap-2.5 shrink-0">
            <img src="{{ asset('images/brand/logo-128.png') }}" alt="" class="h-8 w-8 rounded-md object-contain">
            <span class="font-display font-semibold text-[17px] tracking-tight">Ascento</span>
        </a>

        <div class="hidden lg:flex items-center gap-7 text-sm font-medium text-[#14171C]/65">
            <a href="/" class="hover:text-[#14171C] transition-colors">Inicio</a>
            @foreach($links as $key => [$label, $url])
                <a href="{{ $url }}" @class(['transition-colors', 'text-[#14171C]' => $active === $key, 'hover:text-[#14171C]' => $active !== $key]) @if($active === $key) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </div>

        <div class="hidden lg:flex">
            <a href="{{ Route::has('login') ? route('login') : '/login' }}"
               class="inline-flex items-center gap-2 rounded-full bg-[#12151C] text-white text-sm font-semibold px-4 py-2.5 shadow-sm hover:-translate-y-0.5 transition-all">
                Entrar
                <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M3 8H13M13 8L9 4M13 8L9 12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>
        </div>

        <button type="button" @click="open = !open" class="lg:hidden p-2 -mr-2" :aria-expanded="open.toString()" aria-label="Abrir menú">
            <svg x-show="!open" width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 7H20M4 12H20M4 17H20" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
            <svg x-show="open" x-cloak width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 6L18 18M6 18L18 6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
        </button>
    </nav>

    <div x-show="open" x-cloak x-transition class="lg:hidden bg-[#F7F7F4] border-b border-[#14171C]/10 px-5 pb-5 pt-2">
        <div class="flex flex-col text-[15px] font-medium">
            <a href="/" class="py-3 text-[#14171C]/70">Inicio</a>
            @foreach($links as $key => [$label, $url])
                <a href="{{ $url }}" class="py-3 {{ $active === $key ? 'text-[#14171C]' : 'text-[#14171C]/70' }}">{{ $label }}</a>
            @endforeach
            <a href="{{ Route::has('login') ? route('login') : '/login' }}" class="mt-2 text-center rounded-full bg-[#12151C] text-white py-3 font-semibold">Entrar</a>
        </div>
    </div>
</header>

<main class="pt-16">
    <section class="border-b border-[#14171C]/[0.06]">
        <div class="max-w-4xl mx-auto px-6 py-16 sm:py-20">
            <div class="inline-flex items-center gap-2 rounded-full bg-[#FFE8D6] text-[#C24800] px-3 py-1.5 text-xs font-semibold uppercase tracking-wider">@yield('kicker')</div>
            <h1 class="mt-5 font-display text-4xl sm:text-5xl font-semibold tracking-tight">@yield('heading')</h1>
            <p class="mt-5 max-w-2xl text-base sm:text-lg leading-relaxed text-[#14171C]/60">@yield('lead')</p>
            <p class="mt-6 text-sm text-[#14171C]/45">
                Versión vigente desde el {{ \Carbon\Carbon::parse($legal['updated_at'])->locale('es')->translatedFormat('j \d\e F \d\e Y') }}
            </p>
            @unless($legal['reviewed'])
                <p class="mt-4 max-w-2xl rounded-xl border border-[#FFC9A3] bg-[#FFF4EC] px-4 py-3 text-sm text-[#7A3300]" data-legal-draft>
                    <strong>Borrador pendiente de revisión legal.</strong> Describe cómo funciona Ascento hoy, verificado en el sistema, pero todavía no fue revisado por un abogado.
                </p>
            @endunless
        </div>
    </section>

    <section class="max-w-4xl mx-auto px-6 py-14 sm:py-16">
        <div class="space-y-12">
            @yield('content')

            <section id="contacto" class="rounded-3xl bg-[#12151C] text-white p-7 sm:p-9">
                <span class="font-mono text-xs tracking-widest text-[#FF8A3D] uppercase">Contacto</span>
                <h2 class="mt-2 font-display text-2xl font-semibold">¿Tenés una consulta?</h2>
                <ul class="mt-4 space-y-1.5 text-white/70 leading-7">
                    <li><strong class="text-white">Titular de Ascento:</strong> {!! $pending($legal['owner_name'], 'Razón social') !!}</li>
                    <li><strong class="text-white">CUIT:</strong> {!! $pending($legal['owner_tax_id'], 'CUIT') !!}</li>
                    <li><strong class="text-white">Domicilio:</strong> {!! $pending($legal['owner_address'], 'Domicilio') !!}</li>
                    <li><strong class="text-white">Correo:</strong> <a href="mailto:{{ $email }}" class="text-[#FF8A3D] font-semibold hover:text-white transition-colors">{{ $email }}</a></li>
                </ul>
            </section>
        </div>
    </section>
</main>

<footer class="border-t border-black/[0.06] py-8">
    <div class="max-w-4xl mx-auto px-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <p class="text-sm text-[#14171C]/40">© {{ date('Y') }} Ascento.</p>
        <nav class="flex flex-wrap gap-x-5 gap-y-2 text-sm text-[#14171C]/50" aria-label="Documentos legales">
            @foreach($links as $key => [$label, $url])
                <a href="{{ $url }}" class="{{ $active === $key ? 'text-[#14171C]' : 'hover:text-[#14171C]' }}">{{ $label }}</a>
            @endforeach
        </nav>
    </div>
</footer>

@include('partials.cookie-notice')
</body>
</html>

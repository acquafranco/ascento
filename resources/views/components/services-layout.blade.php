@props(['title' => 'Profesionales y servicios', 'description' => 'Especialistas en ascensores para empresas de mantenimiento: reparación de placas, variadores, asistencia técnica, inspecciones y más.'])
{{-- Páginas públicas de «Profesionales y servicios» (sin cuenta). Estilos propios, prefijo p-. --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('partials.brand-head')
    <title>{{ $title }} · Ascento</title>
    <meta name="description" content="{{ $description }}">
    <style>
        :root { --dark: #11161D; --orange: #FF6600; --orange-ink: #B34700; --light: #F7F7F4; --line: #E4E2DA; --ink: #11161D; --muted: #5F6472; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; background: var(--light); color: var(--ink); line-height: 1.5; -webkit-font-smoothing: antialiased; }
        a { color: inherit; }
        :focus-visible { outline: 3px solid var(--orange); outline-offset: 2px; }
        .p-top { background: var(--dark); color: #fff; }
        .p-in { max-width: 1080px; margin: 0 auto; padding: 0 16px; }
        .p-bar { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 0; flex-wrap: wrap; }
        .p-brand { display: flex; align-items: center; gap: 10px; text-decoration: none; }
        .p-brand img { width: 32px; height: 32px; }
        .p-brand b { font-size: 15px; }
        .p-nav { display: flex; gap: 4px; flex-wrap: wrap; }
        .p-nav a { color: #C9CCD4; text-decoration: none; font-size: 14px; padding: 8px 10px; border-radius: 8px; }
        .p-nav a[aria-current] { color: #fff; background: rgba(255,255,255,.08); font-weight: 600; }
        .p-nav a.p-cta { background: var(--orange); color: var(--dark); font-weight: 700; }
        .p-hero { padding: 26px 0 30px; }
        .p-hero h1 { font-size: 27px; margin: 0 0 6px; letter-spacing: -.01em; }
        .p-hero p { color: #C9CCD4; margin: 0; max-width: 680px; font-size: 15px; }
        main { padding: 22px 0 56px; }
        .p-card { background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 16px; }
        .p-grid { display: grid; gap: 14px; grid-template-columns: repeat(auto-fill, minmax(min(100%, 300px), 1fr)); }
        .p-filters { display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(min(100%, 170px), 1fr)); align-items: end; }
        .p-filters label { display: block; font-size: 12px; font-weight: 600; color: #3D4250; margin-bottom: 3px; }
        .p-input { width: 100%; border: 1px solid #CFCCC2; border-radius: 10px; padding: 9px 11px; font: inherit; font-size: 15px; background: #fff; color: var(--ink); }
        .p-btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; background: var(--orange); color: var(--dark); border: 0; border-radius: 10px; padding: 10px 15px; font: inherit; font-weight: 700; font-size: 14px; cursor: pointer; text-decoration: none; min-height: 42px; }
        .p-btn.secondary { background: #fff; border: 1px solid #D3D1C8; font-weight: 600; }
        .p-muted { color: var(--muted); font-size: 13px; }
        .p-tag { display: inline-block; font-size: 12px; padding: 2px 9px; border-radius: 999px; background: #EEF0F4; color: #3D4250; font-weight: 600; margin: 0 4px 4px 0; }
        .p-tag.o { background: #FFF1E6; color: #A84300; }
        .p-name { font-size: 17px; font-weight: 700; text-decoration: none; display: block; margin: 0 0 2px; }
        .p-name:hover { text-decoration: underline; }
        .p-declared { border: 1px dashed #D3D1C8; border-radius: 10px; padding: 10px 12px; font-size: 13px; color: var(--muted); background: #FBFAF7; }
        .p-two { display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(min(100%, 320px), 1fr)); align-items: start; }
        .p-dl { display: grid; grid-template-columns: max-content 1fr; gap: 6px 14px; font-size: 14px; margin: 0; }
        .p-dl dt { color: var(--muted); } .p-dl dd { margin: 0; }
        .p-pages { display: flex; gap: 8px; justify-content: center; align-items: center; margin-top: 18px; flex-wrap: wrap; }
        .p-foot { text-align: center; font-size: 12px; color: #8C8F98; padding: 0 16px 30px; }
        .p-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
        @media (prefers-reduced-motion: reduce) { * { transition: none !important; animation: none !important; } }
    </style>
</head>
<body>
    <a href="#contenido" style="position:absolute;left:-9999px" onfocus="this.style.left='8px'">Saltar al contenido</a>
    <header class="p-top">
        <div class="p-in">
            <div class="p-bar">
                <a class="p-brand" href="{{ url('/') }}"><img src="{{ asset('images/brand/logo-64.png') }}" alt=""><b>Ascento</b></a>
                <nav class="p-nav" aria-label="Marketplace">
                    <a href="{{ route('services.index') }}" @if(request()->routeIs('services.*')) aria-current="page" @endif>Profesionales y servicios</a>
                    <a href="/admin/repuestos">Repuestos (empresas)</a>
                    <a class="p-cta" href="{{ route('suppliers.register', ['ofrezco' => 'services']) }}">Ofrecé tus servicios</a>
                </nav>
            </div>
            {{ $hero ?? '' }}
        </div>
    </header>
    <main id="contenido"><div class="p-in">{{ $slot }}</div></main>
    <p class="p-foot">Ascento conecta empresas de mantenimiento con profesionales; no participa de la contratación ni procesa pagos entre ellos. La información de cada perfil la declara su titular.
        · <a href="{{ route('legal.terms') }}#profesionales">Términos</a> · <a href="{{ route('legal.privacy') }}">Privacidad</a></p>
</body>
</html>

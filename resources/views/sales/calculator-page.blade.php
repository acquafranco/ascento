{{-- Calculadora como página propia: sin JavaScript, o para compartir el enlace con los datos. --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Calculá tu plan · Ascento</title>
    @include('partials.brand-head')
    @vite(['resources/css/public.css'])
</head>
<body class="bg-paper font-sans text-ink antialiased">
    <header class="border-b border-ink/10 bg-white">
        <div class="mx-auto flex max-w-6xl items-center justify-between px-5 py-4 sm:px-8">
            <a href="{{ url('/') }}" class="font-display text-lg font-semibold">Ascento</a>
            <a href="{{ route('register') }}" class="rounded-full bg-amber-500 px-4 py-2 text-sm font-semibold text-graphite">Probar gratis</a>
        </div>
    </header>
    @include('sales.partials.section', ['old' => $old ?? []])
    @if(! empty($errors) && $errors->any() && ! isset($advice))
        <div class="mx-auto max-w-4xl px-5 pb-10">@include('sales.partials.errors')</div>
    @endif
</body>
</html>

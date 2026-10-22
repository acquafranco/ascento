<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pedido recibido · Ascento</title>
    @include('partials.brand-head')
    @vite(['resources/css/public.css'])
</head>
<body class="bg-paper font-sans text-ink antialiased">
    <main class="mx-auto max-w-xl px-5 py-16">
        @include('sales.partials.lead-done')
        <p class="mt-6 text-center"><a href="{{ url('/') }}" class="font-semibold text-amber-700 underline">Volver a Ascento</a> · <a href="{{ route('register') }}" class="font-semibold text-amber-700 underline">Probar gratis</a></p>
    </main>
</body>
</html>

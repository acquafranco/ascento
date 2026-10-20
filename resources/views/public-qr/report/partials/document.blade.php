{{-- Documento HTML común de las pantallas públicas del reporte. --}}
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <meta name="theme-color" content="#11161D">
    <title>{{ $title }} · {{ $buildingName ?? 'Ascensor' }}</title>
    @include('public-qr.partials.styles')
    <style>body { margin: 0; background: #F4F5F7; }</style>
</head>
<body>
<div class="qr-wrap" data-qr-report>
    @include('public-qr.report.partials.head')
    {!! $slot !!}
    @include('public-qr.partials.footer')
</div>
</body>
</html>

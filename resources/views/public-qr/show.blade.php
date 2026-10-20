<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ $info['building']['name'] }} · Información del ascensor</title>
    @include('public-qr.partials.styles')
    <style>body { margin: 0; background: #F4F5F7; }</style>
</head>
<body>
    @include('public-qr.partials.content', ['info' => $info, 'report' => $report ?? null])
</body>
</html>

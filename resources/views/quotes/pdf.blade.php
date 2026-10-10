<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Presupuesto {{ $quote->numberLabel() }}</title>
    <style>
        @page { margin: 28px 32px; }
        body { margin: 0; }
    </style>
</head>
<body>
    @include('quotes.document', ['quote' => $quote, 'pdf' => true])
</body>
</html>

{{-- Presupuesto que abre el cliente por enlace firmado (ver QuoteDocumentController). --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    @include('partials.brand-head')
    <meta name="robots" content="noindex, nofollow">
    <title>Presupuesto {{ $quote->numberLabel() }} · {{ $quote->company?->name }}</title>
    <style>
        body { margin: 0; background: #F7F7F4; }
        .q-wrap { max-width: 860px; margin: 0 auto; padding: 24px 16px 48px; }
        .q-card { background: #fff; border: 1px solid #E6E4DC; border-radius: 18px; padding: 28px; box-shadow: 0 10px 30px -18px rgba(18,21,28,.35); }
        .q-actions { display: flex; flex-wrap: wrap; gap: 10px; margin: 16px 0 0; font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; }
        .q-btn { display: inline-flex; align-items: center; gap: 6px; padding: 11px 16px; border-radius: 12px; font-weight: 700; font-size: 15px; text-decoration: none; }
        .q-btn.primary { background: #FF6A1A; color: #12151C; }
        .q-btn.secondary { background: #fff; color: #1F2430; border: 1px solid #E6E4DC; }
        @media (max-width: 600px) { .q-card { padding: 18px; border-radius: 14px; } }
        @media print { .q-actions { display: none; } .q-card { border: 0; box-shadow: none; } body { background: #fff; } }
    </style>
</head>
<body>
    <main class="q-wrap">
        <div class="q-card">
            @include('quotes.document', ['quote' => $quote])
        </div>
        <div class="q-actions">
            <a class="q-btn primary" href="{{ $pdfUrl }}" target="_blank" rel="noopener">⬇ Descargar PDF</a>
            @if($quote->company?->phone)
                <a class="q-btn secondary" href="tel:{{ preg_replace('/[^\d+]/', '', $quote->company->phone) }}">📞 Llamar a {{ $quote->company->name }}</a>
            @endif
            @if($quote->company?->email)
                <a class="q-btn secondary" href="mailto:{{ $quote->company->email }}?subject={{ rawurlencode('Presupuesto '.$quote->numberLabel()) }}">✉️ Consultar por correo</a>
            @endif
        </div>
    </main>
</body>
</html>

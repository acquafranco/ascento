{{--
    Hoja del QR para pegar en el ascensor (A4 o compacta A6). Todo embebido: el
    código (SVG) y los logos (data:), sin imágenes remotas. Nada se superpone al
    código y conserva su margen libre. La empresa es la protagonista; Ascento
    figura como plataforma, al pie.
--}}
@php
    $compact = $size === 'compacta';
@endphp
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>QR · {{ $building->name }}</title>
    <style>
        @page { size: {{ $compact ? 'A6' : 'A4' }} portrait; margin: {{ $compact ? '6mm' : '12mm' }}; }
        * { box-sizing: border-box; }
        html, body { margin: 0; background: #E6E8EC; color: #11161D; font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .tools { max-width: {{ $compact ? '105mm' : '210mm' }}; margin: 12px auto; display: flex; gap: 8px; justify-content: flex-end; }
        .tools button, .tools a { font: inherit; font-size: 14px; padding: 8px 14px; border-radius: 8px; border: 1px solid #A6ACB8; background: #fff; color: #11161D; cursor: pointer; text-decoration: none; }
        .tools button { background: #11161D; color: #fff; border-color: #11161D; }
        .sheet { width: {{ $compact ? '93mm' : '186mm' }}; min-height: {{ $compact ? '136mm' : '273mm' }}; margin: 0 auto 24px; background: #fff; display: flex; flex-direction: column; align-items: center; text-align: center; padding: {{ $compact ? '5mm' : '10mm' }}; border-top: {{ $compact ? '2mm' : '4mm' }} solid #11161D; }
        .company { display: flex; align-items: center; justify-content: center; gap: {{ $compact ? '3mm' : '5mm' }}; max-width: 100%; }
        .company img { height: {{ $compact ? '12mm' : '22mm' }}; width: auto; max-width: {{ $compact ? '30mm' : '55mm' }}; object-fit: contain; }
        .company-text { text-align: left; min-width: 0; }
        .company-kicker { font-size: {{ $compact ? '7pt' : '10pt' }}; color: #5B6472; letter-spacing: .04em; text-transform: uppercase; margin: 0; }
        .company-name { font-size: {{ $compact ? '12pt' : '20pt' }}; font-weight: 800; margin: 0; line-height: 1.15; overflow-wrap: anywhere; }
        h1 { font-size: {{ $compact ? '14pt' : '26pt' }}; margin: {{ $compact ? '4mm 0 1mm' : '10mm 0 2mm' }}; line-height: 1.15; }
        .lead { font-size: {{ $compact ? '9pt' : '14pt' }}; margin: 0; color: #2A313C; }
        .code { width: {{ $compact ? '68mm' : '118mm' }}; margin: {{ $compact ? '4mm 0' : '8mm 0' }}; padding: {{ $compact ? '2mm' : '4mm' }}; background: #fff; border: 0.4mm solid #E6E8EC; border-radius: 3mm; }
        .code svg { display: block; width: 100%; height: auto; }
        .building { font-size: {{ $compact ? '10pt' : '15pt' }}; font-weight: 700; margin: 0; overflow-wrap: anywhere; }
        .address { font-size: {{ $compact ? '8pt' : '11pt' }}; color: #5B6472; margin: 1mm 0 0; }
        .help { margin-top: {{ $compact ? '4mm' : '8mm' }}; border: 0.6mm solid #b91c1c; border-radius: 3mm; padding: {{ $compact ? '2.5mm 3mm' : '4mm 6mm' }}; font-size: {{ $compact ? '8pt' : '12pt' }}; line-height: 1.4; max-width: {{ $compact ? '86mm' : '150mm' }}; }
        .help strong { color: #b91c1c; }
        .safety { margin-top: {{ $compact ? '2mm' : '4mm' }}; font-size: {{ $compact ? '7pt' : '10pt' }}; color: #5B6472; max-width: {{ $compact ? '86mm' : '150mm' }}; }
        .foot { margin-top: auto; padding-top: {{ $compact ? '3mm' : '8mm' }}; display: flex; align-items: center; gap: 2mm; font-size: {{ $compact ? '7pt' : '10pt' }}; color: #5B6472; }
        .foot img { height: {{ $compact ? '5mm' : '8mm' }}; width: auto; }
        .foot strong { color: #11161D; }
        @media print { html, body { background: #fff; } .tools { display: none; } .sheet { margin: 0; } }
        @media screen and (max-width: 820px) { .sheet { width: 100%; min-height: 0; } }
    </style>
</head>
<body>
    <div class="tools">
        <a href="{{ route('public-qr.sheet', ['building' => $building->id, 'size' => $compact ? 'a4' : 'compacta']) }}">{{ $compact ? 'Ver en A4' : 'Ver versión compacta' }}</a>
        <button type="button" onclick="window.print()">Imprimir</button>
    </div>

    <main class="sheet" data-qr-sheet="{{ $size }}">
        <div class="company" data-sheet="company">
            @if($companyLogo)
                <img src="{{ $companyLogo }}" alt="Logo de {{ $company?->name }}">
            @endif
            <div class="company-text">
                <p class="company-kicker">Mantenimiento a cargo de</p>
                <p class="company-name">{{ $company?->name }}</p>
            </div>
        </div>

        <h1>Información de este ascensor</h1>
        <p class="lead">Escaneá el código con la cámara de tu celular.</p>

        <div class="code" data-sheet="code" role="img" aria-label="Código QR del edificio">{!! $qr !!}</div>

        <p class="building">{{ $building->name }}</p>
        @if($address = trim(collect([$building->address, $building->locality])->filter()->implode(', ')))
            <p class="address">{{ $address }}</p>
        @endif

        @if($help)
            <p class="help" data-sheet="help"><strong>¿Quedaste atrapado?</strong> Escaneá el código y tocá «Estoy atrapado en el ascensor» para avisar a la empresa de mantenimiento y a la administración.</p>
        @endif
        <p class="safety">En una emergencia, usá el botón de alarma o el intercomunicador del ascensor. Si hay peligro inmediato, llamá al 911.</p>

        <div class="foot" data-sheet="ascento">
            @if($ascentoLogo)<img src="{{ $ascentoLogo }}" alt="Ascento">@endif
            <span>Funciona con <strong>Ascento</strong></span>
        </div>
    </main>
</body>
</html>

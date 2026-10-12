{{--
    Presupuesto como documento comercial para el PDF: tablas y estilos en
    línea (dompdf no entiende flex ni grid). En pantalla (enlace del cliente,
    portal y vista del admin) se usa quotes.partials.web, con los mismos datos
    (App\Support\Quotes\QuoteDocument). Solo muestra los datos de la empresa
    que estén cargados; no inventa datos fiscales ni condiciones.
    $quote (con items, company, client, building), $pdf (bool).
--}}
@php
    $pdf = $pdf ?? false;
    $data = \App\Support\Quotes\QuoteDocument::for($quote, $pdf);
    $company = $data['company'];
    $money = fn ($v) => \App\Support\Quotes\QuoteDocument::money($v);
    $qty = fn ($v) => \App\Support\Quotes\QuoteDocument::quantity($v);
    $status = $data['status'];
    $statusStyle = $data['status_style'];
    $logo = $data['logo'];
    $issuer = $data['issuer'];
    $building = $data['building'];
    $font = $pdf ? "'DejaVu Sans', sans-serif" : "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif";
    // dompdf solo tiene regular y bold de DejaVu: 600/700/800 → bold (si no, cae en una fuente con serif).
    $w = fn (int $weight) => $pdf ? 'bold' : (string) $weight;
@endphp
<div style="font-family: {{ $font }}; color: #1F2430; font-size: {{ $pdf ? '11px' : '14px' }}; line-height: 1.45; background: #fff;">
    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="vertical-align: top; padding: 0 0 14px;">
                @if($logo)
                    <img src="{{ $logo }}" alt="{{ $company->name }}" style="max-height: 56px; max-width: 180px;">
                    <div style="font-weight: {{ $w(700) }}; font-size: {{ $pdf ? '13px' : '16px' }}; margin-top: 6px;">{{ $company->name }}</div>
                @else
                    <div style="font-weight: {{ $w(800) }}; font-size: {{ $pdf ? '17px' : '22px' }}; letter-spacing: -.01em;">{{ $company?->name }}</div>
                @endif
                @foreach($issuer as $line)
                    <div style="color: #6B7080; font-size: {{ $pdf ? '10px' : '13px' }};">{{ $line }}</div>
                @endforeach
            </td>
            <td style="vertical-align: top; text-align: right; padding: 0 0 14px;">
                <div style="font-size: {{ $pdf ? '10px' : '12px' }}; letter-spacing: .12em; color: #C24800; font-weight: {{ $w(700) }};">PRESUPUESTO</div>
                <div style="font-size: {{ $pdf ? '18px' : '24px' }}; font-weight: {{ $w(800) }};">{{ $quote->numberLabel() }}</div>
                <div style="color: #6B7080;">Fecha: {{ $quote->issued_at?->format('d/m/Y') }}</div>
                @if($quote->valid_until)<div style="color: #6B7080;">Válido hasta: <strong style="color:#1F2430">{{ $quote->valid_until->format('d/m/Y') }}</strong></div>@endif
                <div style="margin-top: 6px;"><span style="display: inline-block; padding: 2px 10px; border-radius: 999px; font-weight: {{ $w(700) }}; font-size: {{ $pdf ? '10px' : '12px' }}; {{ $statusStyle }}">{{ $data['status_label'] }}</span></div>
            </td>
        </tr>
    </table>

    <table style="width: 100%; border-collapse: collapse; background: #F7F7F4; border-radius: 10px;">
        <tr>
            <td style="vertical-align: top; padding: 12px 14px; width: 50%;">
                <div style="font-size: {{ $pdf ? '9px' : '11px' }}; letter-spacing: .1em; color: #6B7080; font-weight: {{ $w(700) }};">CLIENTE</div>
                <div style="font-weight: {{ $w(700) }};">{{ $quote->client?->name ?? $building?->client?->name ?? '—' }}</div>
                @if($quote->client?->contact_person)<div style="color:#6B7080">At. {{ $quote->client->contact_person }}</div>@endif
            </td>
            <td style="vertical-align: top; padding: 12px 14px;">
                <div style="font-size: {{ $pdf ? '9px' : '11px' }}; letter-spacing: .1em; color: #6B7080; font-weight: {{ $w(700) }};">EDIFICIO</div>
                <div style="font-weight: {{ $w(700) }};">{{ $building?->name ?? '—' }}</div>
                <div style="color:#6B7080">{{ trim(($building?->address ?? '').' '.($building?->locality ?? '')) }}{{ $quote->unit ? ' · '.$quote->unit : '' }}</div>
            </td>
        </tr>
    </table>

    <h2 style="font-size: {{ $pdf ? '14px' : '19px' }}; margin: 18px 0 4px;">{{ $quote->title }}</h2>
    @if(filled($quote->description))
        <div style="white-space: pre-line; color: #3D4250;">{{ $quote->description }}</div>
    @endif

    <table style="width: 100%; border-collapse: collapse; margin-top: 14px;">
        <thead>
            <tr style="background: #12151C; color: #F7F7F4;">
                <th style="text-align: left; padding: 8px 10px; font-weight: {{ $w(600) }};">Concepto</th>
                <th style="text-align: right; padding: 8px 10px; font-weight: {{ $w(600) }}; width: 12%;">Cant.</th>
                <th style="text-align: right; padding: 8px 10px; font-weight: {{ $w(600) }}; width: 22%;">Precio unit.</th>
                <th style="text-align: right; padding: 8px 10px; font-weight: {{ $w(600) }}; width: 22%;">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @forelse($quote->items as $item)
                <tr style="border-bottom: 1px solid #ECEAE3;">
                    <td style="padding: 9px 10px; vertical-align: top;">
                        <div style="font-weight: {{ $w(600) }};">{{ $item->concept }}</div>
                        @if($item->description)<div style="color: #6B7080; font-size: {{ $pdf ? '10px' : '13px' }};">{{ $item->description }}</div>@endif
                    </td>
                    <td style="padding: 9px 10px; text-align: right; vertical-align: top;">{{ $qty($item->quantity) }}</td>
                    <td style="padding: 9px 10px; text-align: right; vertical-align: top;">{{ $money($item->unit_price) }}</td>
                    <td style="padding: 9px 10px; text-align: right; vertical-align: top; font-weight: {{ $w(600) }};">{{ $money($item->subtotal) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" style="padding: 10px; color: #6B7080;">{{ $quote->title }}</td></tr>
            @endforelse
        </tbody>
    </table>

    <table style="width: 100%; border-collapse: collapse; margin-top: 10px;">
        <tr>
            <td></td>
            <td style="width: 46%; padding: 12px 14px; background: #12151C; color: #F7F7F4; border-radius: 10px;">
                <table style="width: 100%; border-collapse: collapse;">
                    <tr>
                        <td style="font-weight: {{ $w(600) }}; color: #F7F7F4;">TOTAL</td>
                        <td style="text-align: right; font-size: {{ $pdf ? '16px' : '22px' }}; font-weight: {{ $w(800) }}; color: #FF6A1A;">{{ $money($quote->amount) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
    <div style="text-align: right; color: #6B7080; font-size: {{ $pdf ? '9px' : '12px' }}; margin-top: 4px;">Importes en pesos argentinos.</div>

    @if(filled($quote->conditions))
        <div style="margin-top: 18px;">
            <div style="font-size: {{ $pdf ? '9px' : '11px' }}; letter-spacing: .1em; color: #6B7080; font-weight: {{ $w(700) }};">CONDICIONES</div>
            <div style="white-space: pre-line; margin-top: 4px;">{{ $quote->conditions }}</div>
        </div>
    @endif

    @if($data['bank'])
        <div style="margin-top: 14px; color: #3D4250; font-size: {{ $pdf ? '10px' : '13px' }};">
            Datos para transferencias:
            {{ $data['bank'] }}
        </div>
    @endif

    <div style="margin-top: 22px; padding-top: 10px; border-top: 1px solid #ECEAE3; color: #9A9DA6; font-size: {{ $pdf ? '9px' : '12px' }};">
        {{ $company?->name }} · Presupuesto {{ $quote->numberLabel() }} · Emitido con Ascento
    </div>
</div>

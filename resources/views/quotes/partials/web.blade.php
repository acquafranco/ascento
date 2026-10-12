{{--
    Presupuesto para leer en pantalla (enlace del cliente, portal y vista del
    admin). Mismos datos que el PDF (App\Support\Quotes\QuoteDocument), pero
    sin ancho fijo ni tablas anidadas:
    - escritorio: la tabla de ítems como en el PDF;
    - celular (< 640 px): cada ítem es un bloque con concepto, cantidad × precio
      y subtotal; los totales y condiciones ocupan todo el ancho.
    Estilos propios (qw-*): no depende del CSS de la página que lo incluya.
    $quote (con items, company, client, building)
--}}
@php
    $data = \App\Support\Quotes\QuoteDocument::for($quote);
    $company = $data['company'];
    $building = $data['building'];
    $money = fn ($v) => \App\Support\Quotes\QuoteDocument::money($v);
    $qty = fn ($v) => \App\Support\Quotes\QuoteDocument::quantity($v);
@endphp
@once
<style>
    .qw { font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; color: #1F2430; font-size: 15px; line-height: 1.45; }
    .qw * { box-sizing: border-box; }
    .qw-head { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 14px 24px; }
    .qw-logo { max-height: 56px; max-width: 180px; display: block; margin-bottom: 6px; }
    .qw-company { font-weight: 800; font-size: 20px; letter-spacing: -.01em; overflow-wrap: anywhere; }
    .qw-muted { color: #6B7080; font-size: 13px; overflow-wrap: anywhere; }
    .qw-meta { text-align: right; }
    .qw-kicker { font-size: 12px; letter-spacing: .12em; color: #C24800; font-weight: 700; }
    .qw-number { font-size: 24px; font-weight: 800; }
    .qw-pill { display: inline-block; margin-top: 6px; padding: 3px 12px; border-radius: 999px; font-weight: 700; font-size: 12px; }
    .qw-parties { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px 16px; background: #F7F7F4; border-radius: 12px; padding: 14px 16px; margin-top: 16px; }
    .qw-label { font-size: 11px; letter-spacing: .1em; color: #6B7080; font-weight: 700; text-transform: uppercase; }
    .qw-strong { font-weight: 700; overflow-wrap: anywhere; }
    .qw-title { font-size: 19px; margin: 20px 0 4px; overflow-wrap: anywhere; }
    .qw-pre { white-space: pre-line; overflow-wrap: anywhere; color: #3D4250; }
    .qw-items { width: 100%; border-collapse: collapse; margin-top: 14px; }
    .qw-items th { background: #12151C; color: #F7F7F4; font-weight: 600; padding: 9px 10px; text-align: right; font-size: 14px; }
    .qw-items th:first-child { text-align: left; border-radius: 10px 0 0 0; } .qw-items th:last-child { border-radius: 0 10px 0 0; }
    .qw-items td { padding: 10px; border-bottom: 1px solid #ECEAE3; vertical-align: top; text-align: right; }
    .qw-items td:first-child { text-align: left; }
    .qw-concept { font-weight: 600; overflow-wrap: anywhere; }
    .qw-xs { display: none; }
    .qw-total { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin: 12px 0 0 auto; max-width: 360px; background: #12151C; color: #F7F7F4; border-radius: 12px; padding: 14px 16px; }
    .qw-total strong { color: #FF6A1A; font-size: 22px; white-space: nowrap; }
    .qw-note { text-align: right; color: #6B7080; font-size: 12px; margin-top: 4px; }
    .qw-foot { margin-top: 22px; padding-top: 10px; border-top: 1px solid #ECEAE3; color: #9A9DA6; font-size: 12px; overflow-wrap: anywhere; }
    @media (max-width: 639px) {
        .qw { font-size: 15px; }
        .qw-head { flex-direction: column; }
        .qw-meta { text-align: left; }
        .qw-parties { grid-template-columns: 1fr; }
        .qw-items thead { display: none; }
        .qw-items, .qw-items tbody, .qw-items tr, .qw-items td { display: block; width: 100%; }
        .qw-items tr { border: 1px solid #ECEAE3; border-radius: 12px; padding: 12px 14px; margin-top: 10px; }
        .qw-items td { border: 0; padding: 0; text-align: left; }
        .qw-items td.qw-col { display: none; }
        .qw-xs { display: flex; justify-content: space-between; gap: 10px; margin-top: 6px; color: #6B7080; font-size: 14px; }
        .qw-xs strong { color: #1F2430; white-space: nowrap; }
        .qw-total { max-width: none; }
    }
</style>
@endonce

<div class="qw">
    <div class="qw-head">
        <div style="min-width: 0">
            @if ($data['logo'])<img class="qw-logo" src="{{ $data['logo'] }}" alt="{{ $company->name }}">@endif
            <div class="qw-company">{{ $company?->name }}</div>
            @foreach ($data['issuer'] as $line)<div class="qw-muted">{{ $line }}</div>@endforeach
        </div>
        <div class="qw-meta">
            <div class="qw-kicker">PRESUPUESTO</div>
            <div class="qw-number">{{ $quote->numberLabel() }}</div>
            <div class="qw-muted">Fecha: {{ $quote->issued_at?->format('d/m/Y') }}</div>
            @if ($quote->valid_until)<div class="qw-muted">Válido hasta: <strong style="color:#1F2430">{{ $quote->valid_until->format('d/m/Y') }}</strong></div>@endif
            <span class="qw-pill" style="{{ $data['status_style'] }}">{{ $data['status_label'] }}</span>
        </div>
    </div>

    <div class="qw-parties">
        <div>
            <div class="qw-label">Cliente</div>
            <div class="qw-strong">{{ $quote->client?->name ?? $building?->client?->name ?? '—' }}</div>
            @if ($quote->client?->contact_person)<div class="qw-muted">At. {{ $quote->client->contact_person }}</div>@endif
        </div>
        <div>
            <div class="qw-label">Edificio</div>
            <div class="qw-strong">{{ $building?->name ?? '—' }}</div>
            <div class="qw-muted">{{ trim(($building?->address ?? '').' '.($building?->locality ?? '')) }}{{ $quote->unit ? ' · '.$quote->unit : '' }}</div>
        </div>
    </div>

    <h2 class="qw-title">{{ $quote->title }}</h2>
    @if (filled($quote->description))<div class="qw-pre">{{ $quote->description }}</div>@endif

    <table class="qw-items">
        <thead><tr><th scope="col">Concepto</th><th scope="col" style="width: 12%">Cant.</th><th scope="col" style="width: 22%">Precio unit.</th><th scope="col" style="width: 22%">Subtotal</th></tr></thead>
        <tbody>
            @forelse ($quote->items as $item)
                <tr>
                    <td>
                        <div class="qw-concept">{{ $item->concept }}</div>
                        @if ($item->description)<div class="qw-muted">{{ $item->description }}</div>@endif
                        <div class="qw-xs"><span>{{ $qty($item->quantity) }} × {{ $money($item->unit_price) }}</span><strong>{{ $money($item->subtotal) }}</strong></div>
                    </td>
                    <td class="qw-col">{{ $qty($item->quantity) }}</td>
                    <td class="qw-col">{{ $money($item->unit_price) }}</td>
                    <td class="qw-col" style="font-weight: 600">{{ $money($item->subtotal) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="qw-muted">{{ $quote->title }}</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="qw-total"><span style="font-weight: 600">TOTAL</span><strong>{{ $money($quote->amount) }}</strong></div>
    <div class="qw-note">Importes en pesos argentinos.</div>

    @if (filled($quote->conditions))
        <div style="margin-top: 18px">
            <div class="qw-label">Condiciones</div>
            <div class="qw-pre" style="margin-top: 4px; color: #1F2430">{{ $quote->conditions }}</div>
        </div>
    @endif

    @if ($data['bank'])
        <div class="qw-muted" style="margin-top: 14px; font-size: 14px; color: #3D4250">Datos para transferencias: {{ $data['bank'] }}</div>
    @endif

    <div class="qw-foot">{{ $company?->name }} · Presupuesto {{ $quote->numberLabel() }} · Emitido con Ascento</div>
</div>

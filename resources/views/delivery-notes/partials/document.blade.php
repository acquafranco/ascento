{{--
    Remito para leer en pantalla (técnico, portal y enlace público). Los datos
    salen de App\Support\DeliveryNotes\DeliveryNoteDocument: los mismos que el
    PDF y solo los que puede ver el cliente. Estilos propios (rm-*): no depende
    del CSS del layout que lo incluya. Pensado primero para el celular.
    $doc (array)
--}}
@once
<style>
    .rm { font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; color: #11161D; line-height: 1.45; }
    .rm * { box-sizing: border-box; }
    .rm-head { border-radius: 20px; padding: 18px; display: flex; gap: 14px; align-items: center; box-shadow: 0 10px 30px -18px rgba(17,22,29,.5); }
    .rm-logo { width: 64px; height: 64px; flex: none; border-radius: 14px; background: #fff; display: flex; align-items: center; justify-content: center; overflow: hidden; }
    .rm-logo img { max-width: 56px; max-height: 56px; object-fit: contain; }
    .rm-head-main { min-width: 0; flex: 1; }
    .rm-company { font-size: 13px; font-weight: 600; opacity: .85; overflow-wrap: anywhere; }
    .rm-title { font-size: 24px; font-weight: 800; letter-spacing: -.01em; margin: 2px 0 8px; }
    .rm-chips { display: flex; flex-wrap: wrap; gap: 6px; }
    .rm-chip { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; line-height: 1.3; }
    .rm-chip.order { background: #FFEDD5; color: #9A3412; }
    .rm-chip.maintenance { background: #DBEAFE; color: #1E3A8A; }
    .rm-chip.inspection { background: #EDE9FE; color: #4C1D95; }
    .rm-chip.ok { background: #DCFCE7; color: #166534; }
    .rm-chip.no { background: #FEE2E2; color: #991B1B; }
    .rm-card { background: #fff; border: 1px solid #E7E9EE; border-radius: 18px; padding: 16px; margin-top: 12px; }
    .rm-label { font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: #6B7280; margin-bottom: 4px; }
    .rm-facts { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px 16px; }
    .rm-facts .rm-wide { grid-column: 1 / -1; }
    .rm-value { font-size: 15px; font-weight: 600; overflow-wrap: anywhere; }
    .rm-text { font-size: 15px; white-space: pre-line; overflow-wrap: anywhere; color: #1F2937; }
    .rm-list { list-style: none; margin: 0; padding: 0; }
    .rm-list li { display: flex; justify-content: space-between; gap: 12px; padding: 8px 0; border-top: 1px solid #F1F2F5; font-size: 14px; }
    .rm-list li:first-child { border-top: 0; padding-top: 0; }
    .rm-people { display: flex; flex-wrap: wrap; gap: 6px; }
    .rm-person { background: #F1F2F5; border-radius: 999px; padding: 5px 12px; font-size: 14px; font-weight: 600; }
    .rm-person small { color: #6B7280; font-weight: 500; }
    .rm-signs { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
    .rm-sign { border: 1px solid #E7E9EE; border-radius: 14px; overflow: hidden; }
    .rm-sign-img { height: 104px; display: flex; align-items: center; justify-content: center; background: #fff; padding: 6px; }
    .rm-sign-img img { max-height: 92px; max-width: 100%; object-fit: contain; }
    .rm-sign-empty { color: #9CA3AF; font-size: 13px; }
    .rm-sign-foot { background: #F8F9FB; border-top: 1px solid #E7E9EE; padding: 8px 10px; }
    .rm-sign-foot .rm-value { font-size: 14px; }
    .rm-foot { text-align: center; color: #9CA3AF; font-size: 12px; margin: 14px 0 4px; }
    @media (min-width: 640px) {
        .rm-head { padding: 22px 24px; }
        .rm-logo { width: 80px; height: 80px; } .rm-logo img { max-width: 70px; max-height: 70px; }
        .rm-title { font-size: 28px; }
        .rm-facts { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .rm-card { padding: 20px 22px; }
    }
    @media (max-width: 360px) { .rm-signs { grid-template-columns: 1fr; } }
    @media print { .rm-head, .rm-chip { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
</style>
@endonce

<article class="rm" aria-label="Remito {{ $doc['number'] }}">
    <header class="rm-head" style="background: {{ $doc['company']['color'] }}; color: {{ $doc['company']['on_color'] }};">
        @if ($doc['company']['logo'])
            <div class="rm-logo"><img src="{{ $doc['company']['logo'] }}" alt="Logo de {{ $doc['company']['name'] }}"></div>
        @endif
        <div class="rm-head-main">
            <div class="rm-company">{{ $doc['company']['name'] }}</div>
            <h1 class="rm-title">Remito #{{ $doc['number'] }}</h1>
            <div class="rm-chips">
                @if ($doc['kind'])
                    <span class="rm-chip {{ $doc['kind']['tone'] }}"><span aria-hidden="true">{{ $doc['kind']['icon'] }}</span> {{ $doc['kind']['label'] }}</span>
                @endif
                <span class="rm-chip {{ $doc['performed'] ? 'ok' : 'no' }}">{{ $doc['performed'] ? '✓ Realizado' : '✕ No realizado' }}</span>
            </div>
        </div>
    </header>

    <section class="rm-card">
        <div class="rm-facts">
            <div class="rm-wide"><div class="rm-label">Cliente</div><div class="rm-value">{{ $doc['client'] ?: '—' }}</div></div>
            <div class="rm-wide"><div class="rm-label">Edificio y dirección</div><div class="rm-value">{{ $doc['building'] ?: '—' }}</div></div>
            <div><div class="rm-label">Fecha</div><div class="rm-value">{{ $doc['date'] }}</div></div>
            <div><div class="rm-label">Período del servicio</div><div class="rm-value">{{ $doc['period'] }}</div></div>
            <div><div class="rm-label">Equipos</div><div class="rm-value">{{ $doc['equipment'] }}</div></div>
            <div><div class="rm-label">Técnico</div><div class="rm-value">{{ $doc['technician'] ?: '—' }}</div></div>
        </div>
    </section>

    <section class="rm-card">
        <div class="rm-label">Trabajo realizado</div>
        <div class="rm-text">{{ $doc['description'] ?: '—' }}</div>
    </section>

    @if ($doc['materials'])
        <section class="rm-card">
            <div class="rm-label">Materiales utilizados</div>
            <ul class="rm-list">
                @foreach ($doc['materials'] as $material)
                    <li><span>{{ $material['name'] }}</span><strong style="white-space: nowrap">{{ $material['quantity'] }}</strong></li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($doc['parachute'] ?? [])
        <section class="rm-card" data-remito-parachute>
            <div class="rm-label">Prueba de paracaídas</div>
            <ul class="rm-list">
                @foreach ($doc['parachute'] as $test)
                    <li><span>{{ $test['elevator'] }}</span><strong style="white-space: nowrap; color: {{ $test['ok'] ? '#166534' : '#991B1B' }}">{{ $test['result'] }}</strong></li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($doc['participants'])
        <section class="rm-card">
            <div class="rm-label">Técnicos participantes</div>
            <div class="rm-people">
                @foreach ($doc['participants'] as $person)
                    <span class="rm-person">{{ $person['name'] }} @if ($person['responsible'])<small>(Responsable)</small>@endif</span>
                @endforeach
            </div>
        </section>
    @endif

    <section class="rm-card">
        <div class="rm-label">Firmas</div>
        <div class="rm-signs">
            @foreach ($doc['signatures'] as $sign)
                <div class="rm-sign">
                    <div class="rm-sign-img">
                        @if ($sign['image'])
                            <img src="{{ $sign['image'] }}" alt="Firma del {{ strtolower($sign['who']) }}">
                        @else
                            <span class="rm-sign-empty">Sin firma</span>
                        @endif
                    </div>
                    <div class="rm-sign-foot">
                        <div class="rm-label" style="margin-bottom: 0">{{ $sign['who'] }} · aclaración</div>
                        <div class="rm-value">{{ $sign['name'] ?: '—' }}</div>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    <p class="rm-foot">{{ $doc['company']['name'] }} · Remito #{{ $doc['number'] }} · Emitido con Ascento</p>
</article>

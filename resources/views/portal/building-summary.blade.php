{{--
    Resumen mensual de un edificio (Ascento Conecta): calculado al abrirlo con
    los registros reales de la empresa (BuildingTransparency::summary). Si no
    hay registros, se dice. No es un certificado ni una evaluación técnica.
--}}
@php
    $s = $summary;
    $docs = \App\Support\Portal\PortalDocuments::TYPES;
    $none = fn ($n) => (int) $n === 0;
@endphp
<x-portal-layout :title="'Resumen · '.$building->name">
    <a class="p-back" href="{{ route('portal.building', $building) }}">← {{ $building->name }}</a>
    <h1 class="p-h1">Resumen de {{ mb_strtolower($s['label']) }}</h1>
    <p class="p-sub">{{ trim($building->address.' '.$building->locality) }}</p>

    <nav style="display:flex; gap:8px; margin:0 0 14px" aria-label="Cambiar de mes">
        <a class="p-btn secondary" href="{{ route('portal.building-summary', ['building' => $building, 'mes' => $prev]) }}">‹ Mes anterior</a>
        @if($next)<a class="p-btn secondary" href="{{ route('portal.building-summary', ['building' => $building, 'mes' => $next]) }}">Mes siguiente ›</a>@endif
    </nav>

    @if($s['partial'])
        <p class="p-card" style="margin-bottom:12px" data-summary-partial>El mes todavía no terminó: estos números pueden cambiar.</p>
    @elseif($s['future'])
        <p class="p-card" style="margin-bottom:12px">Este mes todavía no empezó: no hay registros.</p>
    @endif

    <section class="p-sec" data-summary="visits">
        <div class="p-sec-head"><h2>Visitas registradas</h2></div>
        <ul class="p-list">
            @foreach(['maintenance' => 'Mantenimientos', 'inspection' => 'Inspecciones'] as $key => $label)
                @php $v = $s['visits'][$key]; @endphp
                <li class="p-row">
                    <span>{{ $label }}</span>
                    <span class="p-muted">{{ $none($v['total']) ? 'Sin registros en el período' : $v['total'].' registrada'.($v['total'] === 1 ? '' : 's').($v['not_done'] ? ' · '.$v['not_done'].' como no realizada'.($v['not_done'] === 1 ? '' : 's') : '') }}</span>
                </li>
            @endforeach
        </ul>
    </section>

    <section class="p-sec" data-summary="documents">
        <div class="p-sec-head"><h2>Documentos compartidos en el mes</h2><a class="p-link" href="{{ route('portal.documents', ['building' => $building->id]) }}">Ver documentos →</a></div>
        <ul class="p-list">
            @foreach($docs as $key => $label)
                <li class="p-row"><span>{{ $label }}</span><span class="p-muted">{{ $none($s['documents'][$key] ?? 0) ? 'Ninguno' : $s['documents'][$key] }}</span></li>
            @endforeach
        </ul>
    </section>

    <section class="p-sec" data-summary="incidents">
        <div class="p-sec-head"><h2>Reclamos e incidencias</h2></div>
        @if($s['incidents']['total'] === 0)
            <div class="p-empty">Sin reclamos ni incidencias compartidas en el período.</div>
        @else
            <ul class="p-list">
                @foreach($s['incidents']['by_status'] as $status => $count)
                    <li class="p-row"><span>{{ $status }}</span><span class="p-muted">{{ $count }}</span></li>
                @endforeach
            </ul>
        @endif
        @if($s['orders'])
            <p class="p-muted" style="font-size:13px; margin:8px 2px 0">Órdenes de trabajo vinculadas: @foreach($s['orders'] as $label => $count){{ $count }} · {{ $label }}@if(! $loop->last); @endif @endforeach</p>
        @endif
    </section>

    <section class="p-sec" data-summary="quotes">
        <div class="p-sec-head"><h2>Presupuestos compartidos</h2></div>
        @if(empty($s['quotes']))
            <div class="p-empty">Sin presupuestos compartidos en el período.</div>
        @else
            <ul class="p-list">
                @foreach($s['quotes'] as $status => $count)
                    <li class="p-row"><span>{{ $status }}</span><span class="p-muted">{{ $count }}</span></li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="p-sec" data-summary="period">
        <div class="p-sec-head"><h2>Visitas previstas</h2></div>
        @if(empty($s['period']))
            <div class="p-empty">No hay una frecuencia de visitas cargada para este edificio: no se muestran visitas previstas.</div>
        @else
            <ul class="p-list">
                @foreach($s['period'] as $p)
                    <li class="p-row"><span>{{ $p['type'] }} · {{ $p['period'] }}</span><span class="p-muted">{{ $p['done'] }} de {{ $p['expected'] }} registrada{{ $p['expected'] === 1 ? '' : 's' }}</span></li>
                @endforeach
            </ul>
        @endif
    </section>

    <p class="p-muted" style="font-size:13px; margin-top:22px" data-summary-note>Este resumen se arma con lo que {{ $portalCompany?->name }} registró y compartió en Ascento. No es un certificado de cumplimiento normativo ni una evaluación técnica de seguridad. {{ \App\Models\ClientClaim::STATUS_NOTE }}</p>
</x-portal-layout>

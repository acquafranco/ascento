@php
    $months = [1 => 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
@endphp
<x-portal-layout :title="$building->name">
    <a class="p-back" href="{{ route('portal.home') }}">← Mis edificios</a>
    <h1 class="p-h1">{{ $building->name }}</h1>
    <p class="p-sub">{{ trim($building->address.' '.$building->locality) }}</p>

    <p style="margin:0 0 12px"><a class="p-btn" href="{{ route('portal.building-summary', $building) }}" data-building-summary-link>Resumen del mes</a></p>

    <div class="p-stats">
        @foreach(\App\Support\Portal\PortalDocuments::TYPES as $key => $label)
            <a class="p-stat" href="{{ route('portal.documents', ['type' => $key, 'building' => $building->id]) }}"><b>{{ $counts[$key] ?? 0 }}</b><span class="p-muted">{{ $label }}</span></a>
        @endforeach
    </div>

    <section class="p-sec">
        <div class="p-sec-head">
            <h2>Últimos documentos</h2>
            @if($recent->total() > $recent->count())<a class="p-link" href="{{ route('portal.documents', ['building' => $building->id]) }}">Ver los {{ $recent->total() }} →</a>@endif
        </div>
        @if($recent->isEmpty())
            <div class="p-empty">Todavía no hay documentos compartidos de este edificio.</div>
        @else
            <ul class="p-list">
                @foreach($recent as $d)
                    @include('portal.partials.doc-row', ['d' => $d])
                @endforeach
            </ul>
        @endif
    </section>

    <section class="p-sec">
        <div class="p-sec-head">
            <h2>Servicio</h2>
            <span>
                <a class="p-link" href="{{ route('portal.visits', ['type' => 'maintenance', 'building' => $building->id]) }}">Mantenimientos →</a>
                &nbsp;<a class="p-link" href="{{ route('portal.visits', ['type' => 'inspection', 'building' => $building->id]) }}">Inspecciones →</a>
            </span>
        </div>
        @if($lastVisits->isEmpty())
            <div class="p-empty">Todavía no hay visitas registradas.</div>
        @else
            <ul class="p-list">
                @foreach($lastVisits as $visit)
                    @php
                        $done = $visit->deliveryNote?->performed !== false;
                    @endphp
                    <li class="p-row">
                        <span>{{ $visit->assignment_type === 'inspection' ? 'Inspección' : 'Mantenimiento' }} · {{ $months[$visit->month] ?? $visit->month }} {{ $visit->year }}</span>
                        <span class="p-tag {{ $done ? 'ok' : 'bad' }}">{{ $done ? 'Realizado' : 'No realizado' }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Este período: solo con una frecuencia de visitas cargada (nada inventado). --}}
    <section class="p-sec" data-building-period>
        <div class="p-sec-head"><h2>Este período</h2></div>
        @if(empty($period))
            <div class="p-empty">La empresa no cargó una frecuencia de visitas para este edificio, así que no se muestran visitas previstas.</div>
        @else
            <ul class="p-list">
                @foreach($period as $p)
                    <li class="p-row">
                        <span>{{ $p['type'] }} · {{ $p['period'] }} <span class="p-muted">({{ mb_strtolower($p['frequency']) }})</span></span>
                        @if($p['done'] >= $p['expected'])
                            <span class="p-tag ok">Registrado</span>
                        @elseif($p['not_done'] > 0 && $p['pending'] === 0)
                            <span class="p-tag bad">Registrado como no realizado</span>
                        @else
                            <span class="p-tag warn">Previsto, sin registrar todavía</span>
                        @endif
                    </li>
                @endforeach
            </ul>
            <p class="p-muted" style="font-size:13px; margin:6px 2px 0">Según la frecuencia acordada con la empresa. «Previsto» no significa que la visita ya se hizo.</p>
        @endif
    </section>

    {{-- Reclamos e incidencias del edificio (los propios y los reportes del QR que la empresa compartió). --}}
    @if(\App\Services\Claims\ClientClaimService::portalCanClaim(auth()->user()))
        <section class="p-sec" data-building-incidents>
            <div class="p-sec-head"><h2>Reclamos e incidencias</h2><a class="p-link" href="{{ route('portal.claims.index') }}">Ver todos →</a></div>
            @if($incidents->isEmpty())
                <div class="p-empty">No hay reclamos ni incidencias compartidas de este edificio.</div>
            @else
                <ul class="p-list">
                    @foreach($incidents as $c)
                        <li><a class="p-row" href="{{ route('portal.claims.show', $c->id) }}">
                            <span class="p-row-main" style="display:block"><span class="p-row-title">{{ $c->reference() }} · {{ $c->categoryLabel() }}</span>
                                <span class="p-row-text">{{ $c->created_at->format('d/m/Y') }}{{ $c->isFromQr() ? ' · Reportado desde el QR' : '' }}</span></span>
                            <span class="p-row-side"><span class="p-tag">{{ $c->statusLabel() }}</span></span>
                        </a></li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif

    @include('portal.partials.responsibles', ['responsibles' => $responsibles, 'title' => 'Responsables de este edificio', 'single' => true])

    <section class="p-sec">
        <details class="p-acc">
            <summary>Equipos ({{ $elevators->count() }}) <span class="p-muted">ver</span></summary>
            @if($elevators->isEmpty())
                <div class="p-row p-muted">No hay equipos cargados para este edificio.</div>
            @else
                <ul class="p-list" style="border:0;border-radius:0">
                    @foreach($elevators as $elevator)
                        <li class="p-row">
                            <span>{{ $elevator->label }}</span>
                            <span class="p-muted">{{ trim($elevator->manufacturer.' '.$elevator->model) ?: '—' }}{{ $elevator->year ? ' · '.$elevator->year : '' }}@if(filled($elevator->controller_brand) || filled($elevator->controller_model))<br>Control: {{ trim($elevator->controller_brand.' '.$elevator->controller_model) }}@endif</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </details>
    </section>
</x-portal-layout>

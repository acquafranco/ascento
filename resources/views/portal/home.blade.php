<x-portal-layout title="Mis edificios">
    <h1 class="p-h1">Hola, {{ $user->name }}</h1>
    <p class="p-sub">{{ $portalClient?->name }} · Información que {{ $portalCompany?->name }} comparte sobre tus edificios.</p>

    @include('portal.partials.push-card', ['company' => $portalCompany])

    <section class="p-sec">
        <div class="p-sec-head"><h2>Tus edificios</h2></div>
        @if($buildings->isEmpty())
            <div class="p-empty">Todavía no tenés edificios habilitados. Pedile a {{ $portalCompany?->name }} que te dé acceso.</div>
        @else
            <div class="p-grid">
                @foreach($buildings as $building)
                    @php
                        $visits = ($lastVisits[$building->id] ?? collect())->keyBy('assignment_type');
                        $lastMaintenance = $visits['maintenance']->last_at ?? null;
                        $lastInspection = $visits['inspection']->last_at ?? null;
                    @endphp
                    <a class="p-card" href="{{ route('portal.building', $building) }}">
                        <h3>{{ $building->name }}</h3>
                        <div class="p-muted">{{ trim($building->address.' '.$building->locality) }}</div>
                        <div class="p-muted" style="margin-top:8px">{{ $building->elevators_count }} {{ $building->elevators_count === 1 ? 'equipo' : 'equipos' }}</div>
                        <div style="margin-top:10px;display:flex;gap:6px;flex-wrap:wrap">
                            <span class="p-tag {{ $lastMaintenance ? 'ok' : '' }}">Mantenimiento: {{ $lastMaintenance ? \Illuminate\Support\Carbon::parse($lastMaintenance)->format('d/m/Y') : 'sin registros' }}</span>
                            <span class="p-tag {{ $lastInspection ? 'ok' : '' }}">Inspección: {{ $lastInspection ? \Illuminate\Support\Carbon::parse($lastInspection)->format('d/m/Y') : 'sin registros' }}</span>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </section>

    <section class="p-sec">
        <div class="p-sec-head">
            <h2>Lo último compartido</h2>
            @if($recent->total() > 0)<a class="p-link" href="{{ route('portal.documents') }}">Ver todo ({{ $recent->total() }}) →</a>@endif
        </div>
        @if($recent->isEmpty())
            <div class="p-empty">Todavía no te compartieron documentos. Cuando {{ $portalCompany?->name }} comparta un remito, reporte, presupuesto o documento, lo vas a ver acá y te avisamos.</div>
        @else
            <ul class="p-list">
                @foreach($recent as $d)
                    @include('portal.partials.doc-row', ['d' => $d])
                @endforeach
            </ul>
        @endif
    </section>
</x-portal-layout>

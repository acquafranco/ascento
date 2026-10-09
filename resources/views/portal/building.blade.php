@php
    $months = [1 => 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
@endphp
<x-portal-layout :title="$building->name">
    <a class="p-back" href="{{ route('portal.home') }}">← Mis edificios</a>
    <h1 class="p-h1">{{ $building->name }}</h1>
    <p class="p-sub">{{ trim($building->address.' '.$building->locality) }}</p>

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
                            <span class="p-muted">{{ trim($elevator->manufacturer.' '.$elevator->model) ?: '—' }}{{ $elevator->year ? ' · '.$elevator->year : '' }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </details>
    </section>
</x-portal-layout>

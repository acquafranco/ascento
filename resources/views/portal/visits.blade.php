@php
    $label = $type === 'maintenance' ? 'Mantenimientos' : 'Inspecciones';
    $months = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
@endphp
<x-portal-layout :title="$label">
    <h1 class="p-h1">{{ $label }}</h1>
    <p class="p-sub">
        {{ $type === 'maintenance' ? 'Las visitas mensuales de mantenimiento' : 'Las inspecciones' }} de tus edificios, con su remito cuando {{ $portalCompany?->name }} lo comparte.
    </p>

    <details class="p-filter-box" open data-filters>
        <summary>Filtros ▾</summary>
    <form method="GET" class="p-filters">
        <div>
            <label for="v-building">Edificio</label>
            <select id="v-building" name="building">
                <option value="">Todos</option>
                @foreach($buildings as $b)
                    <option value="{{ $b->id }}" @selected((int) ($filters['building'] ?? 0) === $b->id)>{{ $b->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="v-year">Año</label>
            <select id="v-year" name="year">
                <option value="">Todos</option>
                @foreach($years as $year)
                    <option value="{{ $year }}" @selected((int) ($filters['year'] ?? 0) === (int) $year)>{{ $year }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="v-result">Resultado</label>
            <select id="v-result" name="result">
                <option value="">Todos</option>
                <option value="done" @selected(($filters['result'] ?? '') === 'done')>Realizado</option>
                <option value="not_done" @selected(($filters['result'] ?? '') === 'not_done')>No se pudo realizar</option>
            </select>
        </div>
        <div class="p-filter-actions">
            <button class="p-btn" type="submit">Filtrar</button>
            @if(array_filter($filters))<a class="p-btn secondary" href="{{ route('portal.visits', $type) }}">Limpiar</a>@endif
        </div>
    </form>
    </details>
    <script>
        // En el celular, los filtros arrancan plegados si no hay ninguno activo.
        (function () { var d = document.querySelector('[data-filters]'); if (d && window.innerWidth < 640 && !/[?&](q|building|status|from|to|year|result)=[^&]/.test(location.search)) d.open = false; })();
    </script>

    <div style="margin-top:14px">
        @if($visits->isEmpty())
            <div class="p-empty">No hay {{ mb_strtolower($label) }} registrados{{ array_filter($filters) ? ' con estos filtros' : '' }}.</div>
        @else
            <ul class="p-list">
                @foreach($visits as $visit)
                    @php
                        $note = $visit->deliveryNote;
                        $done = $note?->performed !== false;
                        $sharedNote = $note && $note->shared_with_client;
                    @endphp
                    <li>
                        @if($sharedNote)<a class="p-row" href="{{ route('portal.delivery-note', $note->number) }}">@else<div class="p-row">@endif
                            <span class="p-row-main">
                                <span class="p-icon" aria-hidden="true">{{ $type === 'maintenance' ? '🔧' : '🔍' }}</span>
                                <span style="min-width:0">
                                    <span class="p-row-title">{{ $months[$visit->month] ?? $visit->month }} {{ $visit->year }} · {{ $buildingNames[$visit->building_id] ?? '' }}</span>
                                    <span class="p-row-text">
                                        {{ $visit->visited_at?->format('d/m/Y') }}
                                        @if($note) · {{ (int) $note->elevator_quantity + (int) $note->freight_elevator_quantity }} equipos @endif
                                        @if($sharedNote && $note->user) · Técnico: {{ $note->user->name }} @endif
                                    </span>
                                    <span class="p-muted">{{ $sharedNote ? 'Ver remito '.$note->number : 'Remito no compartido' }}</span>
                                </span>
                            </span>
                            <span class="p-row-side">
                                <span class="p-tag {{ $done ? 'ok' : 'bad' }}">{{ $done ? 'Realizado' : 'No realizado' }}</span>
                            </span>
                        @if($sharedNote)</a>@else</div>@endif
                    </li>
                @endforeach
            </ul>
            @include('portal.partials.pager', ['paginator' => $visits])
        @endif
    </div>
</x-portal-layout>

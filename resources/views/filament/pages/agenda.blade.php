@php
    $statuses = \App\Services\Insights\MaintenanceAgenda::STATUSES;
    $types = \App\Services\Insights\MaintenanceAgenda::TYPES;
@endphp
<x-filament-panels::page>
    @include('filament.partials.insights-styles')

    <div class="asc-filters">
        <select wire:model.live="month" class="asc-select" aria-label="Mes">
            @foreach($this->monthOptions() as $value => $label)
                <option value="{{ $value }}" @selected($period->format('Y-m') === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <select wire:model.live="type" class="asc-select" aria-label="Tipo">
            <option value="">Todos los tipos</option>
            @foreach($types as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </select>
        <select wire:model.live="technician" class="asc-select" aria-label="Técnico">
            <option value="">Todos los técnicos</option>
            @foreach($this->technicianOptions() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
        </select>
        <select wire:model.live="status" class="asc-select" aria-label="Estado">
            <option value="">Todos los estados</option>
            @foreach($statuses as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </select>
    </div>

    @if($staleVisits->isNotEmpty())
        {{-- Visitas en curso hace más de 7 días: la oficina decide si se continúan o se descartan (nada se cierra ni se borra solo). --}}
        <div class="asc-tip" id="visitas-en-curso" role="status" data-stale-visits style="display: block;">
            <p class="asc-tip-title">⚠️ {{ $staleVisits->count() === 1 ? '1 visita en curso' : $staleVisits->count().' visitas en curso' }} hace más de {{ \App\Services\Visits\VisitService::STALE_DAYS }} días, sin remito</p>
            <p class="asc-tip-body">Se empezaron para cargar fotos y nadie firmó el remito. Abrí cada una para ver sus fotos y decidir: que el técnico la continúe y haga el remito, o descartarla. No se cierran ni se borran solas.</p>
            <ul style="margin: .6rem 0 0; padding: 0; list-style: none;">
                @foreach($staleVisits as $stale)
                    @php $since = $stale->started_at ?? $stale->created_at; @endphp
                    <li data-stale-visit="{{ $stale->id }}" style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .5rem .75rem; padding: .6rem 0; border-top: 1px solid rgba(245, 158, 11, .35);">
                        <div style="min-width: 0; flex: 1 1 14rem;">
                            <div style="font-weight: 600;">{{ $stale->building?->name ?? '—' }} · {{ $types[$stale->assignment_type] ?? $stale->assignment_type }} <span class="asc-small" style="font-weight: 400;">({{ \Carbon\Carbon::create($stale->year, $stale->month, 1)->locale('es')->translatedFormat('F Y') }})</span></div>
                            <div class="asc-small">Inicio: {{ $since?->format('d/m/Y') }} (hace {{ (int) $since?->diffInDays(now()) }} días) · {{ $stale->user?->name }}</div>
                            <div class="asc-small">Estado: <strong>En curso · sin remito</strong> · {{ $stale->photos_count }} {{ $stale->photos_count === 1 ? 'foto' : 'fotos' }}</div>
                        </div>
                        @if($visitsSlug ?? null)
                            <a class="asc-btn" href="{{ route('visits.show', ['company' => $visitsSlug, 'visit' => $stale->id]) }}" target="_blank" rel="noopener">Abrir la visita</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if($progress)
        {{-- Progreso por visitas previstas (no por máquinas), separado por tipo --}}
        <div class="asc-progress-grid">
            @foreach($types as $key => $label)
                @php $p = $progress[$key]; @endphp
                @continue(($type && $type !== $key) || ($p['assigned'] === 0 && $p['expected'] === 0))
                <div class="asc-progress-card" data-agenda-progress="{{ $key }}">
                    <div class="asc-progress-head"><strong>{{ $label }}</strong><span>{{ $p['percent'] === null ? '—' : $p['percent'].'%' }}</span></div>
                    <div class="asc-progress-bar" role="progressbar" aria-label="{{ $label }}: {{ $p['done'] }} de {{ $p['expected'] }} visitas realizadas" aria-valuemin="0" aria-valuemax="{{ $p['expected'] }}" aria-valuenow="{{ $p['done'] }}">
                        <div style="width: {{ $p['expected'] ? min(100, round($p['done'] / $p['expected'] * 100)) : 0 }}%"></div>
                    </div>
                    <div class="asc-small"><strong>{{ $p['done'] }} de {{ $p['expected'] }}</strong> visitas previstas realizadas</div>
                    <div class="asc-small asc-muted">
                        {{ $p['assigned'] }} {{ $p['assigned'] === 1 ? 'edificio asignado' : 'edificios asignados' }} ·
                        {{ $p['pending'] }} pendientes{{ $p['not_done'] ? ' (incluye '.$p['not_done'].' no realizadas)' : '' }}
                    </div>
                </div>
            @endforeach
        </div>
        <details class="asc-small asc-muted" style="margin: -.25rem 0 .75rem;" data-progress-help>
            <summary style="cursor: pointer; font-weight: 600;">¿Qué significa cada número?</summary>
            <p style="margin: .35rem 0 0;"><strong>Edificios asignados:</strong> edificios con técnico para ese trabajo. <strong>Previstas:</strong> visitas que corresponden al mes según la frecuencia de cada edificio (quincenal = 2; trimestral = 1 cada 3 meses; sin equipos = ninguna). <strong>Realizadas:</strong> con remito firmado como realizado. <strong>Pendientes:</strong> previstas que todavía no se completaron (incluye remitos «no realizado» y visitas en curso). Se cuentan visitas, no ascensores. El técnico ve los mismos números para sus edificios.</p>
        </details>
    @endif

    @if($summary)
        <div class="asc-pills">
            @foreach($statuses as $key => $label)
                @if(($summary[$key] ?? 0) > 0)
                    <button type="button" wire:click="$set('status', '{{ $status === $key ? '' : $key }}')" class="asc-pill is-{{ $key }}">{{ $label }}: {{ $summary[$key] }}</button>
                @endif
            @endforeach
        </div>
    @endif

    <x-filament::section>
        @if($rows->isEmpty())
            <p class="asc-muted" style="text-align: center; padding: 1.5rem 0; font-size: .875rem;">
                @if($status || $type || $technician)
                    No hay visitas con estos filtros.
                @else
                    Todavía no hay mantenimientos para mostrar. Asigná técnicos a tus edificios (en Edificios → "Asignar empleado", o varios a la vez con "Asignar técnicos") y aparecen acá solos, según la frecuencia de visitas de cada uno.
                @endif
            </p>
        @else
            <div class="asc-scroll">
                <table class="asc-table">
                    <thead><tr><th>Edificio</th><th>Tipo</th><th>Técnico</th><th>Estado</th></tr></thead>
                    <tbody>
                        @foreach($rows as $row)
                            <tr>
                                <td>
                                    <a class="asc-link" style="font-weight: 600" href="{{ \App\Filament\Resources\Buildings\BuildingResource::getUrl('edit', ['record' => $row['building']]) }}">{{ $row['building']->name }}</a>
                                    <div class="asc-small asc-muted">{{ $row['building']->client?->name }} · {{ $row['elevators'] }} {{ $row['elevators'] === 1 ? 'equipo' : 'equipos' }}</div>
                                </td>
                                <td>
                                    {{ $types[$row['type']] }}
                                    @if($row['period']->plan || $row['period']->isMultiMonth())
                                        <div class="asc-small asc-muted">{{ $row['period']->frequencyLabel() }}{{ $row['period']->isMultiMonth() ? ' · '.$row['period']->label() : '' }}</div>
                                    @endif
                                </td>
                                <td>{{ $row['technicians']->pluck('name')->implode(', ') ?: '—' }}</td>
                                <td>
                                    <span class="asc-pill is-{{ $row['status'] }}">{{ $statuses[$row['status']] }}</span>
                                    @if($row['period']->expected > 1)
                                        <span class="asc-small asc-muted">{{ $row['done'] }} de {{ $row['period']->expected }}</span>
                                    @endif
                                    @foreach($row['visits'] as $visit)
                                        @if($visit->in_progress)
                                            {{-- Abierta para cargar fotos y sin remito: sigue pendiente. La oficina la abre para ver sus fotos o descartarla. --}}
                                            <div class="asc-small asc-muted" data-open-visit>{{ $row['period']->expected > 1 ? 'Visita '.$visit->slot.': ' : '' }}en curso desde el {{ ($visit->started_at ?? $visit->created_at)?->format('d/m') }} · {{ $visit->user?->name }} · sin remito @if($visitsSlug ?? null)· <a class="asc-link" href="{{ route('visits.show', ['company' => $visitsSlug, 'visit' => $visit->id]) }}" target="_blank" rel="noopener">Ver o descartar</a>@endif</div>
                                        @else
                                            <div class="asc-small asc-muted">{{ $row['period']->expected > 1 ? 'Visita '.$visit->slot.': ' : '' }}{{ $visit->visited_at?->format('d/m') }} · {{ $visit->user?->name }}{{ $visit->deliveryNote?->performed === false ? ' · no realizado' : '' }}</div>
                                        @endif
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($rows->hasPages())
                <div style="margin-top: 1rem"><x-filament::pagination :paginator="$rows" /></div>
            @endif
        @endif
    </x-filament::section>
</x-filament-panels::page>

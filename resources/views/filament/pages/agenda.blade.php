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
            <option value="">Mantenimientos e inspecciones</option>
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
                    Todavía no hay mantenimientos para mostrar. Asigná un técnico a cada edificio (en Edificios → "Asignar empleado") y aparecen acá solos, mes a mes.
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
                                <td>{{ $types[$row['type']] }}</td>
                                <td>{{ $row['technicians']->pluck('name')->implode(', ') ?: '—' }}</td>
                                <td>
                                    <span class="asc-pill is-{{ $row['status'] }}">{{ $statuses[$row['status']] }}</span>
                                    @if($row['visit'])
                                        <div class="asc-small asc-muted">{{ $row['visit']->visited_at?->format('d/m') }} · {{ $row['visit']->user?->name }}</div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>

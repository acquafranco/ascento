@php
    $icons = ['report' => '⚠️', 'work_order' => '🛠️', 'visit' => '✅', 'quote' => '💰'];
    $days = \App\Services\Insights\FailureAnalysis::DAYS;
@endphp
<div class="asc-stack">
    @include('filament.partials.insights-styles')

    <div class="asc-stats">
        <div class="asc-stat">Reportes<b>{{ $counts['reports'] }}</b></div>
        <div class="asc-stat">Órdenes<b>{{ $counts['work_orders'] }}</b></div>
        <div class="asc-stat">Reclamos<b>{{ $counts['claims'] }}</b></div>
        <div class="asc-stat">Último mantenimiento<b>{{ $counts['last_visit'] ? \Carbon\Carbon::parse($counts['last_visit'])->format('d/m/Y') : '—' }}</b></div>
    </div>

    {{-- Análisis de fallas (Profesional+) --}}
    @if($analysis !== null)
        <div>
            @livewire(\App\Livewire\HelpTip::class, ['key' => 'failure_analysis'], key('help-failure-'.$elevator->id))
            <div class="asc-alert {{ $analysis['recurrent'] ? 'is-danger' : '' }}">
                <strong>{{ $analysis['recurrent'] ? '🔴 Falla que se repite' : 'Fallas en los últimos '.$days.' días' }}</strong>
                <p style="margin: .2rem 0 0">{{ $analysis['message'] ?? 'Sin reportes ni reclamos en los últimos '.$days.' días.' }}</p>
                @if($analysis['signals'] > 0)
                    <p class="asc-small asc-muted" style="margin: .25rem 0 0">Se cuentan los reportes de los técnicos y los reclamos (órdenes de tipo reclamo) de este equipo; "resueltos" son los reclamos con la orden completada. Un mismo problema puede figurar como reporte y como reclamo.</p>
                @endif
                @if($analysis['unclassified'] > 0)
                    <p class="asc-small asc-muted" style="margin: .25rem 0 0">{{ $analysis['unclassified'] }} sin componente indicado: elegilo al cargar reportes y órdenes para que el análisis sea más preciso.</p>
                @endif
            </div>
        </div>
    @else
        @include('filament.pages.partials.insight-locked', ['title' => 'Análisis de fallas', 'text' => 'detecta si este ascensor se rompe seguido y en qué componente.', 'plan' => 'Profesional', 'url' => $upsellAnalysis])
    @endif

    @if($advanced)
        <div class="asc-filters">
            <select wire:model.live="type" class="asc-select" aria-label="Tipo">
                <option value="">Todo</option>
                @foreach(\App\Services\Insights\ElevatorHistory::TYPES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>
            <select wire:model.live="months" class="asc-select" aria-label="Período">
                <option value="">Desde el principio</option>
                @foreach([3, 6, 12, 24] as $m)<option value="{{ $m }}">Últimos {{ $m }} meses</option>@endforeach
            </select>
        </div>
    @endif

    <ol class="asc-events">
        @forelse($events as $event)
            <li class="asc-event">
                <span>{{ $icons[$event['type']] ?? '•' }}</span>
                <div class="asc-event-main">
                    @if($event['url'])
                        <a href="{{ $event['url'] }}" class="asc-link" style="font-weight: 600">{{ $event['title'] }}</a>
                    @else
                        <span style="font-weight: 600">{{ $event['title'] }}</span>
                    @endif
                    @if($event['badge'])<span class="asc-tag">{{ $event['badge'] }}</span>@endif
                    @if($event['building_level'] ?? false)<span class="asc-tag">del edificio</span>@endif
                    @if($event['detail'])<div class="asc-small asc-muted">{{ $event['detail'] }}</div>@endif
                </div>
                <span class="asc-event-date">{{ $event['date']->format('d/m/Y') }}</span>
            </li>
        @empty
            <li class="asc-muted" style="font-size: .875rem">Todavía no hay nada registrado para este equipo. Va a aparecer solo a medida que se carguen reportes, órdenes y remitos.</li>
        @endforelse
    </ol>

    @unless($advanced)
        @include('filament.pages.partials.insight-locked', ['title' => 'Historial completo', 'text' => 'acá ves los últimos '.\App\Services\Insights\ElevatorHistory::BASIC_LIMIT.'. Con el historial avanzado: todo, con filtros por tipo y período y los materiales usados.', 'plan' => 'Profesional', 'url' => $upsellHistory])
    @endunless
</div>

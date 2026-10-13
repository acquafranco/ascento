@php
    $tone = [
        'overdue' => 'is-overdue', 'failed' => 'is-overdue', 'upcoming' => 'is-pending',
        'no_record' => 'is-pending', 'unconfigured' => '', 'ok' => 'is-done',
    ];
    $statuses = \App\Services\Parachute\ParachuteService::STATUSES;
    $d = fn ($date) => $date?->format('d/m/Y');
@endphp
<x-filament-panels::page>
    @include('filament.partials.insights-styles')

    <div class="asc-grid" data-parachute-summary>
        @foreach($statuses as $key => $label)
            <a href="{{ \App\Filament\Pages\ParachuteTestsPage::getUrl($status === $key ? [] : ['estado' => $key]) }}" class="asc-card asc-link" data-count="{{ $key }}" @if($status === $key) style="outline:2px solid #f59e0b" @endif>
                <p class="asc-q">{{ $label }}</p>
                <p class="asc-v">{{ $counts[$key] ?? 0 }}</p>
            </a>
        @endforeach
    </div>

    @if($total === 0)
        <div class="asc-card"><p class="asc-c">Todavía no hay ascensores activos cargados.</p></div>
    @endif

    @foreach($groups as $key => $rows)
        <section data-parachute-group="{{ $key }}">
            <h2 class="asc-h2">{{ $statuses[$key] }} <span class="asc-muted asc-small" style="font-weight:400">· {{ $rows->count() }}</span></h2>
            <div class="asc-card">
                @foreach($rows as $row)
                    <div class="asc-row" style="align-items:flex-start; padding:.4rem 0; border-top:1px solid rgba(148,163,184,.2)" data-elevator="{{ $row['elevator']->id }}">
                        <span>
                            <strong>{{ $row['building']?->name }} · {{ $row['elevator']->label }}</strong>
                            <span class="asc-pill {{ $tone[$key] }}" style="margin-left:.25rem">{{ $key === 'failed' ? 'No satisfactoria' : $statuses[$key] }}</span>
                            <br><span class="asc-muted asc-small">
                                @if($row['interval'])
                                    Cada {{ $row['interval'] }} {{ $row['interval'] === 1 ? 'mes' : 'meses' }} ({{ $row['interval_source'] === 'client' ? 'política del cliente' : 'del equipo' }})
                                @else
                                    Sin periodicidad definida
                                @endif
                                · Última: {{ $row['last'] ? $d($row['last']->performed_on).' ('.mb_strtolower(\App\Models\ParachuteTest::RESULTS[$row['last']->result] ?? '').')' : 'sin registro' }}
                                @if($row['next_due']) · Próxima prevista: <strong>{{ $d($row['next_due']) }}</strong>@endif
                            </span>
                            @if($r = $reminders->get($row['elevator']->id))
                                <br><span class="asc-muted asc-small" data-reminder>Último aviso: {{ $r['last']->format('d/m/Y') }} · leído por {{ $r['read'] }} de {{ $r['sent'] }}</span>
                            @endif
                            @if($row['schedule']?->inspector || $row['schedule']?->responsible)
                                <br><span class="asc-muted asc-small">
                                    @if($row['schedule']->inspector)Inspector: {{ $row['schedule']->inspector->name }}@endif
                                    @if($row['schedule']->inspector && $row['schedule']->responsible) · @endif
                                    @if($row['schedule']->responsible)Responsable: {{ $row['schedule']->responsible->name }}@endif
                                </span>
                            @endif
                        </span>
                        <span style="display:flex; gap:.4rem; flex-wrap:wrap; justify-content:flex-end">
                            @if($canCreate)
                                <a href="{{ \App\Filament\Resources\ParachuteTests\ParachuteTestResource::getUrl('create', ['ascensor' => $row['elevator']->id]) }}" class="asc-link asc-small" style="color:#d97706">Registrar</a>
                            @endif
                            <a href="{{ \App\Filament\Resources\ParachuteTests\ParachuteTestResource::getUrl('index', ['tableFilters' => ['elevator_id' => ['value' => $row['elevator']->id]]]) }}" class="asc-link asc-small" style="color:#d97706">Historial</a>
                            @if($canManage)
                                {{ ($this->configureAction)(['elevator' => $row['elevator']->id]) }}
                            @endif
                        </span>
                    </div>
                @endforeach
            </div>
        </section>
    @endforeach

</x-filament-panels::page>

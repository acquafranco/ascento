{{--
    Progreso de visitas del período (app del técnico: inicio y Visitas).
    Cuenta VISITAS previstas por edificio (MaintenanceAgenda::progress, con
    las reglas de VisitSchedule), separado por mantenimiento e inspección.
    Realizadas + no realizadas + pendientes = previstas. Las máquinas son
    solo un dato secundario.
    $progress (array), $showMachines (bool, opcional)
--}}
@php
    $labels = \App\Services\Insights\MaintenanceAgenda::TYPES;
    $showMachines = $showMachines ?? false;
@endphp
<div class="space-y-2.5">
    @foreach($labels as $type => $label)
        @php $p = $progress[$type]; @endphp
        @continue($p['assigned'] === 0 && $p['expected'] === 0)
        @php $width = $p['expected'] > 0 ? min(100, round($p['done'] / $p['expected'] * 100)) : 0; @endphp
        <div class="t-card px-4 py-3" data-visit-progress="{{ $type }}">
            <div class="flex items-baseline justify-between gap-2">
                <span class="text-sm font-bold text-ink">{{ $label }}</span>
                <span class="text-sm font-bold {{ $p['expected'] > 0 && $p['pending'] === 0 && $p['not_done'] === 0 ? 'text-emerald-600' : 'text-ink' }}">
                    {{ $p['percent'] === null ? '—' : $p['percent'].'%' }}
                </span>
            </div>
            <div class="mt-2 h-2.5 overflow-hidden rounded-full bg-ink-50" role="progressbar"
                 aria-label="Progreso de {{ mb_strtolower($label) }}: {{ $p['done'] }} de {{ $p['expected'] }} visitas realizadas"
                 aria-valuemin="0" aria-valuemax="{{ $p['expected'] }}" aria-valuenow="{{ $p['done'] }}">
                <div class="h-full rounded-full bg-emerald-500" style="width: {{ $width }}%"></div>
            </div>
            <dl class="mt-2.5 grid grid-cols-4 gap-1 text-center">
                <div><dt class="text-[11px] font-semibold text-ink-500">Asignados</dt><dd class="text-base font-bold text-ink">{{ $p['assigned'] }}</dd></div>
                <div><dt class="text-[11px] font-semibold text-ink-500">Previstas</dt><dd class="text-base font-bold text-ink">{{ $p['expected'] }}</dd></div>
                <div><dt class="text-[11px] font-semibold text-ink-500">Realizadas</dt><dd class="text-base font-bold text-emerald-700">{{ $p['done'] }}</dd></div>
                <div><dt class="text-[11px] font-semibold text-ink-500">Pendientes</dt><dd class="text-base font-bold {{ $p['pending'] ? 'text-amber-700' : 'text-ink' }}">{{ $p['pending'] }}</dd></div>
            </dl>
            @if($p['expected'] === 0)
                <p class="mt-1.5 text-xs text-ink-500">Nada previsto este mes: según la frecuencia, ya se visitó en el período.</p>
            @endif
            @if($p['not_done'])
                <p class="mt-1.5 text-xs font-semibold text-amber-700">{{ $p['not_done'] }} con remito «no realizado»: no cuenta como realizada y la oficina coordina la nueva visita.</p>
            @endif
            @if($showMachines && $p['machines'])
                <p class="mt-1.5 text-xs text-ink-500">Máquinas en esos edificios: {{ $p['machines'] }}</p>
            @endif
        </div>
    @endforeach
</div>

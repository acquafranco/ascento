{{--
    Progreso de visitas del período (app del técnico: inicio y Visitas).
    Cuenta VISITAS previstas por edificio (MaintenanceAgenda::progress, con
    las reglas de VisitSchedule), separado por mantenimiento e inspección.
    Asignados = EDIFICIOS; previstas, realizadas y pendientes = VISITAS
    (pendientes = previstas − realizadas). Las máquinas son solo un dato
    secundario: nunca multiplican el progreso.
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
            <p class="mt-1.5 text-xs text-ink-500" data-progress-summary>
                <strong class="text-ink">{{ $p['done'] }} de {{ $p['expected'] }}</strong> {{ $p['expected'] === 1 ? 'visita prevista realizada' : 'visitas previstas realizadas' }}
                · {{ $p['assigned'] }} {{ $p['assigned'] === 1 ? 'edificio asignado' : 'edificios asignados' }}
            </p>
            {{-- Cuatro indicadores iguales: misma altura, mismo fondo y número alineado (también a 320 px). --}}
            <dl class="mt-2 grid grid-cols-4 gap-1.5 text-center" data-progress-stats>
                @foreach([
                    ['Edificios', $p['assigned'], 'text-ink'],
                    ['Previstas', $p['expected'], 'text-ink'],
                    ['Realizadas', $p['done'], 'text-emerald-700'],
                    ['Pendientes', $p['pending'], $p['pending'] ? 'text-amber-700' : 'text-ink'],
                ] as [$statLabel, $statValue, $statColor])
                    <div class="flex min-h-[3.25rem] min-w-0 flex-col items-center justify-center rounded-lg bg-ink-50 px-0.5 py-1.5" data-progress-stat>
                        <dt class="w-full truncate text-[10px] min-[360px]:text-[11px] font-semibold leading-tight text-ink-500">{{ $statLabel }}</dt>
                        <dd class="mt-0.5 text-base font-bold leading-none {{ $statColor }}">{{ $statValue }}</dd>
                    </div>
                @endforeach
            </dl>
            @if($p['expected'] === 0)
                <p class="mt-1.5 text-xs text-ink-500">Nada previsto este mes: según la frecuencia, ya se visitó en el período.</p>
            @endif
            @if($p['not_done'])
                <p class="mt-1.5 text-xs font-semibold text-amber-700">De las pendientes, {{ $p['not_done'] }} con remito «no realizado»: no cuenta como realizada y la oficina coordina la nueva visita.</p>
            @endif
            @if($showMachines && $p['machines'])
                <p class="mt-1.5 text-xs text-ink-500">Máquinas en esos edificios: {{ $p['machines'] }}</p>
            @endif
        </div>
    @endforeach
    <details class="text-xs text-ink-500" data-progress-help>
        <summary class="inline-flex min-h-[32px] cursor-pointer items-center gap-1 font-semibold text-brand-700">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3"/><path d="M12 17h.01"/></svg>
            ¿Qué significa cada número?
        </summary>
        <dl class="mt-1 space-y-1 rounded-xl bg-white p-3 ring-1 ring-ink-100">
            <div><dt class="inline font-bold text-ink">Edificios:</dt> <dd class="inline">edificios que tenés asignados para ese trabajo.</dd></div>
            <div><dt class="inline font-bold text-ink">Previstas:</dt> <dd class="inline">visitas que te corresponden en el período según la frecuencia de cada edificio (quincenal = 2 en el mes; trimestral = 1 cada 3 meses). Un edificio sin equipos no suma.</dd></div>
            <div><dt class="inline font-bold text-ink">Realizadas:</dt> <dd class="inline">visitas con remito firmado como realizado.</dd></div>
            <div><dt class="inline font-bold text-ink">Pendientes:</dt> <dd class="inline">previstas que todavía no se completaron (incluye las de remito «no realizado» y las visitas en curso).</dd></div>
            <div>Se cuentan visitas, no ascensores.</div>
        </dl>
    </details>
</div>

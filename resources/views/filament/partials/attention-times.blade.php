{{--
    «Tiempos de atención» de una orden en el panel (solo lectura; los arma
    WorkOrderForm::attentionTimes). Aviso: lo carga la oficina. Llegada y salida:
    las registra el técnico; antes de eso, una sola línea compacta. «Pendiente»
    = todavía puede pasar; «Sin registrar» = no se registró. Una duración solo
    con los dos horarios.
--}}
<style>
    .at-grid { display: grid; grid-template-columns: repeat(1, minmax(0, 1fr)); gap: .5rem; }
    @media (min-width: 640px) { .at-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
    .at-card { border: 1px solid rgb(229 231 235); border-radius: .75rem; padding: .625rem .75rem; min-width: 0; }
    .at-card.is-wide { grid-column: span 1; }
    @media (min-width: 640px) { .at-card.is-wide { grid-column: span 2; } }
    .at-head { display: flex; align-items: center; gap: .375rem; font-size: .7rem; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: rgb(107 114 128); }
    .at-head svg { width: .9rem; height: .9rem; flex-shrink: 0; }
    .at-value { margin-top: .25rem; font-size: .95rem; font-weight: 700; color: rgb(17 24 39); }
    .at-sub { margin-top: .125rem; font-size: .75rem; color: rgb(107 114 128); overflow-wrap: anywhere; }
    .at-pill { display: inline-flex; align-items: center; margin-top: .25rem; border-radius: 999px; padding: .125rem .5rem; font-size: .75rem; font-weight: 600; }
    .at-pill.is-pending { background: rgb(254 243 199); color: rgb(146 64 14); }
    .at-pill.is-missing { background: rgb(243 244 246); color: rgb(75 85 99); }
    .at-durations { display: flex; flex-wrap: wrap; gap: .25rem 1.25rem; margin-top: .75rem; padding-top: .625rem; border-top: 1px solid rgb(243 244 246); font-size: .8rem; color: rgb(107 114 128); }
    .at-durations strong { color: rgb(17 24 39); font-weight: 700; }
    .at-durations .is-pending { color: rgb(146 64 14); font-weight: 600; }
    .at-durations .is-missing { color: rgb(107 114 128); font-weight: 600; }
    .at-visit { margin-top: .5rem; font-size: .75rem; color: rgb(107 114 128); }
    .dark .at-card { border-color: rgb(55 65 81); }
    .dark .at-head, .dark .at-sub, .dark .at-durations, .dark .at-visit, .dark .at-durations .is-missing { color: rgb(156 163 175); }
    .dark .at-value, .dark .at-durations strong { color: rgb(243 244 246); }
    .dark .at-pill.is-pending { background: rgb(120 53 15 / .45); color: rgb(253 230 138); }
    .dark .at-pill.is-missing { background: rgb(55 65 81); color: rgb(209 213 219); }
    .dark .at-durations { border-color: rgb(55 65 81); }
    .dark .at-durations .is-pending { color: rgb(253 230 138); }
</style>

@php
    $icon = [
        'call' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106a1.125 1.125 0 0 0-1.173.417l-.97 1.293a1.125 1.125 0 0 1-1.21.38 12.035 12.035 0 0 1-7.143-7.143 1.125 1.125 0 0 1 .38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z"/>',
        'arrived' => '<path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/>',
        'left' => '<path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9"/>',
    ];
    $head = fn (string $key, string $label) => '<div class="at-head"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">'.$icon[$key].'</svg>'.e($label).'</div>';
@endphp

<div data-attention-times-panel>
    <div class="at-grid">
        <div class="at-card" data-attention="call">
            {!! $head('call', 'Aviso recibido') !!}
            @if($call['at'])
                <div class="at-value">{{ \App\Services\WorkOrders\AttendanceTimes::time($call['at']) }}</div>
                @if($call['by'])<div class="at-sub">{{ $call['by'] }}</div>@endif
            @else
                <span class="at-pill is-missing">Sin registrar</span>
            @endif
        </div>

        @if($technicianStarted)
            @foreach(['arrived' => 'Llegada al edificio', 'left' => 'Salida del edificio'] as $key => $label)
                <div class="at-card" data-attention="{{ $key }}" data-state="{{ ${$key}['state'] }}">
                    {!! $head($key, $label) !!}
                    @if(${$key}['at'])
                        <div class="at-value">{{ ${$key}['label'] }}</div>
                        @if(${$key}['by'])<div class="at-sub">{{ ${$key}['by'] }}</div>@endif
                    @else
                        <span class="at-pill {{ ${$key}['state'] === 'pending' ? 'is-pending' : 'is-missing' }}">{{ ${$key}['label'] }}</span>
                    @endif
                </div>
            @endforeach
        @else
            {{-- Todavía sin llegada ni salida: una línea, no dos tarjetas vacías. --}}
            <div class="at-card is-wide" data-attention="technician" data-state="{{ $arrived['state'] }}">
                {!! $head('arrived', 'Llegada y salida') !!}
                <span class="at-pill {{ $arrived['state'] === 'pending' ? 'is-pending' : 'is-missing' }}">{{ $arrived['state'] === 'pending' ? 'Pendiente de llegada' : 'Sin registrar' }}</span>
                <div class="at-sub">Las registra el técnico desde su celular, con la hora del servidor.</div>
            </div>
        @endif
    </div>

    @if($technicianStarted)
        <div class="at-durations" data-attention-durations>
            @foreach(['response' => 'Respuesta', 'on_site' => 'En el edificio', 'total' => 'Total'] as $key => $label)
                <span data-duration="{{ $key }}">{{ $label }}: <strong class="{{ ${$key}['state'] === 'ok' ? '' : 'is-'.${$key}['state'] }}">{{ ${$key}['value'] }}</strong></span>
            @endforeach
        </div>
    @endif

    @if($visit !== 'Sin visita registrada.')
        <p class="at-visit" data-attention-visit>Visita técnica: {{ $visit }}</p>
    @endif
</div>

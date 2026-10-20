{{--
    Portada de «Guardias» (OnCallHome + OnCallBoard): ahora, después, lo que
    falta y los próximos turnos; lo secundario, plegado abajo. Los botones abren
    los asistentes de la página (asignar y reemplazar, OnCallActions), con la
    franja ya elegida.
--}}
@php
    use App\Services\OnCall\OnCallText;
    $short = fn ($d) => OnCallText::short($d);
    $span = fn ($a, $b) => OnCallText::span($a, $b);
    $iso = fn ($d) => $d->format('Y-m-d H:i');
    $names = fn ($shifts) => \App\Services\OnCall\OnCallPlanner::names(collect($shifts)->map->user);
    $several = $schedules->count() > 1;
@endphp
<x-filament-panels::page>
    <style>
        .oc { display: grid; gap: 1rem; }
        .oc-top { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(min(100%, 20rem), 1fr)); }
        .oc-card { border-radius: .9rem; border: 1px solid rgba(148,163,184,.35); background: #fff; padding: 1rem 1.1rem; min-width: 0; }
        .dark .oc-card { background: rgba(255,255,255,.03); border-color: rgba(255,255,255,.1); }
        .oc-card.is-now { border: 2px solid #ff6600; }
        .oc-card.is-empty { border: 2px solid rgba(220,38,38,.5); }
        .oc-kicker { margin: 0; font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; opacity: .7; }
        .oc-who { margin: .25rem 0 0; font-size: 1.35rem; font-weight: 800; line-height: 1.2; overflow-wrap: anywhere; }
        .oc-when { margin: .2rem 0 0; font-size: 1rem; font-weight: 600; }
        .oc-muted { margin: .15rem 0 0; font-size: .85rem; opacity: .7; overflow-wrap: anywhere; }
        .oc-bad { color: #dc2626; }
        .oc-btns { display: flex; flex-wrap: wrap; gap: .4rem; margin-top: .65rem; }
        .oc-alerts { border-radius: .9rem; padding: .85rem 1rem; background: rgba(220,38,38,.06); border: 1px solid rgba(220,38,38,.25); }
        .oc-alerts ul { margin: .5rem 0 0; padding: 0; list-style: none; display: grid; gap: .45rem; }
        .oc-alert { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .4rem .75rem; font-size: .9rem; }
        .oc-alert span { min-width: 0; overflow-wrap: anywhere; }
        .oc-alert.is-warning span::before { content: '⚠ '; }
        .oc-h2 { margin: 0 0 .5rem; font-size: 1rem; font-weight: 700; }
        .oc-rows { margin: 0; padding: 0; list-style: none; display: grid; }
        .oc-row { display: flex; flex-wrap: wrap; align-items: center; gap: .35rem .9rem; padding: .6rem 0; border-top: 1px solid rgba(148,163,184,.25); }
        .oc-row:first-child { border-top: 0; }
        .oc-row-when { font-weight: 700; min-width: 10.5rem; }
        .oc-row-who { flex: 1 1 10rem; min-width: 0; overflow-wrap: anywhere; }
        .oc-row-btns { display: flex; flex-wrap: wrap; gap: .35rem; }
        .oc-more { border: 1px solid rgba(148,163,184,.3); border-radius: .9rem; background: rgba(255,255,255,.6); }
        .dark .oc-more { background: rgba(255,255,255,.03); }
        .oc-more > summary { cursor: pointer; padding: .85rem 1rem; font-weight: 600; list-style: none; display: flex; justify-content: space-between; gap: .5rem; }
        .oc-more > summary::-webkit-details-marker { display: none; }
        .oc-more > summary::after { content: '+'; opacity: .6; }
        .oc-more[open] > summary::after { content: '−'; }
        .oc-more-body { padding: 0 1rem 1rem; display: grid; gap: .75rem; font-size: .9rem; }
        .oc-day { font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; opacity: .65; margin: .25rem 0 0; }
        .oc-plain { display: grid; gap: .4rem; margin: 0; padding: 0; list-style: none; }
        .oc-plain li { min-width: 0; overflow-wrap: anywhere; }
        .oc-group { margin: .25rem 0 0; font-size: .8rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; opacity: .55; }
        .oc-example { border-left: 3px solid #ff6600; padding: .35rem .75rem; margin: 0; }
    </style>

    <div class="oc" data-on-call-home>
        @if(! $hasSchedules)
            {{-- Sin horario de guardia: qué falta y cómo resolverlo. --}}
            <div class="oc-card" data-on-call-setup>
                <p class="oc-kicker">Primer paso</p>
                <p class="oc-who" style="font-size:1.15rem">{{ $hasAnySchedule ? 'No hay guardias en uso' : 'Contanos en qué horario necesitás guardia' }}</p>
                <p class="oc-muted" style="opacity:.85">Por ejemplo: de lunes a viernes de 18:00 a 08:00 del día siguiente, o sábados y domingos las 24 horas. Después asignás quién cubre cada turno.</p>
                @if($canCreate)
                    <div class="oc-btns"><x-filament::button tag="a" :href="$createUrl" icon="heroicon-m-clock">Configurar el horario de guardia</x-filament::button></div>
                @else
                    <p class="oc-muted">Pedile al dueño de la cuenta que configure el horario de guardia.</p>
                @endif
            </div>
        @else
            {{-- Advertencias: lo que falta cubrir o choca, con la acción para resolverlo. --}}
            @if($warnings)
                <div class="oc-alerts" data-on-call-warnings role="status">
                    <p class="oc-h2 oc-bad" style="margin:0">Para revisar</p>
                    <ul>
                        @foreach(collect($warnings)->take(8) as $w)
                            <li class="oc-alert {{ $w['tone'] === 'warning' ? 'is-warning' : '' }}" data-on-call-warning="{{ $w['type'] }}">
                                <span>{{ $w['text'] }}</span>
                                @if($w['type'] === 'gap' && $canManage($w['schedule']))
                                    <x-filament::button size="sm" icon="heroicon-m-user-plus" wire:click="mountAction('assignOnCall', { schedule: {{ $w['schedule']->id }}, start: '{{ $iso($w['start']) }}', end: '{{ $iso($w['end']) }}' })">Asignar</x-filament::button>
                                @elseif(in_array($w['type'], ['rotation_empty', 'rotation_ending'], true) && $canManage($w['schedule']))
                                    <x-filament::button size="sm" color="gray" tag="a" :href="$editUrl($w['schedule'])">Revisar la rotación</x-filament::button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    @if(count($warnings) > 8)
                        <p class="oc-muted">Y {{ count($warnings) - 8 }} más: miralos en «Próximos turnos» o en el calendario.</p>
                    @endif
                </div>
            @endif

            <div class="oc-top">
                {{-- 1. Ahora --}}
                @php
                    $nowCovered = $now->filter(fn ($s) => $s['shifts']->isNotEmpty());
                    $nowEmpty = $now->filter(fn ($s) => $s['shifts']->isEmpty());
                @endphp
                <section class="oc-card {{ $nowCovered->isNotEmpty() ? 'is-now' : ($nowEmpty->isNotEmpty() ? 'is-empty' : '') }}" data-on-call-now-card aria-label="De guardia ahora">
                    <p class="oc-kicker">De guardia ahora</p>
                    @if($now->isEmpty())
                        <p class="oc-who" style="font-size:1.1rem" data-on-call-now-empty>Ahora no es horario de guardia</p>
                        @if($next)
                            <p class="oc-muted">La próxima guardia empieza {{ $short($next['start']) }}.</p>
                        @endif
                    @endif
                    @foreach($now as $segment)
                        <div data-on-call-now style="margin-top:.35rem">
                            @if($segment['shifts']->isEmpty())
                                <p class="oc-who oc-bad">Nadie está de guardia</p>
                                <p class="oc-when">Falta cubrir hasta {{ $short($segment['end']) }}</p>
                            @else
                                <p class="oc-who">{{ $names($segment['shifts']) }}</p>
                                <p class="oc-when">Hasta {{ $short($segment['end']) }}</p>
                                @php
                                    $window = $segment['schedule']->currentWindow(now());
                                    $since = $segment['shifts']->map(fn ($s) => $s->starts_at)->max()->copy()->max($window[0] ?? $segment['start']);
                                @endphp
                                <p class="oc-muted">Desde {{ $short($since) }}{{ $several ? ' · '.$segment['schedule']->name : '' }}</p>
                                @if($segment['missing'] > 0)
                                    <p class="oc-muted oc-bad" style="opacity:1">Falta{{ $segment['missing'] > 1 ? 'n' : '' }} {{ $segment['missing'] }} de {{ $segment['needed'] }} técnicos</p>
                                @endif
                            @endif
                            @if($canManage($segment['schedule']))
                                <div class="oc-btns">
                                    @if($segment['missing'] > 0)
                                        <x-filament::button size="sm" icon="heroicon-m-user-plus" wire:click="mountAction('assignOnCall', { schedule: {{ $segment['schedule']->id }}, start: '{{ $iso(now()->seconds(0)) }}', end: '{{ $iso($segment['end']) }}' })">Asignar guardia</x-filament::button>
                                    @endif
                                    @foreach($segment['shifts'] as $shift)
                                        <x-filament::button size="sm" color="gray" icon="heroicon-m-arrows-right-left" wire:click="mountAction('replaceOnCall', { shift: {{ $shift->id }}, from: '{{ $iso(now()->seconds(0)) }}', to: '{{ $iso($segment['end']) }}' })" data-on-call-replace="{{ $shift->id }}">Reemplazar a {{ $shift->user->name }}</x-filament::button>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </section>

                {{-- 2. Después --}}
                <section class="oc-card" data-on-call-next aria-label="Próxima guardia">
                    <p class="oc-kicker">Próxima guardia</p>
                    @if(! $next)
                        <p class="oc-who" style="font-size:1.1rem">No hay guardias en los próximos días</p>
                    @else
                        @if($next['shifts']->isEmpty())
                            <p class="oc-who oc-bad">Sin técnico</p>
                        @else
                            <p class="oc-who">{{ $names($next['shifts']) }}</p>
                        @endif
                        <p class="oc-when">Empieza {{ $short($next['start']) }}</p>
                        <p class="oc-muted">Hasta {{ $short($next['end']) }}{{ $several ? ' · '.$next['schedule']->name : '' }}</p>
                        @if($canManage($next['schedule']))
                            <div class="oc-btns">
                                @if($next['missing'] > 0)
                                    <x-filament::button size="sm" icon="heroicon-m-user-plus" wire:click="mountAction('assignOnCall', { schedule: {{ $next['schedule']->id }}, start: '{{ $iso($next['start']) }}', end: '{{ $iso($next['end']) }}' })">Asignar guardia</x-filament::button>
                                @endif
                                @foreach($next['shifts'] as $shift)
                                    <x-filament::button size="sm" color="gray" icon="heroicon-m-arrows-right-left" wire:click="mountAction('replaceOnCall', { shift: {{ $shift->id }}, from: '{{ $iso($next['start']) }}', to: '{{ $iso($next['end']) }}' })">Reemplazar a {{ $shift->user->name }}</x-filament::button>
                                @endforeach
                            </div>
                        @endif
                    @endif
                </section>
            </div>

            {{-- 3. Próximos turnos --}}
            <section class="oc-card" data-on-call-upcoming-list aria-label="Próximos turnos">
                <p class="oc-h2">Próximos turnos <span class="oc-muted" style="font-weight:400">· próximos {{ \App\Services\OnCall\OnCallBoard::UPCOMING_DAYS }} días</span></p>
                @if($upcoming->isEmpty())
                    <p class="oc-muted">No hay turnos en los próximos días.</p>
                @else
                    <ul class="oc-rows">
                        @foreach($upcoming as $segment)
                            <li class="oc-row" data-on-call-upcoming>
                                <span class="oc-row-when">{{ ucfirst($span($segment['start'], $segment['end'])) }}</span>
                                <span class="oc-row-who">
                                    @if($segment['shifts']->isEmpty())
                                        <strong class="oc-bad">Sin técnico</strong>
                                    @else
                                        <strong>{{ $names($segment['shifts']) }}</strong>
                                        @if($segment['missing'] > 0)<span class="oc-bad"> · falta{{ $segment['missing'] > 1 ? 'n' : '' }} {{ $segment['missing'] }}</span>@endif
                                    @endif
                                    @if($several)<span class="oc-muted" style="display:block; margin:0">{{ $segment['schedule']->name }}</span>@endif
                                </span>
                                @if($canManage($segment['schedule']))
                                    <span class="oc-row-btns">
                                        @if($segment['missing'] > 0)
                                            <x-filament::button size="xs" icon="heroicon-m-user-plus" wire:click="mountAction('assignOnCall', { schedule: {{ $segment['schedule']->id }}, start: '{{ $iso($segment['start']) }}', end: '{{ $iso($segment['end']) }}' })">Asignar</x-filament::button>
                                        @endif
                                        @foreach($segment['shifts'] as $shift)
                                            <x-filament::button size="xs" color="gray" wire:click="mountAction('replaceOnCall', { shift: {{ $shift->id }}, from: '{{ $iso($segment['start']) }}', to: '{{ $iso($segment['end']) }}' })">Reemplazar{{ $segment['shifts']->count() > 1 ? ' a '.$shift->user->name : '' }}</x-filament::button>
                                        @endforeach
                                    </span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif

        {{-- 4. Plegado: calendario, historial y turnos anteriores --}}
        @if($hasAnySchedule)
            <details class="oc-more" data-on-call-calendar>
                <summary>Calendario completo (próximas {{ intdiv(\App\Services\OnCall\OnCallBoard::CALENDAR_DAYS, 7) }} semanas)</summary>
                <div class="oc-more-body">
                    <div>
                        <label for="on-call-at" style="display:block; font-weight:600">¿Quién está de guardia el…?</label>
                        <input id="on-call-at" type="datetime-local" wire:model.live.debounce.500ms="at"
                               style="width:100%; max-width:16rem; margin-top:.25rem; border-radius:.5rem; border:1px solid rgba(128,128,128,.35); background:transparent; padding:.45rem .6rem; color:inherit;">
                        @if($queryAt)
                            <div data-on-call-query style="margin-top:.5rem">
                                @forelse($query as $row)
                                    <div style="overflow-wrap:anywhere"><strong>{{ $row['schedule']->name }}:</strong> {{ $row['shifts']->isEmpty() ? 'sin técnico' : $names($row['shifts']) }}</div>
                                @empty
                                    <div class="oc-muted">{{ ucfirst($short($queryAt)) }}: ninguna guardia cubre ese horario.</div>
                                @endforelse
                            </div>
                        @endif
                    </div>
                    @forelse($calendar as $day => $rows)
                        <div>
                            <p class="oc-day">{{ ucfirst(\Carbon\Carbon::parse($day)->translatedFormat('l d/m')) }}</p>
                            <ul class="oc-plain">
                                @foreach($rows as $row)
                                    <li>
                                        <strong>{{ $row['start']->format('H:i') }} a {{ $row['start']->isSameDay($row['end']) ? $row['end']->format('H:i') : $short($row['end']) }}</strong>
                                        @if($several) · {{ $row['schedule']->name }} @endif ·
                                        @if($row['shifts']->isEmpty())<strong class="oc-bad">Sin técnico</strong>@else{{ $names($row['shifts']) }}@endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @empty
                        <p class="oc-muted">Sin guardias en las próximas semanas.</p>
                    @endforelse
                </div>
            </details>

            <details class="oc-more" data-on-call-history>
                <summary>Historial de cambios</summary>
                <div class="oc-more-body">
                    @if($changes->isEmpty())
                        <p class="oc-muted">Sin cambios registrados.</p>
                    @else
                        <ul class="oc-plain">
                            @foreach($changes as $change)
                                <li>
                                    <strong>{{ $change->actionLabel() }}</strong>{{ $several || ! $change->schedule?->is_active ? ' · '.$change->schedule?->name : '' }}
                                    @if($change->fromUser && $change->toUser)
                                        · {{ $change->toUser->name }} en lugar de {{ $change->fromUser->name }}
                                    @elseif($change->toUser)
                                        · {{ $change->toUser->name }}
                                    @elseif($change->fromUser)
                                        · {{ $change->fromUser->name }} (sin reemplazo)
                                    @endif
                                    @if($change->period_start && in_array($change->action, ['assigned', 'replaced', 'cancelled', 'ended'], true))
                                        · {{ $change->period_start->format('d/m H:i') }} a {{ $change->period_end?->format('d/m H:i') ?? 'sin fecha de fin' }}
                                    @endif
                                    @if($change->summary)<div class="oc-muted" style="margin:0">{{ $change->summary }}</div>@endif
                                    <div class="oc-muted" style="margin:0">{{ $change->created_at->format('d/m/Y H:i') }} · {{ $change->actor?->name ?? 'Automático' }}{{ $change->reason ? ' · Motivo: '.$change->reason : '' }}</div>
                                </li>
                            @endforeach
                        </ul>
                        <p class="oc-muted">El historial completo de cada guardia está en su página, pestaña «Historial de cambios».</p>
                    @endif
                </div>
            </details>

            <details class="oc-more" data-on-call-past>
                <summary>Turnos anteriores</summary>
                <div class="oc-more-body">
                    @if($past->isEmpty())
                        <p class="oc-muted">Todavía no hay turnos cumplidos.</p>
                    @else
                        <ul class="oc-plain">
                            @foreach($past as $shift)
                                <li>
                                    <strong>{{ $shift->user?->name }}</strong> · {{ $shift->starts_at->format('d/m H:i') }} a {{ $shift->ends_at->format('d/m H:i') }}
                                    @if($several || ! $shift->schedule?->is_active) · {{ $shift->schedule?->name }} @endif
                                    @if($shift->work_orders_count) · {{ $shift->work_orders_count }} {{ $shift->work_orders_count === 1 ? 'trabajo' : 'trabajos' }} @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </details>

            {{-- 5. Configuración avanzada: horarios y rotaciones --}}
            <details class="oc-more" data-on-call-advanced>
                <summary>Configuración avanzada: horarios y rotaciones</summary>
                <div class="oc-more-body">
                    <p class="oc-group">Horarios de guardia</p>
                    <p class="oc-muted" style="opacity:.85">El horario dice cuándo hace falta alguien de guardia. Un técnico asignado solo está de guardia dentro de ese horario.</p>
                    <ul class="oc-plain">
                        @foreach($schedules as $schedule)
                            <li data-on-call-schedule="{{ $schedule->id }}">
                                <a href="{{ $url($schedule) }}" style="font-weight:600; text-decoration:underline">{{ $schedule->name }}</a>:
                                {{ $schedule->coverageSentence() }}
                                {{ (int) $schedule->technicians_needed > 1 ? $schedule->technicians_needed.' técnicos a la vez.' : '' }}
                                {{ $schedule->isRotation() ? $schedule->modeLabel().'.' : '' }}
                            </li>
                        @endforeach
                    </ul>
                    <div class="oc-btns" style="margin-top:0">
                        @if($canCreate)
                            <x-filament::button size="sm" color="gray" tag="a" :href="$createUrl" icon="heroicon-m-plus">Agregar otro horario de guardia</x-filament::button>
                        @endif
                        <x-filament::button size="sm" color="gray" tag="a" :href="$listUrl">Ver todos los horarios (también los que ya no se usan)</x-filament::button>
                    </div>

                    <p class="oc-group" style="margin-top:.5rem">Rotaciones automáticas</p>
                    <p class="oc-muted" style="opacity:.85">Opcional. Si tus técnicos se turnan siempre en el mismo orden, Ascento puede armar los turnos por vos. Para asignar una guardia puntual no hace falta.</p>
                    <p class="oc-example">Ejemplo: Juan, Ana y Luis, turnos de 7 días desde el lunes 3/11 a las 18:00 → Juan del lun 3/11 al lun 10/11, Ana del 10/11 al 17/11, Luis del 17/11 al 24/11, y vuelve a empezar Juan.</p>
                    <p class="oc-muted" style="opacity:.85">Se activa en el horario de guardia, en «Configuración avanzada: rotación automática». Antes de guardar ves la vista previa de los turnos.</p>
                </div>
            </details>
        @endif
    </div>

</x-filament-panels::page>

{{-- Guardias: ahora, después y lo que falta cubrir; calendario, consulta e historial plegados (OnCallOverview). --}}
@php
    $fmt = fn ($d) => ucfirst($d->translatedFormat('D d/m H:i'));
    $until = fn ($start, $end) => $start->isSameDay($end) ? $end->format('H:i') : $fmt($end);
@endphp
<x-filament-widgets::widget>
    <style>
        .oc { display: grid; gap: 1rem; }
        .oc-top { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(min(100%, 22rem), 1fr)); }
        .oc-list { display: grid; gap: .75rem; margin: 0; padding: 0; list-style: none; }
        .oc-item { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .5rem .75rem; min-width: 0; }
        .oc-who { font-size: 1.1rem; font-weight: 700; overflow-wrap: anywhere; }
        .oc-bad { color: #dc2626; }
        .oc-muted { font-size: .85rem; opacity: .7; }
        .oc-name { font-weight: 600; overflow-wrap: anywhere; }
        .oc-btns { display: flex; flex-wrap: wrap; gap: .4rem; }
        .oc-alert { border-radius: .75rem; padding: .75rem 1rem; background: rgba(220,38,38,.08); border: 1px solid rgba(220,38,38,.25); }
        .oc-more { border: 1px solid rgba(148,163,184,.3); border-radius: .75rem; background: rgba(255,255,255,.6); }
        .dark .oc-more { background: rgba(255,255,255,.03); }
        .oc-more > summary { cursor: pointer; padding: .8rem 1rem; font-weight: 600; list-style: none; display: flex; justify-content: space-between; gap: .5rem; }
        .oc-more > summary::-webkit-details-marker { display: none; }
        .oc-more > summary::after { content: '+'; opacity: .6; }
        .oc-more[open] > summary::after { content: '−'; }
        .oc-more-body { padding: 0 1rem 1rem; display: grid; gap: .75rem; }
        .oc-day { font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; opacity: .65; margin: .25rem 0 0; }
        .oc-cal { display: grid; gap: .35rem; margin: 0; padding: 0; list-style: none; font-size: .9rem; }
    </style>

    <div class="oc" data-on-call-overview>
        @if(! $hasSchedules)
            <x-filament::section>
                <x-slot name="heading">Todavía no hay guardias</x-slot>
                <p class="oc-muted" style="margin:0">Creá una guardia con «Nueva guardia»: elegí los días y el horario (por ejemplo, de lunes a viernes de 18 a 8) y después asigná quién la cubre.</p>
            </x-filament::section>
        @else
            <div class="oc-top">
                {{-- 1. Ahora --}}
                <x-filament::section>
                    <x-slot name="heading">De guardia ahora</x-slot>
                    <x-slot name="description">{{ $fmt(now()) }}</x-slot>
                    @if(empty($now))
                        <p data-on-call-now-empty class="oc-muted" style="margin:0">Ninguna guardia cubre este horario.</p>
                    @else
                        <ul class="oc-list">
                            @foreach($now as $row)
                                <li data-on-call-now>
                                    <a href="{{ $url($row['schedule']) }}" class="oc-name">{{ $row['schedule']->name }}</a>
                                    @if($row['shifts']->isEmpty())
                                        <div class="oc-item">
                                            <span class="oc-who oc-bad">Sin cobertura</span>
                                            @if($canManage($row['schedule']))
                                                <x-filament::button size="sm" icon="heroicon-m-user-plus" wire:click="$dispatch('on-call-assign', { schedule: {{ $row['schedule']->id }} })">Asignar</x-filament::button>
                                            @endif
                                        </div>
                                    @else
                                        @foreach($row['shifts'] as $shift)
                                            <div class="oc-item">
                                                <span class="oc-who">{{ $shift->user->name }}</span>
                                                @if($canManage($row['schedule']))
                                                    <x-filament::button size="sm" color="gray" icon="heroicon-m-arrows-right-left" wire:click="$dispatch('on-call-replace', { shift: {{ $shift->id }} })" data-on-call-replace="{{ $shift->id }}">Reemplazar</x-filament::button>
                                                @endif
                                            </div>
                                        @endforeach
                                        @if($row['shifts']->count() < $row['needed'])
                                            <div class="oc-bad oc-muted" style="opacity:1">Faltan {{ $row['needed'] - $row['shifts']->count() }} de {{ $row['needed'] }} técnicos</div>
                                        @endif
                                    @endif
                                    <div class="oc-muted">Hasta {{ $fmt($row['window'][1]) }}</div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-filament::section>

                {{-- 2. Después --}}
                <x-filament::section>
                    <x-slot name="heading">Después</x-slot>
                    <x-slot name="description">La próxima franja de cada guardia</x-slot>
                    @if($next->isEmpty())
                        <p class="oc-muted" style="margin:0">No hay franjas próximas.</p>
                    @else
                        <ul class="oc-list" data-on-call-next>
                            @foreach($next as $row)
                                <li>
                                    <div class="oc-muted">{{ $fmt($row['window'][0]) }} a {{ $until($row['window'][0], $row['window'][1]) }} · <a href="{{ $url($row['schedule']) }}">{{ $row['schedule']->name }}</a></div>
                                    <div class="oc-item">
                                        @if($row['shifts']->isEmpty())
                                            <span class="oc-who oc-bad">Sin técnico</span>
                                            @if($canManage($row['schedule']))
                                                <x-filament::button size="sm" icon="heroicon-m-user-plus" wire:click="$dispatch('on-call-assign', { schedule: {{ $row['schedule']->id }} })">Asignar</x-filament::button>
                                            @endif
                                        @else
                                            <span class="oc-who">{{ $names($row['shifts']) }}</span>
                                            @if($canManage($row['schedule']) && $row['shifts']->count() === 1)
                                                <x-filament::button size="sm" color="gray" icon="heroicon-m-arrows-right-left" wire:click="$dispatch('on-call-replace', { shift: {{ $row['shifts']->first()->id }} })">Reemplazar</x-filament::button>
                                            @endif
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-filament::section>
            </div>

            {{-- 3. Lo que falta cubrir --}}
            @if($gaps->isNotEmpty())
                <div class="oc-alert" data-on-call-gaps>
                    <p class="oc-name oc-bad" style="margin:0">Hay franjas sin técnico en los próximos 7 días</p>
                    <ul class="oc-list" style="margin-top:.5rem; gap:.4rem">
                        @foreach($gaps as $row)
                            <li class="oc-item">
                                <span style="min-width:0; overflow-wrap:anywhere">
                                    <a href="{{ $url($row['schedule']) }}" class="oc-name">{{ $row['schedule']->name }}</a>:
                                    {{ collect($row['gaps'])->take(3)->map(fn ($g) => $fmt($g['start']).' a '.$until($g['start'], $g['end']))->implode(' · ') }}{{ count($row['gaps']) > 3 ? ' · y '.(count($row['gaps']) - 3).' más' : '' }}
                                </span>
                                @if($canManage($row['schedule']))
                                    <x-filament::button size="sm" icon="heroicon-m-user-plus" wire:click="$dispatch('on-call-assign', { schedule: {{ $row['schedule']->id }} })">Asignar</x-filament::button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- 4. Plegados: calendario, consulta e historial --}}
            <details class="oc-more" data-on-call-calendar>
                <summary>Calendario de los próximos 14 días</summary>
                <div class="oc-more-body">
                    @forelse($calendar as $day => $rows)
                        <div>
                            <p class="oc-day">{{ ucfirst(\Carbon\Carbon::parse($day)->translatedFormat('l d/m')) }}</p>
                            <ul class="oc-cal">
                                @foreach($rows as $row)
                                    <li data-on-call-upcoming style="min-width:0; overflow-wrap:anywhere">
                                        <span style="font-weight:600">{{ $row['start']->format('H:i') }} a {{ $until($row['start'], $row['end']) }}</span>
                                        · {{ $row['schedule']->name }} ·
                                        <span class="{{ $row['names'] === '' ? 'oc-bad' : '' }}" style="{{ $row['names'] === '' ? 'font-weight:600' : '' }}">{{ $row['names'] === '' ? 'Sin técnico' : $row['names'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @empty
                        <p class="oc-muted" style="margin:0">Sin franjas de guardia en los próximos 14 días.</p>
                    @endforelse

                    <div>
                        <label for="on-call-at" class="oc-name" style="display:block">¿Quién está de guardia el…?</label>
                        <input id="on-call-at" type="datetime-local" wire:model.live.debounce.500ms="at"
                               style="width:100%; max-width:16rem; margin-top:.25rem; border-radius:.5rem; border:1px solid rgba(128,128,128,.35); background:transparent; padding:.45rem .6rem; color:inherit;">
                        @if($queryAt)
                            <div data-on-call-query style="margin-top:.5rem">
                                @forelse($query as $row)
                                    <div style="overflow-wrap:anywhere"><strong>{{ $row['schedule']->name }}:</strong> {{ $row['shifts']->isEmpty() ? 'sin cobertura' : $names($row['shifts']) }}</div>
                                @empty
                                    <div class="oc-muted">{{ $fmt($queryAt) }}: ninguna guardia cubre ese horario.</div>
                                @endforelse
                            </div>
                        @endif
                    </div>
                </div>
            </details>

            <details class="oc-more" data-on-call-history>
                <summary>Últimos cambios</summary>
                <div class="oc-more-body">
                    @if($changes->isEmpty())
                        <p class="oc-muted" style="margin:0">Sin cambios registrados.</p>
                    @else
                        <ul class="oc-cal">
                            @foreach($changes as $change)
                                <li style="min-width:0; overflow-wrap:anywhere">
                                    <span style="font-weight:600">{{ $change->actionLabel() }}</span> · {{ $change->schedule?->name }}
                                    @if($change->fromUser || $change->toUser)
                                        · {{ $change->fromUser?->name ?? '—' }} → {{ $change->toUser?->name ?? 'sin técnico' }}
                                    @endif
                                    <div class="oc-muted">{{ $change->created_at->format('d/m H:i') }} · {{ $change->actor?->name ?? 'Automático' }}{{ $change->reason ? ' · '.$change->reason : '' }}</div>
                                </li>
                            @endforeach
                        </ul>
                        <p class="oc-muted" style="margin:0">El historial completo de cada guardia está en la guardia, pestaña «Cambios».</p>
                    @endif
                </div>
            </details>
        @endif
    </div>
</x-filament-widgets::widget>

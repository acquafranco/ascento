{{-- Guardias: ahora, consulta por fecha y hora, próximas franjas y últimos cambios (OnCallOverview). --}}
@php
    $fmt = fn ($d) => ucfirst($d->translatedFormat('D d/m H:i'));
    $muted = 'opacity: .7;';
@endphp
<x-filament-widgets::widget>
    <div data-on-call-overview style="display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(min(100%, 20rem), 1fr));">
        <x-filament::section>
            <x-slot name="heading">De guardia ahora</x-slot>
            <x-slot name="description">{{ $fmt(now()) }}</x-slot>
            @if(! $hasSchedules)
                <p style="{{ $muted }}">Todavía no hay guardias configuradas.</p>
            @elseif(empty($now))
                <p data-on-call-now-empty style="{{ $muted }}">Ninguna guardia cubre este horario.</p>
            @else
                <ul style="display: grid; gap: .6rem; margin: 0; padding: 0; list-style: none;">
                    @foreach($now as $row)
                        <li data-on-call-now style="min-width: 0;">
                            <a href="{{ $url($row['schedule']) }}" style="font-weight: 600; overflow-wrap: anywhere;">{{ $row['schedule']->name }}</a>
                            <div style="font-size: 1.05rem; font-weight: 700; overflow-wrap: anywhere; {{ $row['shifts']->isEmpty() ? 'color: #dc2626;' : '' }}">
                                {{ $row['shifts']->isEmpty() ? 'Sin cobertura' : $names($row['shifts']) }}
                                @if($row['shifts']->isNotEmpty() && $row['shifts']->count() < $row['needed'])
                                    <span style="font-size: .85rem; color: #dc2626;">({{ $row['shifts']->count() }} de {{ $row['needed'] }})</span>
                                @endif
                            </div>
                            <div style="font-size: .85rem; {{ $muted }}">Hasta {{ $fmt($row['window'][1]) }}</div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">¿Quién está de guardia el…?</x-slot>
            <label for="on-call-at" style="display: block; font-size: .85rem; {{ $muted }}">Fecha y hora</label>
            <input id="on-call-at" type="datetime-local" wire:model.live.debounce.500ms="at"
                   style="width: 100%; max-width: 16rem; margin-top: .25rem; border-radius: .5rem; border: 1px solid rgba(128,128,128,.35); background: transparent; padding: .45rem .6rem; color: inherit;">
            @if($queryAt)
                <div data-on-call-query style="margin-top: .75rem;">
                    @forelse($query as $row)
                        <div style="overflow-wrap: anywhere;"><strong>{{ $row['schedule']->name }}:</strong> {{ $row['shifts']->isEmpty() ? 'sin cobertura' : $names($row['shifts']) }}</div>
                    @empty
                        <div style="{{ $muted }}">{{ $fmt($queryAt) }}: ninguna guardia cubre ese horario.</div>
                    @endforelse
                </div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Próximas franjas (7 días)</x-slot>
            @if($upcoming->isEmpty())
                <p style="{{ $muted }}">Sin franjas de guardia en los próximos 7 días.</p>
            @else
                <ul style="display: grid; gap: .45rem; margin: 0; padding: 0; list-style: none; font-size: .9rem;">
                    @foreach($upcoming as $row)
                        <li data-on-call-upcoming style="min-width: 0; overflow-wrap: anywhere;">
                            <span style="font-weight: 600;">{{ $fmt($row['start']) }}–{{ $row['end']->format('H:i') }}</span>
                            · {{ $row['schedule']->name }} ·
                            <span style="{{ $row['names'] === '' ? 'color: #dc2626; font-weight: 600;' : '' }}">{{ $row['names'] === '' ? 'Sin técnico' : $row['names'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Últimos cambios</x-slot>
            @if($changes->isEmpty())
                <p style="{{ $muted }}">Sin cambios registrados.</p>
            @else
                <ul style="display: grid; gap: .45rem; margin: 0; padding: 0; list-style: none; font-size: .9rem;">
                    @foreach($changes as $change)
                        <li style="min-width: 0; overflow-wrap: anywhere;">
                            <span style="font-weight: 600;">{{ $change->actionLabel() }}</span> · {{ $change->schedule?->name }}
                            @if($change->fromUser || $change->toUser)
                                · {{ $change->fromUser?->name ?? '—' }} → {{ $change->toUser?->name ?? 'sin técnico' }}
                            @endif
                            <div style="font-size: .8rem; {{ $muted }}">{{ $change->created_at->format('d/m H:i') }} · {{ $change->actor?->name ?? 'Automático' }}{{ $change->reason ? ' · '.$change->reason : '' }}</div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>
    </div>
</x-filament-widgets::widget>

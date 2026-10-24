{{--
    Guardias del técnico: solo consulta (la planificación la hace la oficina).
    Arriba, si está de guardia AHORA; después sus próximas guardias, los trabajos
    de guardia que tiene asignados (enlazan a «Trabajos»), los cambios que lo
    afectaron y su historial. Ver OnCallController.
--}}
@php
    $slug = auth()->user()->company->slug;
    $fmt = fn ($d) => ucfirst($d->translatedFormat('D d/m H:i'));
    $woStatus = fn ($wo) => match (true) {
        $wo->status === 'completed' => ['Realizado', 't-chip-done'],
        $wo->status === 'in_progress' => ['En curso', \App\Support\WorkOrderLabels::statusChip('in_progress')],
        $wo->status === 'failed' => ['No realizado', 't-chip-failed'],
        default => ['Asignado, sin empezar', 't-chip-pending'],
    };
    $nothing = $upcoming->isEmpty() && $history->isEmpty() && $changes->isEmpty();
@endphp
<x-app-layout>
<div class="t-page">
    <header class="mb-4">
        <h1 class="t-h1">Guardias</h1>
        <p class="t-sub">Cuándo te toca cubrir fuera de horario. La planificación la arma la oficina.</p>
    </header>

    {{-- Guardia activa ahora --}}
    @if($current)
        <section data-on-call-active class="mb-4 rounded-2xl border-2 border-brand bg-brand-50 p-4" aria-label="Guardia activa">
            <p class="text-xs font-bold uppercase tracking-[0.08em] text-brand-700">Estás de guardia ahora</p>
            <p class="mt-1 text-lg font-bold text-ink">{{ $current['schedule']->name }}</p>
            <p class="text-sm font-semibold text-ink-700">Hasta {{ $fmt($current['until']) }}</p>
            <p class="mt-1 text-sm text-ink-500">Turno: {{ $current['shift']->periodLabel() }}</p>
            @if($current['schedule']->notes)
                <p class="mt-2 whitespace-pre-line text-sm text-ink-700">{{ $current['schedule']->notes }}</p>
            @endif
        </section>
    @endif

    @if($nothing)
        <div class="t-empty" data-on-call-empty>
            <p class="font-semibold text-ink">No tenés guardias programadas</p>
            <p class="mt-1">Cuando la oficina te asigne una guardia, aparece acá y te llega un aviso.</p>
        </div>
    @endif

    {{-- Próximas (y vigentes) --}}
    @if($upcoming->isNotEmpty())
        <h2 class="t-section">Próximas guardias</h2>
        <div class="t-card t-divide" data-on-call-upcoming>
            @foreach($upcoming as $row)
                <div class="t-row items-start">
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold text-ink">{{ $row['schedule']->name }}</p>
                        <p class="text-sm text-ink-700">{{ $row['shift']->periodLabel() }}</p>
                        <p class="text-xs text-ink-500">{{ $row['schedule']->coverageLabel() }}</p>
                        @if($row['on_duty'])
                            <p class="mt-1 text-sm font-semibold text-brand-700">Cubriendo ahora, hasta {{ $fmt($row['until']) }}</p>
                        @elseif($row['next'])
                            <p class="mt-1 text-sm text-ink-700">Próxima franja: {{ $fmt($row['next'][0]) }} a {{ $fmt($row['next'][1]) }}</p>
                        @endif
                        @if($row['shift']->origin === 'replacement' && $row['shift']->parent?->user)
                            <p class="mt-1 text-xs text-ink-500">Cambio de guardia: reemplazás a {{ $row['shift']->parent->user->name }}.</p>
                        @endif
                    </div>
                    <span class="{{ $row['on_duty'] ? 't-chip-progress' : 't-chip-neutral' }} shrink-0">{{ $row['on_duty'] ? 'Ahora' : ($row['started'] ? 'Vigente' : 'Próxima') }}</span>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Trabajos de guardia asignados (se hacen en «Trabajos», como siempre) --}}
    @if($workOrders->isNotEmpty())
        <h2 class="t-section">Trabajos de guardia asignados</h2>
        <div class="t-card t-divide" data-on-call-work-orders>
            @foreach($workOrders as $wo)
                @php [$label, $chip] = $woStatus($wo); @endphp
                <a href="{{ route('work-orders.show', ['company' => $slug, 'workOrder' => $wo->id]) }}" class="t-row items-start hover:bg-ink-50">
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-semibold text-ink">{{ trim(($wo->building?->name ?? '').' '.($wo->building?->address ?? '')) }}</p>
                        <p class="text-xs text-ink-500">{{ $wo->onCallShift?->schedule?->name }} · cargada el {{ $wo->created_at->format('d/m H:i') }}{{ $wo->deliveryNote ? ' · con remito' : '' }}</p>
                    </div>
                    <span class="{{ $chip }} shrink-0">{{ $label }}</span>
                </a>
            @endforeach
        </div>
        <p class="mt-1 px-1 text-xs text-ink-500">Tener una guardia no implica tener trabajos: estos son los que la oficina te asignó durante una.</p>
    @endif

    {{-- Cambios que te afectaron --}}
    @if($changes->isNotEmpty())
        <h2 class="t-section">Cambios en tus guardias</h2>
        <div class="t-card t-divide" data-on-call-changes>
            @foreach($changes as $change)
                @php
                    $mineFrom = (int) $change->from_user_id === (int) auth()->id();
                    $text = match (true) {
                        $change->action === 'assigned' => 'Te asignaron la guardia',
                        $change->action === 'replaced' && $mineFrom => 'Te reemplaza '.($change->toUser?->name ?? 'otro técnico'),
                        $change->action === 'replaced' => 'Reemplazás a '.($change->fromUser?->name ?? 'otro técnico'),
                        $change->action === 'ended' => 'Tu turno se finalizó',
                        default => 'Tu turno se canceló',
                    };
                @endphp
                <div class="t-row items-start">
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold text-ink">{{ $text }}</p>
                        <p class="text-sm text-ink-700">{{ $change->schedule?->name }} · {{ $change->period_start?->format('d/m H:i') }}{{ $change->period_end ? ' a '.$change->period_end->format('d/m H:i') : ' en adelante' }}</p>
                        <p class="text-xs text-ink-500">Registrado el {{ $change->created_at->format('d/m/Y H:i') }}{{ $change->reason ? ' · '.$change->reason : '' }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Historial --}}
    @if($history->isNotEmpty())
        <h2 class="t-section">Guardias anteriores</h2>
        <div class="t-card t-divide" data-on-call-history>
            @foreach($history as $shift)
                <div class="t-row">
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold text-ink">{{ $shift->schedule?->name }}</p>
                        <p class="text-sm text-ink-700">{{ $shift->periodLabel() }}</p>
                    </div>
                    <span class="t-chip-neutral shrink-0">Terminó</span>
                </div>
            @endforeach
        </div>
    @endif
</div>
</x-app-layout>

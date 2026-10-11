{{--
    Detalle de un día de la planilla (técnico, o un admin mirando la de un
    técnico: $user). Mismos datos que antes: edificio, tipo, horarios,
    participación y el remito (propio o su enlace público).
--}}
@php
    $slug = auth()->user()->company->slug;
    $currentUser = isset($user) ? $user : auth()->user();
    $workTypes = ['claim' => 'Reclamo', 'inspection' => 'Inspección', 'maintenance' => 'Mantenimiento', 'installation' => 'Instalación', 'modernization' => 'Modernización'];
@endphp
<x-app-layout>
<div class="t-page">
    <a href="{{ isset($user) ? route('users.template', ['company' => $slug, 'user' => $user->id]) : route('templates.index', ['company' => $slug, 'month' => $date->month, 'year' => $date->year]) }}"
       class="inline-flex min-h-[40px] items-center gap-1 text-sm font-semibold text-ink-500 hover:text-ink">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m15 18-6-6 6-6"/></svg>
        Planilla
    </a>
    <header class="mb-4 mt-1">
        <h1 class="t-h1">{{ ucfirst($date->translatedFormat('l d \d\e F Y')) }}</h1>
        @isset($user)<p class="t-sub">Técnico: <span class="font-semibold text-ink">{{ $user->name }}</span></p>@endisset
    </header>

    @if($visits->isEmpty())
        <div class="t-empty">
            <p class="font-semibold text-ink">No hay trabajos registrados para este día</p>
            <p class="mt-1">Cuando completes un mantenimiento, una inspección o una orden de trabajo, aparece acá.</p>
        </div>
    @else
        <div class="space-y-3">
            @foreach($visits as $visit)
                @php
                    $remito = $visit->deliveryNote ?? $visit->workOrder?->deliveryNote;
                    $mine = (int) $visit->user_id === (int) $currentUser->id;
                    $label = $visit->source === 'work_order'
                        ? 'Orden de trabajo · '.($workTypes[$visit->work_type] ?? ucfirst($visit->work_type ?? ''))
                        : ($visit->assignment_type === 'inspection' ? 'Inspección' : 'Mantenimiento');
                    $times = $visit->source === 'work_order'
                        ? 'Entrada '.($visit->workOrder?->started_at?->format('H:i') ?? $visit->started_at?->format('H:i') ?? '–').' · Salida '.($visit->workOrder?->finished_at?->format('H:i') ?? $visit->finished_at?->format('H:i') ?? '–')
                        : 'Visita '.optional($visit->visited_at)->format('H:i');
                @endphp
                <article class="t-card p-4">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="t-chip-neutral">{{ $label }}</span>
                        <span class="{{ $mine ? 't-chip-done' : 't-chip-progress' }}">{{ $mine ? (isset($user) ? 'Lo hizo '.$user->name : 'Lo hiciste vos') : 'Participó con '.($visit->user?->name ?? 'otro técnico') }}</span>
                    </div>
                    <h2 class="mt-2 text-[17px] font-bold leading-snug text-ink">{{ $visit->building?->name ?? 'Edificio' }}</h2>
                    <p class="text-sm text-ink-500">{{ $visit->building?->address }}{{ $visit->building?->client?->name ? ' · '.$visit->building->client->name : '' }}</p>
                    <p class="mt-2 text-sm text-ink-700">{{ $times }}</p>

                    @if($remito)
                        <div class="mt-3">
                            @if((int) $remito->user_id === (int) auth()->id())
                                <a href="{{ route('delivery-notes.show', ['company' => $slug, 'deliveryNote' => $remito->number]) }}" class="t-btn-secondary t-btn-sm">Ver remito {{ $remito->number }}</a>
                            @elseif($remito->public_token)
                                <a href="{{ route('delivery-notes.public', ['company' => $slug, 'token' => $remito->public_token]) }}" target="_blank" rel="noopener" class="t-btn-secondary t-btn-sm">Ver remito {{ $remito->number }}</a>
                            @else
                                <span class="t-chip-pending">Remito no disponible</span>
                            @endif
                        </div>
                    @endif
                </article>
            @endforeach
        </div>
    @endif
</div>
</x-app-layout>

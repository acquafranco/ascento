{{--
    Visitas del técnico: sus edificios asignados con el mantenimiento y/o la
    inspección del mes elegido. Desde acá se hace el remito de cada visita.
    Estado y progreso con las reglas de VisitSchedule (frecuencia de cada
    edificio): quincenal = 2 visitas en el mes; cada N meses = 1 visita en
    el período, y los meses en que ya se hizo se muestran como "hecho en el
    período" (no como pendiente). Mismos filtros que antes (mes, año, búsqueda).
--}}
@php
    use App\Support\TechNav;
    $slug = auth()->user()->company->slug;
    $machines = fn (int $n, string $one, string $many) => $n.' '.($n === 1 ? $one : $many);
@endphp
<x-app-layout>
<div class="t-page">
    <header class="mb-4">
        <h1 class="t-h1">Visitas</h1>
        <p class="t-sub">Mantenimientos e inspecciones de tus edificios asignados.</p>
    </header>

    @if(session('success'))
        <div class="t-alert-success mb-4" role="status">{{ session('success') }}</div>
    @endif
    @if($errors->has('general'))
        <div class="t-alert-error mb-4" role="alert">{{ $errors->first('general') }}</div>
    @endif

    {{-- Progreso del mes: visitas previstas por edificio (no máquinas) --}}
    <div class="mb-4">
        <p class="mb-2 text-xs font-semibold text-ink-500">
            Período: {{ ucfirst(\Carbon\Carbon::create($year, $month, 1)->locale('es')->translatedFormat('F Y')) }}
            · {{ $machines($totalMachines, 'máquina asignada', 'máquinas asignadas') }}
        </p>
        @include('partials.visit-progress', ['progress' => $progress, 'showMachines' => true])
    </div>

    <form method="GET" id="filtersForm" class="mb-4 space-y-2" role="search">
        <div class="grid grid-cols-2 gap-2">
            <select name="month" class="t-input" aria-label="Mes" onchange="this.form.submit()">
                @for($m = 1; $m <= 12; $m++)
                    <option value="{{ $m }}" @selected($month == $m)>{{ ucfirst(\Carbon\Carbon::create()->month($m)->translatedFormat('F')) }}</option>
                @endfor
            </select>
            <select name="year" class="t-input" aria-label="Año" onchange="this.form.submit()">
                @for($y = now()->year - 2; $y <= now()->year + 2; $y++)
                    <option value="{{ $y }}" @selected($year == $y)>{{ $y }}</option>
                @endfor
            </select>
        </div>
        <input type="search" name="search" value="{{ request('search') }}" id="searchInput" autocomplete="off"
               placeholder="Edificio, calle, barrio o cliente" class="t-input" aria-label="Buscar edificio">
    </form>

    <div class="space-y-3" id="buildingsList">
        @forelse($buildings as $building)
            @php
                $types = $building->users->pluck('pivot.type')->flatMap(fn ($type) => explode(',', $type))->toArray();
                $place = collect([$building->locality, $building->neighborhood, $building->municipality])->filter()->unique()->implode(' · ');
                $address = trim(($building->address ?? '').', '.($building->locality ?? ''), ', ');
            @endphp
            <article class="t-card overflow-hidden">
                <div class="p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="text-[17px] font-bold leading-snug text-ink">{{ $building->name }}</h2>
                            <p class="text-sm text-ink-700">{{ $building->address }}</p>
                            <p class="text-xs text-ink-500">{{ $place }}{{ $place && $building->client ? ' · ' : '' }}{{ $building->client?->name }}</p>
                        </div>
                        @if($address !== '')
                            <a href="https://www.google.com/maps/search/?api=1&query={{ rawurlencode($address) }}" target="_blank" rel="noopener"
                               class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-ink-100 text-ink-500 hover:bg-ink-50" aria-label="Cómo llegar a {{ $building->name }}">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">{!! TechNav::icon('pin') !!}</svg>
                            </a>
                        @endif
                    </div>

                    <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-sm text-ink-500">
                        <span><strong class="text-ink">{{ $machines((int) $building->elevator_count, 'ascensor', 'ascensores') }}</strong>{{ $building->freight_elevator_count ? ' · '.$machines((int) $building->freight_elevator_count, 'montacargas', 'montacargas') : '' }}</span>
                        @if($building->contact_person)<span>Contacto: <strong class="text-ink">{{ $building->contact_person }}</strong></span>@endif
                        @if($building->phone)
                            <a href="tel:{{ preg_replace('/[^0-9+]/', '', $building->phone) }}" class="font-semibold text-brand-700">{{ $building->phone }}</a>
                        @endif
                    </div>
                </div>

                {{-- Estado y acción de cada tipo de visita asignado --}}
                <div class="t-divide border-t border-ink-100">
                    @foreach(['maintenance' => 'Mantenimiento', 'inspection' => 'Inspección'] as $type => $label)
                        @continue(! in_array($type, $types))
                        @php
                            $row = $rows->get($building->id.'|'.$type);
                            $period = $row['period'] ?? null;
                            $expected = $period?->expected ?? 1;
                            $status = $row['status'] ?? 'pending';
                            $openVisit = ($row['visits'] ?? collect())->first(fn ($v) => $v->in_progress);
                            $closedVisits = ($row['visits'] ?? collect())->reject(fn ($v) => $v->in_progress);
                            $nextSlot = $openVisit ? (int) $openVisit->slot : $closedVisits->count() + 1;
                            $photosOn = in_array($type, $photoTypes, true);
                            $statusChip = ['pending' => ['t-chip-pending', 'Pendiente'], 'overdue' => ['t-chip-failed', 'Vencido'], 'upcoming' => ['t-chip-neutral', 'Próximo']];
                        @endphp
                        <div class="px-4 py-3" data-visit-row="{{ $type }}">
                            <div class="flex items-center gap-3">
                                <div class="min-w-0 flex-1">
                                    <div class="text-sm font-semibold text-ink">{{ $label }}</div>
                                    @if($period && ($period->plan || $period->isMultiMonth()))
                                        <p class="text-xs text-ink-500">{{ $period->frequencyLabel() }}{{ $period->isMultiMonth() ? ' · período '.$period->label() : '' }}</p>
                                    @endif
                                </div>
                                @if(($row['pending'] ?? 1) > 0 && $status !== 'no_equipment')
                                    <a href="{{ route('delivery-notes.building', ['company' => $slug, 'building' => $building, 'month' => $month, 'year' => $year, 'assignment_type' => $type]) }}"
                                       class="{{ $type === 'maintenance' ? 't-btn-primary' : 't-btn-dark' }} t-btn-sm shrink-0">
                                        {{ $openVisit ? 'Hacer remito' : ($expected > 1 && $nextSlot > 1 ? 'Marcar '.$nextSlot.'ª visita' : 'Marcar '.mb_strtolower($label)) }}
                                    </a>
                                @endif
                            </div>

                            @if($status === 'no_equipment')
                                <p class="mt-1.5 text-xs font-semibold text-ink-700" data-no-equipment>Sin ascensores ni montacargas registrados: no hay visitas para hacer. Avisale a la oficina.</p>
                            @endif

                            {{-- Visita en curso (abierta desde acá para cargar fotos) --}}
                            @if($openVisit)
                                <div class="mt-2 space-y-2" data-open-visit>
                                    <span class="t-chip-pending">{{ $expected > 1 ? 'Visita '.$openVisit->slot.': ' : '' }}En curso · sin remito</span>
                                    <a href="{{ route('visits.show', ['company' => $slug, 'visit' => $openVisit->id]) }}" class="t-btn-secondary t-btn-sm w-full" data-work-photos-link>
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                                        Fotos del trabajo ({{ $photoCounts[$openVisit->id] ?? 0 }})
                                    </a>
                                </div>
                            @elseif($photosOn && ($row['pending'] ?? 0) > 0 && $status !== 'no_equipment')
                                <form method="POST" action="{{ route('visits.start', ['company' => $slug]) }}" class="mt-1.5">
                                    @csrf
                                    <input type="hidden" name="building_id" value="{{ $building->id }}">
                                    <input type="hidden" name="assignment_type" value="{{ $type }}">
                                    <input type="hidden" name="month" value="{{ $month }}">
                                    <input type="hidden" name="year" value="{{ $year }}">
                                    <button type="submit" class="t-btn-secondary t-btn-sm mt-1 w-full" data-start-visit>
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                                        Fotos del trabajo
                                    </button>
                                </form>
                            @endif

                            {{-- Visitas ya registradas en el período --}}
                            @foreach($closedVisits as $visit)
                                @php $notDone = $visit->deliveryNote?->performed === false; @endphp
                                <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1">
                                    @if($notDone)
                                        <span class="t-chip-failed">{{ $expected > 1 ? 'Visita '.$visit->slot.': ' : '' }}Remito firmado: no se pudo realizar</span>
                                    @else
                                        <span class="t-chip-done">{{ $expected > 1 ? 'Visita '.$visit->slot.' de '.$expected.' realizada' : ($type === 'maintenance' ? 'Mantenimiento realizado' : 'Inspección realizada') }}</span>
                                    @endif
                                    <span class="text-xs text-ink-500">
                                        {{ $visit->visited_at?->format('d/m') }}{{ ((int) $visit->month !== (int) $month || (int) $visit->year !== (int) $year) ? ' · corresponde a '.\Carbon\Carbon::create($visit->year, $visit->month, 1)->locale('es')->translatedFormat('F') : '' }}{{ $visit->deliveryNote ? ' · Remito #'.$visit->deliveryNote->number : '' }}
                                    </span>
                                    @if($photosOn || ($photoCounts[$visit->id] ?? 0) > 0)
                                        <a href="{{ route('visits.show', ['company' => $slug, 'visit' => $visit->id]) }}" class="text-xs font-semibold text-brand-700 underline" data-work-photos-link>Fotos del trabajo ({{ $photoCounts[$visit->id] ?? 0 }})</a>
                                    @endif
                                </div>
                                @if($notDone)
                                    <p class="mt-1 text-xs text-ink-500">La oficina lo ve y coordina la nueva visita.</p>
                                @endif
                            @endforeach

                            @if($status === 'covered')
                                <p class="mt-1.5 text-xs font-semibold text-ink-700">No corresponde visitar este mes: ya se hizo la visita del período. Próxima: {{ $period->end->addMonthNoOverflow()->locale('es')->translatedFormat('F Y') }}.</p>
                            @elseif(($row['pending'] ?? 1) > 0 && ! $openVisit)
                                @php [$chipClass, $chipLabel] = $statusChip[$status] ?? $statusChip['pending']; @endphp
                                <div class="mt-1.5 flex flex-wrap items-center gap-2">
                                    <span class="{{ $chipClass }}">{{ $chipLabel }}{{ $expected > 1 ? ': visita '.$nextSlot.' de '.$expected : '' }}</span>
                                    @if($period?->isMultiMonth())
                                        <span class="text-xs text-ink-500">Se puede hacer cualquier mes del período (hasta {{ $period->end->locale('es')->translatedFormat('F') }}).</span>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </article>
        @empty
            <div class="t-empty">
                @if(request('search'))
                    <p class="font-semibold text-ink">No encontramos edificios con "{{ request('search') }}"</p>
                    <p class="mt-1">Probá con la calle, el barrio o el nombre del cliente.</p>
                @else
                    <p class="font-semibold text-ink">No tenés edificios asignados</p>
                    <p class="mt-1">Las asignaciones de mantenimiento e inspección las hace la oficina.</p>
                @endif
            </div>
        @endforelse
    </div>

    @if($buildings->hasPages())
        <div class="mt-4">{{ $buildings->links() }}</div>
    @endif
</div>

<script>
    // Búsqueda: se aplica sola al dejar de escribir (medio segundo).
    (() => {
        const input = document.getElementById('searchInput');
        let timer;
        input.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(() => input.form.submit(), 500);
        });
        if (input.value) {
            input.focus();
            input.setSelectionRange(input.value.length, input.value.length);
        }
    })();
</script>
</x-app-layout>

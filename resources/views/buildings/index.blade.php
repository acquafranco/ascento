{{--
    Visitas del técnico: sus edificios asignados con el mantenimiento y/o la
    inspección del mes elegido. Desde acá se hace el remito de cada visita.
    Mismos datos y filtros que antes (mes, año y búsqueda).
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

    {{-- Resumen del mes (máquinas) --}}
    <div class="mb-4 grid grid-cols-3 gap-2.5">
        <div class="t-card px-3 py-3">
            <div class="text-[26px] font-bold leading-none text-ink">{{ $totalMachines }}</div>
            <div class="mt-1 text-xs font-semibold text-ink-500">Máquinas asignadas</div>
        </div>
        @if($maintenanceTotalMachines > 0)
            <div class="t-card px-3 py-3">
                <div class="text-[26px] font-bold leading-none {{ $maintenanceRemaining ? 'text-ink' : 'text-emerald-600' }}">{{ $maintenanceRemaining }}</div>
                <div class="mt-1 text-xs font-semibold text-ink-500">Mantenimientos por hacer</div>
            </div>
        @endif
        @if($inspectionTotalMachines > 0)
            <div class="t-card px-3 py-3">
                <div class="text-[26px] font-bold leading-none {{ $inspectionRemaining ? 'text-ink' : 'text-emerald-600' }}">{{ $inspectionRemaining }}</div>
                <div class="mt-1 text-xs font-semibold text-ink-500">Inspecciones por hacer</div>
            </div>
        @endif
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
               placeholder="Buscar por edificio, calle, cliente, localidad o barrio" class="t-input" aria-label="Buscar edificio">
    </form>

    <div class="space-y-3" id="buildingsList">
        @forelse($buildings as $building)
            @php
                $types = $building->users->pluck('pivot.type')->flatMap(fn ($type) => explode(',', $type))->toArray();
                $visits = [
                    'maintenance' => $building->visits->where('assignment_type', 'maintenance')->first(),
                    'inspection' => $building->visits->where('assignment_type', 'inspection')->first(),
                ];
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
                            $visit = $visits[$type];
                            $notDone = $visit && $visit->deliveryNote?->performed === false;
                        @endphp
                        <div class="flex items-center gap-3 px-4 py-3">
                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-semibold text-ink">{{ $label }}</div>
                                @if(! $visit)
                                    <span class="t-chip-pending mt-0.5">Pendiente</span>
                                @elseif($notDone)
                                    <span class="t-chip-failed mt-0.5">Remito firmado: no se pudo realizar</span>
                                    <p class="mt-1 text-xs text-ink-500">La oficina lo ve y coordina la nueva visita.</p>
                                @else
                                    <span class="t-chip-done mt-0.5">{{ $type === 'maintenance' ? 'Mantenimiento realizado' : 'Inspección realizada' }}</span>
                                @endif
                                @if($visit?->delivery_note)
                                    <span class="ml-1 text-xs text-ink-500">Remito #{{ $visit->delivery_note }}</span>
                                @endif
                            </div>
                            @unless($visit)
                                <a href="{{ route('delivery-notes.building', ['company' => $slug, 'building' => $building, 'month' => $month, 'year' => $year, 'assignment_type' => $type]) }}"
                                   class="{{ $type === 'maintenance' ? 't-btn-primary' : 't-btn-dark' }} t-btn-sm shrink-0">
                                    Marcar {{ mb_strtolower($label) }}
                                </a>
                            @endunless
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

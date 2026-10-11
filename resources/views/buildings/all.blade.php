{{-- Todos los edificios de la empresa (consulta técnica), con búsqueda en el servidor. --}}
@php
    use App\Support\TechNav;
    $machines = fn (int $n, string $one, string $many) => $n.' '.($n === 1 ? $one : $many);
@endphp
<x-app-layout>
<div class="t-page">
    <header class="mb-4">
        <h1 class="t-h1">Edificios</h1>
        <p class="t-sub">Datos técnicos y de contacto de todos los edificios de la empresa.</p>
    </header>

    <form method="GET" class="mb-4" role="search">
        <input type="search" name="search" id="searchBuilding" value="{{ request('search') }}" autocomplete="off"
               placeholder="Buscar por edificio, calle, barrio o cliente" class="t-input" aria-label="Buscar edificio">
    </form>

    <div id="buildingsList" class="space-y-3">
        @forelse($buildings as $building)
            @php($address = trim(($building->address ?? '').', '.($building->locality ?? ''), ', '))
            <article class="t-card p-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="text-[17px] font-bold leading-snug text-ink">{{ $building->name }}</h2>
                        <p class="text-sm text-ink-700">{{ $building->address }}</p>
                        <p class="text-xs text-ink-500">{{ collect([$building->locality, $building->neighborhood])->filter()->implode(' · ') }}{{ ($building->locality || $building->neighborhood) ? ' · ' : '' }}{{ $building->client?->name ?? $building->client_name ?? 'Sin cliente' }}</p>
                    </div>
                    @if($address !== '')
                        <a href="https://www.google.com/maps/search/?api=1&query={{ rawurlencode($address) }}" target="_blank" rel="noopener"
                           class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-ink-100 text-ink-500 hover:bg-ink-50" aria-label="Cómo llegar a {{ $building->name }}">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">{!! TechNav::icon('pin') !!}</svg>
                        </a>
                    @endif
                </div>
                <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-sm text-ink-500">
                    <span><strong class="text-ink">{{ $machines((int) $building->elevator_count, 'ascensor', 'ascensores') }}</strong> · {{ $machines((int) $building->freight_elevator_count, 'montacargas', 'montacargas') }}</span>
                    @if($building->contact_person)<span>Contacto: <strong class="text-ink">{{ $building->contact_person }}</strong></span>@endif
                    @if($building->phone)<a href="tel:{{ preg_replace('/[^0-9+]/', '', $building->phone) }}" class="font-semibold text-brand-700">{{ $building->phone }}</a>@endif
                </div>
                @if($building->notes)
                    <div class="mt-3 rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-900">
                        <span class="font-semibold">Observaciones:</span> {{ $building->notes }}
                    </div>
                @endif
            </article>
        @empty
            <div class="t-empty">
                <p class="font-semibold text-ink">{{ request('search') ? 'No encontramos edificios con "'.request('search').'"' : 'No hay edificios cargados' }}</p>
            </div>
        @endforelse
    </div>

    @if($buildings->hasPages())
        <div class="mt-4">{{ $buildings->links() }}</div>
    @endif
</div>
<script>
    (() => {
        const input = document.getElementById('searchBuilding');
        let timer;
        input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => input.form.submit(), 500); });
        if (input.value) { input.focus(); input.setSelectionRange(input.value.length, input.value.length); }
    })();
</script>
</x-app-layout>

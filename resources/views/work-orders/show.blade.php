@php
    $slug = auth()->user()->company->slug;
    $building = $workOrder->building;
    $address = trim(($building?->address ?? '').', '.($building?->locality ?? ''), ', ');
@endphp
<x-app-layout>
<div class="t-page">
    <a href="{{ route('work-orders.index', ['company' => $slug]) }}" class="inline-flex min-h-[40px] items-center gap-1 text-sm font-semibold text-ink-500 hover:text-ink">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m15 18-6-6 6-6"/></svg>
        Trabajos
    </a>
    <h1 class="t-h1 mb-4 mt-1">Orden de trabajo</h1>

    @if(session('success'))
        <div class="t-alert-success mb-4" role="status">{{ session('success') }}</div>
    @endif

    @include('work-orders.partials.card', ['workOrder' => $workOrder])

    @if($building)
        <section class="t-card mt-3 divide-y divide-ink-100">
            @if($building->client)
                <div class="px-4 py-3">
                    <div class="text-xs font-bold uppercase tracking-wider text-ink-500">Cliente</div>
                    <div class="font-semibold text-ink">{{ $building->client->name }}</div>
                </div>
            @endif
            @if($building->contact_person || $building->phone)
                <div class="flex items-center gap-3 px-4 py-3">
                    <div class="min-w-0 flex-1">
                        <div class="text-xs font-bold uppercase tracking-wider text-ink-500">Contacto en el edificio</div>
                        <div class="truncate font-semibold text-ink">{{ collect([$building->contact_person, $building->phone])->filter()->implode(' · ') }}</div>
                    </div>
                    @if($building->phone)
                        <a href="tel:{{ preg_replace('/[^0-9+]/', '', $building->phone) }}" class="t-btn-secondary t-btn-sm shrink-0">Llamar</a>
                    @endif
                </div>
            @endif
            @if($address !== '')
                <div class="flex items-center gap-3 px-4 py-3">
                    <div class="min-w-0 flex-1">
                        <div class="text-xs font-bold uppercase tracking-wider text-ink-500">Dirección</div>
                        <div class="truncate font-semibold text-ink">{{ $address }}</div>
                    </div>
                    <a href="https://www.google.com/maps/search/?api=1&query={{ rawurlencode($address) }}" target="_blank" rel="noopener" class="t-btn-secondary t-btn-sm shrink-0">Cómo llegar</a>
                </div>
            @endif
        </section>
    @endif
</div>
</x-app-layout>

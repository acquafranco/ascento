<x-filament-panels::page>
    @vite(['resources/js/buildings-map.js'])

    @if (! $this->hasCompany())
        <x-filament::section>
            <p class="bm-empty-text">Elegí una empresa con "Entrar" para ver su mapa.</p>
        </x-filament::section>
    @else
        @php
            $markers = $this->getMarkers();
            $unlocated = $this->unlocated;
            $clients = $this->clients();
            $pendingCount = $this->pendingCount();
        @endphp

        {{-- RESUMEN + FILTROS --}}
        <div class="bm-toolbar">
            <div class="bm-stats">
                <span class="bm-stat">
                    <strong>{{ count($markers) }}</strong> en el mapa
                </span>

                @if ($unlocated->isNotEmpty())
                    <a href="#sin-ubicar" class="bm-stat bm-stat--warning">
                        <strong>{{ $unlocated->count() }}</strong> sin ubicar
                    </a>
                @endif
            </div>

            @if (count($markers) > 0)
                <div class="bm-filters">
                    <label class="bm-sr-only" for="bm-search">Buscar edificio</label>
                    <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                        <x-filament::input
                            id="bm-search"
                            type="search"
                            placeholder="Buscar calle o cliente…"
                            autocomplete="off"
                            data-map-search
                        />
                    </x-filament::input.wrapper>

                    @if (count($clients) > 1)
                        <label class="bm-sr-only" for="bm-client">Filtrar por cliente</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="bm-client" data-map-client>
                                <option value="">Todos los clientes</option>
                                @foreach ($clients as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    @endif

                    <x-filament::button color="gray" icon="heroicon-m-arrows-pointing-out" data-map-fit>
                        Ver todos
                    </x-filament::button>
                </div>
            @endif
        </div>

        {{-- MAPA --}}
        <div wire:ignore class="bm-map-shell">
            <script type="application/json" data-map-payload>@json(['markers' => $markers, 'config' => $this->getMapConfig()])</script>

            <div class="bm-map" data-buildings-map role="region" aria-label="Mapa de edificios"></div>

            @if (count($markers) === 0)
                <div class="bm-map-empty" data-map-empty>
                    <strong>Todavía no hay edificios en el mapa.</strong>
                    <span>
                        @if ($unlocated->isEmpty())
                            Cuando cargues edificios con su dirección, aparecen acá solos.
                        @else
                            Revisá la lista de abajo para ubicarlos.
                        @endif
                    </span>
                </div>
            @endif

            {{-- Barra del modo "marcar en el mapa" (la maneja buildings-map.js) --}}
            <div class="bm-place-bar" data-map-place-bar hidden>
                <span data-map-place-text></span>
                <div class="bm-place-actions">
                    <x-filament::button size="sm" data-map-place-save>Guardar ubicación</x-filament::button>
                    <x-filament::button size="sm" color="gray" data-map-place-cancel>Cancelar</x-filament::button>
                </div>
            </div>
        </div>

        <p class="bm-hint">Naranja: ubicado por dirección · Azul: marcado a mano · Gris: inactivo. Para corregir un punto, tocalo y elegí "Corregir ubicación".</p>

        {{-- SIN UBICAR --}}
        @if ($unlocated->isNotEmpty())
            <x-filament::section id="sin-ubicar" icon="heroicon-o-map-pin" icon-color="warning">
                <x-slot name="heading">Edificios sin ubicar ({{ $unlocated->count() }})</x-slot>
                <x-slot name="description">
                    No los ubicamos automáticamente para no mostrar un punto equivocado.
                    Corregí la dirección o marcalos a mano en el mapa.
                </x-slot>

                @if ($pendingCount > 0 && $this->canGeocode())
                    <x-slot name="afterHeader">
                        <x-filament::button
                            size="sm"
                            icon="heroicon-m-sparkles"
                            wire:click="geocodePending"
                            wire:loading.attr="disabled"
                            wire:target="geocodePending"
                        >
                            <span wire:loading.remove wire:target="geocodePending">
                                Ubicar {{ min($pendingCount, \App\Filament\Pages\BuildingsMap::BATCH_SIZE) }} pendientes
                            </span>
                            <span wire:loading wire:target="geocodePending">Ubicando…</span>
                        </x-filament::button>
                    </x-slot>
                @endif

                <ul class="bm-list" role="list">
                    @foreach ($unlocated as $item)
                        <li class="bm-list-item" wire:key="unlocated-{{ $item['id'] }}">
                            <div class="bm-list-main">
                                <span class="bm-list-title">{{ $item['title'] }}</span>
                                <span class="bm-list-meta">
                                    {{ collect([$item['client'], $item['area']])->filter()->implode(' · ') }}
                                </span>
                                <span class="bm-list-reason {{ $item['needsReview'] ? 'is-warning' : '' }}">
                                    {{ $item['reason'] }}
                                </span>
                            </div>
                            <div class="bm-list-actions">
                                <x-filament::button
                                    size="sm"
                                    icon="heroicon-m-map-pin"
                                    data-map-place="{{ $item['id'] }}"
                                    data-map-place-title="{{ $item['title'] }}"
                                >
                                    Marcar en el mapa
                                </x-filament::button>
                                <x-filament::button
                                    size="sm"
                                    color="gray"
                                    tag="a"
                                    :href="$item['editUrl']"
                                >
                                    Editar dirección
                                </x-filament::button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>

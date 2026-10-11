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
            $unlocatedCount = $this->unlocatedCount();
            $clients = $this->clients();
            $inProgress = $this->isGeocodingInProgress();
        @endphp

        {{-- RESUMEN + FILTROS --}}
        <div class="bm-toolbar">
            <div class="bm-stats">
                <span class="bm-stat">
                    <strong>{{ $this->markerCount() }}</strong> en el mapa
                </span>

                @if ($unlocatedCount > 0)
                    <a href="#sin-ubicar" class="bm-stat bm-stat--warning">
                        <strong>{{ $unlocatedCount }}</strong> sin ubicar
                    </a>
                @endif

                @if ($inProgress)
                    {{-- Mientras se ubican en segundo plano, trae los puntos nuevos. --}}
                    <span class="bm-stat bm-stat--progress" wire:poll.4s="pollNewMarkers" role="status">
                        <span class="bm-spinner" aria-hidden="true"></span> Ubicando edificios…
                    </span>
                @endif
            </div>

            @if (count($markers) > 0 || $inProgress)
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

                    @php
                        $colors = $this->colorsInUse();
                    @endphp
                    @if (count($colors) > 1)
                        <label class="bm-sr-only" for="bm-color">Filtrar por color</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="bm-color" data-map-color>
                                <option value="">Todos los colores</option>
                                @foreach ($colors as $key => $color)
                                    <option value="{{ $key }}">{{ $color['label'] }} ({{ $color['count'] }})</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    @endif

                    @php
                        $zones = $this->zonesInUse();
                        $technicians = $this->techniciansInUse();
                    @endphp
                    @if (count($zones) > 0)
                        <label class="bm-sr-only" for="bm-zone">Filtrar por zona</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="bm-zone" data-map-zone>
                                <option value="">Todas las zonas</option>
                                @foreach ($zones as $zone)
                                    <option value="{{ $zone }}">{{ $zone }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    @endif

                    @if (count($technicians) > 0)
                        <label class="bm-sr-only" for="bm-technician">Filtrar por técnico</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="bm-technician" data-map-technician>
                                <option value="">Todos los técnicos</option>
                                @foreach ($technicians as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    @endif

                    <label class="bm-sr-only" for="bm-status">Filtrar por estado del mes</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="bm-status" data-map-status>
                            <option value="">Cualquier estado del mes</option>
                            @foreach ($this->statusLegend() as $key => $item)
                                <option value="{{ $key }}">{{ $item['label'] }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>

                    <label class="bm-sr-only" for="bm-mode">Colorear por</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="bm-mode" data-map-mode>
                            <option value="color">Colorear por: color asignado</option>
                            <option value="status">Colorear por: estado del mes</option>
                        </x-filament::input.select>
                    </x-filament::input.wrapper>

                    <x-filament::button color="gray" icon="heroicon-m-arrows-pointing-out" data-map-fit>
                        Ver todos
                    </x-filament::button>
                </div>
            @endif
        </div>

        {{-- LEYENDA (cambia con "Colorear por") --}}
        @if (count($markers) > 0)
            <div class="bm-legend" style="display:flex;flex-wrap:wrap;gap:.4rem 1rem;font-size:.85rem;margin:.25rem 0 .75rem;">
                <div data-map-legend="color" style="display:contents">
                    @foreach ($this->colorsInUse() as $key => $color)
                        <span style="display:inline-flex;align-items:center;gap:.35rem"><span style="width:.75rem;height:.75rem;border-radius:999px;background:{{ $color['hex'] }}"></span>{{ $color['label'] }} ({{ $color['count'] }})</span>
                    @endforeach
                    <span style="opacity:.6">El color lo elegís en cada edificio.</span>
                </div>
                <div data-map-legend="status" style="display:none">
                    @foreach ($this->statusLegend() as $item)
                        <span style="display:inline-flex;align-items:center;gap:.35rem"><span style="width:.75rem;height:.75rem;border-radius:999px;background:{{ $item['hex'] }}"></span>{{ $item['label'] }}</span>
                    @endforeach
                    <span style="opacity:.6">Mantenimiento de {{ now()->translatedFormat('F') }}.</span>
                </div>
            </div>
        @endif

        {{-- MAPA --}}
        <div wire:ignore class="bm-map-shell">
            <script type="application/json" data-map-payload>@json(['markers' => $markers, 'config' => $this->getMapConfig()])</script>

            <div class="bm-map" data-buildings-map role="region" aria-label="Mapa de edificios"></div>

            @if (count($markers) === 0)
                <div class="bm-map-empty" data-map-empty>
                    <strong>Todavía no hay edificios en el mapa.</strong>
                    <span>
                        @if ($inProgress)
                            Estamos ubicando tus edificios; van a ir apareciendo solos.
                        @elseif ($unlocated->isEmpty())
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

        <p class="bm-hint">Cada edificio usa el color que le elegiste al cargarlo (los inactivos se ven transparentes). Para corregir un punto, tocalo y elegí "Corregir ubicación".</p>

        {{-- SIN UBICAR (lista liviana: HTML simple, máximo LIST_LIMIT) --}}
        @if ($unlocatedCount > 0)
            <x-filament::section id="sin-ubicar" icon="heroicon-o-map-pin" icon-color="warning">
                <x-slot name="heading">Edificios sin ubicar ({{ $unlocatedCount }})</x-slot>
                <x-slot name="description">
                    Los que dicen "Ubicando…" se ubican solos. Los demás necesitan que corrijas
                    la dirección o los marques a mano en el mapa.
                </x-slot>

                <ul class="bm-list" role="list">
                    @foreach ($unlocated as $item)
                        <li class="bm-list-item" wire:key="unlocated-{{ $item['id'] }}">
                            <div class="bm-list-main">
                                <span class="bm-list-title">{{ $item['title'] }}</span>
                                <span class="bm-list-meta">{{ collect([$item['client'], $item['area']])->filter()->implode(' · ') }}</span>
                                <span class="bm-list-reason {{ $item['needsReview'] ? 'is-warning' : '' }}">{{ $item['reason'] }}</span>
                            </div>
                            <div class="bm-list-actions">
                                <button type="button" class="bm-btn bm-btn--primary"
                                    data-map-place="{{ $item['id'] }}" data-map-place-title="{{ $item['title'] }}">
                                    Marcar en el mapa
                                </button>
                                <a class="bm-btn" href="{{ $item['editUrl'] }}">Editar dirección</a>
                            </div>
                        </li>
                    @endforeach
                </ul>

                @if ($unlocatedCount > $unlocated->count())
                    <p class="bm-hint">Y {{ $unlocatedCount - $unlocated->count() }} más. Se muestran los primeros {{ $unlocated->count() }}.</p>
                @endif
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>

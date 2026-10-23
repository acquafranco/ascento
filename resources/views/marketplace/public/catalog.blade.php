@extends('marketplace.public.layout')

@php
    $cats = \App\Support\Marketplace\Catalog::CATEGORIES;
    $catName = $filters['rubro'] !== '' ? $cats[$filters['rubro']] : null;
    $pageTitle = $catName ? $catName.' para ascensores' : ($q !== '' ? 'Repuestos: «'.$q.'»' : 'Catálogo de repuestos para ascensores');
    $activeCount = count(array_filter($filters));
    $select = 'mt-1.5 w-full rounded-xl border border-ink/15 bg-white px-3.5 py-2.5 text-sm text-ink shadow-sm outline-none transition focus:border-rail-500 focus:ring-4 focus:ring-rail-100';
    $canonical = $filtered ? route('marketplace.catalog') : ($products->currentPage() > 1 ? route('marketplace.catalog', ['page' => $products->currentPage()]) : route('marketplace.catalog'));
@endphp

@section('title', $pageTitle)
@section('description', 'Explorá repuestos para ascensores de proveedores del sector: máquinas, controladores, puertas, botoneras, seguridad y más. Buscá por marca, modelo o código y pedí cotizaciones con tu cuenta de Ascento.')
@section('canonical', $canonical)
@section('robots', $filtered ? 'noindex, follow' : 'index, follow')

@section('content')
    {{-- Encabezado con buscador --}}
    <section class="relative overflow-hidden border-b border-ink/10 bg-white">
        <div class="pointer-events-none absolute inset-0 [background:radial-gradient(50%_80%_at_100%_0%,rgba(255,102,0,0.07),transparent_60%)]" aria-hidden="true"></div>
        <div class="relative mx-auto max-w-7xl px-4 py-10 sm:px-8 sm:py-14">
            <nav aria-label="Ubicación" class="text-xs text-ink/45">
                <a href="{{ route('marketplace') }}" class="hover:text-ink">Marketplace</a> <span aria-hidden="true">/</span>
                <a href="{{ route('marketplace.catalog') }}" class="hover:text-ink">Repuestos</a>
                @if ($catName) <span aria-hidden="true">/</span> <span class="text-ink/70">{{ $catName }}</span> @endif
            </nav>
            <h1 class="mt-3 font-display text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-4xl">{{ $catName ?? 'Repuestos para ascensores' }}</h1>
            <p class="mt-2 max-w-2xl text-sm leading-relaxed text-ink/60 sm:text-base">Productos de proveedores aprobados por Ascento. Explorá sin cuenta; para pedir una cotización, entrá con la cuenta de tu empresa.</p>

            <form method="GET" action="{{ route('marketplace.catalog') }}" role="search" data-loading class="mt-6 flex max-w-2xl gap-2">
                @foreach (array_filter($filters) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                <label for="mk-q" class="sr-only">Buscar repuestos</label>
                <div class="relative min-w-0 flex-1">
                    <svg class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-ink/35" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M7 12A5 5 0 1 0 7 2a5 5 0 0 0 0 10ZM14 14l-3.5-3.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                    <input id="mk-q" type="search" name="q" value="{{ $q }}" maxlength="120" placeholder="Nombre, marca, modelo, código o equipo"
                           class="w-full rounded-full border border-ink/15 bg-white py-3 pl-10 pr-4 text-sm shadow-sm outline-none transition focus:border-rail-500 focus:ring-4 focus:ring-rail-100">
                </div>
                <button type="submit" class="shrink-0 rounded-full bg-graphite px-5 py-3 text-sm font-semibold text-white transition hover:-translate-y-0.5">Buscar</button>
            </form>

            {{-- Accesos por rubro (solo los que tienen productos) --}}
            @if ($categoryCounts)
                <div class="no-scrollbar -mx-4 mt-6 flex gap-2 overflow-x-auto px-4 pb-1 sm:mx-0 sm:flex-wrap sm:overflow-visible sm:px-0" data-category-chips>
                    <a href="{{ route('marketplace.catalog', array_filter(['q' => $q])) }}" data-loading
                       class="shrink-0 rounded-full border px-3.5 py-1.5 text-xs font-semibold transition {{ $filters['rubro'] === '' ? 'border-graphite bg-graphite text-white' : 'border-ink/15 bg-white text-ink/70 hover:border-ink/35' }}">Todos</a>
                    @foreach ($cats as $key => $label)
                        @if (($categoryCounts[$key] ?? 0) > 0)
                            <a href="{{ route('marketplace.catalog', array_filter(['q' => $q, 'rubro' => $key])) }}" data-loading
                               class="shrink-0 whitespace-nowrap rounded-full border px-3.5 py-1.5 text-xs font-semibold transition {{ $filters['rubro'] === $key ? 'border-graphite bg-graphite text-white' : 'border-ink/15 bg-white text-ink/70 hover:border-ink/35' }}">
                                {{ $label }} <span class="font-normal opacity-60">{{ $categoryCounts[$key] }}</span>
                            </a>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    <section class="mx-auto max-w-7xl px-4 py-8 sm:px-8 sm:py-10" x-data="{ filters: false }">
        <div class="grid gap-8 lg:grid-cols-[15rem_minmax(0,1fr)]">
            {{-- Filtros: columna en escritorio, panel desplegable en el celular --}}
            <aside>
                <button type="button" @click="filters = !filters" :aria-expanded="filters.toString()" aria-expanded="false" aria-controls="mk-filters"
                        class="flex w-full items-center justify-between rounded-xl border border-ink/15 bg-white px-4 py-3 text-sm font-semibold lg:hidden" data-filters-toggle>
                    <span>Filtros @if ($activeCount)<span class="ml-1 rounded-full bg-amber-500 px-2 py-0.5 text-[11px] text-graphite">{{ $activeCount }}</span>@endif</span>
                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none" class="transition-transform" :class="filters && 'rotate-180'" aria-hidden="true"><path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
                <form id="mk-filters" method="GET" action="{{ route('marketplace.catalog') }}" data-loading data-filters
                      class="mt-3 hidden space-y-4 rounded-2xl border border-ink/10 bg-white p-4 lg:mt-0 lg:block lg:border-0 lg:bg-transparent lg:p-0" :class="filters ? '!block' : ''">
                    @if ($q !== '')<input type="hidden" name="q" value="{{ $q }}">@endif
                    <div>
                        <label for="f-rubro" class="text-xs font-semibold text-ink/60">Rubro</label>
                        <select id="f-rubro" name="rubro" data-autosubmit class="{{ $select }}">
                            <option value="">Todos</option>
                            @foreach ($cats as $key => $label)<option value="{{ $key }}" @selected($filters['rubro'] === $key)>{{ $label }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label for="f-marca" class="text-xs font-semibold text-ink/60">Marca</label>
                        <select id="f-marca" name="marca" data-autosubmit class="{{ $select }}">
                            <option value="">Todas</option>
                            @foreach ($brands as $brand)<option value="{{ $brand }}" @selected($filters['marca'] === $brand)>{{ $brand }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label for="f-disp" class="text-xs font-semibold text-ink/60">Disponibilidad</label>
                        <select id="f-disp" name="disponibilidad" data-autosubmit class="{{ $select }}">
                            <option value="">Cualquiera</option>
                            @foreach (\App\Support\Marketplace\Catalog::AVAILABILITY as $key => $label)<option value="{{ $key }}" @selected($filters['disponibilidad'] === $key)>{{ $label }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label for="f-precio" class="text-xs font-semibold text-ink/60">Precio</label>
                        <select id="f-precio" name="precio" data-autosubmit class="{{ $select }}">
                            <option value="">Cualquiera</option>
                            @foreach (\App\Support\Marketplace\Catalog::PRICE_MODES as $key => $label)<option value="{{ $key }}" @selected($filters['precio'] === $key)>{{ $label }}</option>@endforeach
                        </select>
                    </div>
                    <div class="flex items-center gap-3 pt-1">
                        <button type="submit" class="rounded-full bg-graphite px-4 py-2 text-xs font-semibold text-white">Aplicar</button>
                        @if ($filtered)
                            <a href="{{ route('marketplace.catalog') }}" data-loading data-clear-filters class="text-xs font-semibold text-ink/60 underline-offset-2 hover:text-ink hover:underline">Limpiar filtros</a>
                        @endif
                    </div>
                </form>

                <div class="mt-6 hidden rounded-2xl border border-ink/10 bg-white p-4 text-sm lg:block">
                    <p class="font-semibold">¿Vendés repuestos?</p>
                    <p class="mt-1 text-xs leading-relaxed text-ink/60">Publicá tu catálogo gratis, hasta {{ \App\Models\Marketplace\MarketplaceSettings::current()->freeLimit() }} productos.</p>
                    <a href="{{ route('suppliers.register') }}" class="mt-3 inline-flex text-xs font-semibold text-amber-700 hover:text-amber-600">Crear cuenta de proveedor →</a>
                </div>
            </aside>

            {{-- Resultados --}}
            <div data-results>
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <p class="text-sm text-ink/60" role="status" data-results-count>
                        <strong class="text-ink">{{ number_format($products->total(), 0, ',', '.') }}</strong> {{ $products->total() === 1 ? 'producto' : 'productos' }}@if ($q !== '') para «{{ $q }}»@endif
                    </p>
                    @if ($products->lastPage() > 1)
                        <p class="text-xs text-ink/45">Página {{ $products->currentPage() }} de {{ $products->lastPage() }}</p>
                    @endif
                </div>

                @if ($products->isEmpty())
                    <div class="mt-6 rounded-2xl border border-dashed border-ink/15 bg-white px-6 py-14 text-center" data-empty-state>
                        <svg class="mx-auto text-ink/25" width="42" height="42" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="5" y="2.5" width="14" height="19" rx="2" stroke="currentColor" stroke-width="1.3"/><path d="M12 2.5v19M9.5 10l-1.5 2 1.5 2M14.5 10l1.5 2-1.5 2" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        @if ($filtered)
                            <p class="mt-4 font-display text-lg font-semibold">No encontramos repuestos con esa búsqueda.</p>
                            <p class="mt-1 text-sm text-ink/60">Probá con otra palabra, el código del fabricante o menos filtros.</p>
                            <a href="{{ route('marketplace.catalog') }}" data-loading class="mt-5 inline-flex rounded-full border border-ink/15 px-5 py-2.5 text-sm font-semibold hover:border-ink/35">Ver todo el catálogo</a>
                        @else
                            <p class="mt-4 font-display text-lg font-semibold">Todavía no hay repuestos publicados.</p>
                            <p class="mt-1 text-sm text-ink/60">Los proveedores están armando sus catálogos. Si vendés repuestos, podés ser de los primeros.</p>
                            <a href="{{ route('suppliers.register') }}" class="mt-5 inline-flex rounded-full bg-amber-500 px-5 py-2.5 text-sm font-semibold text-graphite hover:bg-amber-400">Publicar mi catálogo</a>
                        @endif
                    </div>
                @else
                    <div class="mt-5 grid gap-4 min-[480px]:grid-cols-2 xl:grid-cols-3">
                        @foreach ($products as $product)
                            @include('marketplace.public.partials.card', ['product' => $product])
                        @endforeach
                    </div>

                    @if ($products->hasPages())
                        <nav class="mt-8 flex items-center justify-between gap-3" aria-label="Páginas del catálogo">
                            @if ($products->onFirstPage())
                                <span class="rounded-full border border-ink/10 px-4 py-2 text-sm text-ink/30">Anterior</span>
                            @else
                                <a href="{{ $products->previousPageUrl() }}" rel="prev" data-loading class="rounded-full border border-ink/15 bg-white px-4 py-2 text-sm font-semibold hover:border-ink/35">Anterior</a>
                            @endif
                            <span class="text-xs text-ink/50">{{ $products->firstItem() }}–{{ $products->lastItem() }} de {{ number_format($products->total(), 0, ',', '.') }}</span>
                            @if ($products->hasMorePages())
                                <a href="{{ $products->nextPageUrl() }}" rel="next" data-loading class="rounded-full border border-ink/15 bg-white px-4 py-2 text-sm font-semibold hover:border-ink/35">Siguiente</a>
                            @else
                                <span class="rounded-full border border-ink/10 px-4 py-2 text-sm text-ink/30">Siguiente</span>
                            @endif
                        </nav>
                    @endif
                @endif

                <p class="mt-8 text-xs leading-relaxed text-ink/45">Precio, disponibilidad y compatibilidad los declara cada proveedor y Ascento no los verifica. Confirmalos con el proveedor antes de comprar.</p>
            </div>
        </div>
    </section>
@endsection

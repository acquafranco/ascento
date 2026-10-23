@extends('marketplace.public.layout')

{{-- Perfil comercial público de un proveedor habilitado: solo lo que publica para vender (sin correo, teléfono, identificación fiscal, pedidos ni compradores). --}}
@php
    $cats = collect((array) $supplier->categories)->map(fn ($c) => \App\Support\Marketplace\Catalog::category($c))->unique()->values();
    $brands = collect((array) $supplier->brands)->filter()->values();
    $website = filled($supplier->website) && preg_match('#^https?://#i', (string) $supplier->website) ? $supplier->website : null;
    $canonical = $products->currentPage() > 1 ? route('marketplace.supplier', ['supplier' => $supplier->slug, 'page' => $products->currentPage()]) : route('marketplace.supplier', $supplier->slug);
@endphp

@section('title', $supplier->name.' · Repuestos para ascensores')
@section('description', \Illuminate\Support\Str::limit(($supplier->description ?: 'Catálogo de repuestos para ascensores de '.$supplier->name.'.').' En el marketplace de repuestos de Ascento.', 158))
@section('canonical', $canonical)

@section('content')
    <section class="border-b border-ink/10 bg-white">
        <div class="mx-auto max-w-7xl px-4 py-10 sm:px-8 sm:py-12">
            <nav aria-label="Ubicación" class="text-xs text-ink/45">
                <a href="{{ route('marketplace') }}" class="hover:text-ink">Marketplace</a> <span aria-hidden="true">/</span>
                <a href="{{ route('marketplace.catalog') }}" class="hover:text-ink">Repuestos</a> <span aria-hidden="true">/</span>
                <span class="text-ink/70">Proveedor</span>
            </nav>
            <div class="mt-4 grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start">
                <div>
                    <h1 class="font-display text-[1.75rem] font-semibold leading-tight tracking-tight sm:text-4xl">{{ $supplier->name }}</h1>
                    <p class="mt-2 text-sm text-ink/50">Cuenta revisada por Ascento · {{ \App\Support\Countries::name($supplier->country) }}@if ($operations) · {{ $operations }} {{ $operations === 1 ? 'operación completada' : 'operaciones completadas' }}@endif</p>
                    @if ($supplier->description)
                        <p class="mt-4 max-w-3xl whitespace-pre-line text-sm leading-relaxed text-ink/70 sm:text-base">{{ $supplier->description }}</p>
                    @endif
                </div>
                <dl class="space-y-3 rounded-2xl border border-ink/10 bg-paper p-5 text-sm" data-supplier-public-data>
                    @if ($supplier->coverage)<div><dt class="text-xs font-semibold text-ink/45">Zonas de venta o envío</dt><dd class="mt-0.5">{{ $supplier->coverage }}</dd></div>@endif
                    @if ($cats->isNotEmpty())<div><dt class="text-xs font-semibold text-ink/45">Rubros</dt><dd class="mt-0.5">{{ $cats->implode(', ') }}</dd></div>@endif
                    @if ($brands->isNotEmpty())<div><dt class="text-xs font-semibold text-ink/45">Marcas</dt><dd class="mt-0.5">{{ $brands->implode(', ') }}</dd></div>@endif
                    @if ($website)<div><dt class="text-xs font-semibold text-ink/45">Sitio web</dt><dd class="mt-0.5 break-all"><a href="{{ $website }}" rel="nofollow noopener" target="_blank" class="text-rail-600 hover:underline">{{ preg_replace('#^https?://#i', '', $website) }}</a></dd></div>@endif
                </dl>
            </div>
        </div>
    </section>

    <section class="mx-auto max-w-7xl px-4 py-8 sm:px-8 sm:py-10" data-results>
        <h2 class="font-display text-xl font-semibold">Catálogo <span class="text-base font-normal text-ink/45">({{ number_format($products->total(), 0, ',', '.') }})</span></h2>
        @if ($products->isEmpty())
            <p class="mt-5 rounded-2xl border border-dashed border-ink/15 bg-white px-6 py-10 text-center text-sm text-ink/60" data-empty-state>Este proveedor no tiene productos publicados en este momento.</p>
        @else
            <div class="mt-5 grid gap-4 min-[480px]:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @foreach ($products as $product)
                    @include('marketplace.public.partials.card', ['product' => $product])
                @endforeach
            </div>
            @if ($products->hasPages())
                <nav class="mt-8 flex items-center justify-between gap-3" aria-label="Páginas del catálogo">
                    @if ($products->onFirstPage())<span class="rounded-full border border-ink/10 px-4 py-2 text-sm text-ink/30">Anterior</span>@else<a href="{{ $products->previousPageUrl() }}" rel="prev" data-loading class="rounded-full border border-ink/15 bg-white px-4 py-2 text-sm font-semibold">Anterior</a>@endif
                    <span class="text-xs text-ink/50">Página {{ $products->currentPage() }} de {{ $products->lastPage() }}</span>
                    @if ($products->hasMorePages())<a href="{{ $products->nextPageUrl() }}" rel="next" data-loading class="rounded-full border border-ink/15 bg-white px-4 py-2 text-sm font-semibold">Siguiente</a>@else<span class="rounded-full border border-ink/10 px-4 py-2 text-sm text-ink/30">Siguiente</span>@endif
                </nav>
            @endif
        @endif
        <p class="mt-8 text-xs leading-relaxed text-ink/45">Ascento revisa la cuenta de cada proveedor antes de mostrarla, pero no verifica sus productos, precios ni compatibilidades. Para contactarlo, pedí una cotización con la cuenta de tu empresa.</p>
    </section>
@endsection

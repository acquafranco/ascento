@extends('marketplace.public.layout')

@use('App\Http\Controllers\Marketplace\PublicCatalogController', 'PC')
@php
    $photos = $product->photos;
    $canonical = PC::productUrl($product);
    $meta = collect([$product->brand, $product->model])->filter()->implode(' ');
    $description = \Illuminate\Support\Str::limit(trim(($meta !== '' ? $meta.'. ' : '').($product->description ?: $product->categoryLabel().' para ascensores').' Publicado por '.$supplier->name.' en el marketplace de repuestos de Ascento.'), 158);
    $availability = ['in_stock' => 'bg-emerald-50 text-emerald-700', 'to_order' => 'bg-amber-100 text-amber-700', 'unavailable' => 'bg-ink/5 text-ink/55'][$product->availability] ?? 'bg-ink/5 text-ink/55';
    $schema = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => $product->name,
        'description' => $product->description ?: null,
        'category' => $product->categoryLabel(),
        'sku' => $product->part_number ?: null,
        'mpn' => $product->part_number ?: null,
        'model' => $product->model ?: null,
        'brand' => $product->brand ? ['@type' => 'Brand', 'name' => $product->brand] : null,
        'image' => $photos->map(fn ($p) => PC::photoUrl($p))->values()->all() ?: null,
        'url' => $canonical,
        'offers' => $product->hasPrice() ? [
            '@type' => 'Offer',
            'price' => number_format((float) $product->price, 2, '.', ''),
            'priceCurrency' => $product->currency,
            'availability' => ['in_stock' => 'https://schema.org/InStock', 'to_order' => 'https://schema.org/PreOrder', 'unavailable' => 'https://schema.org/OutOfStock'][$product->availability] ?? null,
            'seller' => ['@type' => 'Organization', 'name' => $supplier->name],
        ] : null,
    ]);
    $specs = array_filter([
        'Rubro' => $product->categoryLabel(),
        'Marca' => $product->brand,
        'Modelo' => $product->model,
        'Código' => $product->part_number,
        'Disponibilidad' => $product->availabilityLabel(),
    ]);
@endphp

@section('title', \Illuminate\Support\Str::limit($product->name.($meta !== '' ? ' · '.$meta : ''), 70))
@section('description', $description)
@section('canonical', $canonical)
@section('og_type', 'product')
@if ($photos->isNotEmpty())
    @section('og_image', PC::photoUrl($photos->first()))
@endif
@section('schema'){!! json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}@endsection

@section('content')
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-8 sm:py-10">
        <nav aria-label="Ubicación" class="flex flex-wrap gap-x-1.5 text-xs text-ink/45">
            <a href="{{ route('marketplace') }}" class="hover:text-ink">Marketplace</a> <span aria-hidden="true">/</span>
            <a href="{{ route('marketplace.catalog') }}" class="hover:text-ink">Repuestos</a> <span aria-hidden="true">/</span>
            <a href="{{ route('marketplace.catalog', ['rubro' => $product->category]) }}" class="hover:text-ink">{{ $product->categoryLabel() }}</a>
        </nav>

        <div class="mt-5 grid gap-8 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)] lg:gap-12">
            {{-- Galería --}}
            <div x-data="{ at: 0, n: {{ $photos->count() }} }" data-gallery @keydown.arrow-right="at = (at + 1) % n" @keydown.arrow-left="at = (at + n - 1) % n">
                <div class="mk-photo relative aspect-[4/3] overflow-hidden rounded-2xl border border-ink/10">
                    @forelse ($photos as $i => $photo)
                        <img src="{{ PC::photoUrl($photo) }}" alt="{{ $product->name }}, foto {{ $i + 1 }} de {{ $photos->count() }}" width="1280" height="960"
                             @if ($i === 0) fetchpriority="high" @else loading="lazy" @endif decoding="async"
                             class="absolute inset-0 h-full w-full object-contain {{ $i === 0 ? '' : 'opacity-0' }}" :class="at === {{ $i }} ? 'opacity-100' : 'opacity-0 pointer-events-none'" data-gallery-photo>
                    @empty
                        <div class="flex h-full w-full flex-col items-center justify-center gap-2 text-ink/30">
                            <svg width="44" height="44" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="4" y="3" width="16" height="18" rx="2" stroke="currentColor" stroke-width="1.3"/><path d="M12 3v18" stroke="currentColor" stroke-width="1.3"/></svg>
                            <span class="text-sm">El proveedor no cargó fotos</span>
                        </div>
                    @endforelse
                    @if ($photos->count() > 1)
                        <button type="button" @click="at = (at + n - 1) % n" aria-label="Foto anterior" class="absolute left-3 top-1/2 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full border border-ink/10 bg-white/95 shadow-card">
                            <svg width="15" height="15" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M10 3L5 8L10 13" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </button>
                        <button type="button" @click="at = (at + 1) % n" aria-label="Foto siguiente" class="absolute right-3 top-1/2 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full border border-ink/10 bg-white/95 shadow-card">
                            <svg width="15" height="15" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M6 3L11 8L6 13" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </button>
                        <span class="absolute bottom-3 right-3 rounded-full bg-graphite/85 px-2.5 py-1 font-mono text-[11px] text-white" aria-live="polite"><span x-text="at + 1">1</span> / {{ $photos->count() }}</span>
                    @endif
                </div>
                @if ($photos->count() > 1)
                    <div class="no-scrollbar mt-3 flex gap-2 overflow-x-auto pb-1" role="list" aria-label="Fotos del producto">
                        @foreach ($photos as $i => $photo)
                            <button type="button" role="listitem" @click="at = {{ $i }}" aria-label="Ver foto {{ $i + 1 }}" :aria-current="at === {{ $i }} ? 'true' : 'false'"
                                    class="mk-photo h-16 w-20 shrink-0 overflow-hidden rounded-lg border-2 transition {{ $i === 0 ? 'border-amber-500' : 'border-transparent' }}" :class="at === {{ $i }} ? 'border-amber-500' : 'border-transparent opacity-75 hover:opacity-100'">
                                <img src="{{ PC::photoUrl($photo, thumb: true) }}" alt="" width="160" height="128" loading="lazy" decoding="async" class="h-full w-full object-cover">
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Datos y acciones --}}
            <div>
                <span class="text-xs font-semibold uppercase tracking-wide text-amber-700">{{ $product->categoryLabel() }}</span>
                <h1 class="mt-2 font-display text-2xl font-semibold leading-tight tracking-tight sm:text-3xl">{{ $product->name }}</h1>
                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <span class="font-display text-3xl font-semibold font-tabular" data-price>{{ $product->priceLabel() }}</span>
                    <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $availability }}">{{ $product->availabilityLabel() }}</span>
                    @if ($product->isFeatured())<span class="rounded-full bg-graphite px-3 py-1 text-xs font-semibold text-white" title="Visibilidad contratada por el proveedor">Destacado</span>@endif
                </div>
                @if ($product->hasPrice())
                    <p class="mt-1.5 text-xs text-ink/45">Precio de referencia publicado por el proveedor. Confirmalo al pedir la cotización.</p>
                @endif

                <dl class="mt-6 grid grid-cols-[max-content_1fr] gap-x-5 gap-y-2.5 border-y border-ink/10 py-5 text-sm">
                    @foreach ($specs as $label => $value)
                        <dt class="text-ink/50">{{ $label }}</dt><dd class="min-w-0 break-words font-medium">{{ $value }}</dd>
                    @endforeach
                </dl>

                {{-- Pedir cotización: con la cuenta de la empresa (el pedido, el pago y la entrega se acuerdan con el proveedor). --}}
                <div class="mt-6 rounded-2xl border border-ink/10 bg-white p-5" data-quote-cta>
                    @if ($panelUrl)
                        <p class="text-sm text-ink/65">Sumalo a tu pedido de cotización desde el panel de tu empresa.</p>
                        <a href="{{ $panelUrl }}" class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-full bg-graphite px-6 py-3.5 font-semibold text-white transition hover:-translate-y-0.5 sm:w-auto" data-open-panel>Pedir cotización en mi panel</a>
                    @elseif ($isGuest)
                        <p class="text-sm text-ink/65">Para pedir una cotización, entrá con la cuenta de tu empresa de ascensores. Explorar el catálogo no necesita cuenta.</p>
                        <div class="mt-4 flex flex-col gap-2 sm:flex-row">
                            <a href="{{ route('login') }}" class="inline-flex items-center justify-center rounded-full bg-graphite px-6 py-3 font-semibold text-white transition hover:-translate-y-0.5" data-login-to-quote>Entrar para pedir cotización</a>
                            <a href="{{ route('register') }}" class="inline-flex items-center justify-center rounded-full border border-ink/15 px-6 py-3 text-sm font-semibold hover:border-ink/35">Crear cuenta de empresa</a>
                        </div>
                    @else
                        <p class="text-sm text-ink/65">Las cotizaciones las piden las empresas de mantenimiento desde su panel (el dueño o quien tenga permiso de Stock).</p>
                    @endif
                    <p class="mt-4 text-xs leading-relaxed text-ink/45">Ascento no vende este producto ni cobra comisiones. Precio, pago, factura, envío y garantía se acuerdan con el proveedor.</p>
                </div>
            </div>
        </div>

        <div class="mt-10 grid gap-8 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)] lg:gap-12">
            <div class="space-y-8">
                @if ($product->description)
                    <section>
                        <h2 class="font-display text-lg font-semibold">Descripción</h2>
                        <p class="mt-3 whitespace-pre-line text-sm leading-relaxed text-ink/70">{{ $product->description }}</p>
                    </section>
                @endif
                @if ($product->compatibility)
                    <section data-compatibility>
                        <h2 class="font-display text-lg font-semibold">Compatibilidad declarada</h2>
                        <p class="mt-3 whitespace-pre-line text-sm leading-relaxed text-ink/70">{{ $product->compatibility }}</p>
                        <p class="mt-2 text-xs text-ink/45">La declara el proveedor; Ascento no la verifica. Confirmala con el proveedor y con los datos de tu equipo.</p>
                    </section>
                @endif
            </div>

            {{-- Perfil comercial público del proveedor (sin correos, teléfonos ni datos fiscales) --}}
            <aside class="rounded-2xl border border-ink/10 bg-white p-5" data-supplier-profile>
                <p class="text-xs font-semibold uppercase tracking-wide text-ink/45">Proveedor</p>
                <a href="{{ route('marketplace.supplier', $supplier->slug) }}" class="mt-1.5 block font-display text-lg font-semibold hover:text-amber-700">{{ $supplier->name }}</a>
                <p class="mt-1 text-xs text-ink/50">Cuenta revisada por Ascento · {{ \App\Support\Countries::name($supplier->country) }}@if ($operations) · {{ $operations }} {{ $operations === 1 ? 'operación completada' : 'operaciones completadas' }}@endif</p>
                @if ($supplier->coverage)<p class="mt-3 text-sm text-ink/65"><span class="font-medium text-ink">Zonas:</span> {{ $supplier->coverage }}</p>@endif
                <a href="{{ route('marketplace.supplier', $supplier->slug) }}" class="mt-4 inline-flex text-sm font-semibold text-amber-700 hover:text-amber-600">Ver su catálogo →</a>
            </aside>
        </div>

        @foreach ([['Relacionados', $related, 'Del mismo rubro'], ['Del mismo proveedor', $fromSupplier, 'Más de '.$supplier->name]] as [$heading, $items, $sub])
            @if ($items->isNotEmpty())
                <section class="mt-14">
                    <div class="flex items-baseline justify-between gap-3">
                        <h2 class="font-display text-xl font-semibold">{{ $heading }}</h2>
                        <span class="text-xs text-ink/45">{{ $sub }}</span>
                    </div>
                    <div class="mt-5 grid gap-4 min-[480px]:grid-cols-2 lg:grid-cols-4">
                        @foreach ($items as $item)
                            @include('marketplace.public.partials.card', ['product' => $item])
                        @endforeach
                    </div>
                </section>
            @endif
        @endforeach
    </div>
@endsection

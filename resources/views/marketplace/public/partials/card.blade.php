{{-- Tarjeta pública de un repuesto visible: foto principal, rubro, nombre, marca/modelo/código, proveedor, precio y disponibilidad. --}}
@php
    $cardPhoto = $product->photos->first();
    $cardUrl = \App\Http\Controllers\Marketplace\PublicCatalogController::productUrl($product);
    $cardAvailability = ['in_stock' => 'bg-emerald-50 text-emerald-700', 'to_order' => 'bg-amber-100 text-amber-700', 'unavailable' => 'bg-ink/5 text-ink/55'][$product->availability] ?? 'bg-ink/5 text-ink/55';
    $cardMeta = collect([$product->brand, $product->model])->filter()->implode(' · ');
@endphp
<article class="mk-card group relative flex flex-col overflow-hidden rounded-2xl border border-ink/10 bg-white shadow-card transition-shadow duration-200 hover:shadow-cardHover" data-product-card="{{ $product->id }}">
    <div class="mk-photo relative aspect-[4/3] overflow-hidden">
        @if ($cardPhoto)
            <img src="{{ \App\Http\Controllers\Marketplace\PublicCatalogController::photoUrl($cardPhoto, thumb: true) }}" alt="{{ $product->name }}"
                 width="640" height="480" loading="lazy" decoding="async" class="h-full w-full object-cover">
        @else
            <div class="flex h-full w-full flex-col items-center justify-center gap-2 text-ink/30" aria-hidden="true">
                <svg width="34" height="34" viewBox="0 0 24 24" fill="none"><rect x="4" y="3" width="16" height="18" rx="2" stroke="currentColor" stroke-width="1.4"/><path d="M12 3v18M9 9l-1.5 1.5L9 12M15 9l1.5 1.5L15 12" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span class="text-xs">Sin foto</span>
            </div>
        @endif
        @if ($product->isFeatured())
            <span class="absolute left-3 top-3 rounded-full bg-graphite/90 px-2.5 py-1 text-[11px] font-semibold text-white" title="Visibilidad contratada por el proveedor">Destacado</span>
        @endif
    </div>
    <div class="flex flex-1 flex-col p-4">
        <span class="text-[11px] font-semibold uppercase tracking-wide text-amber-700">{{ $product->categoryLabel() }}</span>
        <h3 class="mt-1.5 font-display text-[15px] font-semibold leading-snug">
            <a href="{{ $cardUrl }}" data-loading class="after:absolute after:inset-0 focus:outline-none focus-visible:underline">{{ $product->name }}</a>
        </h3>
        @if ($cardMeta !== '' || $product->part_number)
            <p class="mt-1 truncate text-xs text-ink/55">{{ $cardMeta }}@if ($product->part_number){{ $cardMeta !== '' ? ' · ' : '' }}Cód. {{ $product->part_number }}@endif</p>
        @endif
        <p class="mt-1 truncate text-xs text-ink/45">{{ $product->supplier?->name }}</p>
        <div class="mt-auto flex flex-wrap items-end justify-between gap-2 pt-4">
            <span class="font-display text-lg font-semibold font-tabular">{{ $product->priceLabel() }}</span>
            <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $cardAvailability }}">{{ $product->availabilityLabel() }}</span>
        </div>
    </div>
</article>

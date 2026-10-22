@php
    use App\Filament\Pages\Marketplace\PartsCatalog;
    use App\Filament\Pages\Marketplace\PartsRequests;
    use App\Support\Marketplace\Catalog;
    $photoUrl = fn ($photo, $thumb = true) => route('marketplace.photo', ['photo' => $photo->id] + ($thumb ? ['thumb' => 1] : []));
@endphp
<x-filament-panels::page>
    @include('filament.pages.marketplace.styles')

    <div class="mk-stack">
        <div class="mk-note">Precios, disponibilidad y compatibilidad los <strong>declara cada proveedor</strong>; Ascento no los verifica. Una cotización no es una compra: Ascento no cobra ni intermedia el pago.</div>

        @if($mode === 'product')
            {{-- Detalle del producto --}}
            <a class="mk-link" href="{{ PartsCatalog::getUrl() }}" wire:navigate>← Volver al catálogo</a>
            <div class="mk-two" data-mk-product="{{ $product->id }}">
                <section class="mk-card">
                    @if($product->photos->isNotEmpty())
                        <a href="{{ $photoUrl($product->photos->first(), false) }}" target="_blank" rel="noopener"><img src="{{ $photoUrl($product->photos->first(), false) }}" alt="{{ $product->name }}" style="width:100%; border-radius:.7rem; max-height:420px; object-fit:contain; background:#f3f4f6"></a>
                        @if($product->photos->count() > 1)
                            <div class="mk-photos" style="margin-top:.6rem">
                                @foreach($product->photos->skip(1) as $photo)
                                    <a href="{{ $photoUrl($photo, false) }}" target="_blank" rel="noopener"><img src="{{ $photoUrl($photo) }}" alt="Foto {{ $loop->iteration + 1 }} de {{ $product->name }}" loading="lazy"></a>
                                @endforeach
                            </div>
                        @endif
                    @else
                        <div class="mk-muted" style="padding:3rem 0; text-align:center">Sin fotos</div>
                    @endif
                </section>
                <section class="mk-card mk-stack">
                    <div>
                        <div style="display:flex; gap:.4rem; flex-wrap:wrap; margin-bottom:.4rem">
                            <span class="mk-pill">{{ $product->categoryLabel() }}</span>
                            <span class="mk-pill {{ $product->availability === 'in_stock' ? 'ok' : ($product->availability === 'unavailable' ? 'bad' : '') }}">{{ $product->availabilityLabel() }} (declarada)</span>
                            @if($product->isFeatured())<span class="mk-pill featured">Destacado</span>@endif
                        </div>
                        <h2 style="font-size:1.35rem; font-weight:700; margin:0">{{ $product->name }}</h2>
                        <p class="mk-price" style="margin:.35rem 0 0">{{ $product->priceLabel() }}</p>
                        @if($product->hasPrice())<p class="mk-muted">Precio de referencia publicado. El precio final es el de la cotización.</p>@endif
                    </div>
                    <dl class="mk-dl">
                        @if($product->brand)<dt>Marca</dt><dd>{{ $product->brand }}</dd>@endif
                        @if($product->model)<dt>Modelo</dt><dd>{{ $product->model }}</dd>@endif
                        @if($product->part_number)<dt>Código</dt><dd>{{ $product->part_number }}</dd>@endif
                    </dl>
                    @if($product->description)<p style="white-space:pre-line; font-size:.9rem; margin:0">{{ $product->description }}</p>@endif
                    @if($product->compatibility)
                        <div><p class="mk-h2" style="margin-bottom:.2rem">Compatibilidad declarada por el proveedor</p><p class="mk-muted" style="margin:0 0 .3rem">Ascento no la verifica. Confirmala con el proveedor antes de comprar.</p><p style="white-space:pre-line; font-size:.9rem; margin:0">{{ $product->compatibility }}</p></div>
                    @endif

                    <div class="mk-actions">
                        @if($canOrder)
                            <label class="sr-only" for="mk-qty">Cantidad</label>
                            <input id="mk-qty" type="number" min="1" max="{{ Catalog::MAX_QUANTITY }}" wire:model="quantity" class="mk-input" style="width:5.5rem">
                            <button type="button" class="mk-btn" wire:click="addToRequest({{ $product->id }})" data-mk-add>Agregar al pedido</button>
                        @endif
                        <button type="button" class="mk-btn secondary" wire:click="toggleFavorite({{ $product->id }})" aria-pressed="{{ in_array($product->id, $favorites, true) ? 'true' : 'false' }}" data-mk-fav>{{ in_array($product->id, $favorites, true) ? '★ En favoritos' : '☆ Favorito' }}</button>
                        <button type="button" class="mk-btn secondary" wire:click="toggleCompare({{ $product->id }})" aria-pressed="{{ in_array($product->id, $compare, true) ? 'true' : 'false' }}">{{ in_array($product->id, $compare, true) ? 'Quitar de comparar' : 'Comparar' }}</button>
                    </div>
                    <button type="button" class="mk-link" style="background:none; border:0; padding:0; cursor:pointer; text-align:left; font-size:.8rem" wire:click="startReport({{ $product->id }})" data-mk-report>Reportar esta publicación</button>
                    @if($reportingId === $product->id)
                        <form wire:submit="submitReport" class="mk-card" style="background:transparent">
                            <label for="mk-reason" style="font-size:.8rem; font-weight:600">Motivo</label>
                            <select id="mk-reason" wire:model="reportReason" class="mk-input"><option value="">Elegí un motivo</option>@foreach(Catalog::REPORT_REASONS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
                            @error('reportReason')<p class="mk-err">{{ $message }}</p>@enderror
                            <label for="mk-details" style="font-size:.8rem; font-weight:600; display:block; margin-top:.5rem">Detalle (opcional)</label>
                            <textarea id="mk-details" wire:model="reportDetails" maxlength="1000" class="mk-input" rows="3"></textarea>
                            <div class="mk-actions"><button class="mk-btn dark" type="submit">Enviar reporte</button><button type="button" class="mk-btn secondary" wire:click="$set('reportingId', null)">Cancelar</button></div>
                        </form>
                    @endif
                </section>
            </div>

            @include('filament.pages.marketplace.supplier-card', ['supplier' => $product->supplier, 'operations' => $operations])

            @if($history->isNotEmpty())
                <section class="mk-card">
                    <p class="mk-h2">Tus pedidos a este proveedor</p>
                    @foreach($history as $h)
                        <div style="display:flex; justify-content:space-between; gap:.6rem; font-size:.85rem; padding:.3rem 0"><a class="mk-link" style="white-space:nowrap" href="{{ PartsRequests::getUrl(['pedido' => $h->id]) }}">{{ $h->reference }}</a><span class="mk-muted">{{ $h->created_at->format('d/m/Y') }} · {{ $h->statusLabel() }}</span></div>
                    @endforeach
                </section>
            @endif

        @elseif($mode === 'compare')
            <a class="mk-link" href="{{ PartsCatalog::getUrl(['comparar' => $this->comparar]) }}" wire:navigate>← Volver al catálogo</a>
            @if($products->isEmpty())
                <div class="mk-card mk-muted">Elegí productos con «Comparar» para verlos lado a lado.</div>
            @else
                <div class="mk-card mk-scroll" data-mk-compare>
                    <table class="mk-table">
                        <thead><tr><th></th>@foreach($products as $p)<th style="min-width:180px"><a class="mk-title" href="{{ PartsCatalog::getUrl(['producto' => $p->id]) }}">{{ $p->name }}</a></th>@endforeach</tr></thead>
                        <tbody>
                            <tr><th>Foto</th>@foreach($products as $p)<td>@if($p->photos->first())<img src="{{ $photoUrl($p->photos->first()) }}" alt="" style="width:110px; aspect-ratio:1; object-fit:cover; border-radius:.5rem">@else<span class="mk-muted">Sin foto</span>@endif</td>@endforeach</tr>
                            <tr><th>Precio</th>@foreach($products as $p)<td class="mk-price">{{ $p->priceLabel() }}</td>@endforeach</tr>
                            <tr><th>Disponibilidad (declarada)</th>@foreach($products as $p)<td>{{ $p->availabilityLabel() }}</td>@endforeach</tr>
                            <tr><th>Proveedor</th>@foreach($products as $p)<td>{{ $p->supplier->name }}<br><span class="mk-muted">{{ $p->supplier->coverage }}</span></td>@endforeach</tr>
                            <tr><th>Operaciones completadas</th>@foreach($products as $p)<td>{{ $p->supplier->completedOperations() }}</td>@endforeach</tr>
                            <tr><th>Rubro</th>@foreach($products as $p)<td>{{ $p->categoryLabel() }}</td>@endforeach</tr>
                            <tr><th>Marca / modelo</th>@foreach($products as $p)<td>{{ collect([$p->brand, $p->model])->filter()->implode(' · ') ?: '—' }}</td>@endforeach</tr>
                            <tr><th>Código</th>@foreach($products as $p)<td>{{ $p->part_number ?: '—' }}</td>@endforeach</tr>
                            <tr><th>Compatibilidad declarada</th>@foreach($products as $p)<td style="white-space:pre-line">{{ $p->compatibility ?: '—' }}</td>@endforeach</tr>
                            <tr><th></th>@foreach($products as $p)<td><div class="mk-actions" style="padding:0">
                                @if($canOrder)<button type="button" class="mk-btn" wire:click="addToRequest({{ $p->id }})">Agregar al pedido</button>@endif
                                <button type="button" class="mk-btn secondary" wire:click="toggleCompare({{ $p->id }})">Quitar</button></div></td>@endforeach</tr>
                        </tbody>
                    </table>
                </div>
            @endif

        @else
            @if($supplierProfile)
                <a class="mk-link" href="{{ PartsCatalog::getUrl() }}" wire:navigate>← Todos los proveedores</a>
                @include('filament.pages.marketplace.supplier-card', ['supplier' => $supplierProfile, 'operations' => $operations])
            @endif

            <section class="mk-card" aria-label="Buscar y filtrar">
                <div class="mk-filters">
                    <div style="grid-column: 1 / -1"><label for="mk-q">Buscar</label><input id="mk-q" type="search" class="mk-input" wire:model.live.debounce.400ms="q" placeholder="Nombre, marca, modelo, código o equipo compatible" data-mk-search></div>
                    <div><label for="mk-rubro">Rubro</label><select id="mk-rubro" class="mk-input" wire:model.live="rubro"><option value="">Todos</option>@foreach(Catalog::CATEGORIES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                    <div><label for="mk-marca">Marca</label><select id="mk-marca" class="mk-input" wire:model.live="marca"><option value="">Todas</option>@foreach($brands as $b)<option value="{{ $b }}">{{ $b }}</option>@endforeach</select></div>
                    <div><label for="mk-disp">Disponibilidad</label><select id="mk-disp" class="mk-input" wire:model.live="disponibilidad"><option value="">Cualquiera</option>@foreach(Catalog::AVAILABILITY as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                    <div><label for="mk-precio">Precio</label><select id="mk-precio" class="mk-input" wire:model.live="precio"><option value="">Todos</option>@foreach(Catalog::PRICE_MODES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                    <div><label style="display:flex; gap:.4rem; align-items:center; font-size:.85rem; min-height:2.4rem"><input type="checkbox" wire:model.live="favoritos"> Solo favoritos</label></div>
                    <div><button type="button" class="mk-btn secondary" wire:click="clearFilters" style="width:100%">Limpiar</button></div>
                </div>
            </section>

            <p class="mk-muted" role="status" aria-live="polite">{{ $total }} {{ $total === 1 ? 'producto' : 'productos' }}</p>

            @if($products->isEmpty())
                <div class="mk-card mk-muted">No encontramos productos con esos filtros. Probá con otra palabra o limpiá los filtros.</div>
            @else
                <div class="mk-grid" data-mk-list>
                    @foreach($products as $p)
                        <article class="mk-card mk-product" wire:key="mk-p-{{ $p->id }}" data-mk-item="{{ $p->id }}">
                            <a class="mk-img" href="{{ PartsCatalog::getUrl(['producto' => $p->id]) }}" tabindex="-1" aria-hidden="true">
                                @if($p->photos->first())<img src="{{ $photoUrl($p->photos->first()) }}" alt="" loading="lazy">@else Sin foto @endif
                            </a>
                            <div class="mk-body">
                                <div style="display:flex; gap:.3rem; flex-wrap:wrap">
                                    @if($p->isFeatured())<span class="mk-pill featured">Destacado</span>@endif
                                    <span class="mk-pill {{ $p->availability === 'in_stock' ? 'ok' : ($p->availability === 'unavailable' ? 'bad' : '') }}">{{ $p->availabilityLabel() }}</span>
                                </div>
                                <a class="mk-title" href="{{ PartsCatalog::getUrl(['producto' => $p->id]) }}">{{ $p->name }}</a>
                                <span class="mk-muted">{{ collect([$p->brand, $p->model, $p->part_number ? 'Cód. '.$p->part_number : null])->filter()->implode(' · ') }}</span>
                                <span class="mk-price">{{ $p->priceLabel() }}</span>
                                <a class="mk-link" style="font-size:.8rem" href="{{ PartsCatalog::getUrl(['proveedor' => $p->supplier_id]) }}">{{ $p->supplier->name }}</a>
                                <div class="mk-actions">
                                    @if($canOrder)<button type="button" class="mk-btn" wire:click="addToRequest({{ $p->id }})" aria-label="Agregar {{ $p->name }} al pedido">Agregar</button>@endif
                                    <button type="button" class="mk-btn secondary" wire:click="toggleFavorite({{ $p->id }})" aria-pressed="{{ in_array($p->id, $favorites, true) ? 'true' : 'false' }}" aria-label="{{ in_array($p->id, $favorites, true) ? 'Quitar de favoritos' : 'Guardar en favoritos' }}">{{ in_array($p->id, $favorites, true) ? '★' : '☆' }}</button>
                                    <button type="button" class="mk-btn secondary" wire:click="toggleCompare({{ $p->id }})" aria-pressed="{{ in_array($p->id, $compare, true) ? 'true' : 'false' }}">{{ in_array($p->id, $compare, true) ? '✓ Comparar' : 'Comparar' }}</button>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
                @if($total > $products->count())
                    <div style="text-align:center"><button type="button" class="mk-btn secondary" wire:click="showMore">Ver más</button></div>
                @endif
            @endif
        @endif

        @if($compare !== [] || $cartCount > 0)
            <div class="mk-bar" role="region" aria-label="Comparar y pedido">
                <span style="font-size:.85rem">
                    @if($compare !== []){{ count($compare) }} para comparar @endif
                    @if($compare !== [] && $cartCount > 0) · @endif
                    @if($cartCount > 0){{ $cartCount }} {{ $cartCount === 1 ? 'producto' : 'productos' }} en tu pedido @endif
                </span>
                <span class="mk-actions" style="padding:0; margin:0">
                    @if(count($compare) > 1 && $mode !== 'compare')<a class="mk-btn secondary" href="{{ PartsCatalog::getUrl(['comparar' => $this->comparar, 'vista' => 'comparar']) }}" wire:navigate>Comparar</a>@endif
                    @if($cartCount > 0)<a class="mk-btn" href="{{ PartsRequests::getUrl() }}" data-mk-cart>Ver mi pedido</a>@endif
                </span>
            </div>
        @endif
    </div>
</x-filament-panels::page>

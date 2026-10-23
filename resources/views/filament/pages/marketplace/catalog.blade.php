@php
    use App\Filament\Pages\Marketplace\PartsCatalog;
    use App\Filament\Pages\Marketplace\PartsRequests;
    use App\Support\Marketplace\Catalog;
    $photoUrl = fn ($photo, $thumb = true) => route('marketplace.photo', ['photo' => $photo->id] + ($thumb ? ['thumb' => 1] : []));
    $icon = [
        'search' => '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.45 4.39l3.08 3.08a.75.75 0 11-1.06 1.06l-3.08-3.08A7 7 0 012 9z" clip-rule="evenodd"/></svg>',
        'photo' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="M21 16l-5-5-8 8"/></svg>',
        'store' => '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M3 4.5A1.5 1.5 0 014.5 3h11A1.5 1.5 0 0117 4.5V6l1 2.5a2.5 2.5 0 01-2 2.45V16a1 1 0 01-1 1H5a1 1 0 01-1-1v-5.05A2.5 2.5 0 012 8.5L3 6V4.5zM6 11v4.5h3V13h2v2.5h3V11a2.5 2.5 0 01-2-1 2.5 2.5 0 01-4 0 2.5 2.5 0 01-2 1z"/></svg>',
        'star' => '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 1.8l2.47 5.01 5.53.8-4 3.9.94 5.5L10 14.4l-4.94 2.6.94-5.5-4-3.9 5.53-.8L10 1.8z"/></svg>',
        'star-o' => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M10 2.6l2.2 4.46 4.92.72-3.56 3.47.84 4.9L10 13.84l-4.4 2.31.84-4.9L2.88 7.78l4.92-.72L10 2.6z" stroke-linejoin="round"/></svg>',
        'compare' => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M7 3v14M13 3v14M3 7h4M13 13h4" stroke-linecap="round"/></svg>',
        'left' => '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M12.8 4.2a.75.75 0 010 1.06L8.06 10l4.74 4.74a.75.75 0 11-1.06 1.06l-5.27-5.27a.75.75 0 010-1.06l5.27-5.27a.75.75 0 011.06 0z" clip-rule="evenodd"/></svg>',
        'right' => '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.2 15.8a.75.75 0 010-1.06L11.94 10 7.2 5.26a.75.75 0 111.06-1.06l5.27 5.27a.75.75 0 010 1.06l-5.27 5.27a.75.75 0 01-1.06 0z" clip-rule="evenodd"/></svg>',
        'box' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M21 8l-9-5-9 5 9 5 9-5zM3 8v8l9 5 9-5V8M12 13v8"/></svg>',
    ];
    $availabilityTone = fn ($p) => $p->availability === 'in_stock' ? 'ok' : ($p->availability === 'unavailable' ? 'bad' : '');
    $activeFilters = collect([$this->q, $this->rubro, $this->marca, $this->disponibilidad, $this->precio, $this->favoritos ? '1' : ''])->filter(fn ($v) => $v !== '' && $v !== null)->count();
@endphp
<x-filament-panels::page>
    @include('filament.pages.marketplace.styles')

    <div class="mk-stack">
        <div class="mk-note">Precios, disponibilidad y compatibilidad los <strong>declara cada proveedor</strong>; Ascento no los verifica. Una cotización no es una compra: Ascento no cobra comisiones ni procesa el pago; el pago, el envío y la entrega se coordinan directamente con cada proveedor. Los «Destacados» son visibilidad contratada por el proveedor y solo se ordenan antes que productos igual de relevantes.</div>

        @if($mode === 'product')
            {{-- Ficha del producto --}}
            <a class="mk-link mk-back" href="{{ PartsCatalog::getUrl() }}" wire:navigate>{!! $icon['left'] !!} Volver al catálogo</a>
            <div class="mk-detail" data-mk-product="{{ $product->id }}">
                <section class="mk-card mk-gallery" aria-label="Fotos del producto">
                    @php
                        $photos = $product->photos;
                    @endphp
                    @if($photos->isNotEmpty())
                        <div wire:key="mk-gallery-{{ $product->id }}" x-data="{ i: 0, n: {{ $photos->count() }},
                                go(k) { this.i = (k + this.n) % this.n; const t = this.$refs.track; t.scrollTo({ left: t.clientWidth * this.i, behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' }); } }"
                             x-on:keydown.left.prevent="go(i - 1)" x-on:keydown.right.prevent="go(i + 1)" data-mk-gallery>
                            <div class="mk-viewer">
                                <div class="mk-track" x-ref="track" tabindex="0" role="group" aria-roledescription="carrusel" aria-label="{{ $photos->count() }} {{ $photos->count() === 1 ? 'foto' : 'fotos' }} de {{ $product->name }}{{ $photos->count() > 1 ? '. Usá las flechas para cambiar de foto.' : '' }}"
                                     x-on:scroll.debounce.60ms="i = Math.round($el.scrollLeft / Math.max(1, $el.clientWidth))">
                                    @foreach($photos as $photo)
                                        <figure class="mk-slide" aria-label="Foto {{ $loop->iteration }} de {{ $photos->count() }}"><img src="{{ $photoUrl($photo, false) }}" alt="{{ $product->name }} — foto {{ $loop->iteration }}" @unless($loop->first) loading="lazy" @endunless></figure>
                                    @endforeach
                                </div>
                                @if($photos->count() > 1)
                                    <button type="button" class="mk-nav prev" x-on:click="go(i - 1)" aria-label="Foto anterior" data-mk-prev>{!! $icon['left'] !!}</button>
                                    <button type="button" class="mk-nav next" x-on:click="go(i + 1)" aria-label="Foto siguiente" data-mk-next>{!! $icon['right'] !!}</button>
                                    <span class="mk-counter" aria-live="polite"><span x-text="i + 1">1</span> / {{ $photos->count() }}</span>
                                @endif
                                @foreach($photos as $photo)
                                    <a class="mk-zoom" href="{{ $photoUrl($photo, false) }}" target="_blank" rel="noopener" x-show="i === {{ $loop->index }}" @unless($loop->first) x-cloak @endunless>Ver en tamaño completo</a>
                                @endforeach
                            </div>
                            @if($photos->count() > 1)
                                <div class="mk-thumbs" role="tablist" aria-label="Miniaturas">
                                    @foreach($photos as $photo)
                                        <button type="button" class="mk-thumb" role="tab" x-on:click="go({{ $loop->index }})" :aria-current="i === {{ $loop->index }} ? 'true' : 'false'" aria-current="{{ $loop->first ? 'true' : 'false' }}" aria-label="Ver foto {{ $loop->iteration }}"><img src="{{ $photoUrl($photo) }}" alt="" loading="lazy"></button>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @else
                        <div class="mk-viewer mk-noimg">{!! $icon['photo'] !!}<span>El proveedor todavía no cargó fotos</span></div>
                    @endif
                </section>

                <section class="mk-card mk-info">
                    <div class="mk-pills">
                        <span class="mk-pill">{{ $product->categoryLabel() }}</span>
                        <span class="mk-pill {{ $availabilityTone($product) }}">{{ $product->availabilityLabel() }} (declarada)</span>
                        @if($product->isFeatured())<span class="mk-pill featured">Destacado</span>@endif
                    </div>
                    <h2 class="mk-h1">{{ $product->name }}</h2>
                    <div class="mk-pricebox">
                        <p class="mk-price {{ $product->hasPrice() ? '' : 'quote' }}" style="margin:0; font-size:1.4rem">{{ $product->priceLabel() }}</p>
                        <p class="mk-muted" style="margin:.2rem 0 0">{{ $product->hasPrice() ? 'Precio de referencia publicado por el proveedor. El precio final es el de la cotización.' : 'El proveedor informa el precio en la cotización.' }}</p>
                    </div>
                    <dl class="mk-dl">
                            @if($product->brand)<dt>Marca</dt><dd>{{ $product->brand }}</dd>@endif
                            @if($product->model)<dt>Modelo</dt><dd>{{ $product->model }}</dd>@endif
                            @if($product->part_number)<dt>Código</dt><dd class="mk-code">{{ $product->part_number }}</dd>@endif
                            <dt>Proveedor</dt><dd><a class="mk-link" href="{{ PartsCatalog::getUrl(['proveedor' => $product->supplier_id]) }}">{{ $product->supplier->name }}</a></dd>
                    </dl>
                    @if($product->description)<p style="white-space:pre-line; font-size:.9rem; margin:0">{{ $product->description }}</p>@endif
                    @if($product->compatibility)
                        <div class="mk-compatbox" data-mk-compat>
                            <p class="mk-h2" style="margin-bottom:.15rem; font-size:.9rem">Compatibilidad declarada por el proveedor</p>
                            <p class="mk-muted" style="margin:0 0 .35rem">Ascento no la verifica. Confirmala con el proveedor antes de comprar.</p>
                            <p style="white-space:pre-line; font-size:.88rem; margin:0">{{ $product->compatibility }}</p>
                        </div>
                    @endif

                    @if($canOrder)
                        <div class="mk-order">
                            <label class="mk-sr" for="mk-qty">Cantidad</label>
                            <div class="mk-qty">
                                <button type="button" wire:click="$set('quantity', {{ max(1, (int) $quantity - 1) }})" aria-label="Restar uno" @disabled((int) $quantity <= 1)>−</button>
                                <input id="mk-qty" type="number" min="1" max="{{ Catalog::MAX_QUANTITY }}" wire:model="quantity" inputmode="numeric">
                                <button type="button" wire:click="$set('quantity', {{ min(Catalog::MAX_QUANTITY, (int) $quantity + 1) }})" aria-label="Sumar uno">+</button>
                            </div>
                            <button type="button" class="mk-btn grow" wire:click="addToRequest({{ $product->id }})" wire:loading.attr="disabled" wire:target="addToRequest" data-mk-add>Agregar al pedido de cotización</button>
                        </div>
                    @endif
                    <div class="mk-actions" style="padding-top:0">
                        <button type="button" class="mk-btn secondary" wire:click="toggleFavorite({{ $product->id }})" aria-pressed="{{ in_array($product->id, $favorites, true) ? 'true' : 'false' }}" data-mk-fav>{!! in_array($product->id, $favorites, true) ? $icon['star'] : $icon['star-o'] !!} {{ in_array($product->id, $favorites, true) ? 'En favoritos' : 'Favorito' }}</button>
                        <button type="button" class="mk-btn secondary" wire:click="toggleCompare({{ $product->id }})" aria-pressed="{{ in_array($product->id, $compare, true) ? 'true' : 'false' }}">{!! $icon['compare'] !!} {{ in_array($product->id, $compare, true) ? 'Quitar de comparar' : 'Comparar' }}</button>
                    </div>
                    <button type="button" class="mk-link" style="background:none; border:0; padding:0; cursor:pointer; text-align:left; font-size:.8rem" wire:click="startReport({{ $product->id }})" data-mk-report>Reportar esta publicación</button>
                    @if($reportingId === $product->id)
                        <form wire:submit="submitReport" class="mk-card" style="background:transparent">
                            <label class="mk-label" for="mk-reason">Motivo</label>
                            <select id="mk-reason" wire:model="reportReason" class="mk-input mk-select"><option value="">Elegí un motivo</option>@foreach(Catalog::REPORT_REASONS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
                            @error('reportReason')<p class="mk-err">{{ $message }}</p>@enderror
                            <label class="mk-label" for="mk-details" style="margin-top:.5rem">Detalle (opcional)</label>
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
            <a class="mk-link mk-back" href="{{ PartsCatalog::getUrl(['comparar' => $this->comparar]) }}" wire:navigate>{!! $icon['left'] !!} Volver al catálogo</a>
            @if($products->isEmpty())
                <div class="mk-card mk-muted">Elegí productos con «Comparar» para verlos lado a lado.</div>
            @else
                <div class="mk-card mk-scroll" data-mk-compare>
                    <table class="mk-table">
                        <thead><tr><th></th>@foreach($products as $p)<th style="min-width:180px"><a class="mk-title" href="{{ PartsCatalog::getUrl(['producto' => $p->id]) }}">{{ $p->name }}</a></th>@endforeach</tr></thead>
                        <tbody>
                            <tr><th>Foto</th>@foreach($products as $p)<td>@if($p->photos->first())<img src="{{ $photoUrl($p->photos->first()) }}" alt="" style="width:110px; aspect-ratio:1; object-fit:contain; border-radius:.5rem; background:#f5f5f4">@else<span class="mk-muted">Sin foto</span>@endif</td>@endforeach</tr>
                            <tr><th>Precio</th>@foreach($products as $p)<td class="mk-price">{{ $p->priceLabel() }}</td>@endforeach</tr>
                            <tr><th>Disponibilidad (declarada)</th>@foreach($products as $p)<td>{{ $p->availabilityLabel() }}</td>@endforeach</tr>
                            <tr><th>Proveedor</th>@foreach($products as $p)<td>{{ $p->supplier->name }}<br><span class="mk-muted">{{ $p->supplier->coverage }}</span></td>@endforeach</tr>
                            <tr><th>Operaciones completadas</th>@foreach($products as $p)<td>{{ $p->supplier->completedOperations() }}</td>@endforeach</tr>
                            <tr><th>Rubro</th>@foreach($products as $p)<td>{{ $p->categoryLabel() }}</td>@endforeach</tr>
                            <tr><th>Marca / modelo</th>@foreach($products as $p)<td>{{ collect([$p->brand, $p->model])->filter()->implode(' · ') ?: '—' }}</td>@endforeach</tr>
                            <tr><th>Código</th>@foreach($products as $p)<td class="mk-code">{{ $p->part_number ?: '—' }}</td>@endforeach</tr>
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
                <a class="mk-link mk-back" href="{{ PartsCatalog::getUrl() }}" wire:navigate>{!! $icon['left'] !!} Todos los proveedores</a>
                @include('filament.pages.marketplace.supplier-card', ['supplier' => $supplierProfile, 'operations' => $operations])
            @endif

            <section class="mk-card mk-toolbar" aria-label="Buscar y filtrar">
                <div class="mk-search">
                    <label class="mk-sr" for="mk-q">Buscar repuestos</label>
                    {!! $icon['search'] !!}
                    <input id="mk-q" type="search" class="mk-input" wire:model.live.debounce.400ms="q" placeholder="Nombre, marca, modelo, código o equipo compatible" autocomplete="off" data-mk-search>
                </div>
                <div class="mk-filters">
                    <div><label for="mk-rubro">Rubro</label><select id="mk-rubro" class="mk-input mk-select" wire:model.live="rubro"><option value="">Todos los rubros</option>@foreach(Catalog::CATEGORIES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                    <div><label for="mk-marca">Marca</label><select id="mk-marca" class="mk-input mk-select" wire:model.live="marca"><option value="">Todas las marcas</option>@foreach($brands as $b)<option value="{{ $b }}">{{ $b }}</option>@endforeach</select></div>
                    <div><label for="mk-disp">Disponibilidad</label><select id="mk-disp" class="mk-input mk-select" wire:model.live="disponibilidad"><option value="">Cualquiera</option>@foreach(Catalog::AVAILABILITY as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                    <div><label for="mk-precio">Precio</label><select id="mk-precio" class="mk-input mk-select" wire:model.live="precio"><option value="">Con o sin precio</option>@foreach(Catalog::PRICE_MODES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                    <div><span class="mk-label" aria-hidden="true">&nbsp;</span><label class="mk-check"><input type="checkbox" wire:model.live="favoritos"> Solo favoritos</label></div>
                </div>
            </section>

            <div class="mk-resultbar">
                <p class="mk-muted" role="status" aria-live="polite" style="margin:0">
                    <span wire:loading.remove wire:target="q, rubro, marca, disponibilidad, precio, favoritos, clearFilters, showMore">{{ $total }} {{ $total === 1 ? 'producto' : 'productos' }}@if($activeFilters > 0) · {{ $activeFilters }} {{ $activeFilters === 1 ? 'filtro activo' : 'filtros activos' }}@endif</span>
                    <span class="mk-loading" wire:loading wire:target="q, rubro, marca, disponibilidad, precio, favoritos, clearFilters, showMore">Buscando…</span>
                </p>
                @if($activeFilters > 0 || $supplierProfile)<button type="button" class="mk-btn secondary" wire:click="clearFilters" data-mk-clear>Limpiar filtros</button>@endif
            </div>

            @if($products->isEmpty())
                <div class="mk-card mk-empty" data-mk-empty>
                    {!! $icon['box'] !!}
                    <p style="font-weight:700; margin:0">{{ $activeFilters > 0 ? 'No encontramos repuestos con esos filtros' : 'Todavía no hay repuestos publicados' }}</p>
                    <p class="mk-muted" style="margin:.3rem 0 0">{{ $activeFilters > 0 ? 'Probá con otra palabra (por ejemplo, la marca o el código) o limpiá los filtros.' : 'Cuando los proveedores aprobados publiquen productos, los vas a ver acá.' }}</p>
                    @if($activeFilters > 0)<button type="button" class="mk-btn secondary" style="margin-top:.8rem" wire:click="clearFilters">Limpiar filtros</button>@endif
                </div>
            @else
                <div class="mk-grid" data-mk-list wire:loading.attr="aria-busy" wire:target="q, rubro, marca, disponibilidad, precio, favoritos, clearFilters">
                    @foreach($products as $p)
                        @php
                            $first = $p->photos->first();
                            $url = PartsCatalog::getUrl(['producto' => $p->id]);
                        @endphp
                        <article class="mk-card mk-product" wire:key="mk-p-{{ $p->id }}" data-mk-item="{{ $p->id }}">
                            <div class="mk-media">
                                <a class="mk-img" href="{{ $url }}" tabindex="-1" aria-hidden="true">
                                    @if($first)<img src="{{ $photoUrl($first) }}" alt="" loading="lazy">@else<span class="mk-ph">{!! $icon['photo'] !!}Sin foto</span>@endif
                                    @if($p->photos->count() > 1)<span class="mk-count">{{ $p->photos->count() }} fotos</span>@endif
                                </a>
                                @if($p->isFeatured())<span class="mk-badges"><span class="mk-pill featured">Destacado</span></span>@endif
                                <div class="mk-quick">
                                    <button type="button" class="mk-icon-btn" wire:click="toggleFavorite({{ $p->id }})" aria-pressed="{{ in_array($p->id, $favorites, true) ? 'true' : 'false' }}" aria-label="{{ in_array($p->id, $favorites, true) ? 'Quitar '.$p->name.' de favoritos' : 'Guardar '.$p->name.' en favoritos' }}" title="Favorito">{!! in_array($p->id, $favorites, true) ? $icon['star'] : $icon['star-o'] !!}</button>
                                    <button type="button" class="mk-icon-btn" wire:click="toggleCompare({{ $p->id }})" aria-pressed="{{ in_array($p->id, $compare, true) ? 'true' : 'false' }}" aria-label="{{ in_array($p->id, $compare, true) ? 'Quitar '.$p->name.' de comparar' : 'Comparar '.$p->name }}" title="Comparar">{!! $icon['compare'] !!}</button>
                                </div>
                            </div>
                            <div class="mk-body">
                                <span class="mk-cat mk-clamp1">{{ $p->categoryLabel() }}</span>
                                <a class="mk-title mk-clamp2" href="{{ $url }}">{{ $p->name }}</a>
                                @if($p->brand || $p->model)<span class="mk-meta mk-clamp1">{{ collect([$p->brand, $p->model])->filter()->implode(' · ') }}</span>@endif
                                @if($p->part_number)<span class="mk-meta mk-clamp1">Cód. <span class="mk-code">{{ $p->part_number }}</span></span>@endif
                                @if($p->compatibility)<span class="mk-compat mk-clamp1"><b>Compat. declarada:</b> {{ str_replace("\n", ' · ', $p->compatibility) }}</span>@endif
                                <span class="mk-supplier">{!! $icon['store'] !!}<a class="mk-link" href="{{ PartsCatalog::getUrl(['proveedor' => $p->supplier_id]) }}">{{ $p->supplier->name }}</a></span>
                                <div class="mk-foot">
                                    <span class="mk-price {{ $p->hasPrice() ? '' : 'quote' }}">{{ $p->priceLabel() }}</span>
                                    <span class="mk-pill {{ $availabilityTone($p) }}">{{ $p->availabilityLabel() }}</span>
                                </div>
                                <div class="mk-actions">
                                    <a class="mk-btn secondary grow" href="{{ $url }}" aria-label="Ver detalle de {{ $p->name }}">Ver detalle</a>
                                    @if($canOrder)<button type="button" class="mk-btn grow" wire:click="addToRequest({{ $p->id }})" aria-label="Agregar {{ $p->name }} al pedido">Agregar</button>@endif
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
                @if($total > $products->count())
                    <div style="text-align:center"><button type="button" class="mk-btn secondary" wire:click="showMore" wire:loading.attr="disabled" wire:target="showMore">Ver más ({{ $total - $products->count() }})</button></div>
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

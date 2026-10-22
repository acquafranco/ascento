@php
    use App\Support\Marketplace\Catalog;
    use App\Http\Controllers\Supplier\SupplierProductController;
    $isNew = ! $product->exists;
    $locked = in_array($supplier->status, ['rejected', 'suspended'], true);
    $actions = ['published' => 'Publicar', 'paused' => 'Pausar', 'retired' => 'Retirar', 'draft' => 'Volver a borrador'];
@endphp
<x-supplier-layout :title="$isNew ? 'Nuevo producto' : $product->name">
    <a class="s-link" href="{{ route('suppliers.products.index') }}">← Mi catálogo</a>
    <div class="s-row" style="margin:8px 0 14px">
        <h1 class="s-h1">{{ $isNew ? 'Nuevo producto' : $product->name }}</h1>
        @unless($isNew)<span class="s-tag {{ $product->status === 'published' ? 'ok' : '' }}">{{ $product->statusLabel() }}</span>@endunless
    </div>
    @include('suppliers.partials.status-notice')
    @if(! $isNew && $product->hidden_by_admin)
        <div class="s-notice bad" data-product-hidden><strong>Oculto por moderación.</strong> {{ $product->hidden_reason ?: 'No cumple las reglas de publicación.' }} Si lo corregiste, escribinos a {{ config('app.support_email') }} para revisarlo.</div>
    @endif

    @if(! $isNew && ! $locked)
        <div class="s-card s-row">
            <span class="s-muted">Estado de la publicación</span>
            <div class="s-actions">
                @foreach(SupplierProductController::TRANSITIONS[$product->status] ?? [] as $to)
                    <form method="POST" action="{{ route('suppliers.products.status', $product->id) }}">@csrf<input type="hidden" name="estado" value="{{ $to }}">
                        <button class="s-btn {{ $to === 'published' ? '' : ($to === 'retired' ? 'danger' : 'secondary') }}" type="submit" data-product-status="{{ $to }}">{{ $actions[$to] }}</button></form>
                @endforeach
            </div>
        </div>
    @endif

    <form method="POST" action="{{ $isNew ? route('suppliers.products.store') : route('suppliers.products.update', $product->id) }}" class="s-card s-form" data-product-form>
        @csrf @unless($isNew) @method('PUT') @endunless
        <fieldset @disabled($locked) style="border:0; padding:0; margin:0">
            <div class="s-cols">
                <div class="s-field"><label class="s-label" for="name">Nombre</label><input id="name" type="text" name="name" value="{{ old('name', $product->name) }}" required maxlength="160" placeholder="Operador de puerta">@error('name')<p class="s-err">{{ $message }}</p>@enderror</div>
                <div class="s-field"><label class="s-label" for="category">Rubro</label>
                    <select id="category" name="category" required>
                        <option value="">Elegí un rubro</option>
                        @foreach(Catalog::CATEGORIES as $key => $label)<option value="{{ $key }}" @selected(old('category', $product->category) === $key)>{{ $label }}</option>@endforeach
                    </select>@error('category')<p class="s-err">{{ $message }}</p>@enderror</div>
            </div>
            <div class="s-cols">
                <div class="s-field"><label class="s-label" for="brand">Marca</label><input id="brand" type="text" name="brand" value="{{ old('brand', $product->brand) }}" maxlength="80"></div>
                <div class="s-field"><label class="s-label" for="model">Modelo</label><input id="model" type="text" name="model" value="{{ old('model', $product->model) }}" maxlength="120"></div>
                <div class="s-field"><label class="s-label" for="part_number">Código o número de pieza</label><input id="part_number" type="text" name="part_number" value="{{ old('part_number', $product->part_number) }}" maxlength="80"></div>
            </div>
            <div class="s-field"><label class="s-label" for="description">Descripción</label><textarea id="description" name="description" maxlength="5000">{{ old('description', $product->description) }}</textarea></div>
            <div class="s-field"><label class="s-label" for="compatibility">Compatibilidad declarada</label><textarea id="compatibility" name="compatibility" maxlength="2000" placeholder="Equipos, marcas o modelos con los que es compatible" aria-describedby="compat-hint">{{ old('compatibility', $product->compatibility) }}</textarea>
                <p class="s-hint" id="compat-hint">Se muestra como «declarada por el proveedor»: Ascento no la verifica. Sé preciso.</p></div>
            <div class="s-cols">
                <fieldset class="s-field" style="border:0; padding:0">
                    <legend class="s-label">Precio</legend>
                    @foreach(Catalog::PRICE_MODES as $key => $label)
                        <label style="display:flex; gap:8px; align-items:center; font-size:14px; padding:3px 0"><input type="radio" name="price_mode" value="{{ $key }}" @checked(old('price_mode', $product->price_mode) === $key)> {{ $label }}</label>
                    @endforeach
                </fieldset>
                <div class="s-field"><label class="s-label" for="price">Precio unitario (ARS, sin cotizar)</label><input id="price" type="number" name="price" value="{{ old('price', $product->price) }}" min="0.01" step="0.01" inputmode="decimal">
                    <p class="s-hint">Precio de referencia. La cotización final la mandás en cada pedido.</p>@error('price')<p class="s-err">{{ $message }}</p>@enderror</div>
                <div class="s-field"><label class="s-label" for="availability">Disponibilidad declarada</label>
                    <select id="availability" name="availability" required>
                        @foreach(Catalog::AVAILABILITY as $key => $label)<option value="{{ $key }}" @selected(old('availability', $product->availability) === $key)>{{ $label }}</option>@endforeach
                    </select></div>
            </div>
            @unless($locked)<button class="s-btn" type="submit">{{ $isNew ? 'Guardar borrador' : 'Guardar cambios' }}</button>@endunless
        </fieldset>
    </form>

    @unless($isNew)
        <section class="s-card" data-product-photos>
            <h2 class="s-h2">Fotos ({{ $product->photos->count() }}/{{ Catalog::MAX_PHOTOS }})</h2>
            @if($product->photos->isNotEmpty())
                <div class="s-photos">
                    @foreach($product->photos as $photo)
                        <div>
                            <img src="{{ route('marketplace.photo', ['photo' => $photo->id, 'thumb' => 1]) }}" alt="Foto {{ $loop->iteration }} de {{ $product->name }}" loading="lazy">
                            @unless($locked)
                                <form method="POST" action="{{ route('suppliers.products.photos.destroy', [$product->id, $photo->id]) }}" style="margin-top:6px">@csrf @method('DELETE')<button class="s-btn danger" type="submit" style="width:100%; min-height:36px; padding:6px">Quitar</button></form>
                            @endunless
                        </div>
                    @endforeach
                </div>
            @endif
            @if(! $locked && $product->photos->count() < Catalog::MAX_PHOTOS)
                <form method="POST" action="{{ route('suppliers.products.photos.store', $product->id) }}" enctype="multipart/form-data" class="s-form" style="margin-top:12px">
                    @csrf
                    <label class="s-label" for="foto">Agregar una foto</label>
                    <input id="foto" type="file" name="foto" accept="image/jpeg,image/png,image/webp" required>
                    <p class="s-hint">JPG, PNG o WebP, hasta {{ Catalog::MAX_PHOTO_KB / 1024 }} MB. Le quitamos los datos de ubicación.</p>
                    @error('foto')<p class="s-err">{{ $message }}</p>@enderror
                    <button class="s-btn secondary" type="submit" style="margin-top:8px">Subir foto</button>
                </form>
            @endif
        </section>
    @endunless
</x-supplier-layout>

@php
    use App\Support\Marketplace\Catalog;
    use App\Http\Controllers\Supplier\SupplierProductController;
    $isNew = ! $product->exists;
    $locked = in_array($supplier->status, ['rejected', 'suspended'], true);
    $count = $isNew ? 0 : $product->photos->count();
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

    @if(! $isNew && $product->status === 'over_limit')
        <div class="s-notice warn" data-product-over-limit><strong>Fuera del límite de tu plan.</strong> No se ve en el catálogo porque tu plan permite {{ $usage['limit'] }} productos publicados a la vez. No es una sanción ni se borró nada: publicalo cuando tengas lugar (pausá o retirá otro) o con Premium.</div>
    @endif

    @if(! $isNew && ! $locked)
        <div class="s-card s-row">
            <span class="s-muted">Estado de la publicación · {{ $usage['published'] }} de {{ $usage['limit'] }} publicados ({{ $usage['remaining'] }} {{ $usage['remaining'] === 1 ? 'lugar libre' : 'lugares libres' }})</span>
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
            <div class="s-row" style="align-items:baseline">
                <h2 class="s-h2" style="margin:0">Fotos <span class="s-muted" style="font-weight:500">{{ $count }} de {{ Catalog::MAX_PHOTOS }}</span></h2>
                @if($count > 1)<span class="s-muted">La primera es la principal: es la que ven las empresas en el catálogo.</span>@endif
            </div>
            @error('fotos')<p class="s-err" role="alert">{{ $message }}</p>@enderror
            @error('fotos.*')<p class="s-err" role="alert">{{ $message }}</p>@enderror
            @if($count > 0)
                <ol class="sp-grid" aria-label="Fotos del producto, en orden">
                    @foreach($product->photos as $photo)
                        <li class="sp-item" data-photo="{{ $photo->id }}">
                            <div class="sp-frame">
                                <img src="{{ route('marketplace.photo', ['photo' => $photo->id, 'thumb' => 1]) }}" alt="Foto {{ $loop->iteration }} de {{ $product->name }}" loading="lazy">
                                @if($loop->first)<span class="sp-badge">Principal</span>@endif
                                <span class="sp-num" aria-hidden="true">{{ $loop->iteration }}</span>
                            </div>
                            @unless($locked)
                                <div class="sp-tools">
                                    <div class="sp-move">
                                        <form method="POST" action="{{ route('suppliers.products.photos.move', [$product->id, $photo->id]) }}">@csrf<input type="hidden" name="direccion" value="antes"><button class="sp-tool icon" type="submit" @disabled($loop->first) aria-label="Mover la foto {{ $loop->iteration }} antes" title="Mover antes">←</button></form>
                                        <form method="POST" action="{{ route('suppliers.products.photos.move', [$product->id, $photo->id]) }}">@csrf<input type="hidden" name="direccion" value="despues"><button class="sp-tool icon" type="submit" @disabled($loop->last) aria-label="Mover la foto {{ $loop->iteration }} después" title="Mover después">→</button></form>
                                        <form method="POST" action="{{ route('suppliers.products.photos.destroy', [$product->id, $photo->id]) }}" onsubmit="return confirm('¿Quitar esta foto? Se borra del producto.')" style="margin-left:auto">@csrf @method('DELETE')<button class="sp-tool danger" type="submit" aria-label="Quitar la foto {{ $loop->iteration }}">Quitar</button></form>
                                    </div>
                                    @unless($loop->first)
                                        <form method="POST" action="{{ route('suppliers.products.photos.primary', [$product->id, $photo->id]) }}">@csrf<button class="sp-tool wide" type="submit" data-photo-primary>Hacer principal</button></form>
                                    @endunless
                                </div>
                            @endunless
                        </li>
                    @endforeach
                </ol>
            @else
                <p class="s-muted" style="margin:10px 0 0">Todavía no tiene fotos. Los productos con fotos claras (de frente, con la etiqueta o el código visibles) se entienden mejor.</p>
            @endif
            @if(! $locked && $count < Catalog::MAX_PHOTOS)
                <form method="POST" action="{{ route('suppliers.products.photos.store', $product->id) }}" enctype="multipart/form-data" class="s-form sp-upload" data-photo-upload data-free="{{ Catalog::MAX_PHOTOS - $count }}" data-max-kb="{{ Catalog::MAX_PHOTO_KB }}">
                    @csrf
                    <label class="s-label" for="fotos">Agregar fotos <span class="s-muted" style="font-weight:500">(hasta {{ Catalog::MAX_PHOTOS - $count }} más)</span></label>
                    <input id="fotos" type="file" name="fotos[]" accept="image/jpeg,image/png,image/webp" multiple required aria-describedby="fotos-hint fotos-check">
                    <p class="s-hint" id="fotos-hint">JPG, PNG o WebP, hasta {{ Catalog::MAX_PHOTO_KB / 1024 }} MB cada una. Podés elegir varias a la vez. Les quitamos los datos de ubicación.</p>
                    <ul class="sp-preview" data-photo-preview hidden></ul>
                    <p class="s-err" id="fotos-check" data-photo-check role="alert" hidden></p>
                    <button class="s-btn secondary" type="submit" style="margin-top:8px" data-photo-submit>Subir fotos</button>
                </form>
                <script>
                    (() => {
                        const form = document.querySelector('[data-photo-upload]');
                        if (!form) return;
                        const input = form.querySelector('input[type=file]'), list = form.querySelector('[data-photo-preview]'), check = form.querySelector('[data-photo-check]'), submit = form.querySelector('[data-photo-submit]');
                        const free = Number(form.dataset.free), maxKb = Number(form.dataset.maxKb), ok = ['image/jpeg', 'image/png', 'image/webp'];
                        let urls = [];
                        input.addEventListener('change', () => {
                            urls.forEach(URL.revokeObjectURL); urls = []; list.replaceChildren();
                            const files = [...input.files], problems = [];
                            if (files.length > free) problems.push(`Elegiste ${files.length} fotos y podés agregar ${free}.`);
                            files.forEach((f) => {
                                const li = document.createElement('li');
                                const bad = !ok.includes(f.type) ? 'formato no admitido' : (f.size > maxKb * 1024 ? `pesa ${(f.size / 1048576).toFixed(1)} MB` : '');
                                if (bad) { problems.push(`«${f.name}»: ${bad}.`); li.className = 'bad'; li.textContent = f.name; }
                                else { const img = document.createElement('img'); const u = URL.createObjectURL(f); urls.push(u); img.src = u; img.alt = 'Vista previa de ' + f.name; li.append(img); }
                                list.append(li);
                            });
                            list.hidden = files.length === 0;
                            check.textContent = problems.join(' '); check.hidden = problems.length === 0;
                            submit.disabled = problems.length > 0;
                            submit.textContent = files.length > 1 ? `Subir ${files.length} fotos` : 'Subir foto';
                        });
                        form.addEventListener('submit', () => { submit.disabled = true; submit.textContent = 'Subiendo…'; });
                    })();
                </script>
            @elseif(! $locked)
                <p class="s-muted" style="margin-top:12px">Llegaste al máximo de {{ Catalog::MAX_PHOTOS }} fotos. Quitá alguna para agregar otra.</p>
            @endif
        </section>
    @endunless
</x-supplier-layout>

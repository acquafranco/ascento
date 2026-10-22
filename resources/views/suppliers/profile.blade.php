<x-supplier-layout title="Mi perfil">
    <h1 class="s-h1">Mi perfil</h1>
    <p class="s-sub">Es lo que ven las empresas cuando miran tus productos.</p>
    @include('suppliers.partials.status-notice')
    @php $locked = in_array($supplier->status, ['rejected', 'suspended'], true); @endphp

    <form method="POST" action="{{ route('suppliers.profile.update') }}" class="s-card s-form" data-supplier-profile>
        @csrf @method('PUT')
        <fieldset @disabled($locked) style="border:0; padding:0; margin:0">
            <div class="s-cols">
                <div class="s-field"><label class="s-label" for="name">Nombre comercial</label><input id="name" type="text" name="name" value="{{ old('name', $supplier->name) }}" required maxlength="160">@error('name')<p class="s-err">{{ $message }}</p>@enderror</div>
                <div class="s-field"><label class="s-label" for="legal_name">Razón social</label><input id="legal_name" type="text" name="legal_name" value="{{ old('legal_name', $supplier->legal_name) }}" maxlength="190"></div>
            </div>
            <div class="s-field"><label class="s-label" for="description">Descripción</label><textarea id="description" name="description" maxlength="2000">{{ old('description', $supplier->description) }}</textarea>@error('description')<p class="s-err">{{ $message }}</p>@enderror</div>
            <div class="s-cols">
                <div class="s-field"><label class="s-label" for="contact_email">Correo de ventas</label><input id="contact_email" type="email" name="contact_email" value="{{ old('contact_email', $supplier->contact_email) }}" required>@error('contact_email')<p class="s-err">{{ $message }}</p>@enderror</div>
                <div class="s-field"><label class="s-label" for="contact_phone">Teléfono de ventas</label><input id="contact_phone" type="tel" name="contact_phone" value="{{ old('contact_phone', $supplier->contact_phone) }}" required>@error('contact_phone')<p class="s-err">{{ $message }}</p>@enderror</div>
                <div class="s-field"><label class="s-label" for="website">Sitio web</label><input id="website" type="url" name="website" value="{{ old('website', $supplier->website) }}" placeholder="https://">@error('website')<p class="s-err">{{ $message }}</p>@enderror</div>
            </div>
            <div class="s-field"><label class="s-label" for="coverage">Zonas a las que vendés o enviás</label><input id="coverage" type="text" name="coverage" value="{{ old('coverage', $supplier->coverage) }}" required maxlength="255">@error('coverage')<p class="s-err">{{ $message }}</p>@enderror</div>
            <fieldset class="s-field" style="border:0; padding:0">
                <legend class="s-label">Rubros</legend>
                <div class="s-cols">
                    @foreach(\App\Support\Marketplace\Catalog::CATEGORIES as $key => $label)
                        <label style="display:flex; gap:8px; align-items:center; font-size:14px; padding:3px 0"><input type="checkbox" name="categories[]" value="{{ $key }}" @checked(in_array($key, (array) old('categories', $supplier->categories ?? []), true))> {{ $label }}</label>
                    @endforeach
                </div>
                @error('categories')<p class="s-err">{{ $message }}</p>@enderror
            </fieldset>
            <div class="s-field"><label class="s-label" for="brands">Marcas</label><input id="brands" type="text" name="brands" value="{{ old('brands', implode(', ', $supplier->brands ?? [])) }}"><p class="s-hint">Separadas por coma.</p></div>
            <p class="s-muted">{{ \App\Support\Countries::name($supplier->country) }} · {{ \App\Support\Countries::taxIdLabel($supplier->country) }} {{ $supplier->tax_id }} (para cambiarlo, escribinos).</p>
            @unless($locked)<button class="s-btn" type="submit" style="margin-top:8px">Guardar</button>@endunless
        </fieldset>
    </form>
</x-supplier-layout>

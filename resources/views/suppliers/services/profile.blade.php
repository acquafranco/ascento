@php
    use App\Models\Marketplace\ServiceProfile;
    $v = fn (string $field, $default = '') => old($field, $profile?->{$field} ?? $default);
    $list = fn (string $field) => old($field, implode(', ', (array) ($profile?->{$field} ?? [])));
    $country = old('country', $profile?->country ?? auth()->user()->supplier->country);
    $whole = (bool) old('covers_whole_country', $profile?->covers_whole_country ?? false);
    $chosen = (array) old('provinces', $profile?->provinces ?? []);
    $chosenCategories = array_map('intval', (array) old('categories', $selected));
@endphp
<x-supplier-layout title="Perfil profesional">
    <h1 class="s-h1">Perfil profesional</h1>
    <p class="s-sub">Ofrecé tus servicios en «Profesionales y servicios». Es gratis, sin vencimiento y no consume publicaciones de tu catálogo de productos.</p>

    @if($errors->has('perfil'))<div class="s-notice bad" role="alert">{{ $errors->first('perfil') }}</div>@endif
    @if($profile)
        <div class="s-card" style="margin-bottom:14px">
            <div class="s-row">
                <span>Estado: <span class="s-tag {{ $profile->stateTone() }}" data-profile-state>{{ $profile->stateLabel() }}</span></span>
                <span class="s-actions">
                    @if($profile->status !== ServiceProfile::PUBLISHED && $profile->moderation !== ServiceProfile::SUSPENDED)
                        <form method="POST" action="{{ route('suppliers.service-profile.publish') }}">@csrf<button class="s-btn" type="submit" data-profile-publish>Publicar</button></form>
                    @endif
                    @if($profile->status === ServiceProfile::PUBLISHED)
                        <form method="POST" action="{{ route('suppliers.service-profile.archive') }}" onsubmit="return confirm('¿Archivar? Deja de verse en el catálogo; las solicitudes se conservan.')">@csrf<button class="s-btn secondary" type="submit">Archivar</button></form>
                    @endif
                    @if($profile->isVisible())<a class="s-link" href="{{ route('services.show', $profile->slug) }}" target="_blank">Ver perfil público</a>@endif
                </span>
            </div>
            @if($profile->moderation)<p class="s-notice bad" style="margin:10px 0 0">Motivo: {{ $profile->moderation_reason }}</p>@endif
            @if($profile->status === ServiceProfile::PUBLISHED && ! $profile->approved_at)<p class="s-muted" style="margin:8px 0 0">Ascento revisa cada perfil antes de mostrarlo. Te avisamos cuando esté aprobado.</p>@endif
        </div>
    @endif

    <form method="POST" action="{{ route('suppliers.service-profile.update') }}" class="s-card s-form" data-service-profile-form>
        @csrf @method('PUT')
        <p class="s-muted" style="margin-top:0">Se muestra solo información profesional: nunca tu correo ni tu teléfono (las empresas te contactan por solicitud). Lo que declares es tu responsabilidad; Ascento no verifica títulos, matrículas ni certificaciones.</p>
        <div class="s-cols">
            <div class="s-field"><label class="s-label" for="kind">Tipo de perfil</label>
                <select id="kind" name="kind">@foreach(ServiceProfile::KINDS as $k => $l)<option value="{{ $k }}" @selected($v('kind', 'independent') === $k)>{{ $l }}</option>@endforeach</select></div>
            <div class="s-field"><label class="s-label" for="public_name">Nombre público o comercial</label>
                <input id="public_name" type="text" name="public_name" value="{{ $v('public_name', auth()->user()->supplier->name) }}" maxlength="160" required>@error('public_name')<p class="s-err">{{ $message }}</p>@enderror</div>
        </div>
        <div class="s-field"><label class="s-label" for="headline">Frase corta (opcional)</label><input id="headline" type="text" name="headline" value="{{ $v('headline') }}" maxlength="160" placeholder="Reparación de placas y variadores en 48 h"></div>
        <div class="s-field"><label class="s-label" for="description">Descripción</label><textarea id="description" name="description" maxlength="3000" required>{{ $v('description') }}</textarea>@error('description')<p class="s-err">{{ $message }}</p>@enderror</div>
        <div class="s-field"><label class="s-label" for="experience">Experiencia (opcional)</label><textarea id="experience" name="experience" maxlength="2000">{{ $v('experience') }}</textarea></div>
        <fieldset class="s-field" style="border:0; padding:0"><legend class="s-label">Especialidades</legend>
            <div class="s-cols">@foreach($categories as $c)<label style="font-size:14px; display:flex; gap:6px; align-items:center"><input type="checkbox" name="categories[]" value="{{ $c->id }}" @checked(in_array($c->id, $chosenCategories, true))> {{ $c->name }}</label>@endforeach</div>
            @error('categories')<p class="s-err">{{ $message }}</p>@enderror</fieldset>
        <div class="s-field"><label class="s-label" for="services">Servicios que ofrecés (separados por coma, opcional)</label><input id="services" type="text" name="services" value="{{ $list('services') }}" maxlength="1500"></div>
        <div class="s-field"><label class="s-label" for="brands">Marcas y sistemas (separados por coma, opcional)</label><input id="brands" type="text" name="brands" value="{{ $list('brands') }}" maxlength="500"></div>
        <div class="s-cols">
            <div class="s-field"><label class="s-label" for="modality">Modalidad</label>
                <select id="modality" name="modality">@foreach(ServiceProfile::MODALITIES as $k => $l)<option value="{{ $k }}" @selected($v('modality', 'onsite') === $k)>{{ $l }}</option>@endforeach</select></div>
            <div class="s-field"><label class="s-label" for="country">País</label>
                <select id="country" name="country">@foreach(\App\Support\Countries::options() as $k => $l)<option value="{{ $k }}" @selected($country === $k)>{{ $l }}</option>@endforeach</select></div>
        </div>
        <div class="s-field"><input type="hidden" name="covers_whole_country" value="0"><label style="font-size:14px; display:flex; gap:6px; align-items:center"><input type="checkbox" name="covers_whole_country" value="1" @checked($whole)> Trabajo en todo el país</label></div>
        <fieldset class="s-field" style="border:0; padding:0"><legend class="s-label">Provincias (Argentina)</legend>
            <div class="s-cols">@foreach(\App\Support\Provinces::LIST as $p)<label style="font-size:14px; display:flex; gap:6px; align-items:center"><input type="checkbox" name="provinces[]" value="{{ $p }}" @checked(in_array($p, $chosen, true))> {{ $p }}</label>@endforeach</div>
            @error('provinces')<p class="s-err">{{ $message }}</p>@enderror</fieldset>
        <div class="s-cols">
            <div class="s-field"><label class="s-label" for="localities">Localidades o zonas (opcional)</label><input id="localities" type="text" name="localities" value="{{ $v('localities') }}" maxlength="255"></div>
            <div class="s-field"><label class="s-label" for="website">Sitio web (opcional)</label><input id="website" type="url" name="website" value="{{ $v('website') }}" maxlength="255">@error('website')<p class="s-err">{{ $message }}</p>@enderror</div>
        </div>
        <button class="s-btn" type="submit">Guardar perfil</button>
    </form>
</x-supplier-layout>

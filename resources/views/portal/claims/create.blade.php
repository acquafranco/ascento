<x-portal-layout title="Nuevo reclamo">
    @include('portal.claims._styles')
    <p style="margin:0 0 6px"><a href="{{ route('portal.claims.index') }}">← Reclamos</a></p>
    <h1 class="p-h1">Nuevo reclamo</h1>
    <p class="p-sub">Lo recibe {{ $portalCompany?->name }}. Te vamos a mostrar su estado y las respuestas que te compartan.</p>
    @include('portal.claims._safety')

    @if($buildings->isEmpty())
        <div class="p-empty">No tenés edificios habilitados para presentar reclamos. Pedíselo a {{ $portalCompany?->name }}.</div>
    @else
        <form method="POST" action="{{ route('portal.claims.store') }}" enctype="multipart/form-data" class="pc-form" data-claim-form
              x-data="{ building: @js((string) old('building_id', $buildings->count() === 1 ? $buildings->first()->id : '')), elevators: @js($elevators->map(fn ($g) => $g->map(fn ($e) => ['id' => $e->id, 'label' => $e->label])->values())), sending: false }"
              x-on:submit="sending = true">
            @csrf

            <div class="pc-field">
                <label for="claim-building">Edificio</label>
                <select id="claim-building" name="building_id" required x-model="building">
                    <option value="">Elegí el edificio</option>
                    @foreach($buildings as $b)
                        <option value="{{ $b->id }}" @selected((string) old('building_id') === (string) $b->id)>{{ $b->name }}{{ $b->address ? ' · '.$b->address : '' }}</option>
                    @endforeach
                </select>
                @error('building_id')<p class="pc-error">{{ $message }}</p>@enderror
            </div>

            <div class="pc-field">
                <label for="claim-elevator">Ascensor <span class="pc-hint" style="font-weight:400">(si sabés cuál)</span></label>
                <select id="claim-elevator" name="elevator_id">
                    <option value="">No sé / todo el edificio</option>
                    {{-- Sin JavaScript se listan todos; con JavaScript, solo los del edificio elegido. --}}
                    @foreach($elevators as $buildingId => $list)
                        @foreach($list as $e)
                            <option value="{{ $e->id }}" data-building="{{ $buildingId }}" x-bind:hidden="building !== '{{ $buildingId }}'" x-bind:disabled="building !== '{{ $buildingId }}'" @selected((string) old('elevator_id') === (string) $e->id)>{{ $e->label }}@if($buildings->count() > 1) ({{ $buildings->firstWhere('id', $buildingId)?->name }})@endif</option>
                        @endforeach
                    @endforeach
                </select>
                @error('elevator_id')<p class="pc-error">{{ $message }}</p>@enderror
            </div>

            <fieldset class="pc-field">
                <legend>¿Qué pasó?</legend>
                <div class="pc-cats">
                    @foreach(\App\Models\ClientClaim::CATEGORIES as $key => $label)
                        <label class="pc-cat"><input type="radio" name="category" value="{{ $key }}" required @checked(old('category') === $key)> {{ $label }}</label>
                    @endforeach
                </div>
                @error('category')<p class="pc-error">{{ $message }}</p>@enderror
            </fieldset>

            <div class="pc-field">
                <label for="claim-description">Contanos qué pasó</label>
                <textarea id="claim-description" name="description" required minlength="5" maxlength="2000" placeholder="Por ejemplo: desde esta mañana la puerta del 3.º piso no cierra y el ascensor no arranca.">{{ old('description') }}</textarea>
                @error('description')<p class="pc-error">{{ $message }}</p>@enderror
            </div>

            <div class="pc-field">
                <label for="claim-when">¿Cuándo pasó? <span class="pc-hint" style="font-weight:400">(opcional)</span></label>
                <input id="claim-when" type="datetime-local" name="occurred_at" value="{{ old('occurred_at') }}" max="{{ now()->format('Y-m-d\TH:i') }}">
                @error('occurred_at')<p class="pc-error">{{ $message }}</p>@enderror
            </div>

            @if($needsPhone)
                <div class="pc-field">
                    <label for="claim-phone">Teléfono de contacto <span class="pc-hint" style="font-weight:400">(opcional)</span></label>
                    <input id="claim-phone" type="tel" name="contact_phone" value="{{ old('contact_phone') }}" maxlength="40" autocomplete="tel" placeholder="11 2345-6789">
                    <p class="pc-hint">Por si la empresa necesita hablar con vos. Solo lo ve {{ $portalCompany?->name }}.</p>
                    @error('contact_phone')<p class="pc-error">{{ $message }}</p>@enderror
                </div>
            @endif

            <div class="pc-field">
                <label for="claim-photos">Fotos <span class="pc-hint" style="font-weight:400">(opcional, hasta {{ \App\Services\Claims\ClientClaimService::MAX_PHOTOS }})</span></label>
                <input id="claim-photos" type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple>
                <p class="pc-hint">JPG, PNG o WebP de hasta 5 MB cada una.</p>
                @error('photos')<p class="pc-error">{{ $message }}</p>@enderror
                @error('photos.*')<p class="pc-error">{{ $message }}</p>@enderror
            </div>

            <div class="pc-actions">
                <button type="submit" class="p-btn" x-bind:disabled="sending" data-claim-submit><span x-show="! sending">Enviar reclamo</span><span x-show="sending" x-cloak>Enviando…</span></button>
                <a href="{{ route('portal.claims.index') }}">Cancelar</a>
            </div>
        </form>
    @endif
</x-portal-layout>

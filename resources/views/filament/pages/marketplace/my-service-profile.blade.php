@php
    use App\Filament\Pages\Marketplace\MyServiceProfile;
@endphp
<x-filament-panels::page>
    @include('filament.pages.marketplace.styles')
    <div class="mk-stack">
        @error('perfil')<div class="mk-note" role="alert">{{ $message }}</div>@enderror

        @if($mode === 'detail')
            <a class="mk-link" href="{{ MyServiceProfile::getUrl() }}">← Mi perfil y consultas</a>
            <section class="mk-card">
                <div style="display:flex; justify-content:space-between; gap:.6rem; flex-wrap:wrap">
                    <div><p class="mk-h2" style="margin:0">{{ $row->subject }}</p><p class="mk-muted" style="margin:0">{{ $row->reference }} · de {{ $row->buyerLabel() }} · {{ $row->created_at->format('d/m/Y H:i') }}</p></div>
                    <span class="mk-pill {{ $row->statusTone() }}">{{ $row->statusLabel() }}</span>
                </div>
                <p style="white-space:pre-line; font-size:.9rem; margin:.7rem 0 0">{{ $row->need }}</p>
                <dl class="mk-dl" style="margin-top:.7rem">
                    <dt>Contacto</dt><dd>{{ $row->contact_name }} · {{ $row->contact_email }}@if($row->contact_phone) · {{ $row->contact_phone }}@endif</dd>
                    @if($row->modality)<dt>Modalidad</dt><dd>{{ \App\Models\Marketplace\ServiceProfile::MODALITIES[$row->modality] ?? $row->modality }}</dd>@endif
                    @if($row->location)<dt>Lugar</dt><dd>{{ $row->location }}</dd>@endif
                </dl>
            </section>
            <section class="mk-card">
                <p class="mk-h2">Conversación</p>
                @forelse($row->messages as $m)
                    <div style="border-top:1px solid rgba(0,0,0,.06); padding:.5rem 0"><strong style="font-size:.85rem">{{ $m->side === 'professional' ? 'Tu respuesta' : $row->buyerLabel() }}</strong> <span class="mk-muted">· {{ $m->created_at->format('d/m/Y H:i') }}</span><br><span style="white-space:pre-line; font-size:.875rem">{{ $m->body }}</span></div>
                @empty
                    <p class="mk-muted">Todavía no respondiste.</p>
                @endforelse
                @if($row->isOpen())
                    <label for="mp-msg" style="font-size:.8rem; font-weight:600; display:block; margin-top:.6rem">Respuesta</label>
                    <textarea id="mp-msg" class="mk-input" rows="3" wire:model="replyText" maxlength="3000"></textarea>
                    @error('mensaje')<p class="mk-err">{{ $message }}</p>@enderror
                    <div class="mk-actions">
                        <button type="button" class="mk-btn" wire:click="reply" data-mp-reply>Responder</button>
                        <button type="button" class="mk-btn secondary" wire:click="close" wire:confirm="¿Cerrar la solicitud? Queda en el historial." data-mp-close>Cerrar solicitud</button>
                    </div>
                @endif
            </section>
        @else
            <section class="mk-card">
                <div style="display:flex; justify-content:space-between; gap:.6rem; flex-wrap:wrap; align-items:center">
                    <p class="mk-h2" style="margin:0">Perfil</p>
                    @if($profile)<span class="mk-pill {{ $profile->stateTone() }}" data-mp-state>{{ $profile->stateLabel() }}</span>@endif
                </div>
                @if($profile?->moderation)
                    <p class="mk-note" style="margin-top:.5rem">{{ $profile->stateLabel() }}. Motivo: {{ $profile->moderation_reason }}</p>
                @endif
                <p class="mk-muted" style="margin:.3rem 0 .8rem">Gratis y sin vencimiento. Se muestra solo información profesional: nunca correos ni teléfonos (te contactan por solicitud). Lo que declares es tu responsabilidad; Ascento no verifica títulos ni matrículas.</p>
                <div class="mk-filters">
                    <div><label for="mp-kind">Tipo</label><select id="mp-kind" class="mk-input" wire:model="data.kind">@foreach($kinds as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                    <div style="grid-column: span 2"><label for="mp-name">Nombre público</label><input id="mp-name" class="mk-input" wire:model="data.public_name" maxlength="160">@error('data.public_name')<p class="mk-err">{{ $message }}</p>@enderror</div>
                    <div style="grid-column:1 / -1"><label for="mp-head">Frase corta (opcional)</label><input id="mp-head" class="mk-input" wire:model="data.headline" maxlength="160" placeholder="Reparación de placas y variadores en 48 h"></div>
                    <div style="grid-column:1 / -1"><label for="mp-desc">Descripción</label><textarea id="mp-desc" class="mk-input" rows="4" wire:model="data.description" maxlength="3000"></textarea>@error('data.description')<p class="mk-err">{{ $message }}</p>@enderror</div>
                    <div style="grid-column:1 / -1"><label for="mp-exp">Experiencia (opcional)</label><textarea id="mp-exp" class="mk-input" rows="2" wire:model="data.experience" maxlength="2000"></textarea></div>
                    <fieldset style="grid-column:1 / -1; border:0; padding:0; margin:0"><legend style="font-size:.75rem; font-weight:600">Especialidades</legend>
                        <div style="display:grid; gap:.3rem; grid-template-columns:repeat(auto-fill, minmax(min(100%, 230px), 1fr)); margin-top:.3rem">
                            @foreach($categories as $c)<label style="display:flex; gap:.4rem; align-items:center; font-size:.85rem"><input type="checkbox" value="{{ $c->id }}" wire:model="data.categories"> {{ $c->name }}</label>@endforeach
                        </div>@error('data.categories')<p class="mk-err">{{ $message }}</p>@enderror</fieldset>
                    <div style="grid-column:1 / -1"><label for="mp-serv">Servicios (separados por coma, opcional)</label><input id="mp-serv" class="mk-input" wire:model="data.services" maxlength="1500"></div>
                    <div style="grid-column:1 / -1"><label for="mp-brands">Marcas y sistemas (separados por coma, opcional)</label><input id="mp-brands" class="mk-input" wire:model="data.brands" maxlength="500"></div>
                    <div><label for="mp-mod">Modalidad</label><select id="mp-mod" class="mk-input" wire:model="data.modality">@foreach($modalities as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                    <div><label for="mp-country">País</label><select id="mp-country" class="mk-input" wire:model.live="data.country">@foreach($countries as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                    <div><label style="display:flex; gap:.4rem; align-items:center; margin-top:1.4rem"><input type="checkbox" wire:model.live="data.covers_whole_country"> Trabajo en todo el país</label></div>
                    @if(($data['country'] ?? 'AR') === 'AR' && ! ($data['covers_whole_country'] ?? false))
                        <fieldset style="grid-column:1 / -1; border:0; padding:0; margin:0"><legend style="font-size:.75rem; font-weight:600">Provincias</legend>
                            <div style="display:grid; gap:.3rem; grid-template-columns:repeat(auto-fill, minmax(min(100%, 200px), 1fr)); margin-top:.3rem">
                                @foreach($provinces as $p)<label style="display:flex; gap:.4rem; align-items:center; font-size:.85rem"><input type="checkbox" value="{{ $p }}" wire:model="data.provinces"> {{ $p }}</label>@endforeach
                            </div>@error('data.provinces')<p class="mk-err">{{ $message }}</p>@enderror</fieldset>
                    @endif
                    <div style="grid-column: span 2"><label for="mp-loc">Localidades o zonas (opcional)</label><input id="mp-loc" class="mk-input" wire:model="data.localities" maxlength="255"></div>
                    <div><label for="mp-web">Sitio web (opcional)</label><input id="mp-web" type="url" class="mk-input" wire:model="data.website" maxlength="255">@error('data.website')<p class="mk-err">{{ $message }}</p>@enderror</div>
                </div>
                <div class="mk-actions">
                    <button type="button" class="mk-btn" wire:click="save" data-mp-save>Guardar</button>
                    @if($profile && $profile->status !== 'published' && $profile->moderation !== 'suspended')<button type="button" class="mk-btn dark" wire:click="publish" data-mp-publish>Publicar</button>@endif
                    @if($profile && $profile->status === 'published')<button type="button" class="mk-btn secondary" wire:click="archive" wire:confirm="¿Archivar? Deja de verse en el catálogo; las solicitudes se conservan." data-mp-archive>Archivar</button>@endif
                    @if($profile && $profile->isVisible())<a class="mk-link" href="{{ route('services.show', $profile->slug) }}" target="_blank" style="align-self:center">Ver perfil público</a>@endif
                </div>
            </section>

            <section class="mk-stack">
                <div style="display:flex; justify-content:space-between; gap:.6rem; flex-wrap:wrap; align-items:center">
                    <h2 class="mk-h2" style="margin:0">Consultas recibidas</h2>
                    <div class="mk-actions" style="padding:0; margin:0">
                        <button type="button" class="mk-btn {{ $tab === 'abiertas' ? 'dark' : 'secondary' }}" wire:click="$set('estado', 'abiertas')">En curso</button>
                        <button type="button" class="mk-btn {{ $tab === 'cerradas' ? 'dark' : 'secondary' }}" wire:click="$set('estado', 'cerradas')">Cerradas</button>
                    </div>
                </div>
                @if($rows->isEmpty())
                    <div class="mk-card mk-muted">{{ $profile ? 'No hay consultas acá.' : 'Cuando publiques tu perfil, las consultas de otras empresas aparecen acá.' }}</div>
                @else
                    <div class="mk-card mk-scroll"><table class="mk-table">
                        <thead><tr><th>Solicitud</th><th>Empresa</th><th>Título</th><th>Último movimiento</th><th>Estado</th></tr></thead>
                        <tbody>
                            @foreach($rows as $r)
                                <tr data-mp-row="{{ $r->id }}">
                                    <td><a class="mk-link" href="{{ MyServiceProfile::getUrl(['solicitud' => $r->id]) }}">{{ $r->reference }}</a></td>
                                    <td>{{ $r->buyerLabel() }}</td>
                                    <td>{{ \Illuminate\Support\Str::limit($r->subject, 60) }}</td>
                                    <td>{{ $r->last_message_at?->format('d/m/Y H:i') }}</td>
                                    <td><span class="mk-pill {{ $r->statusTone() }}">{{ $r->statusLabel() }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table></div>
                @endif
            </section>
        @endif
    </div>
</x-filament-panels::page>

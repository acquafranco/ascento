@php
    use App\Filament\Pages\Marketplace\ServiceRequests;
@endphp
<x-filament-panels::page>
    @include('filament.pages.marketplace.styles')
    <div class="mk-stack">
        @error('solicitud')<div class="mk-note" role="alert">{{ $message }}</div>@enderror

        @if($mode === 'compose')
            <a class="mk-link" href="{{ ServiceRequests::getUrl() }}">← Mis solicitudes</a>
            <div class="mk-two">
                <section class="mk-card">
                    <p class="mk-h2" style="margin-bottom:.2rem">{{ $profile->public_name }}</p>
                    <p class="mk-muted" style="margin:0 0 .5rem">{{ $profile->kindLabel() }} · {{ $profile->modalityLabel() }} · {{ $profile->coverageLabel() }}</p>
                    <div>@foreach($profile->categories as $c)<span class="mk-pill" style="margin:0 .25rem .25rem 0">{{ $c->name }}</span>@endforeach</div>
                    <p class="mk-muted" style="margin:.5rem 0 0">Información declarada por el titular: Ascento no la verificó. <a class="mk-link" href="{{ route('services.show', $profile->slug) }}" target="_blank">Ver perfil completo</a></p>
                </section>
                <section class="mk-card">
                    @if($own)
                        <p class="mk-muted">Este es el perfil de tu propia empresa.</p>
                    @elseif($open)
                        <p class="mk-muted">Ya tenés una solicitud abierta con este profesional. <a class="mk-link" href="{{ ServiceRequests::getUrl(['solicitud' => $open->id]) }}">Seguir la conversación</a></p>
                    @elseif(! $canRequest)
                        <p class="mk-muted">Para enviar solicitudes necesitás permiso para gestionar «Stock» (repuestos y servicios).</p>
                    @else
                        <p class="mk-h2">Contale qué necesitás</p>
                        <p class="mk-muted" style="margin:-.3rem 0 .6rem">El profesional ve el nombre de tu empresa y lo que completes acá. Nada de tus clientes, edificios ni técnicos.</p>
                        <div class="mk-filters">
                            <div style="grid-column:1 / -1"><label for="sv-subject">Título</label><input id="sv-subject" class="mk-input" wire:model="form.subject" maxlength="160" placeholder="Placa de maniobra que no arranca">@error('form.subject')<p class="mk-err">{{ $message }}</p>@enderror</div>
                            <div style="grid-column:1 / -1"><label for="sv-need">Qué necesitás</label><textarea id="sv-need" class="mk-input" rows="4" wire:model="form.need" maxlength="3000" placeholder="Equipo, marca y modelo, falla, plazo, si puede ser remoto…"></textarea>@error('form.need')<p class="mk-err">{{ $message }}</p>@enderror</div>
                            <div><label for="sv-mod">Modalidad</label><select id="sv-mod" class="mk-input" wire:model="form.modality"><option value="">Indistinta</option><option value="onsite">Presencial</option><option value="remote">Remota</option></select></div>
                            <div><label for="sv-loc">Lugar</label><input id="sv-loc" class="mk-input" wire:model="form.location" maxlength="160"></div>
                            <div><label for="sv-cn">Tu nombre</label><input id="sv-cn" class="mk-input" wire:model="form.contact_name" maxlength="120">@error('form.contact_name')<p class="mk-err">{{ $message }}</p>@enderror</div>
                            <div><label for="sv-ce">Correo para la respuesta</label><input id="sv-ce" type="email" class="mk-input" wire:model="form.contact_email">@error('form.contact_email')<p class="mk-err">{{ $message }}</p>@enderror</div>
                            <div><label for="sv-cp">Teléfono (opcional)</label><input id="sv-cp" type="tel" class="mk-input" wire:model="form.contact_phone">@error('form.contact_phone')<p class="mk-err">{{ $message }}</p>@enderror</div>
                        </div>
                        <div class="mk-actions"><button type="button" class="mk-btn" wire:click="send" wire:loading.attr="disabled" wire:target="send" data-sv-send>Enviar solicitud</button></div>
                    @endif
                </section>
            </div>
            @include('filament.pages.marketplace.service-report')

        @elseif($mode === 'detail')
            <a class="mk-link" href="{{ ServiceRequests::getUrl() }}">← Mis solicitudes</a>
            <section class="mk-card">
                <div style="display:flex; justify-content:space-between; gap:.6rem; flex-wrap:wrap">
                    <div><p class="mk-h2" style="margin:0">{{ $row->subject }}</p><p class="mk-muted" style="margin:0">{{ $row->reference }} · para {{ $row->profile_name }} · {{ $row->created_at->format('d/m/Y H:i') }}</p></div>
                    <span class="mk-pill {{ $row->statusTone() }}">{{ $row->statusLabel() }}</span>
                </div>
                <p style="white-space:pre-line; font-size:.9rem; margin:.7rem 0 0">{{ $row->need }}</p>
                <dl class="mk-dl" style="margin-top:.7rem">
                    <dt>Compartiste</dt><dd>{{ $row->contact_name }} · {{ $row->contact_email }}@if($row->contact_phone) · {{ $row->contact_phone }}@endif</dd>
                    @if($row->location)<dt>Lugar</dt><dd>{{ $row->location }}</dd>@endif
                </dl>
            </section>
            <section class="mk-card">
                <p class="mk-h2">Conversación</p>
                @forelse($row->messages as $m)
                    <div style="border-top:1px solid rgba(0,0,0,.06); padding:.5rem 0" data-sv-message="{{ $m->side }}"><strong style="font-size:.85rem">{{ $m->side === 'company' ? 'Tu empresa' : $row->profile_name }}</strong> <span class="mk-muted">· {{ $m->created_at->format('d/m/Y H:i') }}</span><br><span style="white-space:pre-line; font-size:.875rem">{{ $m->body }}</span></div>
                @empty
                    <p class="mk-muted">Todavía no hay respuestas. Te avisamos cuando el profesional responda.</p>
                @endforelse
                @if($row->isOpen())
                    <label for="sv-msg" style="font-size:.8rem; font-weight:600; display:block; margin-top:.6rem">Mensaje</label>
                    <textarea id="sv-msg" class="mk-input" rows="3" wire:model="replyText" maxlength="3000"></textarea>
                    @error('mensaje')<p class="mk-err">{{ $message }}</p>@enderror
                    <div class="mk-actions">
                        <button type="button" class="mk-btn" wire:click="reply" data-sv-reply>Enviar mensaje</button>
                        <button type="button" class="mk-btn secondary" wire:click="close" wire:confirm="¿Cerrar la solicitud? Queda en tu historial." data-sv-close>Cerrar solicitud</button>
                    </div>
                @else
                    <p class="mk-muted">Solicitud cerrada el {{ $row->closed_at?->format('d/m/Y') }}.</p>
                @endif
            </section>
            @include('filament.pages.marketplace.service-report')

        @else
            <section class="mk-card">
                <p class="mk-h2">Buscá un especialista</p>
                <p class="mk-muted" style="margin:-.3rem 0 .6rem">El catálogo de profesionales es público. Abrí un perfil y tocá «Enviar solicitud».</p>
                <a class="mk-btn" href="{{ route('services.index') }}" target="_blank" data-sv-directory>Ver profesionales y servicios</a>
            </section>
            <section class="mk-stack">
                <div style="display:flex; justify-content:space-between; gap:.6rem; flex-wrap:wrap; align-items:center">
                    <h2 class="mk-h2" style="margin:0">Mis solicitudes</h2>
                    <div class="mk-actions" style="padding:0; margin:0">
                        <button type="button" class="mk-btn {{ $tab === 'abiertas' ? 'dark' : 'secondary' }}" wire:click="$set('estado', 'abiertas')" aria-pressed="{{ $tab === 'abiertas' ? 'true' : 'false' }}">En curso</button>
                        <button type="button" class="mk-btn {{ $tab === 'cerradas' ? 'dark' : 'secondary' }}" wire:click="$set('estado', 'cerradas')" aria-pressed="{{ $tab === 'cerradas' ? 'true' : 'false' }}">Cerradas</button>
                    </div>
                </div>
                @if($rows->isEmpty())
                    <div class="mk-card mk-muted">{{ $tab === 'abiertas' ? 'No tenés solicitudes en curso.' : 'Todavía no hay solicitudes cerradas.' }}</div>
                @else
                    <div class="mk-card mk-scroll"><table class="mk-table">
                        <thead><tr><th>Solicitud</th><th>Profesional</th><th>Título</th><th>Último movimiento</th><th>Estado</th></tr></thead>
                        <tbody>
                            @foreach($rows as $r)
                                <tr data-sv-row="{{ $r->id }}">
                                    <td><a class="mk-link" href="{{ ServiceRequests::getUrl(['solicitud' => $r->id]) }}">{{ $r->reference }}</a></td>
                                    <td>{{ $r->profile_name }}</td>
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

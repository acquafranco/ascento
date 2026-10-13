<x-filament-panels::page>
    @include('filament.partials.insights-styles')
    @include('public-qr.partials.styles')

    @if(isset($platform))
        <div class="asc-card" data-qr-platform>
            <p class="asc-c">QR de todas las empresas. «Abrir» entra a la empresa (como «Entrar») y muestra el QR con su vista previa.</p>
            @forelse($platform as $p)
                <div class="asc-row" style="align-items:center">
                    <span>
                        <strong>{{ $p->building_name }}</strong>
                        <br><span class="asc-muted asc-small">{{ $p->company_name }}</span>
                    </span>
                    <span style="display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; justify-content:flex-end">
                        <span class="asc-pill {{ $p->is_active && ! $p->building_deleted_at ? 'is-done' : '' }}">{{ $p->building_deleted_at ? 'Edificio desactivado' : ($p->is_active ? 'Activo' : 'Desactivado') }}</span>
                        @if($p->is_active && ! $p->building_deleted_at)
                            <a href="{{ $p->publicUrl() }}" target="_blank" rel="noopener" class="asc-link" style="color:#d97706">Ver público</a>
                        @endif
                        <button type="button" wire:click="openForSuperAdmin({{ $p->id }})" class="asc-link" style="color:#d97706">Abrir →</button>
                    </span>
                </div>
            @empty
                <p class="asc-muted asc-small">Ninguna empresa generó un QR todavía.</p>
            @endforelse
        </div>
    @elseif(! $building)
        <div class="asc-card" data-qr-list>
            @forelse($buildings as $b)
                @php $p = $pages->get($b->id); @endphp
                <div class="asc-row" style="align-items:center">
                    <span>
                        <strong>{{ $b->name }}</strong>
                        <br><span class="asc-muted asc-small">{{ trim(collect([$b->address, $b->locality])->filter()->implode(', ')) ?: 'Sin dirección' }}</span>
                    </span>
                    <span style="display:flex; gap:.5rem; align-items:center">
                        <span class="asc-pill {{ $p?->is_active ? 'is-done' : '' }}">{{ $p?->is_active ? 'Activo' : ($p ? 'Desactivado' : 'Sin QR') }}</span>
                        <a href="{{ \App\Filament\Pages\PublicQrPage::getUrl(['edificio' => $b->id]) }}" class="asc-link" style="color:#d97706">Abrir →</a>
                    </span>
                </div>
            @empty
                <p class="asc-muted asc-small">Todavía no hay edificios.</p>
            @endforelse
        </div>
    @else
        <div class="asc-grid-2" style="align-items:start">
            <div class="asc-stack">
                <section class="asc-card" data-qr-state>
                    <p class="asc-q" style="font-weight:600">Código</p>
                    @if($qr)
                        <div style="max-width:220px; margin:.5rem 0; background:#fff; padding:.5rem; border-radius:.5rem" data-qr-image>{!! $qr !!}</div>
                        <p class="asc-small" style="word-break:break-all"><a href="{{ $page->publicUrl() }}" target="_blank" rel="noopener" class="asc-link" style="color:#d97706">{{ $page->publicUrl() }}</a></p>
                        <p class="asc-small" style="display:flex; gap:.75rem; flex-wrap:wrap">
                            <a href="{{ route('public-qr.download', ['building' => $building->id, 'format' => 'svg']) }}" class="asc-link" style="color:#d97706">Descargar SVG (para imprimir)</a>
                            <a href="{{ route('public-qr.download', ['building' => $building->id, 'format' => 'png']) }}" class="asc-link" style="color:#d97706">Descargar PNG</a>
                        </p>
                    @else
                        <p class="asc-c">{{ $page ? 'Desactivado: el código impreso no muestra información.' : 'Todavía no se generó un QR para este edificio.' }}</p>
                    @endif
                    <div style="display:flex; gap:.5rem; flex-wrap:wrap; margin-top:.75rem">
                        {{-- Solo con «gestionar» (el servidor lo vuelve a verificar al ejecutar). --}}
                        @if(! $canManage)
                            <p class="asc-small asc-muted">Podés ver y descargar el QR. Para generarlo o cambiarlo hace falta «gestionar» en «QR y datos públicos».</p>
                        @elseif($page?->is_active)
                            {{ $this->regenerateAction }}
                            {{ $this->deactivateAction }}
                        @else
                            {{ $this->generateAction }}
                        @endif
                    </div>
                </section>

                <section class="asc-card" data-qr-sections>
                    <p class="asc-q" style="font-weight:600">Qué se publica</p>
                    <p class="asc-c">Siempre se muestran el nombre de la empresa y el del edificio con su dirección. Nunca datos del cliente, técnicos, residentes, firmas, fotos, documentos, notas ni montos.</p>
                    @foreach(\App\Models\BuildingPublicPage::SECTIONS as $key => $label)
                        <label class="asc-row" style="cursor:pointer">
                            <span>{{ $label }}
                                @if($key === 'show_contact' && ! ($info['company']['has_contact'] ?? true))
                                    <br><span class="asc-small asc-muted">La empresa no tiene teléfono ni correo cargados en «Mi empresa».</span>
                                @endif
                            </span>
                            <input type="checkbox" wire:model.live="sections.{{ $key }}" @disabled(! $canManage) data-section="{{ $key }}">
                        </label>
                    @endforeach

                    @if($elevators->isNotEmpty())
                        <p class="asc-q" style="font-weight:600; margin-top:1rem">Estado informado de cada ascensor</p>
                        <p class="asc-c">Lo decide la empresa y se muestra como información administrativa, con la fecha en que se cambió.</p>
                        @foreach($elevators as $e)
                            <label class="asc-row" style="align-items:center">
                                <span>{{ $e->label }}</span>
                                <select wire:model="statuses.{{ $e->id }}" @disabled(! $canManage) class="fi-select-input" style="border-radius:.5rem; padding:.25rem 2rem .25rem .5rem; font-size:.875rem; max-width:60%">
                                    <option value="">No informar</option>
                                    @foreach(\App\Models\Elevator::PUBLIC_STATUSES as $value => $text)
                                        <option value="{{ $value }}">{{ $text }}</option>
                                    @endforeach
                                </select>
                            </label>
                        @endforeach
                    @endif

                    @if($canManage)
                        <div style="margin-top:.75rem">{{ $this->saveSettingsAction }}</div>
                    @endif
                    @if(! $page)
                        <p class="asc-small asc-muted" style="margin-top:.5rem">Lo que elijas se aplica al generar el QR.</p>
                    @endif
                </section>
            </div>

            <section data-qr-preview>
                <p class="asc-q" style="font-weight:600; margin-bottom:.5rem">Vista previa <span class="asc-muted" style="font-weight:400">· lo que ve quien escanea</span></p>
                <div style="background:#f8fafc; border-radius:1rem; border:1px dashed #cbd5e1">
                    @include('public-qr.partials.content', ['info' => $info])
                </div>
            </section>
        </div>
    @endif
</x-filament-panels::page>

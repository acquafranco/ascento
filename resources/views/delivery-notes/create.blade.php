{{--
    Remito digital: lo completa el técnico al terminar una visita (mantenimiento
    o inspección del mes) o una orden de trabajo, con su firma y, si se puede,
    la del cliente. Mismos campos y reglas que antes; el servidor valida todo.
--}}
@php
    $typeLabel = $workOrder
        ? 'Orden de trabajo · '.\App\Support\WorkOrderLabels::type($workOrder->type)
        : ($assignmentType === 'inspection' ? 'Inspección del mes' : 'Mantenimiento del mes');
    $participants = $workOrder
        ? $workOrder->users->unique('id')->values()
        : $building->users()->wherePivot('type', $assignmentType)->orderBy('name')->get()->unique('id')->values();
    if ($participants->isEmpty()) {
        $participants = collect([auth()->user()]);
    }
@endphp
<x-app-layout>
<div class="t-page max-w-2xl lg:max-w-2xl">
    <a href="{{ $workOrder ? route('work-orders.show', ['company' => auth()->user()->company->slug, 'workOrder' => $workOrder]) : route('buildings.index', ['company' => auth()->user()->company->slug]) }}"
       class="inline-flex min-h-[40px] items-center gap-1 text-sm font-semibold text-ink-500 hover:text-ink">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m15 18-6-6 6-6"/></svg>
        {{ $workOrder ? 'Orden de trabajo' : 'Visitas' }}
    </a>
    <header class="mb-4 mt-1 flex items-start justify-between gap-3">
        <div>
            <h1 class="t-h1">Nuevo remito</h1>
            <span class="t-chip-progress mt-1.5">{{ $typeLabel }}</span>
        </div>
        @if(auth()->user()->company?->logo)
            <img src="{{ asset('storage/'.auth()->user()->company->logo) }}" alt="{{ auth()->user()->company->name }}" class="h-12 w-12 shrink-0 rounded-xl bg-white object-contain p-1 ring-1 ring-ink-100">
        @endif
    </header>

    @if ($errors->any())
        <div id="remito-errors" class="t-alert-error mb-4" role="alert">
            <p class="mb-1">No se pudo generar el remito:</p>
            <ul class="list-inside list-disc space-y-0.5 font-normal">
                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('delivery-notes.store', ['company' => auth()->user()->company->slug]) }}" id="remito-form" class="space-y-4"
          data-draft="remito.{{ auth()->id() }}.{{ $building->id }}.{{ $workOrder ? 'ot'.$workOrder->id : $assignmentType }}" @if($errors->any()) data-draft-restore="off" @endif>
        @csrf
        @include('partials.draft-notice', ['media' => 'Las firmas, los materiales y las fotos se vuelven a cargar.'])
        @if($workOrder)
            <input type="hidden" name="work_order_id" value="{{ $workOrder->id }}">
        @endif
        <input type="hidden" name="building_id" value="{{ $building->id }}">
        @isset($submissionToken)
            {{-- Identidad de este formulario: si se reenvía, no se registra otra visita. --}}
            <input type="hidden" name="submission_token" value="{{ $submissionToken }}">
        @endisset
        <input type="hidden" name="year" value="{{ $year }}">
        <input type="hidden" name="elevator_quantity" value="{{ $building->elevator_count }}">
        <input type="hidden" name="freight_elevator_quantity" value="{{ $building->freight_elevator_count }}">
        <input type="hidden" name="signature" id="sig-tech-input">
        <input type="hidden" name="client_signature" id="sig-client-input">
        <input type="hidden" name="assignment_type" value="{{ $workOrder ? 'work_order' : $assignmentType }}">

        {{-- 1. Dónde y cuándo --}}
        <section class="t-card divide-y divide-ink-100">
            <div class="px-4 py-3">
                <div class="text-xs font-bold uppercase tracking-wider text-ink-500">Edificio</div>
                <div class="font-semibold text-ink">{{ $building->name }}</div>
                <div class="text-sm text-ink-700">{{ $building->address }}{{ $building->client?->name ? ' · '.$building->client->name : '' }}</div>
            </div>
            <div class="grid grid-cols-2 divide-x divide-ink-100">
                <div class="px-4 py-3">
                    <div class="text-xs font-bold uppercase tracking-wider text-ink-500">Equipos</div>
                    <div class="font-semibold text-ink">
                        @if($workOrder)
                            {{ $workOrder->unit ?: 'Según la orden' }}
                        @else
                            {{ $building->elevator_count }} asc. · {{ $building->freight_elevator_count }} mont.
                        @endif
                    </div>
                </div>
                <div class="px-4 py-3">
                    <div class="text-xs font-bold uppercase tracking-wider text-ink-500">Fecha</div>
                    <div class="font-semibold text-ink">{{ now()->format('d/m/Y') }}</div>
                </div>
            </div>
            <div class="px-4 py-3">
                <label for="month" class="t-label">Corresponde al mes</label>
                <select id="month" name="month" class="t-input" data-draft-field>
                    @foreach(range(1, 12) as $m)
                        <option value="{{ $m }}" @selected(old('month', $month) == $m)>{{ ucfirst(\Carbon\Carbon::create()->month($m)->locale('es')->monthName) }} {{ $year }}</option>
                    @endforeach
                </select>
                @isset($visitInfo)
                    @if($visitInfo['blocked'])
                        <p class="mt-2 text-sm font-semibold text-red-700" data-visit-blocked>{{ $visitInfo['blocked'] }} Elegí otro mes o revisá la pantalla de Visitas.</p>
                    @elseif($visitInfo['expected'] > 1 || $visitInfo['period'])
                        <p class="mt-2 text-xs text-ink-500" data-visit-info>
                            Frecuencia: {{ $visitInfo['frequency'] }}.
                            @if($visitInfo['expected'] > 1) Vas a registrar la visita {{ $visitInfo['slot'] }} de {{ $visitInfo['expected'] }} del mes. @endif
                            @if($visitInfo['period']) Cuenta para el período {{ $visitInfo['period'] }}. @endif
                        </p>
                    @endif
                @endisset
            </div>
        </section>

        {{-- 2. Trabajo --}}
        <section class="t-card space-y-4 p-4">
            <div>
                <label class="t-label" for="description">Trabajo realizado</label>
                <textarea name="description" id="description" data-draft-field rows="6" required maxlength="5000" class="t-input resize-y"
                          placeholder="Detallá todo el trabajo realizado durante la visita">{{ old('description') }}</textarea>
                @error('description')<p class="mt-1 text-sm font-semibold text-red-600">{{ $message }}</p>@enderror
            </div>

            <label class="flex min-h-[52px] cursor-pointer select-none items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4">
                <input type="checkbox" name="performed" value="1" data-draft-field @checked($errors->any() ? old('performed') == '1' : true) class="h-5 w-5 shrink-0 rounded border-ink-300 text-emerald-600 focus:ring-emerald-500">
                <span class="text-sm font-semibold text-emerald-900">Trabajo realizado correctamente</span>
            </label>
            <p class="-mt-2 text-xs text-ink-500">Si no se pudo hacer, destildalo: la oficina lo ve y coordina la nueva visita.</p>

            <fieldset>
                <legend class="t-label">Participantes</legend>
                <div class="space-y-2">
                    @foreach($participants as $participant)
                        <label class="flex min-h-[52px] items-center justify-between gap-3 rounded-xl border border-ink-100 bg-ink-50/60 px-4">
                            <span>
                                <span class="block font-semibold text-ink">{{ $participant->name }}</span>
                                <span class="block text-xs text-ink-500">{{ $participant->id == auth()->id() ? 'Vos' : 'Participó del trabajo' }}</span>
                            </span>
                            <input type="checkbox" name="participants[]" value="{{ $participant->id }}"
                                   @checked($participant->id == auth()->id() || in_array($participant->id, old('participants', $participants->pluck('id')->toArray())))
                                   @disabled($participant->id == auth()->id())
                                   class="h-5 w-5 rounded border-ink-300 text-brand focus:ring-brand">
                            @if($participant->id == auth()->id())
                                <input type="hidden" name="participants[]" value="{{ $participant->id }}">
                            @endif
                        </label>
                    @endforeach
                </div>
            </fieldset>
        </section>

        {{-- Materiales usados (solo órdenes de trabajo con stock cargado). --}}
        @if($workOrder && isset($stockItems) && $stockItems->isNotEmpty())
            <section id="materialsSection" class="t-card p-4">
                <h2 class="font-bold text-ink">Materiales utilizados</h2>
                <p class="mb-3 text-xs text-ink-500">Opcional. Agregá los repuestos que usaste: se descuentan del stock al guardar el remito.</p>

                @if($workOrder->materials->isNotEmpty())
                    <div class="mb-3 rounded-xl bg-ink-50 p-3 text-sm">
                        <div class="mb-1 text-xs font-bold uppercase text-ink-500">Ya cargados por la oficina</div>
                        @foreach($workOrder->materials as $material)
                            <div class="flex justify-between"><span>{{ $material->stockItem?->name }}</span><span class="font-semibold">{{ $material->stockItem?->formatQuantity($material->quantity) }}</span></div>
                        @endforeach
                    </div>
                @endif

                <div id="materialRows" class="space-y-3"></div>
                <button type="button" id="addMaterial" class="t-btn-secondary mt-3 w-full border-dashed">+ Agregar material</button>

                <template id="materialTemplate">
                    <div class="material-row space-y-2 rounded-xl border border-ink-100 p-3">
                        <select class="material-item t-input" required>
                            <option value="">Elegí el material…</option>
                            @foreach($stockItems as $item)
                                <option value="{{ $item->id }}" data-unit="{{ $item->unit }}" data-stock="{{ (float) $item->current_stock }}">
                                    {{ $item->name }}@if($item->code) ({{ $item->code }})@endif
                                </option>
                            @endforeach
                        </select>
                        <div class="flex items-center gap-2">
                            <input type="number" class="material-qty t-input flex-1" min="0.01" max="99999" step="0.01" inputmode="decimal" placeholder="Cantidad" required>
                            <span class="material-unit w-16 text-sm text-ink-500"></span>
                            <button type="button" class="material-remove t-btn-secondary t-btn-sm text-red-700">Quitar</button>
                        </div>
                        <p class="material-stock text-xs"></p>
                    </div>
                </template>
            </section>
        @endif

        {{-- Fotos de antes y después (opcionales; solo mantenimiento e inspección) --}}
        @if(! $workOrder && isset($photoSections) && $photoSections->isNotEmpty())
            @php
                $staged = ($stagedPhotos ?? collect())->groupBy(fn ($p) => $p->photo_section_id.'|'.$p->stage);
                $stages = \App\Models\VisitPhoto::STAGES;
                $maxPerGroup = \App\Models\VisitPhoto::MAX_PER_GROUP;
            @endphp
            <section class="t-card p-4" id="visitPhotos"
                     data-upload-url="{{ route('visit-photos.store', ['company' => auth()->user()->company->slug]) }}"
                     data-building="{{ $building->id }}" data-type="{{ $assignmentType }}"
                     data-max-group="{{ $maxPerGroup }}" data-max-total="{{ \App\Models\VisitPhoto::MAX_PER_VISIT }}">
                <input type="hidden" name="photo_batch" value="{{ $photoBatch }}">
                <input type="hidden" name="photo_count" value="{{ ($stagedPhotos ?? collect())->count() }}" data-photo-count>
                <h2 class="font-bold text-ink">Fotos de antes y después <span class="text-xs font-normal text-ink-500">(opcional)</span></h2>
                <p class="mb-3 text-xs text-ink-500">Hasta {{ $maxPerGroup }} fotos por sección en «Antes» y {{ $maxPerGroup }} en «Después». Se suben solas mientras completás el remito; si alguna falla, tocá «Reintentar». Las ve la oficina, no el cliente.</p>
                <div class="space-y-2">
                    @foreach($photoSections as $section)
                        @php $sectionCount = $stagedPhotos?->where('photo_section_id', $section->id)->count() ?? 0; @endphp
                        <details class="rounded-xl border border-ink-100" data-photo-section="{{ $section->id }}" @if($sectionCount) open @endif>
                            <summary class="flex min-h-[48px] cursor-pointer select-none items-center justify-between gap-2 px-3 font-semibold text-ink">
                                <span>{{ $section->name }}</span>
                                <span class="text-xs font-normal text-ink-500" data-section-count>{{ $sectionCount ? $sectionCount.' '.($sectionCount === 1 ? 'foto' : 'fotos') : 'Sin fotos' }}</span>
                            </summary>
                            <div class="space-y-3 border-t border-ink-100 px-3 pb-3 pt-3">
                                @foreach($stages as $stage => $stageLabel)
                                    @php $groupPhotos = $staged->get($section->id.'|'.$stage, collect()); @endphp
                                    <div data-photo-group data-section="{{ $section->id }}" data-stage="{{ $stage }}" data-section-name="{{ $section->name }}">
                                        <div class="mb-1.5 flex items-center justify-between">
                                            <span class="text-xs font-bold uppercase tracking-wider {{ $stage === 'before' ? 'text-ink-700' : 'text-emerald-700' }}">{{ $stageLabel }}</span>
                                            <span class="text-xs text-ink-500" data-group-count>{{ $groupPhotos->count() }} de {{ $maxPerGroup }}</span>
                                        </div>
                                        <div class="grid grid-cols-3 gap-2" data-thumbs>
                                            @foreach($groupPhotos as $photo)
                                                <div class="relative aspect-square overflow-hidden rounded-lg bg-ink-50" data-photo-id="{{ $photo->id }}" data-state="done">
                                                    <img src="{{ $photo->thumbUrl() }}" alt="{{ $section->name }}, {{ mb_strtolower($stageLabel) }}" class="h-full w-full object-cover">
                                                    <button type="button" class="absolute right-1 top-1 rounded-full bg-white/90 px-2 py-0.5 text-xs font-bold text-red-700 shadow" data-remove
                                                            data-delete-url="{{ route('visit-photos.destroy', ['company' => auth()->user()->company->slug, 'visitPhoto' => $photo->id]) }}" aria-label="Quitar foto">✕</button>
                                                </div>
                                            @endforeach
                                        </div>
                                        <label class="t-btn-secondary t-btn-sm mt-2 w-full cursor-pointer {{ $groupPhotos->count() >= $maxPerGroup ? 'hidden' : '' }}" data-add>
                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                                            Agregar foto · {{ mb_strtolower($stageLabel) }}
                                            <input type="file" accept="image/*" multiple class="sr-only" data-file aria-label="Agregar foto de {{ $section->name }}, {{ mb_strtolower($stageLabel) }}">
                                        </label>
                                    </div>
                                @endforeach
                                @if(($elevators ?? collect())->count() > 1)
                                    <div>
                                        <label class="mb-1 block text-xs text-ink-500" for="photo-elevator-{{ $section->id }}">Equipo (opcional)</label>
                                        <select id="photo-elevator-{{ $section->id }}" name="photo_elevators[{{ $section->id }}]" class="t-input py-2.5" data-draft-field>
                                            <option value="">General del edificio</option>
                                            @foreach($elevators as $elevator)
                                                <option value="{{ $elevator->id }}" @selected(old('photo_elevators.'.$section->id) == $elevator->id)>{{ $elevator->label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                @endif
                                <div>
                                    <label class="mb-1 block text-xs text-ink-500" for="photo-notes-{{ $section->id }}">Observación de {{ $section->name }} (opcional)</label>
                                    <input type="text" id="photo-notes-{{ $section->id }}" name="photo_notes[{{ $section->id }}]" maxlength="500" value="{{ old('photo_notes.'.$section->id) }}" class="t-input py-2.5" data-draft-field>
                                </div>
                            </div>
                        </details>
                    @endforeach
                </div>
                <p id="photos-status" class="mt-2 hidden text-xs font-semibold" role="status" aria-live="polite"></p>
            </section>
        @endif

        {{-- 3. Firmas --}}
        <section class="t-card p-4">
            <div class="mb-3 flex items-baseline justify-between gap-2">
                <h2 class="font-bold text-ink">Firmas</h2>
                <span class="text-xs text-ink-500">Tocá el recuadro para firmar</span>
            </div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                @foreach(['tech' => ['Técnico', true], 'client' => ['Cliente', false]] as $key => [$who, $required])
                    <div class="flex flex-col gap-2">
                        <div class="text-xs font-bold uppercase tracking-wider text-ink-700">
                            {{ $who }} <span class="font-normal normal-case tracking-normal {{ $required ? 'text-red-600' : 'text-ink-500' }}">{{ $required ? '(obligatoria)' : '(opcional)' }}</span>
                        </div>
                        <button type="button" id="{{ $key }}-trigger" data-target="{{ $key }}"
                                class="sig-trigger relative flex h-28 w-full items-center justify-center overflow-hidden rounded-xl border-2 border-dashed border-ink-300 bg-ink-50 transition hover:bg-white focus:outline-none focus-visible:ring-2 focus-visible:ring-brand"
                                aria-label="Firmar como {{ mb_strtolower($who) }}">
                            <span id="{{ $key }}-placeholder" class="pointer-events-none flex flex-col items-center gap-1 text-ink-500">
                                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 17c3-3 4.5-9 7-9s-1 9 2 9 3-5 5-5 2 3 4 3"/></svg>
                                <span class="text-xs font-semibold">Tocá para firmar</span>
                            </span>
                            <img id="{{ $key }}-preview" src="" alt="" class="pointer-events-none absolute inset-0 hidden h-full w-full object-contain p-1.5">
                        </button>
                        @if($key === 'tech')
                            <p id="tech-error" class="hidden text-xs font-semibold text-red-600">Falta tu firma.</p>
                        @endif
                        <div>
                            <label class="mb-1 block text-xs text-ink-500" for="{{ $key }}-name">Aclaración</label>
                            @if($key === 'tech')
                                <input type="text" id="tech-name" name="signature_name" data-draft-field value="{{ old('signature_name', auth()->user()->name) }}" class="t-input py-2.5" placeholder="Nombre y apellido">
                                @error('signature_name')<p class="mt-1 text-sm font-semibold text-red-600">{{ $message }}</p>@enderror
                            @else
                                <input type="text" id="client-name" name="client_signature_name" data-draft-field value="{{ old('client_signature_name') }}" class="t-input py-2.5" placeholder="Nombre y apellido">
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <div id="remito-client-error" class="t-alert-error hidden" role="alert"></div>
        <button id="submit-remito" type="submit" class="t-btn-primary w-full text-base">
            <span id="submit-text">Generar remito</span>
        </button>
    </form>
</div>

{{-- ═══════════════════════════ MODAL DE FIRMA ═══════════════════════════ --}}
<div id="sig-modal" role="dialog" aria-modal="true" aria-labelledby="sig-modal-title"
    style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(15,23,42,0.72);">

    {{-- Wrapper centrado —— NO usar flex en el overlay mismo para evitar bugs de altura en Safari --}}
    <div style="display:flex; min-height:100%; align-items:center; justify-content:center; padding:16px;">

        <div style="background:#fff; border-radius:20px; box-shadow:0 25px 60px rgba(0,0,0,0.3);
                    width:100%; max-width:440px; display:flex; flex-direction:column; max-height:88vh;">

            {{-- Cabecera --}}
            <div style="display:flex; align-items:center; justify-content:space-between;
                        padding:20px 20px 16px; border-bottom:1px solid #f1f5f9; flex-shrink:0;">
                <h2 id="sig-modal-title" style="font-size:16px; font-weight:900; color:#1e293b; margin:0;"></h2>
                <button id="sig-modal-close" type="button"
                    style="width:32px; height:32px; border:none; background:#f1f5f9; border-radius:50%;
                           cursor:pointer; font-size:18px; color:#64748b; display:flex; align-items:center;
                           justify-content:center; flex-shrink:0;">
                    &times;
                </button>
            </div>

            {{-- Instrucción --}}
            <p style="margin:12px 20px 8px; font-size:13px; color:#94a3b8; flex-shrink:0;">
                Firmá con el dedo o el mouse dentro del recuadro.
            </p>

            {{-- Canvas --}}
            <div style="padding:0 20px; flex:1; min-height:0;">
                <div style="border:2px solid #e2e8f0; border-radius:14px; overflow:hidden;
                            height:100%; min-height:190px; background:#fff;">
                    <canvas id="sig-modal-canvas"
                        style="touch-action:none; display:block; width:100%; height:100%;"></canvas>
                </div>
            </div>

            {{-- Botones --}}
            <div style="display:flex; gap:10px; padding:16px 20px 20px; flex-shrink:0;">
                <button id="sig-modal-clear" type="button"
                    style="flex:1; border:2px solid #e2e8f0; background:#fff; border-radius:12px;
                           padding:11px 16px; font-size:14px; font-weight:600; color:#475569;
                           cursor:pointer;">
                    Limpiar
                </button>
                <button id="sig-modal-confirm" type="button"
                    style="flex:2; border:none; background:#FF6600; border-radius:12px;
                           padding:11px 16px; font-size:15px; font-weight:800; color:#11161D;
                           cursor:pointer;">
                    Listo
                </button>
            </div>

        </div>
    </div>
</div>
@vite('resources/js/signature.js')
<script>
document.addEventListener('DOMContentLoaded', function () {

    const modal       = document.getElementById('sig-modal');
    const modalTitle  = document.getElementById('sig-modal-title');
    const modalCanvas = document.getElementById('sig-modal-canvas');
    const btnClear    = document.getElementById('sig-modal-clear');
    const btnConfirm  = document.getElementById('sig-modal-confirm');
    const btnClose    = document.getElementById('sig-modal-close');
    const form        = document.getElementById('remito-form');
    const techError   = document.getElementById('tech-error');

    const pads = {
        tech: {
            hiddenInput: document.getElementById('sig-tech-input'),
            trigger:     document.getElementById('tech-trigger'),
            placeholder: document.getElementById('tech-placeholder'),
            preview:     document.getElementById('tech-preview'),
            title:       'Firma del técnico',
            dataURL:     null,
        },
        client: {
            hiddenInput: document.getElementById('sig-client-input'),
            trigger:     document.getElementById('client-trigger'),
            placeholder: document.getElementById('client-placeholder'),
            preview:     document.getElementById('client-preview'),
            title:       'Firma del cliente',
            dataURL:     null,
        },
    };

    let signaturePad  = null;
    let currentTarget = null;

    function initPad() {
        if (signaturePad) signaturePad.off();
        signaturePad = new SignaturePad(modalCanvas, {
            penColor: '#111111',
            backgroundColor: 'rgba(255,255,255,0)',
            minWidth: 1,
            maxWidth: 3,
        });
    }

    function resizeCanvas() {
        const ratio = Math.max(window.devicePixelRatio || 1, 1);
        const rect  = modalCanvas.getBoundingClientRect();
        if (rect.width === 0 || rect.height === 0) return;

        const saved = signaturePad ? signaturePad.toData() : [];

        modalCanvas.width  = Math.round(rect.width  * ratio);
        modalCanvas.height = Math.round(rect.height * ratio);
        modalCanvas.getContext('2d').scale(ratio, ratio);

        signaturePad.clear();
        if (saved.length) signaturePad.fromData(saved);
    }

    function openModal(target) {
        currentTarget = target;
        modalTitle.textContent = pads[target].title;
        modal.style.display = 'block';
        document.body.style.overflow = 'hidden';

        initPad();

        // Doble rAF: esperar que el navegador pinte el modal antes de medir
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                resizeCanvas();

                if (pads[target].dataURL) {
                    const ratio = Math.max(window.devicePixelRatio || 1, 1);
                    const img   = new Image();
                    img.onload  = function () {
                        modalCanvas.getContext('2d').drawImage(
                            img, 0, 0,
                            modalCanvas.width  / ratio,
                            modalCanvas.height / ratio
                        );
                    };
                    img.src = pads[target].dataURL;
                }
            });
        });
    }

    function closeModal() {
        modal.style.display = 'none';
        document.body.style.overflow = '';
        currentTarget = null;
    }

    btnConfirm.addEventListener('click', function () {
        if (!currentTarget) return;
        if (signaturePad.isEmpty()) { closeModal(); return; }

        const dataURL = signaturePad.toDataURL('image/png');
        const pad     = pads[currentTarget];

        pad.dataURL            = dataURL;
        pad.hiddenInput.value  = dataURL;

        pad.preview.src = dataURL;
        pad.preview.classList.remove('hidden');
        pad.placeholder.classList.add('hidden');

        pad.trigger.style.borderStyle = 'solid';
        pad.trigger.style.borderColor = '#10B981';
        pad.trigger.style.background  = '#ECFDF5';

        if (currentTarget === 'tech') {
            techError.classList.add('hidden');
        }

        closeModal();
    });

    btnClear.addEventListener('click', function () { signaturePad.clear(); });
    btnClose.addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) {
        if (e.target === modal || e.target === modal.firstElementChild) closeModal();
    });

    document.querySelectorAll('.sig-trigger').forEach(function (el) {
        el.addEventListener('click', function () { openModal(el.dataset.target); });
    });

    let resizeTimer;
    window.addEventListener('resize', function () {
        if (modal.style.display === 'none' || !modal.style.display) return;
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(resizeCanvas, 150);
    });

    let sending = false;
    const formError = document.getElementById('remito-client-error');
    function showFormError(message) {
        formError.textContent = message;
        formError.classList.remove('hidden');
        formError.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

form.addEventListener('submit', function (e) {

    if (sending) {
        e.preventDefault();
        return;
    }

    const description = document.getElementById('description');

    if (!description.value.trim()) {
        e.preventDefault();
        showFormError('Completá el campo "Trabajo realizado".');
        description.focus();
        return;
    }

    if (!pads.tech.dataURL) {
        e.preventDefault();
        techError.classList.remove('hidden');
        pads.tech.trigger.style.borderStyle = 'solid';
        pads.tech.trigger.style.borderColor = '#f87171';
        pads.tech.trigger.scrollIntoView({
            behavior: 'smooth',
            block: 'center'
        });
        return;
    }

    // Fotos: no se firma con fotos subiéndose o que fallaron (se perderían sin aviso).
    const photoState = window.ascentoVisitPhotos ? window.ascentoVisitPhotos.state() : { uploading: 0, failed: 0 };
    if (photoState.uploading > 0) {
        e.preventDefault();
        showFormError('Esperá a que terminen de subirse las fotos (' + photoState.uploading + ').');
        return;
    }
    if (photoState.failed > 0) {
        e.preventDefault();
        showFormError('Hay ' + photoState.failed + ' foto(s) que no se subieron: tocá «Reintentar» o quitalas.');
        document.querySelector('[data-state="failed"]')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }

    // Sin señal: no se envía (se perderían las firmas en la página de error
    // del navegador). Todo queda cargado; se manda cuando vuelva la conexión.
    if (navigator.onLine === false) {
        e.preventDefault();
        showFormError('No hay conexión a internet. El remito NO se envió, pero no se perdió nada: esperá a tener señal y tocá "Generar remito" de nuevo.');
        return;
    }

    sending = true;

    const btn = document.getElementById('submit-remito');
    const text = document.getElementById('submit-text');

    formError.classList.add('hidden');
    btn.disabled = true;

    text.textContent = 'Generando remito...';

    pads.tech.hiddenInput.value = pads.tech.dataURL;
    pads.client.hiddenInput.value = pads.client.dataURL || '';
});

});
@if ($errors->any())
window.addEventListener('load', () => {
    document.getElementById('remito-errors')?.scrollIntoView({
        behavior: 'smooth',
        block: 'center'
    });
});
@endif
</script>


<script>
/* Fotos de antes y después: se suben de a una (en fila) mientras se completa
   el remito. Cada una se achica en el celular antes de subir; si falla, queda
   con «Reintentar» y el remito no se puede enviar hasta resolverla. */
(() => {
    const root = document.getElementById('visitPhotos');
    if (!root) return;
    const MAX_SIDE = 2000;
    const maxGroup = parseInt(root.dataset.maxGroup, 10);
    const maxTotal = parseInt(root.dataset.maxTotal, 10);
    const batch = root.querySelector('input[name="photo_batch"]').value;
    const token = document.querySelector('input[name="_token"]').value;
    const status = document.getElementById('photos-status');
    const queue = [];
    let busy = false;

    const tiles = (sel) => root.querySelectorAll('[data-thumbs] > div' + (sel || ''));

    function say(text, isError) {
        status.textContent = text;
        status.className = 'mt-2 text-xs font-semibold ' + (isError ? 'text-red-700' : 'text-ink-500');
        status.classList.toggle('hidden', !text);
    }

    function refresh(group) {
        const n = group.querySelectorAll('[data-thumbs] > div').length;
        group.querySelector('[data-group-count]').textContent = n + ' de ' + maxGroup;
        group.querySelector('[data-add]').classList.toggle('hidden', n >= maxGroup);
        const section = group.closest('[data-photo-section]');
        const total = section.querySelectorAll('[data-thumbs] > div').length;
        section.querySelector('[data-section-count]').textContent = total ? total + (total === 1 ? ' foto' : ' fotos') : 'Sin fotos';
    }

    async function shrink(file) {
        try {
            if (!window.createImageBitmap) return file;
            const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
            const scale = Math.min(1, MAX_SIDE / Math.max(bitmap.width, bitmap.height));
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(bitmap.width * scale);
            canvas.height = Math.round(bitmap.height * scale);
            canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
            const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.85));
            if (!blob || blob.size >= file.size) return file;
            return new File([blob], (file.name || 'foto').replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' });
        } catch (e) {
            return file;
        }
    }

    function newKey() {
        if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
        const bytes = new Uint8Array(16);
        (window.crypto || window.msCrypto).getRandomValues(bytes);
        return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
    }
    function tile(group, file) {
        const el = document.createElement('div');
        el.className = 'relative aspect-square overflow-hidden rounded-lg bg-ink-50';
        el.dataset.state = 'uploading';
        el.innerHTML = '<img alt="" class="h-full w-full object-cover opacity-60">'
            + '<span class="absolute inset-x-0 bottom-0 bg-white/90 px-1 py-0.5 text-center text-[11px] font-semibold text-ink-700" data-label>Subiendo…</span>'
            + '<button type="button" class="absolute right-1 top-1 rounded-full bg-white/90 px-2 py-0.5 text-xs font-bold text-red-700 shadow" data-remove aria-label="Quitar foto">✕</button>';
        const img = el.querySelector('img');
        img.alt = group.dataset.sectionName + ', ' + (group.dataset.stage === 'before' ? 'antes' : 'después');
        try { img.src = URL.createObjectURL(file); } catch (e) {}
        el._file = file;
        el._group = group;
        // Identidad de esta foto: si la subida llegó pero la respuesta no,
        // «Reintentar» recibe la misma foto en vez de duplicarla.
        el._key = newKey();
        group.querySelector('[data-thumbs]').append(el);
        refresh(group);
        return el;
    }

    function setFailed(el, message) {
        el.dataset.state = 'failed';
        const label = el.querySelector('[data-label]');
        label.className = 'absolute inset-x-0 bottom-0 bg-red-50 px-1 py-0.5 text-center text-[11px] font-bold text-red-700';
        label.innerHTML = '';
        const retry = document.createElement('button');
        retry.type = 'button';
        retry.textContent = 'Reintentar';
        retry.className = 'underline';
        retry.addEventListener('click', () => { el.dataset.state = 'uploading'; label.className = 'absolute inset-x-0 bottom-0 bg-white/90 px-1 py-0.5 text-center text-[11px] font-semibold text-ink-700'; label.textContent = 'Subiendo…'; enqueue(el); });
        label.append(retry);
        say(message, true);
    }

    async function upload(el) {
        const group = el._group;
        const body = new FormData();
        body.append('_token', token);
        body.append('batch', batch);
        body.append('building_id', root.dataset.building);
        body.append('assignment_type', root.dataset.type);
        body.append('photo_section_id', group.dataset.section);
        body.append('stage', group.dataset.stage);
        body.append('client_key', el._key);
        body.append('photo', el._file);
        try {
            if (navigator.onLine === false) throw new Error('offline');
            const res = await fetch(root.dataset.uploadUrl, { method: 'POST', body, headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                const msg = data.errors ? Object.values(data.errors).flat()[0] : (data.message || 'No se pudo subir la foto.');
                if (res.status === 422 && data.errors && /hasta|máximo|ya no admite/.test(msg)) { el.remove(); refresh(group); say(msg, true); return; }
                return setFailed(el, msg);
            }
            if (!el.isConnected) {
                // La quitaron mientras se subía: se borra también del servidor.
                fetch(data.delete_url, { method: 'DELETE', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' }).catch(() => {});
                return;
            }
            el.dataset.state = 'done';
            el.dataset.photoId = data.id;
            el.querySelector('[data-remove]').dataset.deleteUrl = data.delete_url;
            const img = el.querySelector('img');
            img.classList.remove('opacity-60');
            img.src = data.thumb_url;
            el.querySelector('[data-label]').remove();
            el._file = null;
            say('');
        } catch (e) {
            setFailed(el, navigator.onLine === false ? 'Sin conexión: la foto no se subió. Tocá «Reintentar» cuando vuelva la señal.' : 'No se pudo subir la foto. Tocá «Reintentar».');
        }
    }

    function enqueue(el) {
        queue.push(el);
        if (!busy) next();
    }

    async function next() {
        busy = true;
        while (queue.length) {
            const el = queue.shift();
            if (!el.isConnected) continue;
            el._file = el._compressed ? el._file : await shrink(el._file);
            el._compressed = true;
            await upload(el);
        }
        busy = false;
    }

    root.addEventListener('change', (e) => {
        const input = e.target.closest('[data-file]');
        if (!input) return;
        const group = input.closest('[data-photo-group]');
        const files = Array.from(input.files || []).filter((f) => !f.type || f.type.startsWith('image/'));
        input.value = '';
        const roomGroup = maxGroup - group.querySelectorAll('[data-thumbs] > div').length;
        const roomTotal = maxTotal - tiles().length;
        const room = Math.max(0, Math.min(roomGroup, roomTotal));
        if (files.length > room) say(roomTotal <= 0 ? 'Llegaste al máximo de ' + maxTotal + ' fotos por visita.' : 'En esta sección entran ' + maxGroup + ' fotos por estado. Se agregaron las primeras.', true);
        files.slice(0, room).forEach((file) => enqueue(tile(group, file)));
    });

    root.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-remove]');
        if (!btn) return;
        const el = btn.closest('[data-thumbs] > div');
        const group = btn.closest('[data-photo-group]');
        if (el.dataset.state === 'done' && btn.dataset.deleteUrl) {
            btn.disabled = true;
            try {
                const res = await fetch(btn.dataset.deleteUrl, { method: 'DELETE', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
                if (!res.ok && res.status !== 404) throw new Error();
            } catch (err) {
                btn.disabled = false;
                return say('No se pudo quitar la foto. Probá de nuevo.', true);
            }
        }
        el.remove();
        refresh(group);
    });

    // Cuántas fotos quedaron subidas: el servidor avisa si alguna no se guardó.
    root.closest('form')?.addEventListener('submit', () => {
        root.querySelector('[data-photo-count]').value = tiles('[data-state="done"]').length;
    });
    window.ascentoVisitPhotos = {
        state: () => ({
            uploading: tiles('[data-state="uploading"]').length,
            failed: tiles('[data-state="failed"]').length,
        }),
    };
})();
</script>

<script>
(() => {
    const rows = document.getElementById('materialRows');
    if (!rows) return;
    const template = document.getElementById('materialTemplate');
    const old = @json(array_values(old('materials', [])));
    let next = 0;

    function refresh(row) {
        const option = row.querySelector('.material-item').selectedOptions[0];
        const qty = parseFloat(row.querySelector('.material-qty').value || '0');
        const info = row.querySelector('.material-stock');
        row.querySelector('.material-unit').textContent = option?.dataset.unit || '';

        if (!option || !option.value) { info.textContent = ''; return; }

        const stock = parseFloat(option.dataset.stock);
        const fmt = (n) => n.toLocaleString('es-AR', { maximumFractionDigits: 2 }) + ' ' + option.dataset.unit;
        info.className = 'material-stock text-xs ' + (qty > stock ? 'text-brand-700 font-semibold' : 'text-ink-500');
        info.textContent = qty > stock
            ? 'Atención: hay ' + fmt(stock) + '. Se registra igual; avisale a la oficina para reponer.'
            : 'Hay ' + fmt(stock) + ' en stock.';
    }

    function add(values = {}) {
        const row = template.content.firstElementChild.cloneNode(true);
        const i = next++;
        const item = row.querySelector('.material-item');
        const qty = row.querySelector('.material-qty');
        item.name = `materials[${i}][stock_item_id]`;
        qty.name = `materials[${i}][quantity]`;
        item.value = values.stock_item_id || '';
        qty.value = values.quantity || '';
        item.addEventListener('change', () => refresh(row));
        qty.addEventListener('input', () => refresh(row));
        row.querySelector('.material-remove').addEventListener('click', () => row.remove());
        rows.append(row);
        refresh(row);
        return row;
    }

    document.getElementById('addMaterial').addEventListener('click', () => add().querySelector('.material-item').focus());
    old.forEach(add);
})();
</script>
</x-app-layout>

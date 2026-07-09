<x-app-layout>

<div class="min-h-screen bg-slate-100 py-6 md:py-10">
    <div class="max-w-2xl mx-auto px-4">

        {{-- Header --}}
        <div class="bg-gradient-to-r from-slate-900 to-slate-700 rounded-2xl p-6 text-white shadow-lg mb-5">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-black leading-tight">Nuevo Remito</h1>
                    <p class="text-slate-400 text-sm mt-1">Registrar trabajo realizado</p>
                </div>
                <span class="text-5xl leading-none">📄</span>
            </div>
        </div>
        @if ($errors->any())
            <div class="mb-5 bg-red-50 border border-red-200 rounded-xl p-4">
                <div class="flex items-center gap-2 mb-2">
                    <span class="text-red-600 text-lg">⚠️</span>
                    <h3 class="font-bold text-red-700">
                        Faltan completar algunos datos
                    </h3>
                </div>

                <ul class="list-disc list-inside text-sm text-red-600 space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        <form method="POST" action="{{ route('delivery-notes.store') }}" id="remito-form">
            @csrf

            @if($workOrder)
                <input type="hidden" name="work_order_id" value="{{ $workOrder->id }}">
            @endif

            <input type="hidden" name="building_id"               value="{{ $building->id }}">
            <input type="hidden" name="year" value="{{ $year }}">
            <input type="hidden" name="month" value="{{ $month }}">
            <input type="hidden" name="elevator_quantity"         value="{{ $building->elevator_count }}">
            <input type="hidden" name="freight_elevator_quantity" value="{{ $building->freight_elevator_count }}">
            <input type="hidden" name="signature"        id="sig-tech-input">
            <input type="hidden" name="client_signature" id="sig-client-input">

            <div class="bg-white rounded-2xl shadow-lg overflow-hidden">
                <div class="p-5 md:p-7 space-y-5">

                    {{-- 1. Cliente / Dirección --}}
                    <div class="grid grid-cols-2 gap-3">
                        <div class="bg-slate-50 rounded-xl p-4">
                            <div class="text-xs text-slate-500 uppercase tracking-wider mb-0.5">Cliente</div>
                            <div class="font-bold text-base leading-snug">{{ $building->client?->name }}</div>
                        </div>
                        <div class="bg-slate-50 rounded-xl p-4">
                            <div class="text-xs text-slate-500 uppercase tracking-wider mb-0.5">Dirección</div>
                            <div class="font-semibold text-sm">{{ $building->name }}{{ $building->address }}</div>
                        </div>
                    </div>

                    {{-- 2. Equipos + Mes en la misma fila --}}
                    <div class="grid grid-cols-2 gap-3">
                        <div class="bg-slate-50 rounded-xl p-4">
                            <div class="text-xs text-slate-500 uppercase tracking-wider mb-0.5">Equipos</div>
                            <div class="font-semibold text-sm">
                                {{ $building->elevator_count }} Asc. / {{ $building->freight_elevator_count }} Mont.
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs text-slate-500 uppercase tracking-wider mb-1.5">
                                Mes del mantenimiento
                            </label>

                            <select
                                name="month"
                                class="w-full rounded-xl border-slate-300 h-11 px-3 text-sm font-semibold"
                            >
                                @foreach(range(1,12) as $m)
                                    <option
                                        value="{{ $m }}"
                                        @selected($month == $m)
                                    >
                                        {{ \Carbon\Carbon::create()->month($m)->locale('es')->monthName }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <input
                        type="hidden"
                        name="assignment_type"
                        value="{{ $assignmentType }}"
                    >

                    {{-- 3. Descripción --}}
                    <div>
                        <label class="block font-bold text-sm mb-1.5" for="description">
                            Trabajo realizado
                        </label>
                        <textarea
                            name="description" id="description" rows="7" required
                            class="w-full rounded-xl border-slate-300 text-sm p-3 resize-y"
                            placeholder="Detallá todo el trabajo realizado durante la visita..."
                        ></textarea>
                        @error('description')
                            <p class="text-red-500 text-sm mt-1">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>

                    {{-- 4. Checkbox --}}
                    <div class="bg-green-50 border border-green-200 rounded-xl p-4">
                        <label class="flex items-center gap-3 cursor-pointer select-none">
                            <input type="checkbox" name="performed" value="1" checked
                                class="w-5 h-5 accent-green-600 flex-shrink-0">
                            <span class="font-semibold text-green-800 text-sm">Trabajo realizado correctamente</span>
                        </label>
                    </div>

                    {{-- 5. Firmas --}}
                    <div>
                        <div class="flex items-baseline gap-2 mb-3">
                            <h2 class="font-black text-base text-slate-800">Firmas</h2>
                            <span class="text-xs text-slate-400">Tocá el recuadro para firmar</span>
                        </div>

                        <div class="grid grid-cols-2 gap-4">

                            {{-- Técnico --}}
                            <div class="flex flex-col gap-2">
                                <div class="flex items-center gap-1.5">
                                    <span class="w-2 h-2 rounded-full bg-slate-800 flex-shrink-0"></span>
                                    <span class="text-xs font-bold text-slate-700 uppercase tracking-wider">Técnico</span>
                                    <span class="text-xs text-red-400">*</span>
                                </div>

                                {{-- Trigger / Preview --}}
                                <button
                                    type="button"
                                    id="tech-trigger"
                                    data-target="tech"
                                    class="sig-trigger relative w-full rounded-xl border-2 border-dashed border-slate-300
                                           bg-slate-50 hover:border-slate-500 hover:bg-white
                                           focus:outline-none focus:ring-2 focus:ring-slate-400
                                           transition-all overflow-hidden flex items-center justify-center"
                                    style="height: 100px;"
                                >
                                    <div id="tech-placeholder" class="flex flex-col items-center gap-1 pointer-events-none">
                                        <span class="text-2xl">✍️</span>
                                        <span class="text-slate-400 text-xs">Tocá para firmar</span>
                                    </div>
                                    <img id="tech-preview" src="" alt=""
                                        class="hidden absolute inset-0 w-full h-full object-contain p-1.5 pointer-events-none">
                                </button>

                                <p id="tech-error" class="hidden text-xs text-red-500 font-semibold">
                                    ⚠️ Firma obligatoria
                                </p>

                                <div>
                                    <label class="block text-xs text-slate-500 mb-1">Aclaración</label>
                                    <input type="text" value="{{ old('signature_name', auth()->user()->name) }}" name="signature_name"
                                        class="w-full rounded-xl border-slate-300 h-9 px-3 text-sm"
                                        placeholder="Nombre y apellido">
                                        @error('signature_name')
                                            <p class="text-red-500 text-sm mt-1">
                                                {{ $message }}
                                            </p>
                                        @enderror
                                </div>
                            </div>

                            {{-- Cliente --}}
                            <div class="flex flex-col gap-2">
                                <div class="flex items-center gap-1.5">
                                    <span class="w-2 h-2 rounded-full bg-slate-400 flex-shrink-0"></span>
                                    <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">Cliente</span>
                                    <span class="text-xs text-slate-400">(opcional)</span>
                                </div>

                                <button
                                    type="button"
                                    id="client-trigger"
                                    data-target="client"
                                    class="sig-trigger relative w-full rounded-xl border-2 border-dashed border-slate-200
                                           bg-slate-50 hover:border-slate-400 hover:bg-white
                                           focus:outline-none focus:ring-2 focus:ring-slate-300
                                           transition-all overflow-hidden flex items-center justify-center"
                                    style="height: 100px;"
                                >
                                    <div id="client-placeholder" class="flex flex-col items-center gap-1 pointer-events-none">
                                        <span class="text-2xl">✍️</span>
                                        <span class="text-slate-400 text-xs">Tocá para firmar</span>
                                    </div>
                                    <img id="client-preview" src="" alt=""
                                        class="hidden absolute inset-0 w-full h-full object-contain p-1.5 pointer-events-none">
                                </button>

                                <div>
                                    <label class="block text-xs text-slate-500 mb-1">Aclaración</label>
                                    <input type="text" name="client_signature_name"
                                        class="w-full rounded-xl border-slate-300 h-9 px-3 text-sm"
                                        placeholder="Nombre y apellido">
                                </div>
                            </div>

                        </div>
                    </div>

                </div>

                {{-- Submit --}}
                <div class="border-t border-slate-100 bg-slate-50 px-5 py-4 md:px-7">
                    <button type="submit"
                        class="w-full bg-slate-900 hover:bg-slate-800 active:bg-black
                               text-white font-black text-base py-2 rounded-xl transition">
                        Generar Remito
                    </button>
                </div>
            </div>

        </form>
    </div>
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
                    style="flex:2; border:none; background:#0f172a; border-radius:12px;
                           padding:11px 16px; font-size:15px; font-weight:900; color:#fff;
                           cursor:pointer;">
                    Listo ✓
                </button>
            </div>

        </div>
    </div>
</div>
<br><br><br>
<script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
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
        pad.trigger.style.borderColor = '#4ade80';
        pad.trigger.style.background  = '#f0fdf4';

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

    form.addEventListener('submit', function (e) {
        const description = document.getElementById('description');

        if (!description.value.trim()) {
            e.preventDefault();

            alert('Debe completar el campo "Trabajo realizado".');

            description.focus();

            return;
        }
        if (!pads.tech.dataURL) {
            e.preventDefault();
            techError.classList.remove('hidden');
            pads.tech.trigger.style.borderStyle = 'solid';
            pads.tech.trigger.style.borderColor = '#f87171';
            pads.tech.trigger.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }
        pads.tech.hiddenInput.value   = pads.tech.dataURL;
        pads.client.hiddenInput.value = pads.client.dataURL || '';
    });

});
@if ($errors->any())
window.addEventListener('load', () => {
    document.querySelector('.bg-red-50')?.scrollIntoView({
        behavior: 'smooth',
        block: 'center'
    });
});
@endif
</script>

</x-app-layout>

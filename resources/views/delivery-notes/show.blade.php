<x-delivery-note-layout>

<div class="min-h-screen bg-slate-100 py-4 md:py-6">
    <div class="max-w-2xl mx-auto px-4">

        {{-- Header --}}
        <div class="bg-gradient-to-r from-slate-900 to-slate-700 rounded-2xl p-5 md:p-6 text-white shadow-lg mb-5">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl md:text-3xl font-black leading-tight">Remito #{{ str_pad($deliveryNote->number, 6, '0', STR_PAD_LEFT) }}</h1>
                    <p class="text-slate-400 text-xs md:text-sm mt-1">{{ $deliveryNote->created_at->format('d/m/Y H:i') }} hs</p>
                </div>
                <span class="text-4xl md:text-5xl leading-none">📄</span>
            </div>
        </div>

        {{-- Tarjeta Principal --}}
        <div class="bg-white rounded-2xl shadow-lg overflow-hidden">
            <div class="p-4 md:p-7 space-y-4 md:space-y-5">

                {{-- 1. Cliente / Dirección --}}
                <div class="grid grid-cols-2 gap-3">
                    <div class="bg-slate-50 rounded-xl p-3 md:p-4">
                        <div class="text-xs text-slate-500 uppercase tracking-wider mb-0.5">Cliente</div>
                        <div class="font-bold text-sm md:text-base leading-snug">{{ $deliveryNote->building?->client?->name }}</div>
                    </div>
                    <div class="bg-slate-50 rounded-xl p-3 md:p-4">
                        <div class="text-xs text-slate-500 uppercase tracking-wider mb-0.5">Dirección</div>
                        <div class="font-semibold text-xs md:text-sm leading-snug">{{ $deliveryNote->building?->name }}</div>
                    </div>
                </div>

                {{-- 2. Período / Equipos --}}
                <div class="grid grid-cols-2 gap-3">
                    <div class="bg-slate-50 rounded-xl p-3 md:p-4">
                        <div class="text-xs text-slate-500 uppercase tracking-wider mb-0.5">Período del servicio</div>
                        {{-- Período del servicio --}}

                            <div class="font-bold text-sm md:text-base capitalize leading-snug">
                                @if($deliveryNote->month && $deliveryNote->year)
                                    {{ \Carbon\Carbon::create()
                                        ->month($deliveryNote->month)
                                        ->locale('es')
                                        ->monthName
                                    }}

                                @else
                                    Sin período asignado
                                @endif
                            </div>
                    </div>
                    <div class="bg-slate-50 rounded-xl p-3 md:p-4">
                        <div class="text-xs text-slate-500 uppercase tracking-wider mb-0.5">Equipos</div>
                        <div class="font-semibold text-xs md:text-sm leading-snug">
                            {{ $deliveryNote->building->elevator_count }} Asc. / {{ $deliveryNote->building->freight_elevator_count }} Mont.
                        </div>
                    </div>
                </div>

                {{-- 3. Técnico / Estado --}}
                <div class="grid grid-cols-2 gap-3">
                    <div class="bg-slate-50 rounded-xl p-3 md:p-4">
                        <div class="text-xs text-slate-500 uppercase tracking-wider mb-0.5">Técnico</div>
                        <div class="font-bold text-sm md:text-base leading-snug">{{ $deliveryNote->user?->name }}</div>
                    </div>
                    <div class="bg-slate-50 rounded-xl p-3 md:p-4">
                        <div class="text-xs text-slate-500 uppercase tracking-wider mb-0.5">Estado</div>
                        @if($deliveryNote->performed)
                            <div class="text-green-600 font-black text-sm md:text-base">✓ Realizado</div>
                        @else
                            <div class="text-red-500 font-black text-sm md:text-base">✕ No realizado</div>
                        @endif
                    </div>
                </div>

                {{-- 4. Descripción --}}
                <div>
                    <label class="block text-xs text-slate-500 uppercase tracking-wider mb-1.5">Trabajo realizado</label>
                    <div class="bg-slate-50 rounded-xl p-3 md:p-4 text-xs md:text-sm leading-relaxed whitespace-pre-line text-slate-800 max-h-32 overflow-y-auto">
                        {{ $deliveryNote->description }}
                    </div>
                </div>

                {{-- 5. Firmas Compactas --}}
                <div>
                    <div class="text-xs text-slate-500 uppercase tracking-wider mb-2.5">Firmas</div>

                    <div class="grid grid-cols-2 gap-3">

                        {{-- Firma técnico --}}
                        <div class="border border-slate-200 rounded-xl overflow-hidden">
                            <div class="bg-slate-50 px-3 py-1.5 border-b border-slate-200 flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full bg-slate-700 flex-shrink-0"></span>
                                <span class="text-xs font-bold text-slate-700 uppercase tracking-wider">Técnico</span>
                            </div>
                            <div class="h-24 md:h-28 flex items-center justify-center bg-white px-2">
                                @if($deliveryNote->signature)
                                    <img src="{{ $deliveryNote->signature }}" alt="Firma técnico"
                                        class="max-h-20 md:max-h-24 max-w-full object-contain">
                                @else
                                    <span class="text-slate-300 text-xs">Sin firma</span>
                                @endif
                            </div>
                            <div class="bg-slate-50 px-3 py-2 border-t border-slate-200">
                                <div class="text-xs text-slate-400 mb-0.5">Aclaración</div>
                                <div class="font-semibold text-xs md:text-sm text-slate-800 truncate">
                                    {{ $deliveryNote->signature_name ?: '—' }}
                                </div>
                            </div>
                        </div>

                        {{-- Firma cliente --}}
                        <div class="border border-slate-200 rounded-xl overflow-hidden">
                            <div class="bg-slate-50 px-3 py-1.5 border-b border-slate-200 flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full bg-slate-400 flex-shrink-0"></span>
                                <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">Cliente</span>
                            </div>
                            <div class="h-24 md:h-28 flex items-center justify-center bg-white px-2">
                                @if($deliveryNote->client_signature)
                                    <img src="{{ $deliveryNote->client_signature }}" alt="Firma cliente"
                                        class="max-h-20 md:max-h-24 max-w-full object-contain">
                                @else
                                    <span class="text-slate-300 text-xs">Sin firma</span>
                                @endif
                            </div>
                            <div class="bg-slate-50 px-3 py-2 border-t border-slate-200">
                                <div class="text-xs text-slate-400 mb-0.5">Aclaración</div>
                                <div class="font-semibold text-xs md:text-sm text-slate-800 truncate">
                                    {{ $deliveryNote->client_signature_name ?: '—' }}
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

            </div>

            {{-- Acciones --}}
            <div class="border-t border-slate-100 bg-slate-50 px-4 py-3 md:px-7 md:py-4 flex gap-3">

                {{-- Botón Compartir --}}
                <button
                    id="btn-share"
                    type="button"
                    class="flex-1 flex items-center justify-center gap-2 bg-slate-900 hover:bg-slate-800 active:bg-black
                           text-white font-bold text-sm md:text-base py-2 rounded-xl transition"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" />
                    </svg>
                    Compartir
                </button>

                {{-- Botón PDF --}}
                <button
                    id="btn-pdf"
                    type="button"
                    class="flex-1 flex items-center justify-center gap-2 bg-red-500 hover:bg-red-600 active:bg-red-700
                           text-white font-bold text-sm md:text-base py-2 rounded-xl transition"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                    </svg>
                    PDF
                </button>

                {{-- Botón Atrás --}}
                <a href="{{ route('dashboard') }}"
                    class="flex-1 flex items-center justify-center gap-2 bg-slate-200 hover:bg-slate-300 active:bg-slate-400
                           text-slate-800 font-bold text-sm md:text-base py-2 rounded-xl transition"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                    Atrás
                </a>

            </div>
        </div>

    </div>
</div>

{{-- ═══════════════════════════ MODAL COMPARTIR ═══════════════════════════ --}}
<div id="share-modal" role="dialog" aria-modal="true"
    style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(15,23,42,0.72);">
    <div style="display:flex; min-height:100%; align-items:flex-end; justify-content:center; padding:0;">

        <div style="background:#fff; border-radius:20px 20px 0 0; width:100%; max-width:440px; padding:24px 20px 32px;">

            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:20px;">
                <span style="font-size:18px; font-weight:900; color:#1e293b;">Compartir remito</span>
                <button id="share-close" type="button"
                    style="width:36px; height:36px; border:none; background:#f1f5f9; border-radius:50%;
                           cursor:pointer; font-size:24px; color:#64748b; flex-shrink:0;">
                    &times;
                </button>
            </div>

            <div style="display:flex; flex-direction:column; gap:12px;">

                {{-- WhatsApp --}}
                <a id="share-wpp" href="#" target="_blank" rel="noopener"
                    style="display:flex; align-items:center; gap:14px; padding:16px;
                           border:1.5px solid #e2e8f0; border-radius:14px; text-decoration:none;
                           color:#1e293b; font-weight:700; font-size:15px; background:#fff;">
                    <span style="width:44px; height:44px; border-radius:50%; background:#25D366;
                                 display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <svg viewBox="0 0 32 32" width="22" height="22" fill="#fff">
                            <path d="M16 2C8.268 2 2 8.268 2 16c0 2.47.648 4.786 1.776 6.79L2 30l7.42-1.746A13.94 13.94 0 0016 30c7.732 0 14-6.268 14-14S23.732 2 16 2zm0 25.6a11.556 11.556 0 01-5.89-1.609l-.422-.252-4.402 1.036 1.053-4.287-.276-.44A11.558 11.558 0 014.4 16C4.4 9.596 9.596 4.4 16 4.4S27.6 9.596 27.6 16 22.404 27.6 16 27.6zm6.306-8.666c-.346-.173-2.044-1.008-2.362-1.123-.317-.115-.548-.173-.778.173-.23.346-.892 1.123-1.094 1.354-.202.23-.403.259-.749.086-.346-.173-1.46-.537-2.78-1.712-1.028-.914-1.72-2.043-1.922-2.389-.202-.346-.022-.533.152-.705.156-.155.346-.403.52-.605.172-.202.229-.346.344-.577.115-.23.058-.432-.029-.605-.086-.173-.778-1.873-1.066-2.563-.28-.672-.565-.58-.778-.591l-.663-.012c-.23 0-.605.086-.921.432-.317.346-1.21 1.18-1.21 2.879s1.238 3.339 1.411 3.569c.172.23 2.436 3.716 5.901 5.21.825.356 1.469.569 1.972.728.828.264 1.582.227 2.178.138.664-.1 2.044-.835 2.333-1.641.289-.806.289-1.497.202-1.641-.086-.144-.317-.23-.663-.403z"/>
                        </svg>
                    </span>
                    <span>Enviar por WhatsApp</span>
                </a>

                {{-- Email --}}
                <a id="share-mail" href="#"
                    style="display:flex; align-items:center; gap:14px; padding:16px;
                           border:1.5px solid #e2e8f0; border-radius:14px; text-decoration:none;
                           color:#1e293b; font-weight:700; font-size:15px; background:#fff;">
                    <span style="width:44px; height:44px; border-radius:50%; background:#3b82f6;
                                 display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#fff" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                        </svg>
                    </span>
                    <span>Enviar por Email</span>
                </a>

                {{-- Copiar enlace --}}
                <button id="share-copy" type="button"
                    style="display:flex; align-items:center; gap:14px; padding:16px;
                           border:1.5px solid #e2e8f0; border-radius:14px; cursor:pointer;
                           color:#1e293b; font-weight:700; font-size:15px; background:#fff; width:100%;">
                    <span style="width:44px; height:44px; border-radius:50%; background:#64748b;
                                 display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#fff" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                        </svg>
                    </span>
                    <span id="copy-label">Copiar enlace</span>
                </button>

            </div>
        </div>
    </div>
</div>

{{-- Estilos de impresión para PDF --}}
<style>
@media print {
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { background: white !important; }
    body * { visibility: hidden; }

    .bg-white.rounded-2xl { visibility: visible !important; }
    .bg-white.rounded-2xl * { visibility: visible !important; }

    .bg-white.rounded-2xl {
        position: absolute !important;
        left: 0 !important;
        top: 0 !important;
        width: 100% !important;
        margin: 0 !important;
        padding: 30px !important;
        background: white !important;
        box-shadow: none !important;
        border-radius: 0 !important;
    }

    .border-t.border-slate-100 { display: none !important; }

    /* Mejorar tamaños en impresión */
    .bg-white.rounded-2xl h1 { font-size: 20pt !important; }
    .bg-white.rounded-2xl .text-base { font-size: 12pt !important; }
    .bg-white.rounded-2xl .text-sm { font-size: 11pt !important; }

    @page { margin: 15mm; size: A4; }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const pageURL = "{{ route('delivery-notes.public', $deliveryNote) }}";
    const noteNum = '{{ str_pad($deliveryNote->number, 6, "0", STR_PAD_LEFT) }}';
    const subject = encodeURIComponent('Remito #' + noteNum);

    // WhatsApp
    document.getElementById('share-wpp').href =
    'https://wa.me/?text=' + encodeURIComponent(
        "📄 *Remito de Servicio*\n" +
        "Número: #" + noteNum + "\n" +
        "Fecha: {{ $deliveryNote->created_at->format('d/m/Y') }}\n\n" +
        "🔗 Ver remito:\n" + pageURL
    );

    // Email
    document.getElementById('share-mail').href =
    'mailto:?subject=' + subject + '&body=' + encodeURIComponent(
        "📄 REMITO DE SERVICIO\n\n" +
        "Número: #" + noteNum + "\n" +
        "Fecha: {{ $deliveryNote->created_at->format('d/m/Y') }}\n" +
        "Estado: {{ $deliveryNote->performed ? 'Realizado' : 'No realizado' }}\n\n" +
        "🔗 Ver remito:\n" + pageURL + "\n"
    );

    // Modal
    const modal      = document.getElementById('share-modal');
    const btnShare   = document.getElementById('btn-share');
    const btnPDF     = document.getElementById('btn-pdf');
    const btnClose   = document.getElementById('share-close');
    const btnCopy    = document.getElementById('share-copy');
    const copyLabel  = document.getElementById('copy-label');

    function openShare() {
        modal.style.display = 'block';
        document.body.style.overflow = 'hidden';
    }

    function closeShare() {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }

    btnShare.addEventListener('click', openShare);
    btnClose.addEventListener('click', closeShare);
    modal.addEventListener('click', function (e) {
        if (e.target === modal) closeShare();
    });

    // Copiar enlace - versión mejorada
    btnCopy.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();

        const textToCopy = pageURL;

        // Intentar con Clipboard API
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(textToCopy)
                .then(function () {
                    copyLabel.textContent = '¡Enlace copiado!';
                    setTimeout(function () { copyLabel.textContent = 'Copiar enlace'; }, 2500);
                })
                .catch(function (err) {
                    console.error('Error con clipboard:', err);
                    fallbackCopy(textToCopy);
                });
        } else {
            fallbackCopy(textToCopy);
        }
    });

    function fallbackCopy(text) {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);

        try {
            textarea.select();
            const successful = document.execCommand('copy');
            if (successful) {
                copyLabel.textContent = '¡Enlace copiado!';
                setTimeout(function () { copyLabel.textContent = 'Copiar enlace'; }, 2500);
            }
        } catch (err) {
            console.error('Error:', err);
        } finally {
            document.body.removeChild(textarea);
        }
    }

    // PDF via print
    btnPDF.addEventListener('click', function () {
        closeShare();
        setTimeout(function () { window.print(); }, 300);
    });

});
</script>

</x-delivery-note-layout>

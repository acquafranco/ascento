{{--
    Remito en pantalla para el técnico / admin ($audience = 'internal') y para
    quien abre el enlace público ($audience = 'public'). El portal tiene su
    propia vista (portal.delivery-note).
    - El documento (delivery-notes.partials.document) es el mismo para todos y
      solo tiene datos que puede ver el cliente.
    - Interno: "Compartir" (WhatsApp, correo, PDF real, copiar enlace) desde el
      plan Profesional; si el plan no lo incluye, el aviso de siempre.
    - Público: solo "Descargar PDF".
    - Interno: fotos de antes y después de la visita (solo quien puede verlas).
    $deliveryNote, $doc, $audience, $pdfUrl (público), $photos (interno)
--}}
@php
    $audience = $audience ?? 'internal';
    // Compartir arma el enlace público (y su PDF) con el token del remito: sin
    // token (remitos muy viejos) no se ofrece, en vez de fallar la página.
    $planAllowsSharing = $deliveryNote->company?->plan()->allows(\App\Enums\PlanFeature::DigitalDeliveryNotes) ?? false;
    $canShareDigitally = $audience === 'internal' && filled($deliveryNote->public_token) && $planAllowsSharing;
    $color = $doc['company']['color'];
    $onColor = $doc['company']['on_color'];
@endphp
<x-delivery-note-layout :title="'Remito #'.$doc['number'].' · '.$doc['company']['name']">
    <style>
        .rm-page { min-height: 100vh; background: #F1F3F6; padding: 16px 16px calc(32px + env(safe-area-inset-bottom)); }
        .rm-wrap { max-width: 720px; margin: 0 auto; }
        .rm-actions { margin-top: 14px; display: grid; gap: 10px; }
        .rm-btn { display: flex; align-items: center; justify-content: center; gap: 8px; min-height: 48px; padding: 12px 16px; border-radius: 14px; font: 700 15px/1.2 system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; text-decoration: none; border: 0; cursor: pointer; width: 100%; }
        .rm-btn:focus-visible { outline: 3px solid #FF6600; outline-offset: 2px; }
        .rm-locked { margin-top: 14px; background: #fff; border: 1px solid #E7E9EE; border-radius: 14px; padding: 12px 14px; font: 14px/1.45 system-ui, sans-serif; color: #4B5563; }
        .rm-modal { display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(15,23,42,.72); }
        .rm-sheet { position: absolute; left: 0; right: 0; bottom: 0; margin: 0 auto; max-width: 440px; background: #fff; border-radius: 20px 20px 0 0; padding: 22px 18px calc(24px + env(safe-area-inset-bottom)); font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; }
        .rm-sheet-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
        .rm-sheet-head strong { font-size: 18px; color: #11161D; }
        .rm-close { width: 40px; height: 40px; border: 0; background: #F1F5F9; border-radius: 50%; cursor: pointer; font-size: 24px; color: #64748B; }
        .rm-option { display: flex; align-items: center; gap: 14px; padding: 14px; border: 1.5px solid #E2E8F0; border-radius: 14px; text-decoration: none; color: #11161D; font-weight: 700; font-size: 15px; background: #fff; width: 100%; cursor: pointer; margin-top: 10px; text-align: left; }
        .rm-option-icon { width: 42px; height: 42px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex: none; color: #fff; font-size: 20px; }
        @media print { .rm-actions, .rm-locked, .rm-modal { display: none !important; } .rm-page { background: #fff; padding: 0; } }
    </style>

    <main class="rm-page">
        <div class="rm-wrap">
            @include('delivery-notes.partials.document', ['doc' => $doc])

            @if ($canShareDigitally)
                <div class="rm-actions">
                    <button id="btn-share" type="button" class="rm-btn" style="background: {{ $color }}; color: {{ $onColor }};" aria-haspopup="dialog">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/></svg>
                        Compartir
                    </button>
                </div>

                <div id="share-modal" class="rm-modal" role="dialog" aria-modal="true" aria-labelledby="share-title">
                    <div class="rm-sheet">
                        <div class="rm-sheet-head">
                            <strong id="share-title">Compartir remito</strong>
                            <button id="share-close" type="button" class="rm-close" aria-label="Cerrar">&times;</button>
                        </div>
                        <a id="share-wpp" href="#" target="_blank" rel="noopener" class="rm-option"><span class="rm-option-icon" style="background:#25D366">💬</span> Enviar por WhatsApp</a>
                        <a id="share-mail" href="#" class="rm-option"><span class="rm-option-icon" style="background:#3B82F6">✉️</span> Enviar por correo</a>
                        {{-- El PDF del enlace público: el mismo que recibe el cliente (los técnicos no usan la ruta de admin). --}}
                        <a id="share-pdf" href="{{ route('delivery-notes.public.pdf', ['company' => $deliveryNote->company?->slug, 'token' => $deliveryNote->public_token]) }}" target="_blank" rel="noopener" class="rm-option"><span class="rm-option-icon" style="background:#EF4444">📄</span> Descargar PDF</a>
                        <button id="share-copy" type="button" class="rm-option"><span class="rm-option-icon" style="background:#64748B">🔗</span> <span id="copy-label">Copiar enlace</span></button>
                    </div>
                </div>

                <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        var pageURL = @json(route('delivery-notes.public', ['company' => $deliveryNote->company?->slug, 'token' => $deliveryNote->public_token]));
                        var noteNum = @json($doc['number']);
                        var date = @json($doc['date']);
                        var state = @json($doc['performed'] ? 'Realizado' : 'No realizado');
                        document.getElementById('share-wpp').href = 'https://wa.me/?text=' + encodeURIComponent('📄 *Remito de servicio*\nNúmero: #' + noteNum + '\nFecha: ' + date + '\n\n🔗 Ver remito:\n' + pageURL);
                        document.getElementById('share-mail').href = 'mailto:?subject=' + encodeURIComponent('Remito #' + noteNum) + '&body=' + encodeURIComponent('REMITO DE SERVICIO\n\nNúmero: #' + noteNum + '\nFecha: ' + date + '\nEstado: ' + state + '\n\nVer remito:\n' + pageURL + '\n');

                        var modal = document.getElementById('share-modal');
                        var open = function () { modal.style.display = 'block'; document.body.style.overflow = 'hidden'; document.getElementById('share-close').focus(); };
                        var close = function () { modal.style.display = 'none'; document.body.style.overflow = ''; document.getElementById('btn-share').focus(); };
                        document.getElementById('btn-share').addEventListener('click', open);
                        document.getElementById('share-close').addEventListener('click', close);
                        modal.addEventListener('click', function (e) { if (e.target === modal) { close(); } });
                        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal.style.display === 'block') { close(); } });

                        var label = document.getElementById('copy-label');
                        var copied = function () { label.textContent = '¡Enlace copiado!'; setTimeout(function () { label.textContent = 'Copiar enlace'; }, 2500); };
                        document.getElementById('share-copy').addEventListener('click', function () {
                            if (navigator.clipboard && navigator.clipboard.writeText) {
                                navigator.clipboard.writeText(pageURL).then(copied).catch(function () { window.prompt('Copiá el enlace:', pageURL); });
                            } else {
                                window.prompt('Copiá el enlace:', pageURL);
                            }
                        });
                    });
                </script>
            @elseif ($audience === 'public')
                <div class="rm-actions">
                    <a class="rm-btn" href="{{ $pdfUrl }}" target="_blank" rel="noopener" style="background: {{ $color }}; color: {{ $onColor }};">⬇ Descargar PDF</a>
                </div>
            @elseif (auth()->check() && $audience === 'internal' && ! $planAllowsSharing)
                <div class="rm-locked" data-plan-locked="digital_delivery_notes">
                    📄 Compartir el remito con el cliente (PDF, WhatsApp o correo) está disponible desde el plan <strong>Profesional</strong>.
                    @if (auth()->user()->isAdmin())
                        <a href="{{ \App\Support\Plans\PlanUpsell::url(feature: \App\Enums\PlanFeature::DigitalDeliveryNotes) }}" style="font-weight:600;text-decoration:underline">Ver planes</a>
                    @endif
                </div>
            @endif

            {{-- Fotos de antes y después: solo interno (nunca enlace público ni portal). --}}
            @if ($audience === 'internal' && isset($photos) && $photos->isNotEmpty())
                @include('delivery-notes.partials.visit-photos', ['photos' => $photos])
            @endif
        </div>
    </main>
</x-delivery-note-layout>

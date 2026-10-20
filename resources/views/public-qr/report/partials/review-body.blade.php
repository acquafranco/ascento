<div data-report-head>
    <p class="qr-step">Paso 2 de 2</p>
    <h2 style="margin:0 0 .3rem; font-size:1.3rem">Revisá tu reporte antes de enviarlo</h2>
    <p class="qr-hint" style="margin:0 0 1rem; font-size:.92rem">Todavía no se envió nada.</p>
</div>

<section class="qr-card" data-qr="summary">
    <dl class="qr-summary">
        <div><dt>Edificio</dt><dd>{{ $buildingName }}{{ $buildingAddress ? ' · '.$buildingAddress : '' }}</dd></div>
        @if($elevatorLabel)<div><dt>Ascensor</dt><dd>{{ $elevatorLabel }}</dd></div>@endif
        <div><dt>Qué notaste</dt><dd>{{ $categories[$old['category']] ?? '' }}</dd></div>
        <div><dt>Descripción</dt><dd>{{ $old['description'] }}</dd></div>
    </dl>
</section>

<form method="POST" action="{{ route('public-qr.report.store', ['token' => $page->token]) }}" enctype="multipart/form-data" class="qr-form" data-report-form>
    <input type="hidden" name="k" value="{{ $nonce }}">
    <input type="hidden" name="category" value="{{ $old['category'] }}">
    <input type="hidden" name="description" value="{{ $old['description'] }}">
    <input type="hidden" name="elevator_id" value="{{ $old['elevator_id'] ?? '' }}">
    {{-- Campo trampa: las personas no lo ven; si viene lleno, no se registra nada. --}}
    <div aria-hidden="true" style="position:absolute; left:-9999px; width:1px; height:1px; overflow:hidden">
        <label>No completar <input type="text" name="{{ \App\Http\Controllers\PublicQrReportController::HONEYPOT }}" tabindex="-1" autocomplete="off"></label>
    </div>

    <div class="qr-field">
        <label for="qr-photo">Foto (opcional)</label>
        <input id="qr-photo" type="file" name="foto" accept="image/jpeg,image/png,image/webp" class="qr-input" style="padding:.55rem">
        <p class="qr-hint">JPG, PNG o WebP, hasta 5 MB. Evitá que se vean personas.</p>
    </div>

    <div class="qr-actions">
        <button type="submit" class="qr-cta" data-report-submit>Enviar reporte</button>
        <button type="submit" class="qr-btn" formaction="{{ route('public-qr.report.review', ['token' => $page->token]) }}" formenctype="application/x-www-form-urlencoded" name="accion" value="corregir" formnovalidate>Corregir</button>
    </div>
    <p class="qr-status" role="status" aria-live="polite" data-report-status></p>
</form>

@include('public-qr.report.partials.safety')

<script>
(function () {
    var form = document.querySelector('[data-report-form]');
    if (!form || !window.fetch || !window.FormData) return; // sin JavaScript moderno: envío común
    var btn = form.querySelector('[data-report-submit]');
    var status = form.querySelector('[data-report-status]');
    var busy = false;
    form.addEventListener('submit', function (ev) {
        if (ev.submitter && ev.submitter !== btn) return; // «Corregir»: envío común
        ev.preventDefault();
        if (busy) return;
        busy = true;
        btn.disabled = true;
        btn.textContent = 'Enviando…';
        status.textContent = '';
        var ctrl = window.AbortController ? new AbortController() : null;
        var timer = setTimeout(function () { if (ctrl) ctrl.abort(); }, 30000);
        fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'Accept': 'application/json' }, signal: ctrl ? ctrl.signal : undefined, credentials: 'omit' })
            .then(function (r) { return r.json().then(function (data) { return { status: r.status, data: data }; }); })
            .then(function (res) {
                clearTimeout(timer);
                if (res.data && res.data.html) {
                    var wrap = document.createElement('div');
                    wrap.innerHTML = res.data.html;
                    form.parentNode.replaceChild(wrap, form);
                    // Ya hay un resultado real: el encabezado y el resumen «sin enviar» dejan de aplicar.
                    ['[data-report-head]', '[data-qr="summary"]'].forEach(function (sel) { var el = document.querySelector(sel); if (el) el.remove(); });
                    window.scrollTo(0, 0);
                    return;
                }
                if (res.data && res.data.errors) {
                    var first = Object.values(res.data.errors)[0];
                    throw new Error(first && first[0] ? first[0] : 'invalid');
                }
                throw new Error('unexpected');
            })
            .catch(function (err) {
                clearTimeout(timer);
                busy = false;
                btn.disabled = false;
                btn.textContent = 'Reintentar el envío';
                var msg = err && err.message && err.message !== 'unexpected' && err.message.indexOf('fetch') === -1 && err.name !== 'AbortError' && err.name !== 'TypeError' && err.name !== 'SyntaxError'
                    ? err.message
                    : 'No pudimos confirmar que tu reporte se haya registrado (sin conexión o sin respuesta). Podés reintentar: si ya se había registrado, no se duplica.';
                status.innerHTML = '<strong>' + msg.replace(/</g, '&lt;') + '</strong>';
            });
    });
})();
</script>

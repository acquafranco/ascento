{{--
    Pedido de ayuda por posible atrapamiento. Funciona sin JavaScript (detalles +
    formulario común); con JavaScript, se envía en segundo plano para mostrar
    «Enviando…», detectar si no hubo respuesta y reintentar con el MISMO
    comprobante (el servidor no duplica la solicitud).
--}}
<details class="qr-help" data-qr="help">
    <summary data-help-open>
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>
        Estoy atrapado en el ascensor
    </summary>
    <div class="qr-help-body">
        <p><strong>¿Confirmás que necesitás ayuda?</strong> Se avisa a la empresa de mantenimiento y a la administración del edificio. No hace falta cuenta ni datos personales.</p>
        <form method="POST" action="{{ $help['action'] }}" data-help-form>
            <input type="hidden" name="k" value="{{ $help['nonce'] }}">
            @if($help['elevators']->count() > 1)
                <fieldset class="qr-choice">
                    <legend>¿En qué ascensor? (opcional)</legend>
                    @foreach($help['elevators'] as $e)
                        <label><input type="radio" name="ascensor" value="{{ $e->id }}"> {{ $e->label }}</label>
                    @endforeach
                    <label><input type="radio" name="ascensor" value="" checked> No sé</label>
                </fieldset>
            @elseif($help['elevators']->count() === 1)
                <input type="hidden" name="ascensor" value="{{ $help['elevators']->first()->id }}">
            @endif
            <button type="submit" class="qr-btn" data-help-submit>Sí, pedir ayuda ahora</button>
        </form>
        <p class="qr-help-status" role="status" aria-live="polite" data-help-status></p>
        <p class="qr-safety">Si hay peligro inmediato, llamá al <strong>911</strong>. Usá también el botón de alarma o el intercomunicador del ascensor. Este pedido no reemplaza los procedimientos de emergencia del edificio.</p>
    </div>
</details>
<script>
(function () {
    var form = document.querySelector('[data-help-form]');
    if (!form || !window.fetch) return; // sin JavaScript moderno: envío común
    var btn = form.querySelector('[data-help-submit]');
    var status = document.querySelector('[data-help-status]');
    var busy = false;
    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        if (busy) return; // doble toque
        busy = true;
        btn.disabled = true;
        btn.textContent = 'Enviando… no cierres esta página';
        status.textContent = '';
        var ctrl = window.AbortController ? new AbortController() : null;
        var timer = setTimeout(function () { if (ctrl) ctrl.abort(); }, 25000);
        fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'Accept': 'application/json' }, signal: ctrl ? ctrl.signal : undefined, credentials: 'omit' })
            .then(function (r) {
                if (r.status === 429) { throw new Error('limit'); }
                return r.json();
            })
            .then(function (data) {
                clearTimeout(timer);
                if (!data || !data.html) { throw new Error('unexpected'); }
                var box = document.querySelector('[data-qr="help"]');
                var wrap = document.createElement('div');
                wrap.innerHTML = data.html;
                box.replaceWith(wrap.firstElementChild || wrap);
                window.scrollTo(0, 0);
            })
            .catch(function (err) {
                clearTimeout(timer);
                busy = false;
                btn.disabled = false;
                btn.textContent = 'Reintentar el pedido';
                status.innerHTML = err && err.message === 'limit'
                    ? '<strong>Hubo demasiados intentos desde esta conexión.</strong> Esperá un minuto y reintentá. Mientras tanto, usá la alarma del ascensor y, si hay peligro, llamá al 911.'
                    : '<strong>No pudimos confirmar que tu pedido se haya registrado</strong> (no hubo respuesta). Podés reintentar: si ya se había registrado, no se duplica. Mientras tanto, usá la alarma del ascensor y, si hay peligro, llamá al 911.';
            });
    });
})();
</script>

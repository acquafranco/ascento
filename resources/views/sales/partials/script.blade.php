{{--
    Calculadora sin recargar (opcional: sin JavaScript, todo funciona con
    formularios comunes). Pide el resultado al servidor (/calculadora/resultado),
    recuerda los datos en esta pestaña (sessionStorage, si se puede) y envía el
    pedido sin salir de la página. Respeta «reducir movimiento».
--}}
<script>
(function () {
    var root = document.querySelector('[data-calc-root]');
    if (!root || !window.fetch) return;
    var form = root.querySelector('[data-calc-form]');
    var out = root.querySelector('[data-calc-output]');
    var resultUrl = root.getAttribute('data-result-url');
    var KEY = 'ascento.calculadora';
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function store(params) { try { sessionStorage.setItem(KEY, params.toString()); } catch (e) {} }
    function restore() {
        try {
            var saved = sessionStorage.getItem(KEY);
            if (!saved) return;
            new URLSearchParams(saved).forEach(function (v, k) { var el = form.elements[k]; if (el && !el.value) el.value = v; });
        } catch (e) {}
    }

    function load(params, focus) {
        out.setAttribute('aria-busy', 'true');
        out.innerHTML = '<p class="text-sm text-ink/60" role="status">Calculando…</p>';
        fetch(resultUrl + '?' + params.toString(), { headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                out.innerHTML = html;
                out.removeAttribute('aria-busy');
                store(params);
                bind();
                var res = out.querySelector('[data-calc-result]') || out.firstElementChild;
                if (focus && res) { res.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' }); res.focus && res.focus({ preventScroll: true }); }
            })
            .catch(function () {
                out.removeAttribute('aria-busy');
                out.innerHTML = '<p class="text-sm font-medium text-red-700" role="alert">No pudimos calcular ahora (¿sin conexión?). Probá de nuevo.</p>';
            });
    }

    function paramsOf(f) { return new URLSearchParams(new FormData(f)); }

    function bind() {
        var opts = out.querySelector('[data-calc-options]');
        if (opts) opts.addEventListener('change', function () { load(paramsOf(opts), false); });

        out.querySelectorAll('[data-lead-form]').forEach(function (lead) {
            lead.addEventListener('submit', function (ev) {
                ev.preventDefault();
                var btn = lead.querySelector('button[type="submit"]');
                var err = lead.querySelector('[data-lead-error]');
                btn.disabled = true; btn.textContent = 'Enviando…'; err.hidden = true;
                fetch(lead.action, { method: 'POST', body: new FormData(lead), headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                    .then(function (r) {
                        if (r.status === 422) return r.json().then(function (d) { throw new Error(Object.values(d.errors || {})[0] || 'Revisá los datos.'); });
                        if (!r.ok) throw new Error('No pudimos enviar el pedido. Probá de nuevo en unos minutos.');
                        return r.text();
                    })
                    .then(function (html) { lead.closest('[data-lead-box]').outerHTML = html; })
                    .catch(function (e) {
                        btn.disabled = false; btn.textContent = 'Enviar pedido';
                        err.textContent = e && e.message && e.message !== 'Failed to fetch' ? e.message : 'No pudimos confirmar el envío (¿sin conexión?). Podés reintentar: no se duplica.';
                        err.hidden = false;
                    });
            });
        });
    }

    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        if (!form.reportValidity()) return;
        load(paramsOf(form), true);
    });

    restore();
    bind();
})();
</script>

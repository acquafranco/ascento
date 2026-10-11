{{--
    Avisos en tiempo real para técnicos y clientes del portal (Reverb + Echo).
    - Escucha el canal privado del usuario (autorizado por el servidor).
    - Actualiza los contadores [data-unread-count] y muestra el aviso nuevo.
    - Al reconectarse, sincroniza el contador (lo que llegó mientras tanto).
    - Respaldo: si no hay conexión en tiempo real, consulta cada 60 s.
    Parámetros: $countUrl, $inboxUrl.
--}}
@php($echoConfig = \App\Support\Realtime::echoConfig())
@if($echoConfig)
    <script src="{{ asset('js/filament/filament/echo.js') }}"></script>
@endif
<div id="asc-live" role="status" aria-live="polite" style="position: fixed; left: 50%; bottom: 96px; transform: translateX(-50%); z-index: 60; width: min(92vw, 420px); display: none;"></div>
<script>
    (function () {
        var countUrl = @json($countUrl), inboxUrl = @json($inboxUrl), cfg = @json($echoConfig);
        var channel = @json('App.Models.User.'.auth()->id()), csrf = @json(csrf_token());
        var live = false;

        function setCount(n) {
            document.querySelectorAll('[data-unread-count]').forEach(function (el) {
                el.textContent = n > 99 ? '99+' : n;
                el.hidden = n === 0;
            });
        }
        function refresh() {
            fetch(countUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (d) { if (d) setCount(d.unread); })
                .catch(function () {});
        }
        function show(n) {
            var box = document.getElementById('asc-live');
            var esc = function (s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; };
            box.innerHTML = '<a href="' + inboxUrl + '" style="display:block;text-decoration:none;background:#12151C;color:#F7F7F4;border-radius:14px;padding:12px 16px;box-shadow:0 12px 30px rgba(18,21,28,.35);border-left:4px solid #FF6A1A;font:14px/1.4 system-ui,-apple-system,sans-serif">'
                + '<strong style="display:block;font-size:15px">' + esc(n.title) + '</strong><span style="opacity:.8">' + esc(n.body) + '</span></a>';
            box.style.display = 'block';
            clearTimeout(box._t);
            box._t = setTimeout(function () { box.style.display = 'none'; }, 7000);
        }

        if (cfg && window.EchoFactory) {
            try {
                cfg.auth = { headers: { 'X-CSRF-TOKEN': csrf } };
                window.Echo = window.Echo || new window.EchoFactory(cfg);
                window.Echo.private(channel).listen('.database-notifications.sent', function (e) {
                    setCount(e.unread);
                    if (e.notification) {
                        if (location.pathname === new URL(inboxUrl, location.href).pathname) { location.reload(); return; }
                        show(e.notification);
                    }
                });
                var conn = window.Echo.connector.pusher.connection;
                conn.bind('state_change', function (s) { live = s.current === 'connected'; });
                conn.bind('connected', refresh); // al (re)conectar: lo que llegó mientras tanto
            } catch (err) {}
        }

        setInterval(function () { if (!live && !document.hidden) refresh(); }, 60000);
        document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(); });
    })();
</script>

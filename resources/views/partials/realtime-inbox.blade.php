{{--
    Avisos en tiempo real para técnicos y clientes del portal (Reverb + Echo).
    - Escucha el canal privado del usuario (autorizado por el servidor).
    - Actualiza los contadores [data-unread-count] y muestra el aviso nuevo.
    - Al reconectarse, sincroniza el contador (lo que llegó mientras tanto).
    - Respaldo: si no hay conexión en tiempo real, consulta cada 60 s.
    - Contadores de trabajo (inicio y "Trabajos" del técnico): si la página
      tiene [data-tech-counters], los vuelve a pedir al servidor cuando llega
      "dashboard.counters" o un aviso (p. ej. una orden asignada), al
      reconectar y al volver a la pestaña. Nunca suma localmente: un evento
      repetido o perdido no deja un número mal.
    Parámetros: $countUrl, $inboxUrl.
--}}
@php($echoConfig = \App\Support\Realtime::echoConfig())
@if($echoConfig)
    <script src="{{ asset('js/filament/filament/echo.js') }}"></script>
@endif
<div id="asc-live" role="status" aria-live="polite" style="position: fixed; left: 50%; bottom: calc(96px + env(safe-area-inset-bottom, 0px)); transform: translateX(-50%); z-index: 60; width: min(92vw, 420px); display: none;"></div>
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
        var countersTimer = null;
        function refreshCounters() {
            // Se busca cada vez: este script corre antes de que exista el contenido.
            var countersBox = document.querySelector('[data-tech-counters]');
            if (!countersBox) return;
            clearTimeout(countersTimer);
            countersTimer = setTimeout(function () {
                fetch(countersBox.dataset.countsUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                    .then(function (r) { return r.ok ? r.json() : null; })
                    .then(function (d) {
                        if (!d) return;
                        document.querySelectorAll('[data-count]').forEach(function (el) {
                            var key = el.dataset.count;
                            if (key in d && String(d[key]) !== el.textContent.trim()) {
                                el.textContent = d[key];
                                el.animate && el.animate([{ transform: 'scale(1.25)' }, { transform: 'scale(1)' }], { duration: 300 });
                            }
                        });
                    })
                    .catch(function () {});
            }, 250);
        }
        function show(n) {
            var box = document.getElementById('asc-live');
            var esc = function (s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; };
            box.innerHTML = '<a href="' + inboxUrl + '" style="display:block;text-decoration:none;background:#11161D;color:#FFFFFF;border-radius:14px;padding:12px 16px;box-shadow:0 12px 30px rgba(18,21,28,.35);border-left:4px solid #FF6600;font:14px/1.4 system-ui,-apple-system,sans-serif">'
                + '<strong style="display:block;font-size:15px">' + esc(n.title) + '</strong><span style="opacity:.8">' + esc(n.body) + '</span></a>';
            box.style.display = 'block';
            clearTimeout(box._t);
            box._t = setTimeout(function () { box.style.display = 'none'; }, 7000);
        }

        if (cfg && window.EchoFactory) {
            try {
                cfg.auth = { headers: { 'X-CSRF-TOKEN': csrf } };
                window.Echo = window.Echo || new window.EchoFactory(cfg);
                window.Echo.private(channel).listen('.dashboard.counters', refreshCounters);
                window.Echo.private(channel).listen('.database-notifications.sent', function (e) {
                    setCount(e.unread);
                    refreshCounters();
                    if (e.notification) {
                        if (location.pathname === new URL(inboxUrl, location.href).pathname) { location.reload(); return; }
                        show(e.notification);
                    }
                });
                var conn = window.Echo.connector.pusher.connection;
                conn.bind('state_change', function (s) { live = s.current === 'connected'; });
                conn.bind('connected', function () { refresh(); refreshCounters(); }); // al (re)conectar: lo que llegó mientras tanto
            } catch (err) {}
        }

        // Se acaban de marcar avisos como vistos en la bandeja: el número de
        // esta página pudo salir antes de que el servidor los registrara.
        try {
            if (sessionStorage.getItem('ascento-seen-pending')) {
                sessionStorage.removeItem('ascento-seen-pending');
                setTimeout(refresh, 700);
            }
        } catch (e) {}

        setInterval(function () { if (!live && !document.hidden) { refresh(); refreshCounters(); } }, 60000);
        document.addEventListener('visibilitychange', function () { if (!document.hidden) { refresh(); refreshCounters(); } });
        // Volver con "atrás" muestra la página guardada: el contador se vuelve a pedir.
        window.addEventListener('pageshow', function (e) { if (e.persisted) { refresh(); refreshCounters(); } });
    })();
</script>

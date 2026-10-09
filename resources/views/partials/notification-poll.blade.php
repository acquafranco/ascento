{{-- Actualiza los contadores [data-unread-count] cada 60 s (sin WebSockets). --}}
<script>
    (function () {
        var url = @json($url);
        function update() {
            fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (d) {
                    if (!d) return;
                    document.querySelectorAll('[data-unread-count]').forEach(function (el) {
                        el.textContent = d.unread > 99 ? '99+' : d.unread;
                        el.hidden = d.unread === 0;
                    });
                })
                .catch(function () {});
        }
        setInterval(function () { if (!document.hidden) update(); }, 60000);
        document.addEventListener('visibilitychange', function () { if (!document.hidden) update(); });
    })();
</script>

{{--
    Bandeja de técnicos y clientes: misma regla que la campanita del panel.
    Los avisos sin leer que estuvieron en pantalla (al menos la mitad visible)
    se marcan leídos al salir de la página o cambiar de pestaña; los que no se
    vieron quedan sin leer. Se manda con keepalive para que el envío no se
    corte al navegar. Parámetro: $seenUrl.
--}}
<script>
    (function () {
        var url = @json($seenUrl), csrf = @json(csrf_token())
        // Volver con "atrás" a la bandeja: se recarga para mostrar lo ya leído.
        window.addEventListener('pageshow', function (e) { if (e.persisted) { location.reload() } })
        var items = document.querySelectorAll('[data-notification-unread]')
        if (!items.length || !('IntersectionObserver' in window)) { return }

        var seen = new Set()
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (e) {
                if (e.isIntersecting && e.intersectionRatio >= 0.5) { seen.add(e.target.dataset.notificationId) }
            })
        }, { threshold: [0.5] })
        items.forEach(function (el) { io.observe(el) })

        function flush() {
            if (!seen.size) { return }
            var ids = Array.from(seen)
            seen = new Set()
            // La página siguiente puede dibujarse antes de que llegue este envío:
            // le avisa que vuelva a pedir el contador (ver realtime-inbox).
            try { sessionStorage.setItem('ascento-seen-pending', '1') } catch (e) {}
            try {
                fetch(url, {
                    method: 'POST', keepalive: true, credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                    body: JSON.stringify({ ids: ids }),
                })
            } catch (e) {}
        }
        window.addEventListener('pagehide', flush)
        document.addEventListener('visibilitychange', function () { if (document.hidden) { flush() } })
    })()
</script>

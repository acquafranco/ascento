{{--
    Campanita del panel: "visto" = estuvo en pantalla mientras estaba abierta.
    - Al abrirla se observan los avisos sin leer (IntersectionObserver, al
      menos la mitad visible). Los que nunca se vieron (más abajo, sin
      desplazar) no se tocan.
    - Al cerrarla (X, clic afuera o Esc) se mandan esos ids al componente
      (App\Livewire\DatabaseNotifications::markSeen), que solo marca avisos
      del usuario autenticado.
    - Al volver a la pestaña se actualiza el contador (lo que se leyó en otra).
--}}
<script>
    (function () {
        if (window.__ascentoBellSeen) { return }
        window.__ascentoBellSeen = true

        var MODAL = 'database-notifications'
        var seen = new Set(), io = null, mo = null

        function idOf(el) { return el.dataset.notificationId || '' }
        function observeAll() {
            document.querySelectorAll('[data-bell-notification][data-notification-unread]').forEach(function (el) {
                if (!el.__ascentoObserved) { el.__ascentoObserved = true; io.observe(el) }
            })
        }
        function stop() {
            io && io.disconnect(); mo && mo.disconnect()
            io = mo = null
            document.querySelectorAll('[data-bell-notification]').forEach(function (el) { el.__ascentoObserved = false })
        }
        function start() {
            stop()
            seen = new Set()
            io = new IntersectionObserver(function (entries) {
                entries.forEach(function (e) {
                    if (e.isIntersecting && e.intersectionRatio >= 0.5) {
                        var id = idOf(e.target)
                        if (id) { seen.add(id) }
                    }
                })
            }, { threshold: [0.5] })
            observeAll()
            // La lista se vuelve a dibujar (página siguiente, aviso nuevo): se observa lo nuevo.
            mo = new MutationObserver(observeAll)
            mo.observe(document.body, { childList: true, subtree: true })
        }

        document.addEventListener('x-modal-opened', function (e) {
            if (e.detail && e.detail.id === MODAL) { start() }
        })
        window.addEventListener('modal-closed', function (e) {
            if (!e.detail || e.detail.id !== MODAL) { return }
            var ids = Array.from(seen)
            stop()
            seen = new Set()
            if (ids.length && window.Livewire) {
                window.Livewire.dispatch(@js(\App\Livewire\DatabaseNotifications::SEEN_EVENT), { ids: ids })
            }
        })

        function refreshBell() { window.Livewire && window.Livewire.dispatch('databaseNotificationsSent') }
        document.addEventListener('visibilitychange', function () { if (!document.hidden) { refreshBell() } })
        window.addEventListener('pageshow', function (e) { if (e.persisted) { refreshBell() } })
    })()
</script>

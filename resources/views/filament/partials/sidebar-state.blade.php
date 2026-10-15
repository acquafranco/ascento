{{--
    Categorías plegables del menú (solo visual: los permisos los sigue
    controlando el servidor en cada pantalla).

    - El menú arranca compacto: las categorías cerradas, salvo la de la
      pantalla actual y una que tenga un pedido de ayuda abierto.
    - Se recuerdan las categorías que la persona abrió, en ESTE navegador, con
      una clave propia de usuario y panel (un técnico, otro administrativo u
      otra empresa en la misma computadora no comparten la preferencia). Solo
      se guardan los nombres de las categorías abiertas.
    - Filament guarda el estado en una clave común («collapsedGroups»): acá se
      reescribe en cada carga a partir de la preferencia de esta persona.
    - Sin almacenamiento disponible: todo funciona, solo que no se recuerda.

    Va al final del menú (SIDEBAR_NAV_END): las categorías ya están en la
    página y Alpine todavía no leyó el estado.
--}}
<style>
    .fi-sidebar-nav-groups { row-gap: 1rem; }
    .fi-sidebar-group.fi-collapsible > .fi-sidebar-group-btn { cursor: pointer; border-radius: .5rem; }
    .fi-sidebar-group.fi-collapsible > .fi-sidebar-group-btn:hover { background: rgba(148, 163, 184, .12); }
    .fi-sidebar-group.fi-collapsible .fi-sidebar-group-label { font-weight: 600; letter-spacing: .01em; }
    .fi-sidebar-group.fi-collapsible .fi-sidebar-group-collapse-btn:focus-visible { outline: 2px solid var(--primary-500, #f59e0b); outline-offset: 2px; }
    .fi-sidebar-group.fi-collapsible .fi-sidebar-group-collapse-btn svg { transition: transform .15s ease; }
    .asc-nav-count { display: none; margin-left: auto; margin-right: .25rem; min-width: 1.25rem; padding: 0 .35rem; height: 1.25rem; border-radius: 9999px; font-size: .7rem; font-weight: 700; line-height: 1.25rem; text-align: center; background: rgba(148, 163, 184, .2); }
    .asc-nav-count.is-urgent { background: #dc2626; color: #fff; }
    .fi-sidebar-group.fi-collapsed .asc-nav-count { display: inline-block; }
    @media (prefers-reduced-motion: reduce) { .fi-sidebar-group.fi-collapsible .fi-sidebar-group-collapse-btn svg { transition: none; } }
</style>
<script>
    (() => {
        const KEY = @js($storageKey);
        const URGENT = @js($urgentUrls);
        const read = () => { try { const v = JSON.parse(window.localStorage.getItem(KEY)); return Array.isArray(v) ? v.filter((x) => typeof x === 'string') : [] } catch (e) { return [] } }
        const write = (open) => { try { window.localStorage.setItem(KEY, JSON.stringify(open)) } catch (e) {} }

        const groups = [...document.querySelectorAll('.fi-main-sidebar .fi-sidebar-group.fi-collapsible[data-group-label]')]
            .filter((g) => g.dataset.groupLabel !== '' && g.querySelector('.fi-sidebar-group-items'))
        const urgent = (g) => URGENT.some((url) => [...g.querySelectorAll('a[href]')].some((a) => a.getAttribute('href') === url && a.querySelector('.fi-badge')))
        const open = read()
        const collapsed = []

        groups.forEach((g) => {
            const label = g.dataset.groupLabel
            const items = g.querySelector('.fi-sidebar-group-items')
            const isUrgent = urgent(g)
            // Cuántas opciones con aviso (número rojo) quedan adentro: se ve en la categoría cerrada.
            const badges = g.querySelectorAll('.fi-sidebar-item .fi-badge').length
            const btn = g.querySelector('.fi-sidebar-group-btn')
            if (badges && btn && ! btn.querySelector('.asc-nav-count')) {
                const count = document.createElement('span')
                count.className = 'asc-nav-count' + (isUrgent ? ' is-urgent' : '')
                count.textContent = badges
                count.title = badges === 1 ? 'Una opción con avisos' : badges + ' opciones con avisos'
                count.setAttribute('aria-label', count.title)
                btn.querySelector('.fi-sidebar-group-label')?.after(count)
            }
            const show = open.includes(label) || g.classList.contains('fi-active') || isUrgent
            if (! show) { collapsed.push(label) }
            items.style.display = show ? '' : 'none'
            g.classList.toggle('fi-collapsed', ! show)
        })

        // Alpine toma este estado al iniciar (no se guarda nada de otra persona).
        try { window.localStorage.setItem('collapsedGroups', JSON.stringify(collapsed)) } catch (e) {}
        window.ascentoNavInitialCollapsed = collapsed

        // Lo que abre o cierra la persona (clic, Enter o Espacio en la categoría) se recuerda.
        // En captura: el botón de Filament frena la propagación del clic.
        document.addEventListener('click', (event) => {
            const btn = event.target.closest?.('.fi-main-sidebar .fi-sidebar-group-btn')
            const group = btn?.closest('.fi-sidebar-group.fi-collapsible')
            if (! group) { return }
            setTimeout(() => {
                const label = group.dataset.groupLabel
                const isOpen = ! window.Alpine?.store('sidebar')?.groupIsCollapsed(label)
                const saved = read().filter((x) => x !== label)
                write(isOpen ? saved.concat(label) : saved)
            }, 0)
        }, true)
    })()
</script>

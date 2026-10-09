{{--
    Estilos de Agenda, Centro de atención, Indicadores, legajo y ayudas.
    El panel usa el CSS compilado de Filament (sin tema propio), así que estas
    vistas traen su propio CSS, como la guía de bienvenida. Prefijo asc-.
--}}
@once
<style>
    .asc-stack > * + * { margin-top: 1rem; }
    .asc-h2 { font-size: 1rem; font-weight: 700; margin: 0 0 .5rem; }
    .asc-muted { color: #6b7280; }
    .asc-small { font-size: .75rem; }
    .asc-link { color: inherit; text-decoration: none; }
    .asc-link:hover { text-decoration: underline; }
    .dark .asc-muted { color: #9ca3af; }

    /* Ayuda contextual */
    .asc-tip { display: flex; gap: .75rem; align-items: flex-start; margin-bottom: 1rem; padding: .75rem 1rem; border-radius: .75rem; border: 1px solid #fcd34d; background: #fffbeb; color: #78350f; font-size: .875rem; }
    .asc-tip-title { font-weight: 600; margin: 0; }
    .asc-tip-body { margin: .15rem 0 0; opacity: .85; }
    .asc-tip-icon { font-size: 1.1rem; line-height: 1; }
    .dark .asc-tip { background: rgba(245, 158, 11, .1); border-color: rgba(245, 158, 11, .35); color: #fde68a; }
    .asc-btn { flex-shrink: 0; cursor: pointer; padding: .4rem .8rem; border-radius: .5rem; border: 1px solid #fcd34d; background: #fff; color: #78350f; font-size: .75rem; font-weight: 600; }
    .asc-btn:hover { background: #fef3c7; }
    .dark .asc-btn { background: rgba(255,255,255,.08); color: #fde68a; border-color: rgba(245,158,11,.35); }

    /* Tarjetas de indicadores */
    .asc-grid { display: grid; gap: .75rem; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); }
    .asc-grid-2 { display: grid; gap: .75rem; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); }
    .asc-card { padding: .85rem 1rem; border-radius: .75rem; border: 1px solid #e5e7eb; background: #fff; }
    .dark .asc-card { border-color: rgba(255,255,255,.1); background: rgba(255,255,255,.04); }
    .asc-q { font-size: .875rem; color: #4b5563; margin: 0; }
    .dark .asc-q { color: #9ca3af; }
    .asc-v { font-size: 1.75rem; font-weight: 700; margin: .25rem 0 0; line-height: 1.2; }
    .asc-c { font-size: .75rem; color: #6b7280; margin: .15rem 0 0; }
    .asc-row { display: flex; justify-content: space-between; gap: .75rem; font-size: .875rem; margin-top: .3rem; }

    /* Centro de atención */
    .asc-item { display: flex; justify-content: space-between; gap: .75rem; align-items: flex-start; padding: .75rem 1rem; border-radius: .75rem; border: 1px solid #e5e7eb; background: #fff; color: inherit; text-decoration: none; }
    .asc-item + .asc-item { margin-top: .6rem; }
    .asc-item:hover { box-shadow: 0 1px 4px rgba(0,0,0,.08); }
    .asc-item-title { font-weight: 600; margin: 0; }
    .asc-item-detail { font-size: .875rem; color: #4b5563; margin: .15rem 0 0; }
    .asc-count { font-size: 1.5rem; font-weight: 700; }
    .asc-item.is-danger { border-color: #fecaca; background: #fef2f2; }
    .asc-item.is-danger .asc-count { color: #b91c1c; }
    .asc-item.is-warning { border-color: #fde68a; background: #fffbeb; }
    .asc-item.is-warning .asc-count { color: #b45309; }
    .dark .asc-item { background: rgba(255,255,255,.04); border-color: rgba(255,255,255,.1); }
    .dark .asc-item.is-danger { background: rgba(239,68,68,.1); border-color: rgba(239,68,68,.35); }
    .dark .asc-item.is-warning { background: rgba(245,158,11,.1); border-color: rgba(245,158,11,.35); }
    .dark .asc-item-detail { color: #9ca3af; }
    .asc-ok { padding: .75rem 1rem; border-radius: .75rem; border: 1px solid #bbf7d0; background: #f0fdf4; color: #166534; font-size: .875rem; }
    .dark .asc-ok { background: rgba(34,197,94,.1); border-color: rgba(34,197,94,.3); color: #86efac; }
    .asc-locked { padding: .75rem 1rem; border-radius: .75rem; border: 1px dashed #d1d5db; color: #4b5563; font-size: .875rem; }
    .asc-locked a { font-weight: 600; color: #d97706; text-decoration: none; margin-left: .25rem; }
    .dark .asc-locked { border-color: rgba(255,255,255,.15); color: #9ca3af; }

    /* Tablas y estados */
    .asc-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
    .asc-table th { text-align: left; font-size: .7rem; text-transform: uppercase; letter-spacing: .03em; color: #6b7280; padding: .4rem .6rem; }
    .asc-table td { padding: .55rem .6rem; border-top: 1px solid #f3f4f6; vertical-align: top; }
    .dark .asc-table td { border-color: rgba(255,255,255,.06); }
    .asc-scroll { overflow-x: auto; }
    .asc-pill { display: inline-block; padding: .1rem .55rem; border-radius: 999px; font-size: .75rem; font-weight: 600; background: #f3f4f6; color: #374151; border: 0; }
    .asc-pill.is-done { background: #dcfce7; color: #166534; }
    .asc-pill.is-overdue, .asc-pill.is-unassigned, .asc-pill.is-not_done { background: #fee2e2; color: #991b1b; }
    .asc-pill.is-pending { background: #fef3c7; color: #92400e; }
    .dark .asc-pill { background: rgba(255,255,255,.1); color: #d1d5db; }
    .dark .asc-pill.is-done { background: rgba(34,197,94,.15); color: #86efac; }
    .dark .asc-pill.is-overdue, .dark .asc-pill.is-unassigned, .dark .asc-pill.is-not_done { background: rgba(239,68,68,.15); color: #fca5a5; }
    .dark .asc-pill.is-pending { background: rgba(245,158,11,.15); color: #fcd34d; }
    button.asc-pill { cursor: pointer; padding: .3rem .8rem; font-size: .8rem; }
    .asc-pills { display: flex; flex-wrap: wrap; gap: .5rem; }

    /* Filtros */
    .asc-filters { display: grid; gap: .6rem; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); }
    .asc-select { width: 100%; padding: .5rem .7rem; border-radius: .5rem; border: 1px solid #d1d5db; background: #fff; font-size: .875rem; color: inherit; }
    .dark .asc-select { background: rgba(255,255,255,.05); border-color: rgba(255,255,255,.15); }

    /* Historial del ascensor */
    .asc-stats { display: grid; gap: .6rem; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); }
    .asc-stat { padding: .55rem .8rem; border-radius: .6rem; background: #f9fafb; font-size: .8rem; color: #6b7280; }
    .asc-stat b { display: block; font-size: 1.25rem; color: #111827; }
    .dark .asc-stat { background: rgba(255,255,255,.05); }
    .dark .asc-stat b { color: #f3f4f6; }
    .asc-events { list-style: none; margin: 0; padding: 0; }
    .asc-event { display: flex; gap: .7rem; padding: .55rem .75rem; border: 1px solid #f3f4f6; border-radius: .6rem; font-size: .875rem; }
    .asc-event + .asc-event { margin-top: .45rem; }
    .dark .asc-event { border-color: rgba(255,255,255,.06); }
    .asc-event-main { flex: 1; min-width: 0; }
    .asc-event-date { flex-shrink: 0; font-size: .75rem; color: #6b7280; }
    .asc-tag { display: inline-block; margin-left: .35rem; padding: 0 .4rem; border-radius: .3rem; background: #f3f4f6; font-size: .7rem; color: #4b5563; }
    .dark .asc-tag { background: rgba(255,255,255,.1); color: #d1d5db; }
    .asc-alert { padding: .75rem 1rem; border-radius: .75rem; border: 1px solid #e5e7eb; font-size: .875rem; }
    .asc-alert.is-danger { border-color: #fecaca; background: #fef2f2; }
    .dark .asc-alert { border-color: rgba(255,255,255,.1); }
    .dark .asc-alert.is-danger { background: rgba(239,68,68,.1); border-color: rgba(239,68,68,.35); }
</style>
@endonce

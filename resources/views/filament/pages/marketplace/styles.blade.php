{{-- Estilos del marketplace en el panel (sin tema propio de Filament). Prefijo mk-. --}}
@once
<style>
    .mk-stack > * + * { margin-top: 1rem; }
    .mk-card { border: 1px solid #e5e7eb; border-radius: .9rem; background: #fff; padding: 1rem; }
    .dark .mk-card { border-color: rgba(255,255,255,.1); background: rgba(255,255,255,.04); }
    .mk-muted { color: #6b7280; font-size: .8rem; }
    .dark .mk-muted { color: #9ca3af; }
    .mk-filters { display: grid; gap: .6rem; grid-template-columns: repeat(auto-fit, minmax(min(100%, 160px), 1fr)); align-items: end; }
    .mk-filters label { display: block; font-size: .75rem; font-weight: 600; color: #4b5563; margin-bottom: .2rem; }
    .dark .mk-filters label { color: #d1d5db; }
    .mk-input { width: 100%; border: 1px solid #d1d5db; border-radius: .6rem; padding: .5rem .65rem; font-size: .875rem; background: #fff; color: #11161D; }
    .dark .mk-input { background: rgba(255,255,255,.06); border-color: rgba(255,255,255,.15); color: #f3f4f6; }
    .mk-grid { display: grid; gap: .9rem; grid-template-columns: repeat(auto-fill, minmax(min(100%, 230px), 1fr)); }
    .mk-product { display: flex; flex-direction: column; overflow: hidden; padding: 0; }
    .mk-product .mk-img { aspect-ratio: 4 / 3; background: #f3f4f6; display: flex; align-items: center; justify-content: center; color: #9ca3af; font-size: .8rem; }
    .dark .mk-product .mk-img { background: rgba(255,255,255,.06); }
    .mk-product .mk-img img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .mk-product .mk-body { padding: .8rem .9rem 1rem; display: flex; flex-direction: column; gap: .3rem; flex: 1; }
    .mk-title { font-weight: 700; font-size: .95rem; line-height: 1.25; color: inherit; text-decoration: none; }
    .mk-title:hover { text-decoration: underline; }
    .mk-price { font-weight: 700; font-size: 1.05rem; }
    .mk-pill { display: inline-block; padding: .1rem .55rem; border-radius: 999px; font-size: .72rem; font-weight: 600; background: #f3f4f6; color: #374151; }
    .mk-pill.ok { background: #dcfce7; color: #166534; } .mk-pill.warn { background: #fff1e6; color: #a84300; }
    .mk-pill.bad { background: #fee2e2; color: #991b1b; } .mk-pill.info { background: #e0e7ff; color: #1e3a8a; }
    .mk-pill.featured { background: #FF6600; color: #11161D; }
    .dark .mk-pill { background: rgba(255,255,255,.1); color: #e5e7eb; }
    .dark .mk-pill.ok { background: rgba(34,197,94,.15); color: #86efac; } .dark .mk-pill.bad { background: rgba(239,68,68,.15); color: #fca5a5; }
    .dark .mk-pill.warn { background: rgba(255,102,0,.15); color: #fdba74; } .dark .mk-pill.info { background: rgba(99,102,241,.18); color: #c7d2fe; }
    .mk-actions { display: flex; flex-wrap: wrap; gap: .4rem; margin-top: auto; padding-top: .5rem; }
    .mk-btn { display: inline-flex; align-items: center; justify-content: center; gap: .35rem; min-height: 2.4rem; padding: .45rem .85rem; border-radius: .6rem; font-size: .82rem; font-weight: 700; cursor: pointer; border: 0; background: #FF6600; color: #11161D; text-decoration: none; }
    .mk-btn:hover { background: #ff7a22; }
    .mk-btn.secondary { background: #fff; color: #11161D; border: 1px solid #d1d5db; font-weight: 600; }
    .mk-btn.dark { background: #11161D; color: #fff; }
    .mk-btn.danger { background: #fff; color: #991b1b; border: 1px solid #fecaca; font-weight: 600; }
    .dark .mk-btn.secondary { background: rgba(255,255,255,.06); color: #f3f4f6; border-color: rgba(255,255,255,.15); }
    .dark .mk-btn.danger { background: rgba(239,68,68,.08); color: #fca5a5; border-color: rgba(239,68,68,.35); }
    .mk-btn[aria-pressed="true"] { outline: 2px solid #FF6600; outline-offset: 1px; }
    .mk-link { color: #b34700; font-weight: 600; text-decoration: none; }
    .mk-link:hover { text-decoration: underline; }
    .dark .mk-link { color: #fdba74; }
    .mk-note { border: 1px solid #fed7aa; background: #fff7ed; color: #7c2d12; border-radius: .75rem; padding: .65rem .85rem; font-size: .82rem; }
    .dark .mk-note { background: rgba(255,102,0,.08); border-color: rgba(255,102,0,.3); color: #fed7aa; }
    .mk-bar { position: sticky; bottom: .75rem; z-index: 10; display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; justify-content: space-between; background: #11161D; color: #fff; border-radius: .9rem; padding: .6rem .8rem; margin-top: 1rem; box-shadow: 0 10px 30px -12px rgba(0,0,0,.45); }
    .mk-table { width: 100%; border-collapse: collapse; font-size: .85rem; }
    .mk-table th { text-align: left; font-size: .72rem; text-transform: uppercase; letter-spacing: .03em; color: #6b7280; padding: .45rem .6rem; vertical-align: bottom; }
    .mk-table td { padding: .55rem .6rem; border-top: 1px solid #f3f4f6; vertical-align: top; }
    .dark .mk-table td { border-color: rgba(255,255,255,.06); }
    .mk-scroll { overflow-x: auto; }
    .mk-photos { display: grid; gap: .5rem; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); }
    .mk-photos img { width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: .6rem; display: block; }
    .mk-two { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(min(100%, 320px), 1fr)); align-items: start; }
    .mk-dl { display: grid; grid-template-columns: max-content 1fr; gap: .3rem .9rem; font-size: .875rem; margin: 0; }
    .mk-dl dt { color: #6b7280; } .dark .mk-dl dt { color: #9ca3af; }
    .mk-dl dd { margin: 0; }
    .mk-h2 { font-size: 1rem; font-weight: 700; margin: 0 0 .6rem; }
    .mk-err { color: #b91c1c; font-size: .8rem; margin-top: .25rem; }
    @media (prefers-reduced-motion: reduce) { .mk-card, .mk-btn { transition: none !important; } }
</style>
@endonce

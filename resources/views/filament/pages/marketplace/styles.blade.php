{{-- Estilos del marketplace en el panel (sin tema propio de Filament). Prefijo mk-. Naranja #FF6600, oscuro #11161D. --}}
@once
<style>
    .mk-stack > * + * { margin-top: 1rem; }
    .mk-card { border: 1px solid #e5e7eb; border-radius: .9rem; background: #fff; padding: 1rem; }
    .dark .mk-card { border-color: rgba(255,255,255,.1); background: rgba(255,255,255,.04); }
    .mk-muted { color: #6b7280; font-size: .8rem; }
    .dark .mk-muted { color: #9ca3af; }
    [x-cloak] { display: none !important; }
    .mk-sr { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }

    /* Campos. background-color (no el atajo background): así el selector conserva su flecha. */
    .mk-input { width: 100%; min-height: 2.5rem; border: 1px solid #d1d5db; border-radius: .6rem; padding: .5rem .7rem; font-size: .875rem; line-height: 1.25; background-color: #fff; color: #11161D; }
    .dark .mk-input { background-color: rgba(255,255,255,.06); border-color: rgba(255,255,255,.15); color: #f3f4f6; }
    .mk-input:focus { outline: 2px solid #FF6600; outline-offset: 1px; border-color: #FF6600; }
    /* Selector: la flecha nativa quedaba pegada al borde o recortada por el radio (mismo padding que un texto).
       Flecha propia en SVG, con espacio reservado a la derecha y texto largo con «…». */
    .mk-select { appearance: none; -webkit-appearance: none; -moz-appearance: none; padding-right: 2.25rem; text-overflow: ellipsis; white-space: nowrap; overflow: hidden; cursor: pointer;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='%236b7280'%3E%3Cpath fill-rule='evenodd' d='M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z' clip-rule='evenodd'/%3E%3C/svg%3E");
        background-repeat: no-repeat; background-position: right .6rem center; background-size: 1.1rem 1.1rem; }
    .dark .mk-select { background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='%23d1d5db'%3E%3Cpath fill-rule='evenodd' d='M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z' clip-rule='evenodd'/%3E%3C/svg%3E"); }
    .dark .mk-select option { background: #1f2937; color: #f3f4f6; }
    .mk-search { position: relative; }
    .mk-search svg { position: absolute; left: .75rem; top: 50%; width: 1.1rem; height: 1.1rem; transform: translateY(-50%); color: #9ca3af; pointer-events: none; }
    .mk-search .mk-input { padding-left: 2.4rem; min-height: 2.75rem; font-size: .95rem; }

    /* Filtros */
    .mk-toolbar { display: grid; gap: .75rem; }
    .mk-filters { display: grid; gap: .6rem; grid-template-columns: repeat(auto-fit, minmax(min(100%, 170px), 1fr)); align-items: end; }
    .mk-filters label, .mk-label { display: block; font-size: .75rem; font-weight: 600; color: #4b5563; margin-bottom: .25rem; }
    .dark .mk-filters label, .dark .mk-label { color: #d1d5db; }
    .mk-filters label.mk-check, .mk-check { display: flex; width: 100%; margin: 0; font-weight: 500; color: inherit; font-size: .85rem; gap: .45rem; align-items: center; min-height: 2.5rem; padding: 0 .75rem; border: 1px solid #d1d5db; border-radius: .6rem; font-size: .85rem; cursor: pointer; user-select: none; }
    .dark .mk-check { border-color: rgba(255,255,255,.15); }
    .mk-check:has(input:checked) { border-color: #FF6600; background: rgba(255,102,0,.08); }
    .mk-check input { accent-color: #FF6600; }
    .mk-resultbar { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; justify-content: space-between; }
    .mk-loading { display: inline-flex; gap: .4rem; align-items: center; }
    .mk-loading::before { content: ''; width: .8rem; height: .8rem; border-radius: 999px; border: 2px solid #FF6600; border-right-color: transparent; animation: mk-spin .7s linear infinite; }
    @keyframes mk-spin { to { transform: rotate(360deg); } }

    /* Tarjetas del catálogo: foto proporcionada (4:3, sin deformar), datos con altura estable. */
    .mk-grid { display: grid; gap: .9rem; grid-template-columns: repeat(auto-fill, minmax(min(100%, 228px), 1fr)); }
    .mk-grid[aria-busy="true"] { opacity: .55; transition: opacity .15s; }
    .mk-product { display: flex; flex-direction: column; overflow: hidden; padding: 0; transition: border-color .15s, box-shadow .15s; }
    .mk-product:hover { border-color: #FF6600; box-shadow: 0 8px 24px -16px rgba(17,22,29,.45); }
    .mk-img { position: relative; display: flex; align-items: center; justify-content: center; aspect-ratio: 4 / 3; background: #f5f5f4; color: #9ca3af; font-size: .78rem; text-decoration: none; }
    .dark .mk-img { background: rgba(255,255,255,.05); }
    .mk-img img { width: 100%; height: 100%; object-fit: contain; display: block; }
    .mk-img .mk-ph { display: flex; flex-direction: column; align-items: center; gap: .3rem; }
    .mk-img .mk-ph svg { width: 1.8rem; height: 1.8rem; }
    .mk-media { position: relative; }
    .mk-media .mk-badges { position: absolute; left: .5rem; top: .5rem; display: flex; gap: .3rem; pointer-events: none; }
    .mk-quick { position: absolute; right: .45rem; top: .45rem; display: flex; gap: .3rem; }
    .mk-quick .mk-icon-btn { width: 2.1rem; height: 2.1rem; background: rgba(255,255,255,.94); border-color: rgba(17,22,29,.12); box-shadow: 0 2px 8px -4px rgba(0,0,0,.35); }
    .dark .mk-quick .mk-icon-btn { background: rgba(17,22,29,.85); border-color: rgba(255,255,255,.15); }
    .mk-img .mk-count { position: absolute; right: .5rem; bottom: .5rem; background: rgba(17,22,29,.78); color: #fff; font-size: .68rem; font-weight: 600; border-radius: 999px; padding: .1rem .5rem; }
    .mk-body { padding: .75rem .85rem .85rem; display: flex; flex-direction: column; gap: .3rem; flex: 1; min-width: 0; }
    .mk-cat { font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #b34700; }
    .dark .mk-cat { color: #fdba74; }
    .mk-title { font-weight: 700; font-size: .95rem; line-height: 1.25; color: inherit; text-decoration: none; }
    .mk-title:hover { text-decoration: underline; }
    .mk-clamp2 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    .mk-clamp1 { display: -webkit-box; -webkit-line-clamp: 1; -webkit-box-orient: vertical; overflow: hidden; }
    .mk-meta { font-size: .78rem; color: #4b5563; overflow-wrap: anywhere; }
    .dark .mk-meta { color: #d1d5db; }
    .mk-code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .74rem; }
    .mk-compat { font-size: .76rem; color: #6b7280; }
    .dark .mk-compat { color: #9ca3af; }
    .mk-compat b { font-weight: 600; color: #4b5563; } .dark .mk-compat b { color: #d1d5db; }
    .mk-supplier { display: inline-flex; gap: .3rem; align-items: center; font-size: .78rem; min-width: 0; }
    .mk-supplier svg { width: .9rem; height: .9rem; flex: none; color: #9ca3af; }
    .mk-supplier a { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .mk-foot { display: flex; flex-wrap: wrap; gap: .4rem; align-items: center; justify-content: space-between; margin-top: .15rem; }
    .mk-price { font-weight: 800; font-size: 1.05rem; }
    .mk-price.quote { font-weight: 700; font-size: .9rem; color: #4b5563; } .dark .mk-price.quote { color: #d1d5db; }

    .mk-pill { display: inline-block; padding: .1rem .55rem; border-radius: 999px; font-size: .7rem; font-weight: 600; background: #f3f4f6; color: #374151; white-space: nowrap; }
    .mk-pill.ok { background: #dcfce7; color: #166534; } .mk-pill.warn { background: #fff1e6; color: #a84300; }
    .mk-pill.bad { background: #fee2e2; color: #991b1b; } .mk-pill.info { background: #e0e7ff; color: #1e3a8a; }
    .mk-pill.featured { background: #FF6600; color: #11161D; }
    .dark .mk-pill { background: rgba(255,255,255,.1); color: #e5e7eb; }
    .dark .mk-pill.ok { background: rgba(34,197,94,.15); color: #86efac; } .dark .mk-pill.bad { background: rgba(239,68,68,.15); color: #fca5a5; }
    .dark .mk-pill.warn { background: rgba(255,102,0,.15); color: #fdba74; } .dark .mk-pill.info { background: rgba(99,102,241,.18); color: #c7d2fe; }
    .dark .mk-pill.featured { background: #FF6600; color: #11161D; }

    .mk-actions { display: flex; flex-wrap: wrap; gap: .4rem; margin-top: auto; padding-top: .5rem; }
    .mk-btn { display: inline-flex; align-items: center; justify-content: center; gap: .35rem; min-height: 2.4rem; padding: .45rem .85rem; border-radius: .6rem; font-size: .82rem; font-weight: 700; cursor: pointer; border: 0; background: #FF6600; color: #11161D; text-decoration: none; line-height: 1.1; }
    .mk-btn:hover { background: #ff7a22; }
    .mk-btn:focus-visible, .mk-icon-btn:focus-visible, .mk-thumb:focus-visible, .mk-nav:focus-visible { outline: 2px solid #FF6600; outline-offset: 2px; }
    .mk-btn.secondary { background: #fff; color: #11161D; border: 1px solid #d1d5db; font-weight: 600; }
    .mk-btn.secondary:hover { border-color: #FF6600; }
    .mk-btn.dark { background: #11161D; color: #fff; }
    .mk-btn.danger { background: #fff; color: #991b1b; border: 1px solid #fecaca; font-weight: 600; }
    .mk-btn.grow { flex: 1 1 auto; }
    .dark .mk-btn.secondary { background: rgba(255,255,255,.06); color: #f3f4f6; border-color: rgba(255,255,255,.15); }
    .dark .mk-btn.danger { background: rgba(239,68,68,.08); color: #fca5a5; border-color: rgba(239,68,68,.35); }
    .mk-btn[aria-pressed="true"] { outline: 2px solid #FF6600; outline-offset: 1px; }
    .mk-icon-btn { display: inline-flex; align-items: center; justify-content: center; width: 2.4rem; height: 2.4rem; flex: none; border-radius: .6rem; border: 1px solid #d1d5db; background: #fff; color: #4b5563; cursor: pointer; }
    .mk-icon-btn svg { width: 1.1rem; height: 1.1rem; }
    .mk-icon-btn:hover { border-color: #FF6600; color: #b34700; }
    .mk-icon-btn[aria-pressed="true"] { border-color: #FF6600; background: rgba(255,102,0,.1); color: #b34700; }
    .dark .mk-icon-btn { background: rgba(255,255,255,.06); border-color: rgba(255,255,255,.15); color: #d1d5db; }
    .dark .mk-icon-btn[aria-pressed="true"] { color: #fdba74; }
    .mk-link { color: #b34700; font-weight: 600; text-decoration: none; }
    .mk-link:hover { text-decoration: underline; }
    .dark .mk-link { color: #fdba74; }
    .mk-back { display: inline-flex; align-items: center; gap: .3rem; font-size: .85rem; }
    .mk-back svg, .mk-btn svg { width: 1rem; height: 1rem; flex: none; }
    .mk-note { border: 1px solid #fed7aa; background: #fff7ed; color: #7c2d12; border-radius: .75rem; padding: .6rem .85rem; font-size: .8rem; line-height: 1.45; }
    .dark .mk-note { background: rgba(255,102,0,.08); border-color: rgba(255,102,0,.3); color: #fed7aa; }
    .mk-empty { text-align: center; padding: 2.2rem 1rem; }
    .mk-empty svg { width: 2.2rem; height: 2.2rem; color: #9ca3af; margin: 0 auto .5rem; }
    .mk-bar { position: sticky; bottom: .75rem; z-index: 10; display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; justify-content: space-between; background: #11161D; color: #fff; border-radius: .9rem; padding: .6rem .8rem; margin-top: 1rem; box-shadow: 0 10px 30px -12px rgba(0,0,0,.45); }
    .mk-table { width: 100%; border-collapse: collapse; font-size: .85rem; }
    .mk-table th { text-align: left; font-size: .72rem; text-transform: uppercase; letter-spacing: .03em; color: #6b7280; padding: .45rem .6rem; vertical-align: bottom; }
    .mk-table td { padding: .55rem .6rem; border-top: 1px solid #f3f4f6; vertical-align: top; }
    .dark .mk-table td { border-color: rgba(255,255,255,.06); }
    .mk-scroll { overflow-x: auto; }
    .mk-two { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(min(100%, 320px), 1fr)); align-items: start; }
    .mk-dl { display: grid; grid-template-columns: max-content 1fr; gap: .35rem .9rem; font-size: .875rem; margin: 0; }
    .mk-dl dt { color: #6b7280; } .dark .mk-dl dt { color: #9ca3af; }
    .mk-dl dd { margin: 0; overflow-wrap: anywhere; }
    .mk-h2 { font-size: 1rem; font-weight: 700; margin: 0 0 .6rem; }
    .mk-err { color: #b91c1c; font-size: .8rem; margin-top: .25rem; }

    /* Ficha del producto y galería (deslizable en el celular, flechas y miniaturas). */
    .mk-detail { display: grid; gap: 1rem; grid-template-columns: minmax(0, 1.1fr) minmax(0, 1fr); align-items: start; }
    .mk-gallery { padding: .75rem; }
    .mk-viewer { position: relative; border-radius: .7rem; overflow: hidden; background: #f5f5f4; }
    .dark .mk-viewer { background: rgba(255,255,255,.05); }
    .mk-track { display: flex; overflow-x: auto; scroll-snap-type: x mandatory; scrollbar-width: none; overscroll-behavior-x: contain; }
    .mk-track::-webkit-scrollbar { display: none; }
    .mk-track:focus-visible { outline: 2px solid #FF6600; outline-offset: -2px; }
    .mk-slide { flex: 0 0 100%; aspect-ratio: 4 / 3; scroll-snap-align: start; display: flex; align-items: center; justify-content: center; margin: 0; }
    .mk-slide img { width: 100%; height: 100%; object-fit: contain; display: block; }
    .mk-nav { position: absolute; top: 50%; transform: translateY(-50%); width: 2.5rem; height: 2.5rem; border-radius: 999px; border: 0; background: rgba(17,22,29,.72); color: #fff; display: grid; place-items: center; cursor: pointer; }
    .mk-nav:hover { background: #11161D; }
    .mk-nav svg { width: 1.2rem; height: 1.2rem; }
    .mk-nav.prev { left: .6rem; } .mk-nav.next { right: .6rem; }
    .mk-counter { position: absolute; right: .6rem; bottom: .6rem; background: rgba(17,22,29,.78); color: #fff; font-size: .72rem; font-weight: 700; border-radius: 999px; padding: .15rem .55rem; }
    .mk-zoom { position: absolute; left: .6rem; bottom: .6rem; background: rgba(255,255,255,.9); color: #11161D; font-size: .72rem; font-weight: 600; border-radius: 999px; padding: .15rem .6rem; text-decoration: none; }
    .mk-thumbs { display: flex; gap: .45rem; margin-top: .6rem; overflow-x: auto; padding-bottom: .15rem; }
    .mk-thumb { flex: 0 0 4rem; height: 4rem; border-radius: .5rem; overflow: hidden; border: 2px solid transparent; background: #f5f5f4; padding: 0; cursor: pointer; }
    .dark .mk-thumb { background: rgba(255,255,255,.05); }
    .mk-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .mk-thumb[aria-current="true"] { border-color: #FF6600; }
    .mk-noimg { aspect-ratio: 4 / 3; display: flex; flex-direction: column; gap: .4rem; align-items: center; justify-content: center; color: #9ca3af; font-size: .85rem; }
    .mk-noimg svg { width: 2.4rem; height: 2.4rem; }
    .mk-info { display: flex; flex-direction: column; gap: .85rem; }
    .mk-info > * { margin-top: 0; margin-bottom: 0; }
    .mk-pills { display: flex; gap: .35rem; flex-wrap: wrap; }
    .mk-h1 { font-size: 1.35rem; font-weight: 800; line-height: 1.2; margin: 0; overflow-wrap: anywhere; }
    .mk-pricebox { border-top: 1px solid #f3f4f6; border-bottom: 1px solid #f3f4f6; padding: .6rem 0; }
    .dark .mk-pricebox { border-color: rgba(255,255,255,.08); }
    .mk-compatbox { border: 1px dashed #d1d5db; border-radius: .7rem; padding: .7rem .8rem; }
    .dark .mk-compatbox { border-color: rgba(255,255,255,.18); }
    .mk-order { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
    .mk-qty { display: inline-flex; align-items: center; border: 1px solid #d1d5db; border-radius: .6rem; overflow: hidden; }
    .dark .mk-qty { border-color: rgba(255,255,255,.15); }
    .mk-qty button { width: 2.3rem; height: 2.4rem; border: 0; background: transparent; color: inherit; font-size: 1.1rem; cursor: pointer; }
    .mk-qty input { width: 3.2rem; height: 2.4rem; border: 0; text-align: center; background: transparent; color: inherit; font-size: .9rem; -moz-appearance: textfield; }
    .mk-qty input::-webkit-outer-spin-button, .mk-qty input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }

    @media (max-width: 900px) { .mk-detail { grid-template-columns: minmax(0, 1fr); } }
    /* Celular: tarjetas horizontales (foto chica a la izquierda) para ver varias sin desplazarse tanto. */
    @media (max-width: 560px) {
        .mk-grid { grid-template-columns: minmax(0, 1fr); gap: .6rem; }
        .mk-filters { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .5rem; }
        .mk-filters > :last-child { grid-column: 1 / -1; }
        .mk-filters > :last-child .mk-label { display: none; }
        .mk-product .mk-img img { object-fit: cover; }
        .mk-product { display: grid; grid-template-columns: 7rem minmax(0, 1fr); }
        .mk-product .mk-media, .mk-product .mk-img { height: 100%; }
        .mk-product .mk-img { aspect-ratio: auto; min-height: 7.5rem; }
        .mk-quick { top: auto; bottom: .35rem; right: .35rem; }
        .mk-quick .mk-icon-btn { width: 1.9rem; height: 1.9rem; }
        .mk-media .mk-badges { left: .35rem; top: .35rem; }
        .mk-product .mk-img .mk-count { display: none; }
        .mk-body { padding: .6rem .7rem .7rem; gap: .2rem; }
        .mk-product .mk-actions { padding-top: .35rem; }
        .mk-product .mk-actions .mk-btn { min-height: 2.25rem; padding: .35rem .6rem; }
        .mk-product .mk-compat { display: none; }
        .mk-nav { width: 2.2rem; height: 2.2rem; }
    }
    @media (prefers-reduced-motion: reduce) { .mk-card, .mk-btn, .mk-product, .mk-grid { transition: none !important; } .mk-loading::before { animation: none; } .mk-track { scroll-behavior: auto; } }
</style>
@endonce

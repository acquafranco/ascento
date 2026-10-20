{{-- Estilos del QR público (también en la vista previa del panel). Sin recursos remotos ni fuentes externas. --}}
<style>
    .qr-wrap { max-width: 34rem; margin: 0 auto; padding: 0 1rem 1rem; font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #11161D; box-sizing: border-box; }
    .qr-wrap *, .qr-wrap *::before, .qr-wrap *::after { box-sizing: border-box; }
    .qr-head { position: relative; display: flex; gap: .9rem; align-items: center; margin: 0 -1rem 1rem; padding: 1.1rem 1rem 1rem; background: #11161D; color: #fff; border-bottom: 4px solid var(--qr-accent, #FF6600); }
    .qr-logo { width: 3.25rem; height: 3.25rem; flex: none; object-fit: contain; border-radius: .6rem; background: #fff; border: 1px solid #E6E8EC; padding: .2rem; }
    .qr-company { margin: 0; font-size: .8rem; color: #C9CED6; overflow-wrap: anywhere; }
    .qr-company strong { color: #fff; }
    .qr-building { margin: .15rem 0 0; font-size: 1.35rem; line-height: 1.25; overflow-wrap: anywhere; }
    .qr-card { background: #fff; border: 1px solid #E6E8EC; border-radius: .9rem; padding: .9rem 1rem; margin-bottom: .8rem; }
    .qr-h2 { font-size: 1rem; margin: 0 0 .5rem; }
    .qr-row { display: flex; justify-content: space-between; gap: .75rem; align-items: center; padding: .35rem 0; }
    .qr-row + .qr-row { border-top: 1px solid #F4F5F7; }
    .qr-label { margin: .2rem 0 0; font-size: .95rem; overflow-wrap: anywhere; }
    .qr-value { margin: 0; font-weight: 700; white-space: nowrap; }
    .qr-elevator { padding: .5rem 0; }
    .qr-elevator + .qr-elevator { border-top: 1px solid #F4F5F7; }
    .qr-line { margin: .3rem 0 0; font-size: .9rem; line-height: 1.45; overflow-wrap: anywhere; }
    .qr-line a { color: #B84A00; }
    .qr-muted { color: #5B6472; }
    .qr-tag { display: inline-block; font-size: .7rem; font-weight: 700; border-radius: 999px; padding: .05rem .5rem; margin-right: .25rem; vertical-align: middle; }
    .qr-done { background: #dcfce7; color: #166534; }
    .qr-info { background: #e0f2fe; color: #075985; }
    .qr-warn { background: #fef3c7; color: #92400e; }
    .qr-admin { background: #F4F5F7; color: #2A313C; }
    .qr-note { font-size: .85rem; line-height: 1.5; color: #2A313C; padding: .2rem .25rem .5rem; }
    .qr-note p { margin: .4rem 0; }
    /* Acción principal: reportar un problema (visible, sin aspecto de alarma) */
    .qr-cta { display: flex; align-items: center; justify-content: center; gap: .55rem; width: 100%; min-height: 3.5rem; padding: .85rem 1rem; border: 0; border-radius: .9rem; background: #FF6600; color: #11161D; font-size: 1.1rem; font-weight: 800; text-decoration: none; cursor: pointer; }
    .qr-cta:hover { background: #FF7A1F; }
    .qr-cta:focus-visible, .qr-btn:focus-visible, .qr-opt input:focus-visible + span { outline: 3px solid #11161D; outline-offset: 2px; }
    .qr-cta[disabled] { opacity: .7; cursor: progress; }
    .qr-cta-note { margin: .45rem .2rem 1rem; font-size: .82rem; line-height: 1.45; color: #5B6472; text-align: center; }
    .qr-btn { display: flex; align-items: center; justify-content: center; width: 100%; min-height: 3rem; border: 1px solid #A6ACB8; border-radius: .8rem; background: #fff; color: #11161D; font-size: 1rem; font-weight: 700; cursor: pointer; text-decoration: none; }
    .qr-form { display: grid; gap: 1rem; }
    .qr-field label, .qr-field legend { display: block; font-weight: 700; font-size: .98rem; margin-bottom: .4rem; padding: 0; }
    .qr-field fieldset { border: 0; margin: 0; padding: 0; }
    .qr-hint { font-size: .82rem; color: #5B6472; margin: .3rem 0 0; }
    .qr-opts { display: grid; gap: .45rem; }
    .qr-opt { display: block; cursor: pointer; }
    .qr-opt input { position: absolute; opacity: 0; width: 1px; height: 1px; }
    .qr-opt span { display: flex; align-items: center; gap: .6rem; min-height: 3rem; padding: .6rem .85rem; border: 1.5px solid #D5D9E0; border-radius: .8rem; background: #fff; font-size: .98rem; }
    .qr-opt span::before { content: ''; flex: none; width: 1.1rem; height: 1.1rem; border-radius: 999px; border: 2px solid #A6ACB8; }
    .qr-opt input:checked + span { border-color: #FF6600; background: #FFF4EC; font-weight: 700; }
    .qr-opt input:checked + span::before { border: 5px solid #FF6600; }
    .qr-input { width: 100%; min-height: 3rem; padding: .7rem .8rem; border: 1.5px solid #D5D9E0; border-radius: .8rem; font: inherit; font-size: 1rem; background: #fff; color: #11161D; }
    textarea.qr-input { min-height: 7rem; resize: vertical; }
    .qr-input:focus { outline: 3px solid #FF6600; outline-offset: 1px; border-color: #FF6600; }
    .qr-error { color: #b91c1c; font-size: .88rem; font-weight: 600; margin: .35rem 0 0; }
    .qr-errors { border: 1.5px solid #fecaca; background: #fef2f2; border-radius: .8rem; padding: .7rem .85rem; color: #991b1b; font-size: .92rem; }
    .qr-summary { margin: 0; display: grid; gap: .55rem; }
    .qr-summary dt { font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #5B6472; }
    .qr-summary dd { margin: .1rem 0 0; font-size: 1rem; overflow-wrap: anywhere; white-space: pre-line; }
    .qr-actions { display: grid; gap: .6rem; }
    .qr-status { font-size: .95rem; line-height: 1.5; }
    .qr-result { border-radius: 1rem; padding: 1rem; margin: 0 0 1rem; border: 2px solid; background: #fff; }
    .qr-ok { border-color: #15803d; }
    .qr-bad { border-color: #b45309; }
    .qr-result h1, .qr-result h2 { margin: 0 0 .4rem; font-size: 1.15rem; }
    .qr-result p { margin: .35rem 0; font-size: .95rem; line-height: 1.5; }
    .qr-safety { font-size: .9rem; line-height: 1.5; background: #F4F5F7; border: 1px solid #D5D9E0; border-radius: .8rem; padding: .7rem .85rem; margin: .8rem 0 0; color: #2A313C; }
    .qr-step { font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #B84A00; margin: 0 0 .2rem; }
    .qr-sec-h { font-size: .75rem; font-weight: 800; text-transform: uppercase; letter-spacing: .07em; color: #5B6472; margin: 1.2rem .2rem .45rem; }
    .qr-list { list-style: none; margin: 0; padding: 0; }
    .qr-list li { display: flex; justify-content: space-between; gap: .75rem; padding: .5rem 0; border-top: 1px solid #F0F1F4; font-size: .92rem; }
    .qr-list li:first-child { border-top: 0; }
    .qr-list a { color: #B84A00; font-weight: 700; }
    /* Pie: la plataforma (separada de la identidad de la empresa) */
    .qr-foot { display: flex; align-items: center; justify-content: center; gap: .5rem; padding: 1rem 0 1.5rem; font-size: .8rem; color: #5B6472; }
    .qr-foot img { width: 1.4rem; height: 1.4rem; border-radius: .35rem; }
    .qr-foot strong { color: #11161D; }
</style>

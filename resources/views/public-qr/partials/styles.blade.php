{{-- Estilos del QR público (también en la vista previa del panel). Sin recursos remotos ni fuentes externas. --}}
<style>
    .qr-wrap { max-width: 34rem; margin: 0 auto; padding: 0 1rem 1rem; font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #11161D; box-sizing: border-box; }
    .qr-wrap *, .qr-wrap *::before, .qr-wrap *::after { box-sizing: border-box; }
    .qr-head { position: relative; display: flex; gap: .9rem; align-items: center; margin: 0 -1rem 1rem; padding: 1.1rem 1rem 1rem; background: #fff; border-bottom: 1px solid #E6E8EC; border-top: 4px solid var(--qr-accent, #11161D); }
    .qr-logo { width: 3.25rem; height: 3.25rem; flex: none; object-fit: contain; border-radius: .6rem; background: #fff; border: 1px solid #E6E8EC; padding: .2rem; }
    .qr-company { margin: 0; font-size: .8rem; color: #5B6472; overflow-wrap: anywhere; }
    .qr-company strong { color: #2A313C; }
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
    /* Pedido de ayuda */
    .qr-help { border: 2px solid #b91c1c; background: #fff; border-radius: 1rem; margin-bottom: 1rem; overflow: hidden; }
    .qr-help > summary { list-style: none; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: .6rem; min-height: 3.75rem; padding: .9rem 1rem; background: #b91c1c; color: #fff; font-size: 1.15rem; font-weight: 800; text-align: center; }
    .qr-help > summary::-webkit-details-marker { display: none; }
    .qr-help > summary:focus-visible, .qr-btn:focus-visible { outline: 3px solid #11161D; outline-offset: 2px; }
    .qr-help-body { padding: 1rem; }
    .qr-help-body p { margin: 0 0 .6rem; font-size: .95rem; line-height: 1.5; }
    .qr-choice { display: flex; flex-wrap: wrap; gap: .4rem; margin: .2rem 0 .8rem; padding: 0; border: 0; }
    .qr-choice legend { font-size: .85rem; color: #5B6472; margin-bottom: .3rem; padding: 0; }
    .qr-choice label { display: inline-flex; align-items: center; gap: .35rem; min-height: 2.75rem; padding: .35rem .75rem; border: 1px solid #A6ACB8; border-radius: .6rem; font-size: .95rem; }
    .qr-btn { display: block; width: 100%; min-height: 3.5rem; border: 0; border-radius: .8rem; background: #b91c1c; color: #fff; font-size: 1.1rem; font-weight: 800; cursor: pointer; }
    .qr-btn[disabled] { opacity: .75; cursor: progress; }
    .qr-btn-light { background: #fff; color: #11161D; border: 1px solid #A6ACB8; font-weight: 700; font-size: 1rem; min-height: 3rem; }
    .qr-help-status { margin-top: .8rem; font-size: .95rem; line-height: 1.5; }
    .qr-result { border-radius: 1rem; padding: 1rem; margin: 1rem 0; border: 2px solid; }
    .qr-ok { border-color: #15803d; background: #f0fdf4; }
    .qr-bad { border-color: #b91c1c; background: #fef2f2; }
    .qr-result h1, .qr-result h2 { margin: 0 0 .4rem; font-size: 1.15rem; }
    .qr-result p { margin: .35rem 0; font-size: .95rem; line-height: 1.5; }
    .qr-safety { font-size: .9rem; line-height: 1.5; background: #FFF4EC; border: 1px solid #FFC9A3; border-radius: .8rem; padding: .7rem .85rem; margin: .8rem 0 0; }
    /* Pie: la plataforma (separada de la identidad de la empresa) */
    .qr-foot { display: flex; align-items: center; justify-content: center; gap: .5rem; padding: 1rem 0 1.5rem; font-size: .8rem; color: #5B6472; }
    .qr-foot img { width: 1.4rem; height: 1.4rem; border-radius: .35rem; }
    .qr-foot strong { color: #11161D; }
</style>

{{--
    Aviso de cookies (páginas públicas). Ver config/cookies.php.
    - Sin categorías opcionales (hoy): aviso informativo con "Entendido".
    - Con categorías opcionales: Aceptar / Rechazar / Configurar; los scripts
      con type="text/plain" data-consent="x" se activan solo si se aceptan.
    - La elección se guarda en este navegador (localStorage) y se puede cambiar
      desde "Preferencias de cookies" ([data-cookie-preferences]).
--}}
@php
    $optional = config('cookies.optional', []);
    $version = (int) config('cookies.version', 1);
    $policyUrl = route('legal.cookies');
@endphp
@once
<style>
    .ck-bar { position: fixed; left: 16px; right: 16px; bottom: calc(16px + env(safe-area-inset-bottom)); z-index: 45; max-width: 560px; margin: 0 auto; padding: 16px 18px; border-radius: 16px; background: #11161D; color: #fff; box-shadow: 0 20px 50px -15px rgba(17,22,29,.6); font: 14px/1.5 system-ui, -apple-system, 'Segoe UI', sans-serif; }
    .ck-bar[hidden], .ck-panel[hidden] { display: none; }
    .ck-bar p { margin: 0; color: rgba(255,255,255,.82); }
    .ck-bar a { color: #FFB27A; font-weight: 600; }
    .ck-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
    .ck-btn { min-height: 40px; padding: 0 16px; border-radius: 10px; border: 0; font: 700 14px/1 system-ui, -apple-system, sans-serif; cursor: pointer; }
    .ck-btn-primary { background: #FF6600; color: #11161D; }
    .ck-btn-ghost { background: rgba(255,255,255,.1); color: #fff; }
    .ck-btn:focus-visible, .ck-panel :focus-visible { outline: 3px solid #FF6600; outline-offset: 2px; }
    .ck-panel { width: min(460px, calc(100vw - 32px)); padding: 0; border: 0; border-radius: 18px; background: #fff; color: #11161D; font: 15px/1.45 system-ui, -apple-system, sans-serif; }
    .ck-panel::backdrop { background: rgba(17,22,29,.55); }
    .ck-panel header { padding: 20px 22px 12px; }
    .ck-panel h2 { margin: 0; font-size: 18px; }
    .ck-panel .ck-body { padding: 0 22px 20px; }
    .ck-row { display: flex; gap: 12px; justify-content: space-between; align-items: flex-start; padding: 12px 0; border-top: 1px solid #E6E8EC; }
    .ck-row strong { display: block; }
    .ck-row span { color: #5B6472; font-size: 13.5px; }
    .ck-tag { flex-shrink: 0; padding: 3px 9px; border-radius: 999px; background: #E3F4EA; color: #17663A; font-size: 12px; font-weight: 700; }
    .ck-tag.is-off { background: #F4F5F7; color: #5B6472; }
    .ck-panel .ck-btn-ghost { background: #F4F5F7; color: #2A313C; }
</style>
@endonce

<div class="ck-bar" data-cookie-bar role="region" aria-label="Aviso de cookies" hidden>
    @if(empty($optional))
        <p>Usamos <strong style="color:#fff">solo cookies necesarias</strong> para iniciar sesión y proteger los formularios. No usamos cookies de publicidad ni de analítica. <a href="{{ $policyUrl }}">Política de cookies</a>.</p>
        <div class="ck-actions">
            <button type="button" class="ck-btn ck-btn-primary" data-ck="ok">Entendido</button>
            <button type="button" class="ck-btn ck-btn-ghost" data-cookie-preferences>Ver detalle</button>
        </div>
    @else
        <p>Usamos cookies necesarias para que Ascento funcione y, si nos dejás, otras opcionales. Podés aceptarlas, rechazarlas o elegir. <a href="{{ $policyUrl }}">Política de cookies</a>.</p>
        <div class="ck-actions">
            <button type="button" class="ck-btn ck-btn-primary" data-ck="accept">Aceptar</button>
            <button type="button" class="ck-btn ck-btn-ghost" data-ck="reject">Rechazar</button>
            <button type="button" class="ck-btn ck-btn-ghost" data-cookie-preferences>Configurar</button>
        </div>
    @endif
</div>

<dialog class="ck-panel" data-cookie-panel aria-labelledby="ck-title">
    <header><h2 id="ck-title">Preferencias de cookies</h2></header>
    <div class="ck-body">
        <div class="ck-row">
            <div><strong>Necesarias</strong><span>Sesión, protección de formularios (CSRF) y "Recordarme". Sin ellas no se puede ingresar.</span></div>
            <span class="ck-tag">Siempre activas</span>
        </div>
        @forelse($optional as $key => $category)
            <label class="ck-row">
                <div><strong>{{ $category['label'] }}</strong><span>{{ $category['description'] }}</span></div>
                <input type="checkbox" data-ck-category="{{ $key }}" style="width:20px;height:20px;accent-color:#FF6600">
            </label>
        @empty
            <div class="ck-row">
                <div><strong>Analítica y publicidad</strong><span>No las usamos.</span></div>
                <span class="ck-tag is-off">No usamos</span>
            </div>
        @endforelse
        <div class="ck-actions">
            @if(! empty($optional))
                <button type="button" class="ck-btn ck-btn-primary" data-ck="save">Guardar elección</button>
            @endif
            <button type="button" class="ck-btn ck-btn-ghost" data-ck="close">Cerrar</button>
        </div>
        <p style="margin:12px 0 0;font-size:13px;color:#5B6472">Más información en la <a href="{{ $policyUrl }}" style="color:#B84A00;font-weight:600">política de cookies</a>.</p>
    </div>
</dialog>

<script>
    (() => {
        const KEY = 'ascento.cookies';
        const version = @json($version);
        const categories = @json(array_keys($optional));
        const bar = document.querySelector('[data-cookie-bar]');
        const panel = document.querySelector('[data-cookie-panel]');
        if (!bar || !panel) return;

        const read = () => { try { const v = JSON.parse(localStorage.getItem(KEY) || 'null'); return v && v.v === version ? v : null; } catch (e) { return null; } };
        const write = (choices) => { try { localStorage.setItem(KEY, JSON.stringify({ v: version, choices, at: new Date().toISOString() })); } catch (e) {} };

        // Activa SOLO los scripts de las categorías aceptadas.
        const apply = (choices) => {
            document.querySelectorAll('script[type="text/plain"][data-consent]').forEach((tag) => {
                if (!choices[tag.dataset.consent] || tag.dataset.loaded) return;
                const real = document.createElement('script');
                [...tag.attributes].forEach((a) => { if (a.name !== 'type') real.setAttribute(a.name, a.value); });
                real.text = tag.text;
                tag.dataset.loaded = '1';
                tag.after(real);
            });
        };
        window.ascentoConsent = { allows: (category) => !!(read()?.choices || {})[category] };

        // Mientras el aviso está abierto, la página deja lugar abajo para que
        // nada quede tapado (p. ej. el botón "Entrar" en pantallas bajas).
        const reserve = () => { document.body.style.paddingBottom = bar.hidden ? '' : (bar.offsetHeight + 24) + 'px'; };
        window.addEventListener('resize', reserve);
        const decide = (choices) => { write(choices); bar.hidden = true; reserve(); apply(choices); };
        const all = (value) => Object.fromEntries(categories.map((c) => [c, value]));

        const saved = read();
        if (saved) apply(saved.choices || {}); else { bar.hidden = false; reserve(); }

        bar.addEventListener('click', (e) => {
            const action = e.target.closest('[data-ck]')?.dataset.ck;
            if (action === 'ok') decide({});
            if (action === 'accept') decide(all(true));
            if (action === 'reject') decide(all(false));
        });
        const openPanel = (e) => {
            e?.preventDefault();
            const choices = read()?.choices || {};
            panel.querySelectorAll('[data-ck-category]').forEach((box) => { box.checked = !!choices[box.dataset.ckCategory]; });
            panel.showModal ? panel.showModal() : panel.setAttribute('open', '');
        };
        document.querySelectorAll('[data-cookie-preferences]').forEach((el) => el.addEventListener('click', openPanel));
        panel.addEventListener('click', (e) => {
            const action = e.target.closest('[data-ck]')?.dataset.ck;
            if (action === 'save') {
                const choices = {};
                panel.querySelectorAll('[data-ck-category]').forEach((box) => { choices[box.dataset.ckCategory] = box.checked; });
                decide(choices);
            }
            if (action === 'save' || action === 'close' || e.target === panel) panel.close ? panel.close() : panel.removeAttribute('open');
        });
    })();
</script>

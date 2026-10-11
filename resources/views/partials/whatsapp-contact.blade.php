{{--
    Contacto por WhatsApp (páginas públicas). Un panel con un formulario corto
    que arma la consulta y abre WhatsApp con el mensaje listo: el mensaje lo
    envía la persona desde WhatsApp (acá no se envía ni se guarda nada).
    - Se abre desde cualquier elemento con [data-whatsapp-open] (y un acceso
      discreto abajo a la derecha si $launcher es true).
    - Sin dependencias: <dialog> nativo, CSS propio (prefijo wa-) y JS simple.
    - Lo escrito solo se usa como texto del enlace (encodeURIComponent).
--}}
@php
    $waNumber = config('app.whatsapp_contact');
    $launcher = $launcher ?? true;
    $reasons = [
        'Quiero conocer Ascento',
        'Quiero solicitar una demostración',
        'Necesito ayuda',
        'Tengo una consulta comercial',
        'Otro motivo',
    ];
@endphp
@once
<style>
    .wa-launch { position: fixed; right: 16px; bottom: calc(16px + env(safe-area-inset-bottom)); z-index: 40; display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px 10px 12px; border: 1px solid rgba(255,255,255,.12); border-radius: 999px; background: #11161D; color: #fff; font: 600 14px/1 system-ui, -apple-system, 'Segoe UI', sans-serif; box-shadow: 0 10px 30px -10px rgba(17,22,29,.55); cursor: pointer; opacity: 0; transform: translateY(12px); pointer-events: none; transition: opacity .25s, transform .25s; }
    .wa-launch.is-visible { opacity: 1; transform: none; pointer-events: auto; }
    .wa-launch:hover { background: #1c232d; }
    .wa-launch:focus-visible, .wa-dialog :focus-visible { outline: 3px solid #FF6600; outline-offset: 2px; }
    .wa-launch .wa-dot { display: inline-flex; width: 26px; height: 26px; align-items: center; justify-content: center; border-radius: 999px; background: #FF6600; color: #11161D; }
    @media (max-width: 480px) { .wa-launch .wa-launch-text { display: none; } .wa-launch { padding: 10px; } }
    .wa-dialog { width: min(440px, calc(100vw - 32px)); max-height: calc(100dvh - 32px); padding: 0; border: 0; border-radius: 20px; background: #fff; color: #11161D; box-shadow: 0 30px 80px -20px rgba(17,22,29,.5); font: 15px/1.45 system-ui, -apple-system, 'Segoe UI', sans-serif; }
    .wa-dialog::backdrop { background: rgba(17,22,29,.55); backdrop-filter: blur(2px); }
    .wa-head { position: relative; padding: 22px 22px 16px; background: #11161D; color: #fff; }
    .wa-head h2 { margin: 0; font-size: 19px; font-weight: 700; letter-spacing: -.01em; }
    .wa-head p { margin: 6px 0 0; color: rgba(255,255,255,.7); font-size: 14px; }
    .wa-x { position: absolute; top: 14px; right: 14px; width: 36px; height: 36px; border: 0; border-radius: 999px; background: rgba(255,255,255,.1); color: #fff; font-size: 20px; line-height: 1; cursor: pointer; }
    .wa-body { padding: 18px 22px 22px; overflow-y: auto; }
    .wa-field { margin-bottom: 12px; }
    .wa-field label { display: block; margin-bottom: 5px; font-size: 13px; font-weight: 600; color: #2A313C; }
    .wa-field label span { font-weight: 400; color: #5B6472; }
    .wa-field input, .wa-field select, .wa-field textarea { box-sizing: border-box; width: 100%; padding: 11px 12px; border: 1px solid #E6E8EC; border-radius: 12px; background: #fff; color: #11161D; font: inherit; font-size: 16px; }
    .wa-field input:focus, .wa-field select:focus, .wa-field textarea:focus { border-color: #FF6600; outline: 2px solid rgba(255,102,0,.2); }
    .wa-field textarea { min-height: 84px; resize: vertical; }
    .wa-field [aria-invalid="true"] { border-color: #DC2626; }
    .wa-err { margin: 4px 0 0; color: #B91C1C; font-size: 13px; font-weight: 600; }
    .wa-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 16px; }
    .wa-btn { display: inline-flex; flex: 1 1 auto; min-height: 48px; align-items: center; justify-content: center; gap: 8px; padding: 0 18px; border-radius: 12px; border: 0; font: 700 15px/1 system-ui, -apple-system, sans-serif; cursor: pointer; text-decoration: none; }
    .wa-btn-primary { background: #FF6600; color: #11161D; }
    .wa-btn-primary:hover { background: #E85D00; }
    .wa-btn-secondary { flex: 0 0 auto; background: #F4F5F7; color: #2A313C; }
    .wa-note { margin: 12px 0 0; color: #5B6472; font-size: 12.5px; }
    .wa-done { text-align: center; padding: 8px 0 4px; }
    .wa-done strong { display: block; font-size: 17px; margin-bottom: 6px; }
    .wa-done p { margin: 0 0 14px; color: #5B6472; }
</style>
@endonce

@if($launcher)
    <button type="button" class="wa-launch" data-whatsapp-open data-wa-launcher aria-haspopup="dialog">
        <span class="wa-dot" aria-hidden="true">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8s-.4-.1-.6.1-.7.8-.8 1-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.3-.4.3-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.9 11.9 0 0 0 4.6 4c1.7.7 2.4.8 3.2.7a2.8 2.8 0 0 0 1.8-1.3 2.3 2.3 0 0 0 .2-1.3c-.1-.1-.3-.2-.5-.3Z"/></svg>
        </span>
        <span class="wa-launch-text">Consultas</span>
    </button>
@endif

<dialog id="wa-dialog" class="wa-dialog" aria-labelledby="wa-title" aria-describedby="wa-desc">
    <div class="wa-head">
        <h2 id="wa-title">Hablemos por WhatsApp</h2>
        <p id="wa-desc">Completá estos datos y se abre WhatsApp con tu consulta lista. El mensaje lo enviás vos desde WhatsApp.</p>
        <button type="button" class="wa-x" data-wa-close aria-label="Cerrar">&times;</button>
    </div>
    <div class="wa-body">
        <form id="wa-form" novalidate>
            <div class="wa-field">
                <label for="wa-name">Nombre</label>
                <input id="wa-name" name="name" autocomplete="name" maxlength="80" required>
            </div>
            <div class="wa-field">
                <label for="wa-company">Empresa</label>
                <input id="wa-company" name="company" autocomplete="organization" maxlength="100" required>
            </div>
            <div class="wa-field">
                <label for="wa-email">Correo <span>(opcional)</span></label>
                <input id="wa-email" name="email" type="email" autocomplete="email" maxlength="120" inputmode="email">
            </div>
            <div class="wa-field">
                <label for="wa-reason">Motivo</label>
                <select id="wa-reason" name="reason" required>
                    <option value="">Elegí una opción</option>
                    @foreach($reasons as $reason)
                        <option>{{ $reason }}</option>
                    @endforeach
                </select>
            </div>
            <div class="wa-field">
                <label for="wa-message">Mensaje <span>(opcional)</span></label>
                <textarea id="wa-message" name="message" maxlength="600" placeholder="Contanos en pocas palabras qué necesitás"></textarea>
            </div>
            <div class="wa-actions">
                <button type="submit" class="wa-btn wa-btn-primary">Continuar en WhatsApp</button>
                <button type="button" class="wa-btn wa-btn-secondary" data-wa-close>Cerrar</button>
            </div>
            <p class="wa-note">No guardamos estos datos: solo se usan para armar el mensaje.</p>
        </form>
        <div id="wa-done" class="wa-done" hidden>
            <strong>Abrimos WhatsApp con tu consulta</strong>
            <p>Revisá el mensaje y tocá <b>Enviar</b> en WhatsApp para que nos llegue.</p>
            <div class="wa-actions">
                <a id="wa-retry" class="wa-btn wa-btn-primary" href="#" target="_blank" rel="noopener">Si no se abrió, tocá acá</a>
                <button type="button" class="wa-btn wa-btn-secondary" data-wa-close>Cerrar</button>
            </div>
        </div>
    </div>
</dialog>

<script>
    (() => {
        const number = @json($waNumber);
        const dialog = document.getElementById('wa-dialog');
        const form = document.getElementById('wa-form');
        const done = document.getElementById('wa-done');
        const launcher = document.querySelector('[data-wa-launcher]');
        if (!dialog || !form || !number) return;

        const open = (event) => {
            event?.preventDefault();
            form.hidden = false;
            done.hidden = true;
            if (typeof dialog.showModal === 'function') dialog.showModal(); else dialog.setAttribute('open', '');
            setTimeout(() => document.getElementById('wa-name').focus(), 50);
        };
        const close = () => (dialog.close ? dialog.close() : dialog.removeAttribute('open'));

        document.querySelectorAll('[data-whatsapp-open]').forEach((el) => el.addEventListener('click', open));
        dialog.querySelectorAll('[data-wa-close]').forEach((el) => el.addEventListener('click', close));
        dialog.addEventListener('click', (e) => { if (e.target === dialog) close(); }); // tocar afuera

        // El acceso discreto aparece recién al bajar (no tapa el inicio).
        if (launcher) {
            const toggle = () => launcher.classList.toggle('is-visible', window.scrollY > 480);
            window.addEventListener('scroll', toggle, { passive: true });
            toggle();
        }

        const setError = (input, message) => {
            input.setAttribute('aria-invalid', message ? 'true' : 'false');
            let error = input.parentElement.querySelector('.wa-err');
            if (!message) { error?.remove(); return; }
            if (!error) {
                error = document.createElement('p');
                error.className = 'wa-err';
                error.id = input.id + '-err';
                input.setAttribute('aria-describedby', error.id);
                input.parentElement.appendChild(error);
            }
            error.textContent = message;
        };

        const clean = (value) => value.replace(/\s+/g, ' ').trim();

        // El error de un campo se borra apenas se corrige.
        form.querySelectorAll('input, select').forEach((input) => {
            input.addEventListener(input.tagName === 'SELECT' ? 'change' : 'input', () => setError(input, ''));
        });

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            const f = form.elements;
            const data = {
                name: clean(f.name.value), company: clean(f.company.value), email: clean(f.email.value),
                reason: f.reason.value, message: f.message.value.trim(),
            };
            const checks = [
                [f.name, data.name.length >= 2 ? '' : 'Escribí tu nombre.'],
                [f.company, data.company.length >= 2 ? '' : 'Escribí el nombre de tu empresa.'],
                [f.email, !data.email || /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(data.email) ? '' : 'Revisá el correo (o dejalo vacío).'],
                [f.reason, data.reason ? '' : 'Elegí el motivo de la consulta.'],
            ];
            checks.forEach(([input, message]) => setError(input, message));
            const firstInvalid = checks.find(([, message]) => message);
            if (firstInvalid) { firstInvalid[0].focus(); return; }

            const lines = [
                'Hola, equipo de Ascento.',
                '',
                'Motivo: ' + data.reason,
                'Nombre: ' + data.name,
                'Empresa: ' + data.company,
            ];
            if (data.email) lines.push('Correo: ' + data.email);
            if (data.message) lines.push('', data.message);

            const url = 'https://wa.me/' + number + '?text=' + encodeURIComponent(lines.join('\n'));
            document.getElementById('wa-retry').href = url;
            form.hidden = true;
            done.hidden = false;
            // En el celular abre la app; en la computadora, WhatsApp Web.
            window.open(url, '_blank', 'noopener');
        });
    })();
</script>

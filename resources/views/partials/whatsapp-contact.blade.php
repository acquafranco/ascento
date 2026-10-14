{{--
    Asistente de consultas (páginas públicas, Etapa 10). Responde las dudas
    frecuentes con datos reales (App\Support\Sales\SalesAssistant) y, si hace
    falta una persona, abre WhatsApp con el tema y lo que la persona quiso
    contar. El mensaje lo envía ella desde WhatsApp: acá no se envía ni se
    guarda nada, y no hay ningún endpoint.
    - Se abre desde cualquier elemento con [data-whatsapp-open] (y un acceso
      discreto abajo a la derecha si $launcher es true).
    - Sin dependencias: <dialog> nativo, CSS propio (prefijo wa-) y JS simple.
    - Las respuestas se escriben en el servidor (escapadas por Blade); lo
      escrito por la persona solo se usa como texto del enlace (encodeURIComponent).
--}}
@php
    $waNumber = \App\Support\Sales\SalesAssistant::whatsappNumber(); // null: contacto por correo
    $launcher = $launcher ?? true;
    $topics = \App\Support\Sales\SalesAssistant::topics();
    $buildingRanges = \App\Support\Sales\SalesAssistant::buildingRanges();
    $hours = \App\Support\Sales\SalesAssistant::hours();
    $trialDays = \App\Models\SubscriptionPlan::TRIAL_DAYS;
    $actions = [
        'trial' => [Route::has('register') ? route('register') : url('/register'), 'Probar gratis '.$trialDays.' días'],
        'plans' => [url('/').'#planes', 'Ver los planes'],
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
    .wa-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 16px; }
    .wa-btn { display: inline-flex; flex: 1 1 auto; min-height: 48px; align-items: center; justify-content: center; gap: 8px; padding: 0 18px; border-radius: 12px; border: 0; font: 700 15px/1 system-ui, -apple-system, sans-serif; cursor: pointer; text-decoration: none; }
    .wa-btn-primary { background: #FF6600; color: #11161D; }
    .wa-btn-primary:hover { background: #E85D00; }
    .wa-btn-secondary { flex: 0 0 auto; background: #F4F5F7; color: #2A313C; }
    .wa-note { margin: 12px 0 0; color: #5B6472; font-size: 12.5px; }
    .wa-done { text-align: center; padding: 8px 0 4px; }
    .wa-done strong { display: block; font-size: 17px; margin-bottom: 6px; }
    .wa-done p { margin: 0 0 14px; color: #5B6472; }
    .wa-topics { display: grid; gap: 8px; margin: 0; padding: 0; list-style: none; }
    .wa-topic { display: flex; width: 100%; min-height: 48px; align-items: center; justify-content: space-between; gap: 10px; padding: 10px 14px; border: 1px solid #E6E8EC; border-radius: 12px; background: #fff; color: #11161D; font: 600 15px/1.3 system-ui, -apple-system, 'Segoe UI', sans-serif; text-align: left; cursor: pointer; }
    .wa-topic:hover { border-color: #FF6600; background: #FFF7F0; }
    .wa-topic::after { content: '›'; color: #5B6472; font-size: 20px; line-height: 1; }
    .wa-topic-fixed { background: #F4F5F7; font-weight: 500; }
    .wa-sub { margin: 18px 0 8px; color: #5B6472; font-size: 12px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
    .wa-answer h3 { margin: 0 0 10px; font-size: 17px; font-weight: 700; }
    .wa-answer p { margin: 0 0 10px; color: #2A313C; }
    .wa-answer ul { margin: 0 0 10px; padding-left: 20px; color: #2A313C; }
    .wa-answer li { margin-bottom: 6px; }
    .wa-alert { padding: 12px 14px; border-radius: 12px; background: #FEF2F2; }
    .wa-link { color: #C2410C; font-weight: 700; }
    .wa-back { margin-top: 12px; padding: 0; border: 0; background: none; color: #5B6472; font: 600 14px/1.4 system-ui, -apple-system, sans-serif; text-decoration: underline; cursor: pointer; }
    .wa-dialog [tabindex="-1"]:focus { outline: none; }
    .wa-more { margin: 0 0 10px; color: #2A313C; font-size: 14px; }
    .wa-more summary { cursor: pointer; color: #C2410C; font-weight: 700; padding: 4px 0; }
    .wa-more ul { margin-top: 6px; }
    .wa-chips { display: flex; flex-wrap: wrap; gap: 8px; }
    .wa-chip { min-height: 40px; padding: 0 14px; border: 1px solid #E6E8EC; border-radius: 999px; background: #fff; color: #11161D; font: 600 14px/1 system-ui, -apple-system, sans-serif; cursor: pointer; }
    .wa-chip:hover { border-color: #FF6600; background: #FFF7F0; }
    .wa-note-top { margin: -6px 0 12px; }
    .wa-chosen { margin: 0 0 14px; padding: 10px 12px; border-radius: 12px; background: #F4F5F7; color: #2A313C; font-size: 14px; }
</style>
@endonce

@if($launcher)
    <button type="button" class="wa-launch" data-whatsapp-open data-wa-launcher aria-haspopup="dialog" aria-label="Consultas por WhatsApp">
        <span class="wa-dot" aria-hidden="true">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8s-.4-.1-.6.1-.7.8-.8 1-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.3-.4.3-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.9 11.9 0 0 0 4.6 4c1.7.7 2.4.8 3.2.7a2.8 2.8 0 0 0 1.8-1.3 2.3 2.3 0 0 0 .2-1.3c-.1-.1-.3-.2-.5-.3Z"/></svg>
        </span>
        <span class="wa-launch-text">Consultas</span>
    </button>
@endif

<dialog id="wa-dialog" class="wa-dialog" aria-labelledby="wa-title" aria-describedby="wa-desc">
    <div class="wa-head">
        <h2 id="wa-title">¿En qué te ayudamos?</h2>
        <p id="wa-desc">Elegí un tema para ver la respuesta. Si querés hablar con una persona, seguimos por WhatsApp.</p>
        <button type="button" class="wa-x" data-wa-close aria-label="Cerrar">&times;</button>
    </div>
    <div class="wa-body" aria-live="polite">
        {{-- 1. Temas --}}
        <div data-wa-view="topics">
            <ul class="wa-topics">
                @foreach (collect($topics)->where('kind', \App\Support\Sales\SalesAssistant::SALES) as $topic)
                    <li><button type="button" class="wa-topic" data-wa-pick="{{ $topic['key'] }}">{{ $topic['label'] }}</button></li>
                @endforeach
            </ul>
            <p class="wa-sub">Otras consultas</p>
            <ul class="wa-topics">
                {{-- Al final: vecinos y urgencias (respuesta fija, sin pasar a ventas). --}}
                @foreach (collect($topics)->where('kind', \App\Support\Sales\SalesAssistant::FIXED) as $topic)
                    <li><button type="button" class="wa-topic wa-topic-fixed" data-wa-pick="{{ $topic['key'] }}">{{ $topic['label'] }}</button></li>
                @endforeach
            </ul>
            <p class="wa-note">Nunca te vamos a pedir contraseñas, códigos de verificación ni pagos por WhatsApp.</p>
        </div>

        {{-- 2. Respuestas (una por tema) --}}
        @foreach ($topics as $topic)
            <section class="wa-answer" data-wa-view="topic" data-wa-topic="{{ $topic['key'] }}" data-wa-label="{{ $topic['label'] }}" hidden>
                <h3>{{ $topic['label'] }}</h3>
                <div @class(['wa-alert' => $topic['key'] === 'emergency'])>
                    @foreach ($topic['answer'] as $block)
                        @if (is_string($block))
                            <p>{{ $block }}</p>
                        @elseif (isset($block['details']))
                            <details class="wa-more"><summary>{{ $block['details'] }}</summary><ul>@foreach ($block['items'] as $item)<li>{{ $item }}</li>@endforeach</ul></details>
                        @else
                            <ul>@foreach ($block as $item)<li>{{ $item }}</li>@endforeach</ul>
                        @endif
                    @endforeach
                </div>
                @if ($topic['subjects'] && $waNumber)
                    <p class="wa-sub">Consultar por un plan</p>
                    <div class="wa-chips">
                        @foreach ($topic['subjects'] as $subject)
                            <button type="button" class="wa-chip" data-wa-handoff data-wa-subject="{{ $subject }}">{{ $subject }}</button>
                        @endforeach
                    </div>
                @endif
                <div class="wa-actions">
                    @if ($topic['handoff'] && $waNumber)
                        <button type="button" class="wa-btn wa-btn-primary" data-wa-handoff>{{ $topic['cta'] }}</button>
                    @endif
                    @foreach ($topic['actions'] as $action)
                        <a class="wa-btn wa-btn-secondary" href="{{ $actions[$action][0] }}" data-wa-follow>{{ $actions[$action][1] }}</a>
                    @endforeach
                </div>
                @if ($topic['handoff'] && ! $waNumber)
                    <p class="wa-note">Escribinos a <a class="wa-link" href="mailto:{{ config('app.support_email') }}">{{ config('app.support_email') }}</a>.</p>
                @endif
                <button type="button" class="wa-back" data-wa-back>Ver otros temas</button>
            </section>
        @endforeach

        {{-- 3. Datos para WhatsApp (todos opcionales) --}}
        <form id="wa-form" data-wa-view="form" novalidate hidden>
            <p class="wa-chosen" data-wa-focus>Tema: <strong data-wa-chosen></strong></p>
            <p class="wa-note wa-note-top">Todos los datos son opcionales: completá lo que quieras y seguimos por WhatsApp.</p>
            <div class="wa-field">
                <label for="wa-name">Nombre <span>(opcional)</span></label>
                <input id="wa-name" name="name" autocomplete="name" maxlength="80">
            </div>
            <div class="wa-field">
                <label for="wa-company">Empresa <span>(opcional)</span></label>
                <input id="wa-company" name="company" autocomplete="organization" maxlength="100">
            </div>
            @if ($buildingRanges)
                <div class="wa-field">
                    <label for="wa-buildings">Edificios que mantienen <span>(opcional)</span></label>
                    <select id="wa-buildings" name="buildings">
                        <option value="">Prefiero no decirlo</option>
                        @foreach ($buildingRanges as $range)
                            <option>{{ $range }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="wa-field">
                <label for="wa-message">Mensaje <span>(opcional)</span></label>
                <textarea id="wa-message" name="message" maxlength="600" placeholder="Contanos en pocas palabras qué necesitás"></textarea>
            </div>
            <div class="wa-actions">
                <button type="submit" class="wa-btn wa-btn-primary">Continuar en WhatsApp</button>
                <button type="button" class="wa-btn wa-btn-secondary" data-wa-back>Volver</button>
            </div>
            @if ($hours)
                <p class="wa-note" data-wa-hours>Horario de atención: {{ $hours }}.</p>
            @endif
            <p class="wa-note">No guardamos estos datos: solo se usan para armar el mensaje, que enviás vos desde WhatsApp. No escribas contraseñas ni códigos.</p>
        </form>

        <div id="wa-done" class="wa-done" data-wa-view="done" hidden>
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
        const launcher = document.querySelector('[data-wa-launcher]');
        if (!dialog || !form) return;

        let topic = null;   // sección del tema elegido
        let subject = null; // consulta puntual («Plan Profesional»), si la hay

        const show = (view) => {
            dialog.querySelectorAll('[data-wa-view]').forEach((el) => { el.hidden = el !== view; });
            dialog.querySelector('.wa-body').scrollTop = 0;
            // El foco va al título o al tema elegido, no a un campo: en el celular no abre el teclado sin que la persona lo pida.
            const focusable = view.dataset.waView === 'topics' ? document.getElementById('wa-title') : view.querySelector('[data-wa-focus], h3, button');
            if (focusable) { if (focusable.tagName !== 'BUTTON') focusable.setAttribute('tabindex', '-1'); setTimeout(() => focusable.focus({ preventScroll: true }), 30); }
        };
        const showTopics = () => show(dialog.querySelector('[data-wa-view="topics"]'));
        const subjectText = () => topic.dataset.waLabel + (subject ? ' · ' + subject : '');
        const showForm = (event) => {
            subject = event?.currentTarget?.dataset.waSubject || null;
            dialog.querySelector('[data-wa-chosen]').textContent = subjectText();
            show(form);
        };
        const pick = (key) => {
            topic = dialog.querySelector('[data-wa-topic="' + key + '"]');
            if (!topic) return showTopics();
            // «Hablar con una persona» va directo a los datos.
            if (key === 'human' && number) return showForm();
            show(topic);
        };

        const open = (event) => {
            event?.preventDefault();
            topic = null;
            subject = null;
            if (typeof dialog.showModal === 'function') dialog.showModal(); else dialog.setAttribute('open', '');
            showTopics();
        };
        const close = () => (dialog.close ? dialog.close() : dialog.removeAttribute('open'));

        document.querySelectorAll('[data-whatsapp-open]').forEach((el) => el.addEventListener('click', open));
        dialog.querySelectorAll('[data-wa-close]').forEach((el) => el.addEventListener('click', close));
        dialog.querySelectorAll('[data-wa-follow]').forEach((el) => el.addEventListener('click', close));
        dialog.querySelectorAll('[data-wa-pick]').forEach((el) => el.addEventListener('click', () => pick(el.dataset.waPick)));
        dialog.querySelectorAll('[data-wa-handoff]').forEach((el) => el.addEventListener('click', showForm));
        dialog.querySelectorAll('[data-wa-back]').forEach((el) => el.addEventListener('click', () => (
            el.closest('form') && topic && topic.querySelector('[data-wa-handoff]') && topic.dataset.waTopic !== 'human' ? show(topic) : showTopics()
        )));
        dialog.addEventListener('click', (e) => { if (e.target === dialog) close(); }); // tocar afuera

        // El acceso discreto aparece recién al bajar (no tapa el inicio).
        if (launcher) {
            const toggle = () => launcher.classList.toggle('is-visible', window.scrollY > 480);
            window.addEventListener('scroll', toggle, { passive: true });
            toggle();
        }

        // Una línea, sin caracteres de control, con tope de largo.
        const clean = (value, max) => value.replace(/[\u0000-\u001F\u007F]+/g, ' ').replace(/\s+/g, ' ').trim().slice(0, max);

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            if (!number || !topic) return;
            const f = form.elements;
            const data = {
                name: clean(f.name.value, 80), company: clean(f.company.value, 100),
                buildings: f.buildings ? f.buildings.value : '',
                message: f.message.value.replace(/[\u0000-\u0009\u000B-\u001F\u007F]+/g, ' ').trim().slice(0, 600),
            };
            // Todo es opcional: no hay nada que validar más allá de limpiar y recortar.
            // Solo el tema y lo que la persona escribió (nada más).
            const lines = ['Hola, equipo de Ascento.', '', 'Tema: ' + subjectText()];
            if (data.name) lines.push('Nombre: ' + data.name);
            if (data.company) lines.push('Empresa: ' + data.company);
            if (data.buildings && [...f.buildings.options].some((o) => o.value === data.buildings)) lines.push('Edificios: ' + data.buildings);
            if (data.message) lines.push('', data.message);

            // Mismo formato que SalesAssistant::whatsappUrl(): wa.me/<número>?text=<mensaje codificado>.
            const url = 'https://wa.me/' + number + '?text=' + encodeURIComponent(lines.join('\n'));
            document.getElementById('wa-retry').href = url;
            show(document.getElementById('wa-done'));
            // En el celular abre la app; en la computadora, WhatsApp Web.
            window.open(url, '_blank', 'noopener');
        });
    })();
</script>

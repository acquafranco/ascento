{{--
    Diagrama animado de «El cuidado del ascensor conecta a todos» (landing).

    - Ilustración, no una captura: muestra cómo circula la información entre la
      empresa de mantenimiento, la administración del consorcio y quienes usan
      el ascensor (por el QR). El pedido de ayuda va con línea punteada y la
      leyenda «si la empresa lo activa»: no se muestra como algo que llega
      siempre ni como un rescate.
    - Solo SVG y CSS, sin bibliotecas ni recursos externos. La secuencia corre
      una sola vez al entrar en pantalla (unos 6 s) y queda quieta; «Ver de
      nuevo» la repite. Sin JavaScript o con movimiento reducido se ve el
      diagrama completo, sin animación.
    - Tamaño reservado (aspect-ratio): no mueve el resto de la página. En el
      celular se usa una composición vertical en lugar de achicar la ancha.
    - El SVG es decorativo para lectores de pantalla: los pasos están en texto
      en la lista de abajo.
--}}
@php $helpOn = $helpOn ?? true; @endphp
<style>
    .cx { margin: 3rem auto 0; max-width: 64rem; }
    .cx-stage { position: relative; border-radius: 1.5rem; border: 1px solid rgba(20,23,28,.08); background: radial-gradient(120% 90% at 50% 0%, #FFF7F0 0%, #FFFFFF 60%); padding: 1rem; }
    .cx-svg { display: block; width: 100%; height: auto; }
    .cx-wide { aspect-ratio: 960 / 390; }
    .cx-tall { aspect-ratio: 360 / 410; }
    .cx-tall-wrap { display: block; } .cx-wide-wrap { display: none; }
    @media (min-width: 768px) { .cx-tall-wrap { display: none; } .cx-wide-wrap { display: block; } }
    .cx-box { fill: #fff; stroke: rgba(20,23,28,.14); }
    .cx-title { font: 600 15px/1 ui-sans-serif, system-ui, sans-serif; fill: #14171C; }
    .cx-sub { font: 400 12px/1 ui-sans-serif, system-ui, sans-serif; fill: rgba(20,23,28,.6); }
    .cx-tall .cx-title { font-size: 13px; } .cx-tall .cx-sub { font-size: 11px; }
    .cx-ico { fill: none; stroke: #E8590C; stroke-width: 1.6; stroke-linecap: round; stroke-linejoin: round; }
    .cx-icobg { fill: #FFEDE0; }
    .cx-bld { fill: #fff; stroke: #2B323C; stroke-width: 1.6; }
    .cx-win { fill: #EEF0F3; }
    .cx-shaft { fill: #F6F7F9; stroke: rgba(20,23,28,.18); }
    .cx-cabin { fill: #FF6A1A; }
    .cx-qr rect { fill: #14171C; }
    .cx-qr .cx-qrbg { fill: #fff; stroke: #14171C; stroke-width: 1.2; }
    .cx-line { fill: none; stroke: #FF6A1A; stroke-width: 2.2; stroke-linecap: round; }
    .cx-help { fill: none; stroke: #B42318; stroke-width: 1.8; stroke-dasharray: 2 6; stroke-linecap: round; }
    .cx-chip rect { fill: #14171C; } .cx-chip text { font: 600 11.5px/1 ui-sans-serif, system-ui, sans-serif; fill: #fff; }
    .cx-chip.cx-chip-help rect { fill: #fff; stroke: #B42318; stroke-dasharray: 3 3; } .cx-chip.cx-chip-help text { fill: #B42318; }
    .cx-steps { list-style: none; margin: 1.25rem 0 0; padding: 0; display: grid; gap: .6rem; grid-template-columns: repeat(auto-fit, minmax(min(100%, 13rem), 1fr)); counter-reset: cx; }
    .cx-steps li { counter-increment: cx; border-radius: .9rem; padding: .7rem .85rem .75rem; border: 1px solid rgba(20,23,28,.08); background: #fff; font-size: .85rem; line-height: 1.45; color: rgba(20,23,28,.72); transition: border-color .4s ease, box-shadow .4s ease; }
    .cx-steps li strong { display: block; color: #14171C; font-size: .9rem; margin-bottom: .15rem; }
    .cx-steps li strong::before { content: counter(cx) ". "; color: #E8590C; }
    .cx-steps li.is-current { border-color: rgba(255,106,26,.55); box-shadow: 0 6px 18px -10px rgba(255,106,26,.55); }
    .cx-replay { display: block; visibility: hidden; margin: .75rem auto 0; border: 0; background: none; color: #C2410C; font-weight: 600; font-size: .85rem; cursor: pointer; padding: .35rem .5rem; }
    .cx.is-done .cx-replay { visibility: visible; }

    /* Con JavaScript (y sin movimiento reducido): todo arranca apagado y se enciende por pasos. */
    .cx.js .cx-line { stroke-dasharray: 100; stroke-dashoffset: 100; transition: stroke-dashoffset 1.1s ease; }
    .cx.js .cx-chip, .cx.js .cx-help { opacity: 0; transition: opacity .6s ease; }
    .cx.js .cx-cabin { transform: translateY(var(--cx-cabin-from, 150px)); transition: transform 2.4s cubic-bezier(.45,0,.25,1); }
    .cx.js .cx-qr { transition: transform .5s ease; transform-box: fill-box; transform-origin: center; }
    .cx.js.s1 .l1, .cx.js.s2 .l2, .cx.js.s3 .l3 { stroke-dashoffset: 0; }
    .cx.js.s1 .c1, .cx.js.s2 .c2, .cx.js.s3 .c3, .cx.js.s4 .c4, .cx.js.s4 .cx-help { opacity: 1; }
    .cx.js.s1 .cx-cabin { transform: translateY(0); }
    .cx.js.s3:not(.s4) .cx-qr { transform: scale(1.12); }
    @media (prefers-reduced-motion: reduce) {
        .cx.js .cx-line, .cx.js .cx-chip, .cx.js .cx-help, .cx.js .cx-cabin, .cx.js .cx-qr, .cx-steps li { transition: none !important; }
    }
</style>

<figure class="cx" data-connect-diagram aria-labelledby="cx-caption">
    <div class="cx-stage">
        {{-- Ancho (tablet y escritorio) --}}
        <div class="cx-wide-wrap">
            <svg class="cx-svg cx-wide" viewBox="0 0 960 390" aria-hidden="true" focusable="false">
                {{-- Empresa --}}
                <rect class="cx-box" x="20" y="125" width="240" height="110" rx="16"/>
                <circle class="cx-icobg" cx="62" cy="168" r="22"/>
                <path class="cx-ico" d="M68 156a8 8 0 0 0-10.6 10.6L49 175v6h6l8.4-8.4A8 8 0 0 0 74 162l-4.8 4.8-5-1-1-5L68 156Z"/>
                <text class="cx-title" x="96" y="164">Empresa de</text>
                <text class="cx-title" x="96" y="183">mantenimiento</text>
                <text class="cx-sub" x="40" y="214">Técnicos, visitas y remitos</text>

                {{-- Edificio con su ascensor --}}
                <path class="cx-bld" d="M405 345V80L485 45L565 80V345Z"/>
                @foreach([[420,100],[420,140],[420,180],[420,220],[530,100],[530,140],[530,180],[530,220]] as [$wx, $wy])
                    <rect class="cx-win" x="{{ $wx }}" y="{{ $wy }}" width="20" height="22" rx="3"/>
                @endforeach
                <rect class="cx-shaft" x="465" y="90" width="40" height="255" rx="4"/>
                <rect class="cx-cabin" x="469" y="140" width="32" height="40" rx="4" style="--cx-cabin-from: 150px"/>
                <g class="cx-qr">
                    <rect class="cx-qrbg" x="518" y="262" width="36" height="36" rx="4"/>
                    <rect x="523" y="267" width="9" height="9"/><rect x="540" y="267" width="9" height="9"/><rect x="523" y="284" width="9" height="9"/>
                    <rect x="536" y="281" width="4" height="4"/><rect x="543" y="286" width="5" height="5"/><rect x="536" y="290" width="4" height="4"/>
                </g>
                <line x1="395" y1="345" x2="575" y2="345" stroke="#2B323C" stroke-width="1.6"/>

                {{-- Administración --}}
                <rect class="cx-box" x="700" y="30" width="240" height="105" rx="16"/>
                <circle class="cx-icobg" cx="742" cy="72" r="22"/>
                <path class="cx-ico" d="M733 85V61h18v24M729 85h26M738 66h2M744 66h2M738 72h2M744 72h2M740 85v-5h4v5"/>
                <text class="cx-title" x="776" y="68">Administración</text>
                <text class="cx-title" x="776" y="87">del consorcio</text>
                <text class="cx-sub" x="720" y="118">Ve lo que le compartís</text>

                {{-- Personas --}}
                <rect class="cx-box" x="700" y="215" width="240" height="110" rx="16"/>
                <circle class="cx-icobg" cx="742" cy="258" r="22"/>
                <path class="cx-ico" d="M742 254a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm-9 16c1-6 4.5-9 9-9s8 3 9 9"/>
                <text class="cx-title" x="776" y="254">Quienes usan</text>
                <text class="cx-title" x="776" y="273">el ascensor</text>
                <text class="cx-sub" x="720" y="306">Escanean el QR del edificio</text>

                {{-- Conexiones --}}
                <path class="cx-line l1" pathLength="100" d="M260 180H400"/>
                <path class="cx-line l2" pathLength="100" d="M565 125C620 125 640 85 700 85"/>
                <path class="cx-line l3" pathLength="100" d="M556 280C620 280 640 270 700 270"/>
                @if($helpOn)
                    <path class="cx-help" d="M790 325C770 382 250 382 140 235"/>
                    <path class="cx-help" d="M860 215V135"/>
                @endif

                <g class="cx-chip c1"><rect x="268" y="150" width="122" height="22" rx="11"/><text x="329" y="165" text-anchor="middle">Remito firmado</text></g>
                <g class="cx-chip c2"><rect x="585" y="62" width="104" height="22" rx="11"/><text x="637" y="77" text-anchor="middle">Portal del cliente</text></g>
                <g class="cx-chip c3"><rect x="590" y="292" width="100" height="22" rx="11"/><text x="640" y="307" text-anchor="middle">Info pública</text></g>
                @if($helpOn)
                    <g class="cx-chip cx-chip-help c4"><rect x="330" y="356" width="300" height="24" rx="12"/><text x="480" y="372" text-anchor="middle">Pedido de ayuda · si la empresa lo activa</text></g>
                @endif
            </svg>
        </div>

        {{-- Vertical (celular) --}}
        <div class="cx-tall-wrap">
            <svg class="cx-svg cx-tall" viewBox="0 0 360 410" aria-hidden="true" focusable="false">
                <rect class="cx-box" x="20" y="8" width="320" height="64" rx="14"/>
                <circle class="cx-icobg" cx="52" cy="40" r="18"/>
                <path class="cx-ico" d="M57 30a7 7 0 0 0-9.3 9.3L41 46v5h5l6.7-6.7A7 7 0 0 0 62 35l-4 4-4-1-1-4 4-4Z"/>
                <text class="cx-title" x="80" y="36">Empresa de mantenimiento</text>
                <text class="cx-sub" x="80" y="55">Técnicos, visitas y remitos</text>

                <path class="cx-bld" d="M45 330V140L105 110L165 140V330Z"/>
                @foreach([[58,150],[58,185],[58,220],[138,150],[138,185],[138,220]] as [$wx, $wy])
                    <rect class="cx-win" x="{{ $wx }}" y="{{ $wy }}" width="14" height="17" rx="2"/>
                @endforeach
                <rect class="cx-shaft" x="90" y="145" width="30" height="185" rx="3"/>
                <rect class="cx-cabin" x="93" y="175" width="24" height="30" rx="3" style="--cx-cabin-from: 115px"/>
                <g class="cx-qr">
                    <rect class="cx-qrbg" x="128" y="262" width="28" height="28" rx="3"/>
                    <rect x="132" y="266" width="7" height="7"/><rect x="145" y="266" width="7" height="7"/><rect x="132" y="279" width="7" height="7"/><rect x="144" y="278" width="5" height="5"/>
                </g>
                <line x1="38" y1="330" x2="172" y2="330" stroke="#2B323C" stroke-width="1.4"/>

                <rect class="cx-box" x="200" y="120" width="145" height="74" rx="14"/>
                <text class="cx-title" x="214" y="146">Administración</text>
                <text class="cx-title" x="214" y="163">del consorcio</text>
                <text class="cx-sub" x="214" y="181">Portal del cliente</text>

                <rect class="cx-box" x="200" y="236" width="145" height="74" rx="14"/>
                <text class="cx-title" x="214" y="262">Quienes usan</text>
                <text class="cx-title" x="214" y="279">el ascensor</text>
                <text class="cx-sub" x="214" y="297">Escanean el QR</text>

                <path class="cx-line l1" pathLength="100" d="M105 72V108"/>
                <path class="cx-line l2" pathLength="100" d="M165 160C182 160 184 157 200 157"/>
                <path class="cx-line l3" pathLength="100" d="M156 276C178 276 182 273 200 273"/>
                @if($helpOn)
                    <path class="cx-help" d="M272 310C272 388 22 388 22 220C22 130 26 90 40 72"/>
                    <path class="cx-help" d="M300 236V194"/>
                @endif

                <g class="cx-chip c1"><rect x="113" y="80" width="104" height="20" rx="10"/><text x="165" y="94" text-anchor="middle">Remito firmado</text></g>
                @if($helpOn)
                    <g class="cx-chip cx-chip-help c4"><rect x="55" y="372" width="250" height="22" rx="11"/><text x="180" y="387" text-anchor="middle">Pedido de ayuda · si la empresa lo activa</text></g>
                @endif
            </svg>
        </div>
    </div>

    <figcaption id="cx-caption" class="sr-only">Cómo Ascento conecta a la empresa de mantenimiento, la administración del consorcio y quienes usan el ascensor, en {{ $helpOn ? 'cuatro' : 'tres' }} pasos.</figcaption>
    <ol class="cx-steps">
        <li data-cx-step="1"><strong>Tu empresa trabaja ordenada</strong>El técnico hace la visita y la cierra con el remito firmado en el celular.</li>
        <li data-cx-step="2"><strong>La administración está al tanto</strong>Ve en su portal las visitas y lo que le compartís, sin tener que llamar para preguntar.</li>
        <li data-cx-step="3"><strong>El edificio sabe quién lo cuida</strong>Con el QR, vecinos y visitantes consultan la información que decidís publicar.</li>
        @if($helpOn)
            <li data-cx-step="4"><strong>Un canal más, si tu plan lo incluye y lo activás</strong>Desde el QR se puede pedir ayuda por un posible atrapamiento. Avisa a tu empresa y a la administración; no reemplaza la alarma ni el 911.</li>
        @endif
    </ol>
    <button type="button" class="cx-replay" data-cx-replay>Ver de nuevo la animación</button>
</figure>

<script>
    // Secuencia de una sola vez al entrar en pantalla. Sin IntersectionObserver
    // o con movimiento reducido: queda el diagrama completo, quieto.
    (() => {
        const fig = document.querySelector('[data-connect-diagram]')
        if (! fig || ! ('IntersectionObserver' in window)) { return }
        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches
        if (reduce) { return }
        const steps = fig.querySelectorAll('[data-cx-step]').length
        const items = [...fig.querySelectorAll('[data-cx-step]')]
        let timers = []
        const set = (n) => {
            for (let i = 1; i <= 4; i++) { fig.classList.toggle('s' + i, i <= n) }
            items.forEach((li) => li.classList.toggle('is-current', +li.dataset.cxStep === n))
        }
        const play = () => {
            timers.forEach(clearTimeout); timers = []
            fig.classList.remove('is-done'); set(0)
            for (let i = 1; i <= steps; i++) { timers.push(setTimeout(() => set(i), 350 + (i - 1) * 1500)) }
            timers.push(setTimeout(() => { items.forEach((li) => li.classList.remove('is-current')); fig.classList.add('is-done') }, 350 + steps * 1500 + 600))
        }
        fig.classList.add('js')
        set(0)
        const io = new IntersectionObserver((entries) => {
            if (entries.some((e) => e.isIntersecting)) { io.disconnect(); play() }
        }, { threshold: 0.35 })
        io.observe(fig)
        fig.querySelector('[data-cx-replay]').addEventListener('click', play)
    })()
</script>

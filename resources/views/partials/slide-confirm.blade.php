{{--
    «Deslizá para confirmar» (llegada y salida del edificio). La perilla se arrastra con el
    dedo, el mouse o un lápiz (pointer events, igual en iPhone y Android); con el teclado,
    → o Fin la llevan al final. Solo envía al llegar al final; si se suelta antes, vuelve.
    Es role="slider" para los lectores de pantalla. Sin JavaScript, un botón común.
    Parámetros: $action (URL), $label, $name (único en la página).
--}}
@once
    <style>
        .slide-confirm { position: relative; height: 60px; border-radius: 999px; background: #F1F2F4; overflow: hidden; user-select: none; -webkit-user-select: none; touch-action: pan-y; }
        .slide-confirm__fill { position: absolute; inset: 0 auto 0 0; width: 60px; border-radius: 999px; background: #FF6600; pointer-events: none; }
        .slide-confirm__label { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; padding: 0 64px; font-weight: 700; font-size: 15px; color: #11161D; pointer-events: none; text-align: center; }
        .slide-confirm__knob { position: absolute; top: 4px; left: 4px; width: 52px; height: 52px; border: 0; border-radius: 999px; background: #11161D; color: #fff; display: flex; align-items: center; justify-content: center;
            box-shadow: 0 2px 6px rgba(0,0,0,.25); cursor: grab; touch-action: none; }
        .slide-confirm__knob:focus-visible { outline: 3px solid #2E4FBE; outline-offset: 2px; }
        .slide-confirm.is-returning .slide-confirm__knob, .slide-confirm.is-returning .slide-confirm__fill { transition: transform .2s ease, width .2s ease; }
        .slide-confirm[aria-busy="true"] { opacity: .7; }
        @media (prefers-reduced-motion: reduce) { .slide-confirm.is-returning .slide-confirm__knob, .slide-confirm.is-returning .slide-confirm__fill { transition: none; } }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('[data-slide-confirm]').forEach((box) => {
                const knob = box.querySelector('.slide-confirm__knob');
                const fill = box.querySelector('.slide-confirm__fill');
                const form = box.closest('form');
                let startX = 0, offset = 0, dragging = false;
                const max = () => box.clientWidth - knob.offsetWidth - 8;
                const paint = (px) => {
                    offset = Math.max(0, Math.min(px, max()));
                    knob.style.transform = 'translateX(' + offset + 'px)';
                    fill.style.width = (offset + 60) + 'px';
                    knob.setAttribute('aria-valuenow', String(Math.round(offset / Math.max(1, max()) * 100)));
                };
                const back = () => { box.classList.add('is-returning'); paint(0); setTimeout(() => box.classList.remove('is-returning'), 220); };
                const done = () => {
                    if (box.getAttribute('aria-busy') === 'true') return;
                    box.setAttribute('aria-busy', 'true');
                    paint(max());
                    knob.disabled = true;
                    form.submit(); // un envío; el servidor igual ignora un segundo
                };
                knob.addEventListener('pointerdown', (e) => {
                    if (box.getAttribute('aria-busy') === 'true') return;
                    dragging = true; startX = e.clientX - offset;
                    knob.setPointerCapture(e.pointerId);
                    e.preventDefault();
                });
                knob.addEventListener('pointermove', (e) => { if (dragging) paint(e.clientX - startX); });
                const release = () => {
                    if (!dragging) return;
                    dragging = false;
                    offset >= max() * 0.92 ? done() : back();
                };
                knob.addEventListener('pointerup', release);
                knob.addEventListener('pointercancel', () => { dragging = false; back(); });
                knob.addEventListener('keydown', (e) => {
                    if (['ArrowRight', 'End'].includes(e.key)) { e.preventDefault(); done(); }
                });
                // Volver atrás con el navegador: listo para usar de nuevo.
                window.addEventListener('pageshow', () => { box.removeAttribute('aria-busy'); knob.disabled = false; paint(0); });
            });
        });
    </script>
@endonce
<form method="POST" action="{{ $action }}" data-slide-form="{{ $name }}">
    @csrf
    <div class="slide-confirm" data-slide-confirm="{{ $name }}">
        <div class="slide-confirm__fill" aria-hidden="true"></div>
        <div class="slide-confirm__label" aria-hidden="true">{{ $label }}</div>
        <button type="button" class="slide-confirm__knob" role="slider" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"
                aria-label="{{ $label }}: deslizá hasta el final (o tocá la flecha derecha) para confirmar">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </button>
    </div>
    <noscript><button type="submit" class="t-btn-dark mt-2 w-full">{{ $label }}</button></noscript>
</form>

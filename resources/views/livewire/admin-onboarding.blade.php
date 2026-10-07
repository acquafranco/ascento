<div class="asc-onb-root">
    <style>
        .asc-onb [x-cloak] { display: none !important; }
        .asc-onb { --asc-primary: var(--primary-600, #d97706); --asc-primary-soft: var(--primary-50, #fffbeb); font-family: inherit; }
        .asc-onb-backdrop { position: fixed; inset: 0; background: rgba(15, 23, 42, .45); z-index: 9997; pointer-events: none; }
        .asc-onb-spot { position: fixed; z-index: 9998; border-radius: .75rem; pointer-events: none;
            box-shadow: 0 0 0 4px var(--asc-primary), 0 0 0 9999px rgba(15, 23, 42, .45);
            transition: top .25s ease, left .25s ease, width .25s ease, height .25s ease; }
        .asc-onb-card { position: fixed; z-index: 9999; width: min(23rem, calc(100vw - 1.5rem)); background: #fff; color: #0f172a;
            border-radius: 1rem; box-shadow: 0 20px 45px -12px rgba(15, 23, 42, .35); padding: 1.1rem 1.15rem 1rem;
            transition: top .25s ease, left .25s ease; outline: none; }
        .dark .asc-onb-card { background: #18181b; color: #f4f4f5; box-shadow: 0 20px 45px -12px rgba(0, 0, 0, .7); }
        .asc-onb-arrow { position: absolute; width: 14px; height: 14px; background: inherit; transform: rotate(45deg); }
        .asc-onb-progress { font-size: .72rem; font-weight: 600; letter-spacing: .02em; color: var(--asc-primary); }
        .asc-onb-bar { height: 4px; border-radius: 9999px; background: rgba(148, 163, 184, .3); margin: .45rem 0 .8rem; overflow: hidden; }
        .asc-onb-bar > span { display: block; height: 100%; background: var(--asc-primary); border-radius: inherit; transition: width .25s ease; }
        .asc-onb-title { font-size: 1.05rem; font-weight: 700; line-height: 1.3; margin: 0 1.5rem .35rem 0; }
        .asc-onb-body { font-size: .9rem; line-height: 1.5; color: #475569; margin: 0; }
        .dark .asc-onb-body { color: #a1a1aa; }
        .asc-onb-flow { display: flex; flex-wrap: wrap; align-items: center; gap: .35rem; margin-top: .7rem; font-size: .78rem; font-weight: 600; }
        .asc-onb-flow span.asc-chip { background: var(--asc-primary-soft); color: var(--asc-primary); padding: .2rem .55rem; border-radius: 9999px; }
        .dark .asc-onb-flow span.asc-chip { background: rgba(255, 255, 255, .08); }
        .asc-onb-actions { display: flex; align-items: center; gap: .5rem; margin-top: 1rem; }
        .asc-onb-spacer { flex: 1; }
        .asc-onb-btn { border: 0; border-radius: .6rem; padding: .5rem .9rem; font-size: .85rem; font-weight: 600; cursor: pointer; line-height: 1.2; }
        .asc-onb-btn-primary { background: var(--asc-primary); color: #fff; }
        .asc-onb-btn-primary:hover { filter: brightness(1.07); }
        .asc-onb-btn-ghost { background: transparent; color: #64748b; padding-left: .3rem; padding-right: .3rem; }
        .asc-onb-btn-ghost:hover { color: #0f172a; }
        .dark .asc-onb-btn-ghost:hover { color: #fff; }
        .asc-onb-btn-soft { background: rgba(148, 163, 184, .18); color: inherit; }
        .asc-onb-close { position: absolute; top: .55rem; right: .55rem; width: 1.9rem; height: 1.9rem; border: 0; border-radius: 9999px;
            background: transparent; color: #94a3b8; cursor: pointer; font-size: 1.2rem; line-height: 1; }
        .asc-onb-close:hover { background: rgba(148, 163, 184, .18); color: inherit; }
        .asc-onb-check { list-style: none; padding: 0; margin: .75rem 0 0; display: grid; gap: .35rem; }
        .asc-onb-check a { display: flex; gap: .5rem; align-items: flex-start; font-size: .85rem; color: inherit; text-decoration: none; padding: .3rem .4rem; border-radius: .5rem; }
        .asc-onb-check a:hover { background: rgba(148, 163, 184, .14); }
        .asc-onb-tick { flex: 0 0 1.1rem; height: 1.1rem; border-radius: 9999px; border: 2px solid #cbd5e1; display: inline-flex; align-items: center; justify-content: center; font-size: .7rem; color: #fff; margin-top: .05rem; }
        .asc-onb-tick.is-done { background: #16a34a; border-color: #16a34a; }
        .asc-onb-check .is-done-text { color: #94a3b8; text-decoration: line-through; }
        .asc-onb-help { top: 4.25rem; right: .75rem; }
        .asc-onb-help h3 { font-size: .75rem; text-transform: uppercase; letter-spacing: .05em; color: #94a3b8; margin: 1rem 0 0; font-weight: 700; }
        @media (max-width: 640px) { .asc-onb-card { padding: 1rem; } .asc-onb-body { font-size: .88rem; } }
        @media (prefers-reduced-motion: reduce) { .asc-onb-spot, .asc-onb-card, .asc-onb-bar > span { transition: none; } }
    </style>

    <script>
        window.ascentoOnboarding ??= ({ steps, autoStart, storageKey }) => ({
            steps,
            active: false,
            helpOpen: false,
            index: 0,
            spot: null,
            arrow: null,
            cardStyle: '',
            openedSidebar: false,
            listeners: null,

            get step() { return this.steps[this.index] ?? this.steps[0] },
            get isLast() { return this.index === this.steps.length - 1 },

            init() {
                if (autoStart && ! this.read(sessionStorage, 'paused')) {
                    this.start(this.savedIndex())
                }
            },

            // localStorage/sessionStorage pueden no estar disponibles
            // (modo privado, bloqueados): la guía funciona igual sin ellos.
            read(store, suffix) {
                try { return store.getItem(`${storageKey}-${suffix}`) } catch (e) { return null }
            },
            write(store, suffix, value) {
                try {
                    value === null ? store.removeItem(`${storageKey}-${suffix}`) : store.setItem(`${storageKey}-${suffix}`, value)
                } catch (e) {}
            },
            savedIndex() {
                const saved = parseInt(this.read(localStorage, 'step') ?? '0', 10)
                return Number.isInteger(saved) && saved > 0 && saved < this.steps.length - 1 ? saved : 0
            },

            start(index = 0) {
                this.helpOpen = false
                this.index = index
                this.active = true
                this.write(sessionStorage, 'paused', null)
                this.bind()
                this.go()
            },

            next() { this.isLast ? this.finish() : (this.index++, this.go()) },
            back() { if (this.index > 0) { this.index--; this.go() } },

            // "Omitir": no vuelve a abrirse sola (se puede reabrir desde Ayuda).
            skip() { this.$wire.skip(); this.end(true) },
            finish() { this.$wire.complete(); this.end(true) },

            // Cerrar con la X: se retoma en el mismo paso la próxima vez
            // que entre (no en cada página de esta sesión).
            pause() {
                this.write(sessionStorage, 'paused', '1')
                this.end(false)
            },
            onEscape() {
                if (this.active) { this.pause() } else { this.helpOpen = false }
            },

            end(forget) {
                if (forget) { this.write(localStorage, 'step', null) }
                this.active = false
                this.spot = null
                this.unbind()
                if (this.openedSidebar && this.isMobile()) { Alpine.store('sidebar')?.close() }
                this.openedSidebar = false
            },

            toggleHelp() {
                if (this.active) { return }
                this.helpOpen = ! this.helpOpen
            },

            isMobile() { return window.innerWidth < 1024 },

            target() {
                if (! this.step.target) { return null }
                const selector = `a[href="${this.step.target}"]`
                return document.querySelector(`.fi-main-sidebar ${selector}`) ?? document.querySelector(selector)
            },

            go() {
                if (this.index > 0) { this.write(localStorage, 'step', String(this.index)) }

                const el = this.target()

                if (! el) {
                    // Bienvenida/cierre (o un ítem que no está en el menú):
                    // tarjeta centrada, sin señalar nada.
                    this.spot = null
                    this.arrow = null
                    this.$nextTick(() => this.placeCentered())
                    return
                }

                let wait = 0
                const sidebar = Alpine.store('sidebar')

                // En celular/tablet el menú está escondido: se abre.
                if (this.isMobile() && sidebar && ! sidebar.isOpen) {
                    sidebar.open()
                    this.openedSidebar = true
                    wait = 320
                }

                // Si el grupo del menú está colapsado, se expande.
                if (el.offsetParent === null && sidebar && Array.isArray(sidebar.collapsedGroups)) {
                    sidebar.collapsedGroups = []
                    wait = Math.max(wait, 120)
                }

                setTimeout(() => {
                    el.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
                    setTimeout(() => this.place(el), 180)
                }, wait)
            },

            placeCentered() {
                const card = this.$refs.card
                if (! card) { return }
                const top = Math.max(12, (window.innerHeight - card.offsetHeight) / 2)
                const left = Math.max(12, (window.innerWidth - card.offsetWidth) / 2)
                this.cardStyle = `top:${top}px;left:${left}px`
                card.focus({ preventScroll: true })
            },

            place(el) {
                if (! this.active) { return }
                const card = this.$refs.card
                const r = el.getBoundingClientRect()

                if (! card || r.width === 0) { return this.placeCentered() }

                const pad = 6
                this.spot = { top: r.top - pad, left: r.left - pad, width: r.width + pad * 2, height: r.height + pad * 2 }

                const cw = card.offsetWidth
                const ch = card.offsetHeight
                const gap = 18
                const margin = 12
                const clamp = (v, min, max) => Math.min(Math.max(v, min), max)

                // Escritorio con lugar a la derecha del menú: al costado, con flecha.
                if (! this.isMobile() && r.right + gap + cw + margin <= window.innerWidth) {
                    const top = clamp(r.top + r.height / 2 - ch / 2, margin, window.innerHeight - ch - margin)
                    this.cardStyle = `top:${top}px;left:${r.right + gap}px`
                    this.arrow = `left:-7px;top:${clamp(r.top + r.height / 2 - top - 7, 14, ch - 28)}px`
                } else {
                    // Celular/tablet: arriba o abajo, donde no tape el ítem.
                    const below = r.bottom + gap + ch + margin <= window.innerHeight
                    const top = below ? r.bottom + gap : Math.max(margin, r.top - gap - ch)
                    const left = clamp(r.left, margin, window.innerWidth - cw - margin)
                    this.cardStyle = `top:${top}px;left:${left}px`
                    this.arrow = below
                        ? `top:-7px;left:${clamp(r.left + 24 - left, 14, cw - 28)}px`
                        : `bottom:-7px;left:${clamp(r.left + 24 - left, 14, cw - 28)}px`
                }

                card.focus({ preventScroll: true })
            },

            bind() {
                if (this.listeners) { return }
                const reposition = () => {
                    if (! this.active) { return }
                    const el = this.target()
                    el ? this.place(el) : this.placeCentered()
                }
                this.listeners = reposition
                window.addEventListener('resize', reposition)
                window.addEventListener('scroll', reposition, true)
            },
            unbind() {
                if (! this.listeners) { return }
                window.removeEventListener('resize', this.listeners)
                window.removeEventListener('scroll', this.listeners, true)
                this.listeners = null
            },
        })
    </script>

<div
    class="asc-onb"
    x-data="ascentoOnboarding({
        steps: @js($steps),
        autoStart: @js($autoStart),
        storageKey: @js($userKey),
    })"
    x-on:ascento-help.window="toggleHelp()"
    x-on:keydown.escape.window="onEscape()"
    data-autostart="{{ $autoStart ? 'true' : 'false' }}"
>


    {{-- ============================ GUÍA ============================ --}}
    <template x-if="active">
        <div>
            <div class="asc-onb-backdrop" x-show="! spot" x-cloak></div>
            <div class="asc-onb-spot" x-show="spot" x-cloak
                 x-bind:style="spot && `top:${spot.top}px;left:${spot.left}px;width:${spot.width}px;height:${spot.height}px`"></div>

            <div class="asc-onb-card" x-ref="card" role="dialog" aria-modal="false" tabindex="-1"
                 aria-labelledby="asc-onb-title" aria-describedby="asc-onb-body"
                 x-bind:style="cardStyle">

                <span class="asc-onb-arrow" x-show="arrow" x-cloak x-bind:style="arrow"></span>

                <button type="button" class="asc-onb-close" x-on:click="pause()"
                        title="Cerrar y seguir después" aria-label="Cerrar y seguir después">&times;</button>

                <template x-if="index > 0 && index < steps.length - 1">
                    <div>
                        <div class="asc-onb-progress" x-text="`Paso ${index} de ${steps.length - 2}`"></div>
                        <div class="asc-onb-bar"><span x-bind:style="`width:${(index / (steps.length - 2)) * 100}%`"></span></div>
                    </div>
                </template>

                <p class="asc-onb-title" id="asc-onb-title" x-text="step.title"></p>
                <p class="asc-onb-body" id="asc-onb-body" x-text="step.body"></p>

                <template x-if="step.flow">
                    <div class="asc-onb-flow">
                        <template x-for="(item, i) in step.flow" :key="i">
                            <span style="display:contents">
                                <span class="asc-chip" x-text="item"></span>
                                <span x-show="i < step.flow.length - 1" aria-hidden="true">→</span>
                            </span>
                        </template>
                    </div>
                </template>

                {{-- Cierre: primeros pasos con el progreso real de la empresa --}}
                <template x-if="isLast">
                    <ul class="asc-onb-check">
                        @foreach ($checklist as $item)
                            <li>
                                <a href="{{ $item['url'] }}">
                                    <span class="asc-onb-tick {{ $item['done'] ? 'is-done' : '' }}">{{ $item['done'] ? '✓' : '' }}</span>
                                    <span class="{{ $item['done'] ? 'is-done-text' : '' }}">{{ $item['label'] }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </template>

                <div class="asc-onb-actions">
                    <template x-if="index === 0">
                        <button type="button" class="asc-onb-btn asc-onb-btn-ghost" x-on:click="skip()">Omitir guía</button>
                    </template>
                    <template x-if="index > 0 && ! isLast">
                        <button type="button" class="asc-onb-btn asc-onb-btn-ghost" x-on:click="skip()">Omitir</button>
                    </template>

                    <span class="asc-onb-spacer"></span>

                    <template x-if="index > 0">
                        <button type="button" class="asc-onb-btn asc-onb-btn-soft" x-on:click="back()">Atrás</button>
                    </template>

                    <button type="button" class="asc-onb-btn asc-onb-btn-primary" x-on:click="next()"
                            x-text="index === 0 ? 'Comenzar' : (isLast ? 'Terminar' : 'Siguiente')"></button>
                </div>
            </div>
        </div>
    </template>

    {{-- ============================ AYUDA ============================ --}}
    <div class="asc-onb-card asc-onb-help" x-show="helpOpen && ! active" x-cloak x-transition.opacity
         role="dialog" aria-label="Ayuda" x-on:click.outside="helpOpen = false">
        <button type="button" class="asc-onb-close" x-on:click="helpOpen = false" aria-label="Cerrar ayuda">&times;</button>

        <p class="asc-onb-title">{{ $sectionHelp['title'] }}</p>
        <p class="asc-onb-body">{{ $sectionHelp['body'] }}</p>

        <div class="asc-onb-actions" style="flex-wrap: wrap">
            <button type="button" class="asc-onb-btn asc-onb-btn-primary" x-on:click="start(0)" data-ascento-restart>Ver la guía desde el principio</button>
            <template x-if="savedIndex() > 0">
                <button type="button" class="asc-onb-btn asc-onb-btn-soft" x-on:click="start(savedIndex())">Continuar donde la dejé</button>
            </template>
        </div>

        <h3>Primeros pasos</h3>
        <ul class="asc-onb-check">
            @foreach ($checklist as $item)
                <li>
                    <a href="{{ $item['url'] }}">
                        <span class="asc-onb-tick {{ $item['done'] ? 'is-done' : '' }}">{{ $item['done'] ? '✓' : '' }}</span>
                        <span class="{{ $item['done'] ? 'is-done-text' : '' }}">{{ $item['label'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </div>


</div>
</div>

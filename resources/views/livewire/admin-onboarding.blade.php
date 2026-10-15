<div class="asc-onb-root">
    <style>
        .asc-onb [x-cloak] { display: none !important; }
        .asc-onb { --asc-primary: var(--primary-600, #d97706); --asc-primary-soft: var(--primary-50, #fffbeb); font-family: inherit; }
        .asc-onb-backdrop { position: fixed; inset: 0; background: rgba(15, 23, 42, .45); z-index: 9997; pointer-events: none; }
        .asc-onb-spot { position: fixed; z-index: 9998; border-radius: .75rem; pointer-events: none;
            box-shadow: 0 0 0 4px var(--asc-primary), 0 0 0 9999px rgba(15, 23, 42, .45);
            transition: top .25s ease, left .25s ease, width .25s ease, height .25s ease; }
        .asc-onb-card { position: fixed; z-index: 9999; width: min(24rem, calc(100vw - 1.5rem)); background: #fff; color: #0f172a;
            border-radius: 1rem; box-shadow: 0 20px 45px -12px rgba(15, 23, 42, .35); padding: 1.1rem 1.15rem 1rem;
            transition: top .25s ease, left .25s ease; outline: none; max-height: calc(100vh - 1.5rem); overflow-y: auto; }
        .dark .asc-onb-card { background: #18181b; color: #f4f4f5; box-shadow: 0 20px 45px -12px rgba(0, 0, 0, .7); }
        .asc-onb-arrow { position: absolute; width: 14px; height: 14px; background: inherit; transform: rotate(45deg); }
        .asc-onb-progress { font-size: .72rem; font-weight: 600; letter-spacing: .02em; color: var(--asc-primary); }
        .asc-onb-bar { height: 4px; border-radius: 9999px; background: rgba(148, 163, 184, .3); margin: .45rem 0 .8rem; overflow: hidden; }
        .asc-onb-bar > span { display: block; height: 100%; background: var(--asc-primary); border-radius: inherit; transition: width .25s ease; }
        .asc-onb-title { font-size: 1.05rem; font-weight: 700; line-height: 1.3; margin: 0 1.5rem .35rem 0; }
        .asc-onb-body { font-size: .9rem; line-height: 1.5; color: #475569; margin: 0; }
        .dark .asc-onb-body { color: #a1a1aa; }
        .asc-onb-note { font-size: .8rem; line-height: 1.4; color: #64748b; margin: .6rem 0 0; padding: .45rem .6rem; border-radius: .5rem; background: rgba(148, 163, 184, .14); }
        .asc-onb-flow { display: flex; flex-wrap: wrap; align-items: center; gap: .35rem; margin-top: .7rem; font-size: .78rem; font-weight: 600; }
        .asc-onb-flow span.asc-chip { background: var(--asc-primary-soft); color: var(--asc-primary); padding: .2rem .55rem; border-radius: 9999px; }
        .dark .asc-onb-flow span.asc-chip { background: rgba(255, 255, 255, .08); }
        .asc-onb-list { list-style: none; padding: 0; margin: .6rem 0 0; display: grid; gap: .35rem; font-size: .83rem; line-height: 1.4; }
        .asc-onb-list strong { display: block; font-size: .85rem; }
        .asc-onb-list span { color: #64748b; }
        .dark .asc-onb-list span { color: #a1a1aa; }
        .asc-onb-actions { display: flex; align-items: center; gap: .5rem; margin-top: 1rem; flex-wrap: wrap; }
        .asc-onb-spacer { flex: 1; }
        .asc-onb-btn { border: 0; border-radius: .6rem; padding: .5rem .9rem; font-size: .85rem; font-weight: 600; cursor: pointer; line-height: 1.2; text-decoration: none; display: inline-flex; align-items: center; }
        .asc-onb-btn:focus-visible, .asc-onb-close:focus-visible, .asc-onb-link:focus-visible, .asc-onb-tour:focus-visible { outline: 2px solid var(--asc-primary); outline-offset: 2px; }
        .asc-onb-btn-primary { background: var(--asc-primary); color: #fff; }
        .asc-onb-btn-primary:hover { filter: brightness(1.07); }
        .asc-onb-btn-ghost { background: transparent; color: #64748b; padding-left: .3rem; padding-right: .3rem; }
        .asc-onb-btn-ghost:hover { color: #0f172a; }
        .dark .asc-onb-btn-ghost:hover { color: #fff; }
        .asc-onb-btn-soft { background: rgba(148, 163, 184, .18); color: inherit; }
        .asc-onb-close { position: absolute; top: .55rem; right: .55rem; width: 1.9rem; height: 1.9rem; border: 0; border-radius: 9999px;
            background: transparent; color: #94a3b8; cursor: pointer; font-size: 1.2rem; line-height: 1; }
        .asc-onb-close:hover { background: rgba(148, 163, 184, .18); color: inherit; }
        .asc-onb-link { color: var(--asc-primary); font-weight: 600; font-size: .85rem; text-decoration: none; }
        .asc-onb-link:hover { text-decoration: underline; }
        .asc-onb-check { list-style: none; padding: 0; margin: .75rem 0 0; display: grid; gap: .35rem; }
        .asc-onb-check a { display: flex; gap: .5rem; align-items: flex-start; font-size: .85rem; color: inherit; text-decoration: none; padding: .3rem .4rem; border-radius: .5rem; }
        .asc-onb-check a:hover { background: rgba(148, 163, 184, .14); }
        .asc-onb-tick { flex: 0 0 1.1rem; height: 1.1rem; border-radius: 9999px; border: 2px solid #cbd5e1; display: inline-flex; align-items: center; justify-content: center; font-size: .7rem; color: #fff; margin-top: .05rem; }
        .asc-onb-tick.is-done { background: #16a34a; border-color: #16a34a; }
        .asc-onb-check .is-done-text { color: #94a3b8; text-decoration: line-through; }
        .asc-onb-help { top: 4.25rem; right: .75rem; max-height: calc(100vh - 5rem); }
        .asc-onb-help h3 { font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; color: #94a3b8; margin: 1.1rem 0 .4rem; font-weight: 700; }
        .asc-onb-guides { list-style: none; padding: 0; margin: .5rem 0 0; display: grid; gap: .15rem; }
        .asc-onb-guides a { font-size: .85rem; }
        .asc-onb-tours { display: grid; gap: .35rem; }
        .asc-onb-tour { display: flex; align-items: center; justify-content: space-between; gap: .5rem; width: 100%; text-align: left; border: 1px solid rgba(148, 163, 184, .35); background: transparent; color: inherit; border-radius: .6rem; padding: .5rem .65rem; font-size: .85rem; font-weight: 600; cursor: pointer; }
        .asc-onb-tour:hover { border-color: var(--asc-primary); }
        .asc-onb-tour small { font-weight: 500; color: #94a3b8; white-space: nowrap; }
        @media (max-width: 640px) { .asc-onb-card { padding: 1rem; } .asc-onb-body { font-size: .88rem; } .asc-onb-help { top: 4rem; right: .5rem; left: .5rem; width: auto; } }
        @media (prefers-reduced-motion: reduce) { .asc-onb-spot, .asc-onb-card, .asc-onb-bar > span { transition: none; } }
    </style>

    <script>
        // Introducción (pasos del servidor; su avance y su estado viven en el
        // servidor) y recorrido del menú por categorías (solo en el navegador).
        window.ascentoOnboarding ??= ({ steps, menu, autoStart, savedStep }) => ({
            intro: steps,
            menu,
            steps,
            mode: 'intro',
            tourGroup: null,
            saved: savedStep,
            active: false,
            // Un modal de Filament abierto (campanita, acciones): la guía se
            // oculta para no quedar encima y su Esc no la cierra.
            modalOpen: false,
            helpOpen: false,
            index: 0,
            spot: null,
            arrow: null,
            cardStyle: '',
            missing: false,
            openedSidebar: false,
            openedGroups: [],
            listeners: null,

            get step() { return this.steps[this.index] ?? this.steps[0] },
            get isLast() { return this.index === this.steps.length - 1 },
            get isTour() { return this.mode === 'tour' },

            init() {
                if (! autoStart) { return }
                // Después de que Filament acomode el menú (en celular lo cierra
                // al cargar): si no, se señala un ítem que ya no está a la vista.
                const begin = () => requestAnimationFrame(() => this.start(this.savedIndex()))
                document.readyState === 'complete' ? begin() : window.addEventListener('load', begin, { once: true })
            },

            savedIndex() {
                const saved = parseInt(this.saved ?? 0, 10)
                return Number.isInteger(saved) && saved > 0 && saved < this.intro.length ? saved : 0
            },
            remember() {
                if (this.isTour || this.saved === this.index) { return }
                this.saved = this.index
                this.$wire.saveStep(this.index)
            },

            // ---------------------------- Introducción ----------------------------
            start(index = 0) {
                this.reset()
                this.mode = 'intro'
                this.steps = this.intro
                this.helpOpen = false
                this.index = index
                this.active = true
                this.bind()
                this.go()
            },

            // ------------------------ Recorrido de una categoría ------------------------
            startTour(g) {
                const group = this.menu[g]
                if (! group) { return }
                this.reset()
                this.mode = 'tour'
                this.tourGroup = g
                const names = group.items.map((i) => i.label)
                this.steps = [
                    { key: 'group', title: group.title, body: group.intro, flow: group.flow, group: group.label, note: 'Tiene ' + (names.length === 1 ? 'una opción: ' : names.length + ' opciones: ') + names.join(', ') + '.' },
                    ...group.items.map((item) => ({ key: item.url, title: item.label, body: item.body, target: item.url, guide: item.guide, open: item.url })),
                    { key: 'end', title: 'Listo: ya conocés «' + group.title + '»', body: g < this.menu.length - 1 ? 'Podés seguir con «' + this.menu[g + 1].title + '» o terminar acá. Lo que abrió la guía en el menú vuelve a quedar como estaba.' : 'Ya recorriste la última categoría. Lo que abrió la guía en el menú vuelve a quedar como estaba.' },
                ]
                this.helpOpen = false
                this.index = 0
                this.active = true
                this.bind()
                this.go()
            },
            nextGroup() {
                const next = this.tourGroup + 1
                this.end()
                if (next < this.menu.length) { this.startTour(next) }
            },

            next() { this.isLast ? this.finish() : (this.index++, this.go()) },
            back() { if (this.index > 0) { this.index--; this.go() } },
            // «Saltar categoría»: al cierre del recorrido (desde ahí, la siguiente o terminar).
            skipItem() { this.index = this.steps.length - 1; this.go() },

            // Introducción: «Omitir», la X y Esc la descartan (no vuelve a abrirse sola).
            skip() { if (! this.isTour) { this.$wire.skip(); this.saved = 0 } this.end() },
            finish() { if (! this.isTour) { this.$wire.complete(); this.saved = 0 } this.end() },
            close() { this.skip() },

            onEscape() {
                if (this.modalOpen) { return }
                if (this.active) { this.close() } else { this.helpOpen = false }
            },
            modalClosed() {
                this.modalOpen = false
                if (! this.active) { return }
                this.$nextTick(() => this.go(false))
            },

            reset() {
                if (this.active) { this.end(false) }
            },
            end(focusHelp = true) {
                this.active = false
                this.spot = null
                this.missing = false
                this.unbind()
                // El menú vuelve a quedar como estaba: se cierran las categorías que abrió la guía.
                const sidebar = Alpine.store('sidebar')
                this.openedGroups.forEach((label) => { if (sidebar && ! sidebar.groupIsCollapsed(label)) { sidebar.toggleCollapsedGroup(label) } })
                this.openedGroups = []
                if (this.openedSidebar && this.isMobile()) { sidebar?.close() }
                this.openedSidebar = false
                if (focusHelp) { this.$nextTick(() => document.querySelector('[data-ascento-help]')?.focus({ preventScroll: true })) }
            },

            toggleHelp() {
                if (this.active) { return }
                this.helpOpen = ! this.helpOpen
                if (this.helpOpen) { this.$nextTick(() => this.$refs.helpPanel?.focus({ preventScroll: true })) }
            },

            isMobile() { return window.innerWidth < 1024 },

            // ¿Qué señala este paso? (un ítem del menú, una categoría o el botón «?»)
            find() {
                const s = this.step
                if (s.target) {
                    const selector = `a[href="${s.target}"]`
                    return { el: document.querySelector(`.fi-main-sidebar ${selector}`) ?? document.querySelector(selector), sidebar: true }
                }
                if (s.group) {
                    const g = [...document.querySelectorAll('.fi-main-sidebar .fi-sidebar-group')].find((x) => x.dataset.groupLabel === s.group)
                    return { el: g?.querySelector('.fi-sidebar-group-btn') ?? null, sidebar: true }
                }
                if (s.help) { return { el: document.querySelector('[data-ascento-help]'), sidebar: false } }
                return { el: null, sidebar: false }
            },
            visible(el) {
                if (! el || el.closest('[style*="display: none"]')) { return false }
                const r = el.getBoundingClientRect()
                return r.width > 0 && r.height > 0 && r.right > 0 && r.left < window.innerWidth
            },

            go(scroll = true) {
                this.remember()
                this.missing = false
                const { el, sidebar: inSidebar } = this.find()
                const store = Alpine.store('sidebar')

                if (! el) {
                    // Bienvenida/cierre (o algo que no está en el menú): tarjeta centrada, sin señalar nada.
                    this.missing = !! (this.step.target || this.step.group)
                    this.spot = null
                    this.arrow = null
                    this.$nextTick(() => this.placeCentered())
                    return
                }

                let wait = 0
                if (inSidebar && this.isMobile() && store && ! store.isOpen) {
                    // En celular/tablet el menú está escondido: se abre.
                    store.open()
                    this.openedSidebar = true
                    wait = 320
                } else if (! inSidebar && this.isMobile() && store?.isOpen && this.openedSidebar) {
                    // El botón «?» está arriba: se cierra el menú que abrió la guía.
                    store.close()
                    this.openedSidebar = false
                    wait = 320
                }

                // La opción está dentro de una categoría cerrada: se abre solo esa (y se cierra al terminar).
                const group = this.step.target ? el.closest('.fi-sidebar-group') : null
                const label = group?.dataset.groupLabel
                if (label && store?.groupIsCollapsed(label)) {
                    store.toggleCollapsedGroup(label)
                    if (! this.openedGroups.includes(label)) { this.openedGroups.push(label) }
                    wait = Math.max(wait, 260) // Filament la despliega en 200 ms
                }

                setTimeout(() => {
                    if (! this.active) { return }
                    if (scroll) { el.scrollIntoView({ block: 'nearest', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' }) }
                    setTimeout(() => this.place(el), scroll ? 200 : 0)
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
                // Nunca se señala algo que no se ve (por ejemplo, adentro de una categoría cerrada).
                if (! card || ! this.visible(el)) {
                    this.missing = true
                    this.spot = null
                    this.arrow = null
                    return this.$nextTick(() => this.placeCentered())
                }
                const r = el.getBoundingClientRect()

                const pad = 6
                this.spot = { top: r.top - pad, left: r.left - pad, width: r.width + pad * 2, height: r.height + pad * 2 }

                const cw = card.offsetWidth
                const ch = card.offsetHeight
                const gap = 18
                const margin = 12
                const clamp = (v, min, max) => Math.min(Math.max(v, min), max)

                if (! this.isMobile() && r.right + gap + cw + margin <= window.innerWidth) {
                    // Escritorio con lugar a la derecha: al costado, con flecha.
                    const top = clamp(r.top + r.height / 2 - ch / 2, margin, window.innerHeight - ch - margin)
                    this.cardStyle = `top:${top}px;left:${r.right + gap}px`
                    this.arrow = `left:-7px;top:${clamp(r.top + r.height / 2 - top - 7, 14, ch - 28)}px`
                } else {
                    // Celular/tablet o cerca del borde: arriba o abajo, donde no tape lo señalado.
                    const below = r.bottom + gap + ch + margin <= window.innerHeight
                    const top = below ? r.bottom + gap : Math.max(margin, r.top - gap - ch)
                    const left = clamp(r.left + r.width / 2 - cw / 2, margin, window.innerWidth - cw - margin)
                    this.cardStyle = `top:${top}px;left:${left}px`
                    const x = clamp(r.left + r.width / 2 - left - 7, 14, cw - 28)
                    this.arrow = below ? `top:-7px;left:${x}px` : `bottom:-7px;left:${x}px`
                }

                card.focus({ preventScroll: true })
            },

            bind() {
                if (this.listeners) { return }
                const reposition = () => { if (this.active) { this.go(false) } }
                this.listeners = reposition
                window.addEventListener('resize', reposition)
            },
            unbind() {
                if (! this.listeners) { return }
                window.removeEventListener('resize', this.listeners)
                this.listeners = null
            },
        })
    </script>

<div
    class="asc-onb"
    x-data="ascentoOnboarding({
        steps: @js($steps),
        menu: @js($menu),
        autoStart: @js($autoStart),
        savedStep: @js($savedStep),
    })"
    x-on:ascento-help.window="toggleHelp()"
    x-on:keydown.escape.window="onEscape()"
    x-on:x-modal-opened.document="modalOpen = true"
    x-on:modal-closed.window="modalClosed()"
    data-autostart="{{ $autoStart ? 'true' : 'false' }}"
>

    {{-- ============================ GUÍA ============================ --}}
    <template x-if="active">
        <div x-show="! modalOpen">
            <div class="asc-onb-backdrop" x-show="! spot" x-cloak></div>
            <div class="asc-onb-spot" x-show="spot" x-cloak
                 x-bind:style="spot && `top:${spot.top}px;left:${spot.left}px;width:${spot.width}px;height:${spot.height}px`"></div>

            <div class="asc-onb-card" x-ref="card" role="dialog" aria-modal="false" tabindex="-1"
                 aria-labelledby="asc-onb-title" aria-describedby="asc-onb-body"
                 x-bind:style="cardStyle" data-onboarding-card x-bind:data-mode="mode">

                <span class="asc-onb-arrow" x-show="arrow && spot" x-cloak x-bind:style="arrow"></span>

                <button type="button" class="asc-onb-close" x-on:click="close()"
                        x-bind:title="isTour ? 'Cerrar el recorrido' : 'Cerrar la guía'" x-bind:aria-label="isTour ? 'Cerrar el recorrido' : 'Cerrar la guía'">&times;</button>

                <template x-if="isTour ? index < steps.length - 1 : (index > 0 && index < steps.length - 1)">
                    <div>
                        <div class="asc-onb-progress" x-text="isTour ? `${steps[0].title} · ${index + 1} de ${steps.length - 1}` : `Paso ${index} de ${steps.length - 2}`"></div>
                        <div class="asc-onb-bar"><span x-bind:style="`width:${isTour ? ((index + 1) / (steps.length - 1)) * 100 : (index / (steps.length - 2)) * 100}%`"></span></div>
                    </div>
                </template>

                <p class="asc-onb-title" id="asc-onb-title" x-text="step.title" aria-live="polite"></p>
                <p class="asc-onb-body" id="asc-onb-body" x-text="step.body"></p>

                <template x-if="step.note">
                    <p class="asc-onb-note" x-text="step.note"></p>
                </template>
                <template x-if="missing">
                    <p class="asc-onb-note" data-onboarding-missing>No encontramos esta opción en el menú en este momento. Podés seguir con el próximo paso.</p>
                </template>

                <template x-if="step.flow">
                    <div class="asc-onb-flow" aria-label="Orden sugerido">
                        <template x-for="(item, i) in step.flow" :key="i">
                            <span style="display:contents">
                                <span class="asc-chip" x-text="item"></span>
                                <span x-show="i < step.flow.length - 1" aria-hidden="true">→</span>
                            </span>
                        </template>
                    </div>
                </template>

                <template x-if="step.list && step.list.length">
                    <ul class="asc-onb-list">
                        <template x-for="(item, i) in step.list" :key="i">
                            <li><strong x-text="item.title"></strong><span x-text="item.text"></span></li>
                        </template>
                    </ul>
                </template>

                <template x-if="step.guide || step.open">
                    <div class="asc-onb-actions" style="margin-top:.6rem">
                        <template x-if="step.guide"><a class="asc-onb-link" x-bind:href="step.guide">Ver la guía</a></template>
                        <template x-if="step.open"><a class="asc-onb-link" x-bind:href="step.open" x-on:click="end(false)">Abrir esta sección</a></template>
                    </div>
                </template>

                {{-- Cierre de la introducción del dueño: primeros pasos con el progreso real de la empresa --}}
                @if($checklist !== [])
                    <template x-if="! isTour && isLast">
                        <ul class="asc-onb-check">
                            @foreach ($checklist as $i => $item)
                                <li>
                                    {{-- Es el final de la guía: se da por terminada y se va a esa pantalla. --}}
                                    <a href="{{ $item['url'] }}" wire:click.prevent="openChecklistItem({{ $i }})" data-onboarding-checklist>
                                        <span class="asc-onb-tick {{ $item['done'] ? 'is-done' : '' }}">{{ $item['done'] ? '✓' : '' }}</span>
                                        <span class="{{ $item['done'] ? 'is-done-text' : '' }}">{{ $item['label'] }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </template>
                @endif

                <div class="asc-onb-actions">
                    <template x-if="! isTour && ! isLast">
                        <button type="button" class="asc-onb-btn asc-onb-btn-ghost" x-on:click="skip()" x-text="index === 0 ? 'Omitir guía' : 'Omitir'"></button>
                    </template>
                    <template x-if="isTour && ! isLast">
                        <button type="button" class="asc-onb-btn asc-onb-btn-ghost" x-on:click="skipItem()">Saltar categoría</button>
                    </template>

                    <span class="asc-onb-spacer"></span>

                    <template x-if="index > 0">
                        <button type="button" class="asc-onb-btn asc-onb-btn-soft" x-on:click="back()">Atrás</button>
                    </template>

                    <template x-if="isTour && isLast && tourGroup < menu.length - 1">
                        <button type="button" class="asc-onb-btn asc-onb-btn-soft" x-on:click="nextGroup()">Siguiente categoría</button>
                    </template>

                    <button type="button" class="asc-onb-btn asc-onb-btn-primary" x-on:click="next()"
                            x-text="! isTour && index === 0 ? 'Comenzar' : (isLast ? 'Terminar' : 'Siguiente')"></button>
                </div>
            </div>
        </div>
    </template>

    {{-- ============================ AYUDA («?») ============================ --}}
    <div class="asc-onb-card asc-onb-help" x-show="helpOpen && ! active" x-cloak x-transition.opacity x-ref="helpPanel" tabindex="-1"
         role="dialog" aria-labelledby="asc-help-title" x-on:click.outside="if (! $event.target.closest('[data-ascento-help]')) helpOpen = false" data-help-panel>
        <button type="button" class="asc-onb-close" x-on:click="helpOpen = false" aria-label="Cerrar ayuda">&times;</button>

        <p class="asc-onb-progress" id="asc-help-title">Ayuda</p>
        <h3 style="margin-top:.35rem">En esta pantalla</h3>
        <p class="asc-onb-title" style="font-size:.98rem">{{ $sectionHelp['title'] }}</p>
        <p class="asc-onb-body">{{ $sectionHelp['body'] }}</p>
        @if($screenGuides !== [])
            <ul class="asc-onb-guides" data-help-screen-guides>
                @foreach($screenGuides as $g)
                    <li><a class="asc-onb-link" href="{{ \App\Support\Help\HelpGuides::url('panel', $g['key'], ['desde' => $from]) }}" data-guide-link="{{ $g['key'] }}">{{ $g['title'] }}</a></li>
                @endforeach
            </ul>
        @endif

        @if($menu !== [])
            <h3>Recorrer el menú</h3>
            <p class="asc-onb-body" style="font-size:.82rem; margin-bottom:.45rem">Elegí una categoría y te mostramos cada opción, una por una.</p>
            <div class="asc-onb-tours" data-help-tours>
                @foreach($menu as $i => $group)
                    <button type="button" class="asc-onb-tour" x-on:click="startTour({{ $i }})" data-help-tour="{{ $group['title'] }}">
                        <span>{{ $group['title'] }}</span><small>{{ count($group['items']) === 1 ? '1 opción' : count($group['items']).' opciones' }}</small>
                    </button>
                @endforeach
            </div>
        @endif

        <div class="asc-onb-actions" style="flex-wrap: wrap">
            <button type="button" class="asc-onb-btn asc-onb-btn-primary" x-on:click="start(0)" data-ascento-restart>Ver la guía desde el principio</button>
            <template x-if="savedIndex() > 0">
                <button type="button" class="asc-onb-btn asc-onb-btn-soft" x-on:click="start(savedIndex())">Continuar donde la dejé</button>
            </template>
        </div>
        <p class="asc-onb-body" style="margin-top:.75rem"><a href="{{ $helpCenterUrl }}" class="asc-onb-link" data-help-center-link>Abrir el Centro de ayuda →</a></p>

        @if($checklist !== [])
            <h3>Primeros pasos</h3>
            <ul class="asc-onb-check" style="margin-top:0">
                @foreach ($checklist as $item)
                    <li>
                        <a href="{{ $item['url'] }}">
                            <span class="asc-onb-tick {{ $item['done'] ? 'is-done' : '' }}">{{ $item['done'] ? '✓' : '' }}</span>
                            <span class="{{ $item['done'] ? 'is-done-text' : '' }}">{{ $item['label'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

</div>
</div>

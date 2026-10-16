<div>
@if($visible && $progress)
    @php
        $steps = $progress['steps'];
        $current = $progress['current'];
        $step = $current !== null ? $steps[$current] : null;
        $pct = (int) round($progress['done'] / max(1, $progress['total']) * 100);
        $types = ['maintenance' => 'Mantenimiento', 'inspection' => 'Inspección', 'claim' => 'Reclamo', 'installation' => 'Instalación', 'modernization' => 'Modernización'];
        $priorities = ['low' => 'Baja', 'medium' => 'Media', 'high' => 'Alta', 'urgent' => 'Urgente'];
    @endphp
    <style>
        .cs { --cs: {{ $color }}; font-family: inherit; }
        .cs [x-cloak] { display: none !important; }
        .cs-overlay { position: fixed; inset: 0; z-index: 9990; display: flex; align-items: center; justify-content: center; padding: 1rem; background: rgba(15, 23, 42, .55); backdrop-filter: blur(3px); animation: cs-fade .3s ease both; }
        .cs-hero { width: min(30rem, 100%); max-height: calc(100vh - 2rem); overflow-y: auto; border-radius: 1.25rem; background: #fff; color: #0f172a; text-align: center; padding: 2rem 1.5rem 1.5rem; box-shadow: 0 30px 60px -20px rgba(0, 0, 0, .45); animation: cs-pop .45s cubic-bezier(.2, .9, .3, 1.2) both; }
        .dark .cs-hero, .dark .cs-panel, .dark .cs-modal { background: #18181b; color: #f4f4f5; }
        .cs-logo { width: 5rem; height: 5rem; margin: 0 auto 1rem; border-radius: 1.25rem; display: flex; align-items: center; justify-content: center; overflow: hidden; background: color-mix(in srgb, var(--cs) 14%, transparent); box-shadow: 0 0 0 6px color-mix(in srgb, var(--cs) 10%, transparent); animation: cs-float 3s ease-in-out .5s 2 both; }
        .cs-logo img { max-width: 100%; max-height: 100%; object-fit: contain; }
        .cs-h1 { font-size: 1.6rem; font-weight: 800; line-height: 1.2; margin: 0 0 .35rem; }
        .cs-lead { margin: 0 auto; max-width: 24rem; font-size: .95rem; line-height: 1.5; color: #475569; }
        .dark .cs-lead, .dark .cs-text, .dark .cs-muted { color: #a1a1aa; }
        .cs-chips { display: flex; flex-wrap: wrap; justify-content: center; gap: .4rem; margin: 1.1rem 0 1.25rem; }
        .cs-chips span { font-size: .75rem; font-weight: 600; padding: .25rem .6rem; border-radius: 9999px; background: color-mix(in srgb, var(--cs) 12%, transparent); color: inherit; opacity: 0; animation: cs-up .4s ease both; }
        .cs-btn { border: 0; border-radius: .7rem; padding: .7rem 1.1rem; font-weight: 700; font-size: .9rem; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: .35rem; }
        .cs-btn:focus-visible { outline: 2px solid var(--cs); outline-offset: 2px; }
        .cs-btn-primary { background: var(--cs); color: #fff; text-shadow: 0 1px 1px rgba(0, 0, 0, .2); }
        .cs-btn-primary:hover { filter: brightness(1.06); }
        .cs-btn-ghost { background: transparent; color: #64748b; }
        .cs-btn-soft { background: rgba(148, 163, 184, .18); color: inherit; }
        .cs-actions { display: flex; flex-wrap: wrap; gap: .5rem; justify-content: center; }
        .cs-panel { position: fixed; z-index: 9980; right: 1rem; bottom: 1rem; width: min(23rem, calc(100vw - 2rem)); max-height: calc(100vh - 6rem); overflow-y: auto; border-radius: 1rem; background: #fff; color: #0f172a; box-shadow: 0 20px 45px -15px rgba(15, 23, 42, .45); border-top: 4px solid var(--cs); animation: cs-up .35s ease both; }
        @media (max-width: 640px) { .cs-panel { right: .5rem; left: .5rem; bottom: .5rem; width: auto; max-height: 70vh; } }
        .cs-head { display: flex; align-items: center; gap: .6rem; padding: .85rem 1rem .5rem; }
        .cs-kicker { font-size: .7rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--cs); }
        .cs-icon-btn { margin-left: auto; border: 0; background: transparent; color: #94a3b8; cursor: pointer; border-radius: 9999px; width: 2rem; height: 2rem; font-size: 1.1rem; }
        .cs-icon-btn:hover { background: rgba(148, 163, 184, .18); }
        .cs-bar { height: 6px; margin: 0 1rem; border-radius: 9999px; background: rgba(148, 163, 184, .25); overflow: hidden; }
        .cs-bar span { display: block; height: 100%; background: var(--cs); border-radius: inherit; transition: width .6s ease; }
        .cs-body { padding: .85rem 1rem 1rem; }
        .cs-title { font-weight: 800; font-size: 1.05rem; margin: 0 0 .3rem; }
        .cs-text { font-size: .9rem; line-height: 1.5; color: #475569; margin: 0; }
        .cs-tip { margin: .75rem 0 0; padding: .6rem .7rem; border-radius: .6rem; font-size: .85rem; line-height: 1.45; background: color-mix(in srgb, var(--cs) 12%, transparent); }
        .cs-tip strong { display: block; font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; margin-bottom: .15rem; }
        .cs-sr { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
        .cs-steps summary { cursor: pointer; font-size: .8rem; font-weight: 600; color: #64748b; margin-top: .85rem; }
        .cs-steps summary:focus-visible { outline: 2px solid var(--cs); outline-offset: 2px; border-radius: .3rem; }
        .cs-list { list-style: none; margin: .5rem 0 0; padding: 0; display: grid; gap: .25rem; }
        .cs-list li { display: flex; gap: .55rem; align-items: center; font-size: .85rem; padding: .3rem .25rem; border-radius: .5rem; }
        .cs-list li.is-current { background: rgba(148, 163, 184, .14); font-weight: 600; }
        .cs-dot { flex: 0 0 1.25rem; height: 1.25rem; border-radius: 9999px; border: 2px solid #cbd5e1; display: inline-flex; align-items: center; justify-content: center; font-size: .7rem; color: #fff; }
        .cs-dot.is-done { background: #16a34a; border-color: #16a34a; animation: cs-pop .4s ease both; }
        .cs-dot.is-current { border-color: var(--cs); }
        .cs-done-text { color: #94a3b8; }
        .cs-foot { display: flex; justify-content: space-between; gap: .5rem; padding: .65rem 1rem .85rem; border-top: 1px solid rgba(148, 163, 184, .2); }
        .cs-link { background: none; border: 0; padding: .2rem 0; font-size: .8rem; color: #64748b; cursor: pointer; text-decoration: underline; }
        .cs-pill { position: fixed; z-index: 9980; right: 1rem; bottom: 1rem; border: 0; border-radius: 9999px; padding: .65rem 1rem; font-weight: 700; font-size: .85rem; background: var(--cs); color: #fff; box-shadow: 0 12px 30px -10px rgba(0, 0, 0, .45); cursor: pointer; }
        .cs-toast { position: fixed; z-index: 9991; left: 50%; top: 1.25rem; transform: translateX(-50%); background: #16a34a; color: #fff; font-weight: 700; padding: .6rem 1rem; border-radius: 9999px; box-shadow: 0 12px 30px -10px rgba(0, 0, 0, .45); animation: cs-toast 2.8s ease both; pointer-events: none; font-size: .9rem; white-space: nowrap; max-width: calc(100vw - 2rem); overflow: hidden; text-overflow: ellipsis; }
        .cs-modal { width: min(28rem, 100%); max-height: calc(100vh - 2rem); overflow-y: auto; border-radius: 1.25rem; background: #fff; color: #0f172a; padding: 1.25rem; animation: cs-pop .35s ease both; text-align: left; }
        .cs-phone { margin: .9rem auto; width: min(17rem, 100%); border-radius: 1.6rem; padding: .65rem; background: #0f172a; box-shadow: 0 18px 40px -18px rgba(0, 0, 0, .6); }
        .cs-screen { border-radius: 1.1rem; background: #f4f5f7; color: #0f172a; padding: .8rem; font-size: .8rem; }
        .cs-screen h4 { margin: 0 0 .5rem; font-size: .95rem; font-weight: 800; }
        .cs-card { background: #fff; border-radius: .8rem; padding: .7rem; border-left: 4px solid var(--cs); box-shadow: 0 2px 6px rgba(15, 23, 42, .08); }
        .cs-card b { display: block; font-size: .85rem; }
        .cs-tag { display: inline-block; font-size: .68rem; font-weight: 700; padding: .1rem .45rem; border-radius: 9999px; background: #fef3c7; color: #92400e; margin-top: .35rem; }
        .cs-fakebtn { margin-top: .55rem; text-align: center; border-radius: .6rem; padding: .45rem; background: var(--cs); color: #fff; font-weight: 700; }
        .cs-muted { color: #64748b; font-size: .82rem; line-height: 1.45; }
        .cs-confetti { position: absolute; inset: 0; overflow: hidden; pointer-events: none; }
        .cs-confetti i { position: absolute; top: -10px; width: 8px; height: 14px; border-radius: 2px; opacity: .9; animation: cs-fall 2.4s ease-in both; }
        @keyframes cs-fade { from { opacity: 0 } }
        @keyframes cs-pop { from { opacity: 0; transform: scale(.92) } }
        @keyframes cs-up { from { opacity: 0; transform: translateY(12px) } to { opacity: 1; transform: none } }
        @keyframes cs-float { 50% { transform: translateY(-6px) } }
        @keyframes cs-toast { 0% { opacity: 0; transform: translate(-50%, -10px) } 12%, 80% { opacity: 1; transform: translate(-50%, 0) } 100% { opacity: 0; transform: translate(-50%, -10px) } }
        @keyframes cs-fall { to { transform: translateY(26rem) rotate(540deg); opacity: 0 } }
        @media (prefers-reduced-motion: reduce) { .cs *, .cs-overlay, .cs-hero, .cs-panel { animation: none !important; transition: none !important; } .cs-chips span { opacity: 1; } .cs-confetti { display: none; } }
    </style>

    <div class="cs" x-data="{ min: false }" data-company-setup data-setup-step="{{ $step['key'] ?? 'done' }}">
        @if($celebrate && $progress['done'] > 0 && $current !== null)
            <div class="cs-toast" role="status">✓ Listo: {{ $celebrate }}</div>
        @endif

        @if(! $progress['welcomed'])
            {{-- 1. Bienvenida --}}
            <div class="cs-overlay" role="dialog" aria-modal="true" aria-labelledby="cs-welcome-title" data-setup-welcome>
                <div class="cs-hero">
                    <div class="cs-logo">
                        @if($logo)
                            <img src="{{ $logo }}" alt="Logo de {{ $company->name }}" onerror="this.replaceWith(Object.assign(document.createElement('img'),{src:'{{ asset('images/brand/logo-64.png') }}',alt:''}))">
                        @else
                            <img src="{{ asset('images/brand/logo-64.png') }}" alt="" width="56" height="56">
                        @endif
                    </div>
                    <h2 class="cs-h1" id="cs-welcome-title">¡Bienvenido a Ascento!</h2>
                    <p class="cs-lead">Vamos a dejar lista a <strong>{{ $company->name }}</strong> y a crear tu primera orden de trabajo, paso a paso. Lleva unos 10 minutos y podés pausarlo cuando quieras.</p>
                    <div class="cs-chips" aria-hidden="true">
                        @foreach(['Tu empresa', 'Técnico', 'Cliente', 'Edificio', 'Orden', '¡Listo!'] as $i => $chip)
                            <span style="animation-delay: {{ 0.15 + $i * 0.08 }}s">{{ $chip }}</span>
                        @endforeach
                    </div>
                    <div class="cs-actions">
                        <button type="button" class="cs-btn cs-btn-primary" wire:click="start" data-setup-start>Empezar</button>
                        <button type="button" class="cs-btn cs-btn-ghost" wire:click="pause" data-setup-pause>Seguir más tarde</button>
                    </div>
                </div>
            </div>
        @elseif($current === null)
            {{-- 8. Felicitación --}}
            <div class="cs-overlay" role="dialog" aria-modal="true" aria-labelledby="cs-done-title" data-setup-finished>
                <div class="cs-hero" style="position:relative">
                    <div class="cs-confetti" aria-hidden="true">
                        @foreach(range(1, 18) as $i)
                            <i style="left: {{ ($i * 37) % 100 }}%; background: {{ ['#F59E0B', '#16A34A', '#3B82F6', '#EF4444', $color][$i % 5] }}; animation-delay: {{ ($i % 6) * 0.12 }}s"></i>
                        @endforeach
                    </div>
                    <div class="cs-logo" style="font-size:2.2rem">🎉</div>
                    <h2 class="cs-h1" id="cs-done-title">¡Felicitaciones!</h2>
                    <p class="cs-lead"><strong>{{ $company->name }}</strong> ya tiene su técnico, su primer cliente con su edificio y la primera orden de trabajo asignada. Ahora te mostramos cómo moverte por el resto de Ascento.</p>
                    <div class="cs-actions" style="margin-top:1.25rem">
                        <button type="button" class="cs-btn cs-btn-primary" wire:click="finish" data-setup-finish>Ir al panel</button>
                    </div>
                </div>
            </div>
        @else
            {{-- Pasos 2 a 7 --}}
            <button type="button" class="cs-pill" x-show="min" x-cloak x-on:click="min = false" data-setup-pill>Configuración inicial · {{ $progress['done'] }}/{{ $progress['total'] }}</button>
            <section class="cs-panel" x-show="! min" role="region" aria-labelledby="cs-step-title" data-setup-panel>
                <div class="cs-head">
                    <span class="cs-kicker">Configuración inicial · Paso {{ $current + 1 }} de {{ $progress['total'] }}</span>
                    <button type="button" class="cs-icon-btn" x-on:click="min = true" aria-label="Minimizar la guía" title="Minimizar">–</button>
                </div>
                <div class="cs-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $pct }}" aria-label="Progreso"><span style="width: {{ $pct }}%"></span></div>
                <div class="cs-body">
                    <p class="cs-title" id="cs-step-title">{{ $step['title'] }}</p>
                    <p class="cs-text">{{ $step['text'] }}</p>
                    @if($here && $step['tip'])
                        <p class="cs-tip" data-setup-tip><strong>En esta pantalla</strong>{{ $step['tip'] }}</p>
                    @endif
                    <div style="margin-top:.85rem">
                        @if($step['key'] === 'technician_view')
                            <button type="button" class="cs-btn cs-btn-primary" wire:click="openPreview" data-setup-preview>{{ $step['action'] }}</button>
                        @elseif(! $here)
                            <a class="cs-btn cs-btn-primary" href="{{ $step['url'] }}" data-setup-go>{{ $step['action'] }} →</a>
                        @endif
                    </div>
                    <details class="cs-steps">
                    <summary>Ver los {{ $progress['total'] }} pasos ({{ $progress['done'] }} hechos)</summary>
                    <ol class="cs-list" aria-label="Pasos">
                        @foreach($steps as $i => $s)
                            <li class="{{ $s['current'] ? 'is-current' : '' }}">
                                <span class="cs-dot {{ $s['done'] ? 'is-done' : ($s['current'] ? 'is-current' : '') }}" aria-hidden="true">{{ $s['done'] ? '✓' : '' }}</span>
                                <span class="{{ $s['done'] ? 'cs-done-text' : '' }}">{{ $s['title'] }}@if($s['done'] && ! empty($s['record'])) · {{ $s['record'] }}@endif</span>
                                <span class="cs-sr">{{ $s['done'] ? '(hecho)' : ($s['current'] ? '(ahora)' : '(pendiente)') }}</span>
                            </li>
                        @endforeach
                    </ol>
                    </details>
                </div>
                <div class="cs-foot">
                    <button type="button" class="cs-link" wire:click="pause" data-setup-pause>Pausar (sigo después)</button>
                    <button type="button" class="cs-link" wire:click="skip" wire:confirm="¿Omitir la configuración guiada? Lo que cargaste queda guardado y la podés retomar desde Ayuda." data-setup-skip>Omitir el recorrido</button>
                </div>
            </section>

            @if($previewOpen && $progress['order'])
                {{-- 7. Así lo ve el técnico (vista previa con los datos reales de la orden) --}}
                <div class="cs-overlay" role="dialog" aria-modal="true" aria-labelledby="cs-preview-title" data-setup-preview-modal>
                    <div class="cs-modal">
                        <p class="cs-kicker">Paso {{ $current + 1 }} de {{ $progress['total'] }}</p>
                        <h2 class="cs-title" id="cs-preview-title" style="font-size:1.15rem">Así ve {{ $progress['technician']?->name }} su orden</h2>
                        <p class="cs-muted">{{ $progress['technician']?->name }} entra en <strong>{{ route('login') }}</strong> desde su celular, con su correo <strong>{{ $progress['technician']?->email }}</strong> y la contraseña que le cargaste. En la pestaña «Trabajos» ve la orden:</p>
                        <div class="cs-phone" aria-label="Vista previa de la app del técnico">
                            <div class="cs-screen">
                                <h4>Trabajos</h4>
                                <div class="cs-card">
                                    <b>{{ $progress['orderBuilding']?->name ?? 'Edificio' }}</b>
                                    <span class="cs-muted">{{ $types[$progress['order']->type] ?? 'Trabajo' }} · Prioridad {{ $priorities[$progress['order']->priority] ?? '—' }}</span>
                                    @if($progress['order']->notes)<div class="cs-muted" style="margin-top:.25rem">{{ \Illuminate\Support\Str::limit($progress['order']->notes, 80) }}</div>@endif
                                    <span class="cs-tag">Pendiente</span>
                                    <div class="cs-fakebtn" aria-hidden="true">Tomar trabajo</div>
                                </div>
                            </div>
                        </div>
                        <p class="cs-muted">Al tocar «Tomar trabajo» la orden pasa a «En progreso», y al terminar la cierra con «Hacer remito y finalizar»: el remito firmado te llega al panel. Esta es una vista previa con los datos reales de tu orden.</p>
                        <div class="cs-actions" style="justify-content:flex-end; margin-top:1rem">
                            <button type="button" class="cs-btn cs-btn-soft" wire:click="$set('previewOpen', false)">Cerrar</button>
                            <button type="button" class="cs-btn cs-btn-primary" wire:click="acknowledgeTechnicianView" data-setup-understood>Entendido</button>
                        </div>
                    </div>
                </div>
            @endif
        @endif
    </div>
@endif
</div>

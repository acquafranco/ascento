<x-filament-panels::page>
    @include('filament.partials.insights-styles')
    @include('partials.help-guides-styles')
    <style>
        .hc { --hc-accent: var(--primary-600, #d97706); display: flex; flex-direction: column; gap: 1.25rem; }
        .hc-top { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .5rem; }
        .hc-crumbs { display: flex; flex-wrap: wrap; align-items: center; gap: .35rem; font-size: .85rem; }
        .hc-crumbs button, .hc-back { background: none; border: 0; padding: .25rem 0; color: var(--hc-accent); font-weight: 600; cursor: pointer; text-decoration: none; font-size: .85rem; }
        .hc-crumbs button:hover, .hc-back:hover { text-decoration: underline; }
        .hc-search { border-radius: 1rem; padding: 1.25rem; background: linear-gradient(135deg, rgba(245,158,11,.12), rgba(245,158,11,.03)); border: 1px solid rgba(245,158,11,.25); }
        .hc-search h2 { font-size: 1.15rem; font-weight: 700; margin: 0 0 .65rem; }
        .hc-search-row { display: flex; gap: .5rem; flex-wrap: wrap; }
        .hc-input { flex: 1 1 14rem; min-width: 0; border-radius: .65rem; padding: .7rem .85rem; font-size: 1rem; border: 1px solid rgba(148,163,184,.45); background: #fff; color: #0f172a; }
        .dark .hc-input, .dark .hc-select { background: rgba(255,255,255,.04); color: #f4f4f5; }
        .hc-input:focus, .hc-select:focus { outline: 2px solid var(--hc-accent); outline-offset: 1px; }
        .hc-select { border-radius: .65rem; padding: .6rem 2rem .6rem .75rem; border: 1px solid rgba(148,163,184,.45); background: #fff; max-width: 100%; font-size: .9rem; }
        .hc-hint { font-size: .8rem; opacity: .7; margin: .5rem 0 0; }
        .hc-h { font-size: 1rem; font-weight: 700; margin: 0 0 .65rem; }
        .hc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 16rem), 1fr)); gap: .75rem; }
        .hc-cat { display: flex; flex-direction: column; gap: .35rem; border-radius: .9rem; padding: 1rem; border: 1px solid rgba(148,163,184,.25); background: #fff; }
        .dark .hc-cat { background: rgba(255,255,255,.03); }
        .hc-cat-head { display: flex; align-items: center; gap: .6rem; background: none; border: 0; padding: 0; text-align: left; cursor: pointer; color: inherit; font: inherit; }
        .hc-cat-head:focus-visible { outline: 2px solid var(--hc-accent); outline-offset: 3px; border-radius: .4rem; }
        .hc-icon { flex: 0 0 2.25rem; width: 2.25rem; height: 2.25rem; border-radius: .65rem; display: inline-flex; align-items: center; justify-content: center; background: rgba(245,158,11,.14); color: var(--hc-accent); }
        .hc-icon svg { width: 1.25rem; height: 1.25rem; }
        .hc-cat-title { font-weight: 700; font-size: .95rem; line-height: 1.3; }
        .hc-cat-desc { font-size: .82rem; opacity: .7; margin: 0; }
        .hc-cat ul { list-style: none; margin: .25rem 0 0; padding: 0; display: flex; flex-direction: column; gap: .1rem; }
        .hc-cat li a { font-size: .85rem; color: inherit; text-decoration: none; display: block; padding: .2rem 0; opacity: .9; }
        .hc-cat li a:hover { color: var(--hc-accent); text-decoration: underline; }
        .hc-more { font-size: .82rem; font-weight: 600; color: var(--hc-accent); background: none; border: 0; padding: .2rem 0; cursor: pointer; text-align: left; }
        .hc-featured { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 14rem), 1fr)); gap: .6rem; }
        .hc-feat { display: block; border-radius: .75rem; padding: .75rem .9rem; border: 1px solid rgba(148,163,184,.25); text-decoration: none; color: inherit; }
        .hc-feat:hover, .hc-feat:focus-visible { border-color: var(--hc-accent); outline: none; }
        .hc-feat strong { display: block; font-size: .9rem; }
        .hc-feat span { font-size: .8rem; opacity: .7; }
        .hc-card { border-radius: .9rem; padding: 1rem 1.1rem; border: 1px solid rgba(148,163,184,.25); background: #fff; }
        .dark .hc-card { background: rgba(255,255,255,.03); }
        .hc-empty { text-align: center; padding: 1.5rem 1rem; }
        .hc-empty p { margin: .25rem 0; }
        .hc-chips { display: flex; flex-wrap: wrap; gap: .4rem; justify-content: center; margin-top: .6rem; }
        .hc-chips button { border: 1px solid rgba(148,163,184,.45); background: none; border-radius: 9999px; padding: .3rem .75rem; font-size: .82rem; cursor: pointer; color: inherit; }
        .hc-chips button:hover { border-color: var(--hc-accent); }
        .hc-faq .hg-summary .hg-title { font-size: .92rem; }
        .hc-faq-a { margin: .35rem 0 0; font-size: .9rem; line-height: 1.5; }
        .hc-small { font-size: .82rem; opacity: .7; }
    </style>

    <div class="hc" data-help-center>
        @if($back || $mode !== 'home')
            <nav class="hc-top" aria-label="Navegación de la ayuda">
                <div class="hc-crumbs">
                    @if($mode !== 'home')
                        <button type="button" wire:click="home" data-help-home>Centro de ayuda</button>
                        @if($mode === 'topic' && $topicSlug)
                            <span aria-hidden="true">›</span><span>{{ $categories[$topicSlug]['title'] }}</span>
                        @elseif($mode === 'search')
                            <span aria-hidden="true">›</span><span>Resultados</span>
                        @endif
                    @endif
                </div>
                @if($back)
                    <a class="hc-back" href="{{ $back }}" data-help-back>← Volver a donde estabas</a>
                @endif
            </nav>
        @endif

        {{-- Buscador: siempre arriba. --}}
        <section class="hc-search" role="search" data-help-filters>
            <h2 id="hc-search-title">¿Qué necesitás hacer?</h2>
            <div class="hc-search-row">
                <input type="search" class="hc-input" wire:model.live.debounce.300ms="search" aria-labelledby="hc-search-title"
                       placeholder="Por ejemplo: registrar un edificio, reemplazar una guardia, volver a cobrar" autocomplete="off">
                <select class="hc-select" wire:model.live="module" aria-label="Filtrar por categoría">
                    <option value="">Todas las categorías</option>
                    @foreach($modules as $m)<option value="{{ $m }}">{{ $m }}</option>@endforeach
                </select>
            </div>
            <p class="hc-hint">Buscá con tus palabras: también encuentra sinónimos (consorcio, turno, comprobante) y palabras incompletas.</p>
        </section>

        <div wire:loading.delay class="hc-small" role="status">Buscando…</div>

        @if($mode === 'search')
            {{-- ======================== RESULTADOS ======================== --}}
            <section aria-live="polite" data-help-results>
                @if($results !== [] || $faqResults !== [])
                    <p class="hc-small" style="margin:0 0 .5rem">{{ count($results) === 1 ? '1 artículo' : count($results).' artículos' }}@if($faqResults !== []) · {{ count($faqResults) === 1 ? '1 pregunta frecuente' : count($faqResults).' preguntas frecuentes' }}@endif</p>
                    @if($results !== [])
                        <div class="hc-card">
                            @foreach($results as $g)
                                @include('partials.help-guide', ['g' => $g, 'open' => count($results) === 1, 'from' => $back])
                            @endforeach
                        </div>
                    @endif
                    @if($faqResults !== [])
                        <div class="hc-card hc-faq" style="margin-top:.75rem">
                            <p class="hc-h">Preguntas frecuentes</p>
                            @foreach($faqResults as $f)
                                @include('filament.partials.help-faq-item', ['f' => $f])
                            @endforeach
                        </div>
                    @endif
                @else
                    <div class="hc-card hc-empty" data-help-empty>
                        <p style="font-weight:600">No encontramos ayuda para «{{ $search }}».</p>
                        <p class="hc-small">Probá con otra palabra o elegí una categoría. Algunas ideas:</p>
                        <div class="hc-chips">
                            @foreach(['edificio', 'remito', 'orden', 'guardia', 'cobranza', 'permisos'] as $idea)
                                <button type="button" wire:click="$set('search', '{{ $idea }}')">{{ $idea }}</button>
                            @endforeach
                        </div>
                    </div>
                @endif
            </section>
        @elseif($mode === 'topic')
            {{-- ======================== CATEGORÍA ======================== --}}
            @php $cat = $categories[$topicSlug]; @endphp
            <section class="hc-card" data-help-module="{{ $cat['title'] }}" data-help-topic="{{ $topicSlug }}">
                <div style="display:flex; gap:.75rem; align-items:center; margin-bottom:.5rem">
                    <span class="hc-icon" aria-hidden="true">{!! svg('heroicon-o-'.$cat['icon'])->toHtml() !!}</span>
                    <div>
                        <h2 class="hc-h" style="margin:0">{{ $cat['title'] }}</h2>
                        <p class="hc-cat-desc">{{ $cat['description'] }}</p>
                    </div>
                </div>
                @foreach($cat['guides'] as $g)
                    @include('partials.help-guide', ['g' => $g, 'open' => ($current['key'] ?? null) === $g['key'], 'from' => $back])
                @endforeach
            </section>
        @else
            {{-- ========================== PORTADA ========================== --}}
            @if($featured !== [])
                <section aria-labelledby="hc-featured">
                    <h2 class="hc-h" id="hc-featured">Para empezar</h2>
                    <div class="hc-featured">
                        @foreach($featured as $g)
                            <a class="hc-feat" href="{{ \App\Support\Help\HelpGuides::url('panel', $g['key'], $back ? ['desde' => $back] : []) }}" data-guide="{{ $g['key'] }}">
                                <strong>{{ $g['title'] }}</strong><span>{{ $g['summary'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif

            <section aria-labelledby="hc-cats">
                <h2 class="hc-h" id="hc-cats">Categorías</h2>
                <div class="hc-grid">
                    @foreach($categories as $slug => $cat)
                        <div class="hc-cat" data-help-category="{{ $slug }}">
                            <button type="button" class="hc-cat-head" wire:click="showTopic('{{ $slug }}')">
                                <span class="hc-icon" aria-hidden="true">{!! svg('heroicon-o-'.$cat['icon'])->toHtml() !!}</span>
                                <span class="hc-cat-title">{{ $cat['title'] }}</span>
                            </button>
                            <p class="hc-cat-desc">{{ $cat['description'] }}</p>
                            <ul>
                                @foreach(array_slice($cat['guides'], 0, 4) as $g)
                                    <li><a href="{{ \App\Support\Help\HelpGuides::url('panel', $g['key'], $back ? ['desde' => $back] : []) }}" data-guide="{{ $g['key'] }}">{{ $g['title'] }}</a></li>
                                @endforeach
                            </ul>
                            @if(count($cat['guides']) > 4)
                                <button type="button" class="hc-more" wire:click="showTopic('{{ $slug }}')">Ver los {{ count($cat['guides']) }} artículos →</button>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>

            @if($faq !== [])
                <section class="hc-card hc-faq" aria-labelledby="hc-faq" data-help-faq>
                    <h2 class="hc-h" id="hc-faq">Preguntas frecuentes</h2>
                    @foreach($faq as $f)
                        @include('filament.partials.help-faq-item', ['f' => $f])
                    @endforeach
                </section>
            @endif
        @endif

        @if($usage)
            <div class="hc-card" data-help-usage>
                <p class="asc-q" style="font-weight:600">Tu plan: {{ $usage['plan'] }}</p>
                <p class="asc-c">{{ implode(' · ', $usage['lines']) }}</p>
            </div>
        @endif

        <p class="hc-small">¿No encontrás lo que buscás? Escribinos a {{ config('app.support_email') }}.</p>
    </div>
</x-filament-panels::page>

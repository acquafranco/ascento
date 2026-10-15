{{--
    Cuerpo de la ayuda del técnico y del portal: buscador (GET ?q=, funciona
    sin JavaScript), resultados o categorías, y preguntas frecuentes.
    Variables: $grouped, $results, $query, $faq, $open, $action, $cardClass.
--}}
<style>
    .hl-search { display: flex; gap: .5rem; margin: 0 0 1rem; }
    .hl-search input { flex: 1 1 auto; min-width: 0; border-radius: .7rem; border: 1px solid rgba(148,163,184,.5); padding: .7rem .85rem; font-size: 1rem; background: #fff; color: inherit; }
    .hl-search input:focus { outline: 2px solid #f59e0b; outline-offset: 1px; }
    .hl-search button { border: 0; border-radius: .7rem; padding: .7rem 1rem; font-weight: 600; background: #f59e0b; color: #111827; cursor: pointer; }
    .hl-head { display: flex; align-items: baseline; justify-content: space-between; gap: .5rem; margin: 0 0 .25rem; }
    .hl-head h2 { font-size: 1rem; font-weight: 700; margin: 0; }
    .hl-muted { font-size: .85rem; opacity: .7; margin: 0; }
    .hl-stack > * + * { margin-top: 1rem; }
    .hl-faq-a { margin: .35rem 0 0; font-size: .9rem; line-height: 1.5; }
</style>
<form method="GET" action="{{ $action }}" class="hl-search" role="search">
    <input type="search" name="q" value="{{ $query }}" placeholder="¿Qué necesitás hacer? Ej.: firmar un remito" aria-label="Buscar en la ayuda" autocomplete="off">
    <button type="submit">Buscar</button>
</form>

<div class="hl-stack">
    @if($results !== null)
        <section class="{{ $cardClass }}" aria-live="polite" data-help-results>
            <div class="hl-head"><h2>Resultados para «{{ $query }}»</h2><a href="{{ $action }}" class="hl-muted">Ver todo</a></div>
            @forelse($results as $g)
                @include('partials.help-guide', ['g' => $g, 'open' => count($results) === 1])
            @empty
                <p class="hl-muted" data-help-empty>No encontramos ayuda para eso. Probá con otra palabra (por ejemplo: remito, guardia, foto).</p>
            @endforelse
        </section>
    @else
        @foreach($grouped as $module => $guides)
            <section class="{{ $cardClass }}" data-help-module="{{ $module }}">
                <div class="hl-head"><h2>{{ $module }}</h2></div>
                @foreach($guides as $g)
                    @include('partials.help-guide', ['g' => $g, 'open' => $open === $g['key']])
                @endforeach
            </section>
        @endforeach
    @endif

    @if($faq !== [])
        <section class="{{ $cardClass }}" data-help-faq>
            <div class="hl-head"><h2>Preguntas frecuentes</h2></div>
            @foreach($faq as $f)
                <details class="hg" data-faq="{{ $f['key'] }}">
                    <summary class="hg-summary"><span class="hg-title">{{ $f['q'] }}</span></summary>
                    <p class="hl-faq-a">{{ $f['a'] }}</p>
                </details>
            @endforeach
        </section>
    @endif
</div>

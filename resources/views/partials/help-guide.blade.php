{{--
    Un artículo del Centro de ayuda (HelpGuides). Se abre solo si viene en el
    enlace (?guia=). Sirve en el panel, la app del técnico y el portal, y
    funciona sin JavaScript (<details>).

    Estructura: para qué sirve (resumen) · antes de empezar · pasos ·
    qué sucede después · notas · problemas frecuentes · relacionados.
    Los relacionados y «Abrir la sección» solo aparecen si la persona puede
    usarlos.
--}}
@php
    $related = \App\Support\Help\HelpGuides::relatedFor(auth()->user(), $g['audience'], $g);
    $openUrl = \App\Support\Help\HelpGuides::openUrl($g);
    $from = $from ?? null;
@endphp
<details class="hg" id="guia-{{ $g['key'] }}" data-guide="{{ $g['key'] }}" @if($open ?? false) open @endif>
    <summary class="hg-summary">
        <span class="hg-title">{{ $g['title'] }}</span>
        <span class="hg-sub">{{ $g['summary'] }}</span>
    </summary>
    <div class="hg-body">
        @if($g['before'] !== [])
            <div class="hg-before">
                <p class="hg-label">Antes de empezar</p>
                <ul>@foreach($g['before'] as $line)<li>{{ $line }}</li>@endforeach</ul>
            </div>
        @endif

        @if($g['flow'])
            <p class="hg-flow" aria-label="Orden: {{ implode(', ', $g['flow']) }}">
                @foreach($g['flow'] as $i => $step)
                    <span class="hg-chip">{{ $step }}</span>@if(! $loop->last)<span class="hg-arrow" aria-hidden="true">→</span>@endif
                @endforeach
            </p>
        @endif

        <p class="hg-label">Pasos</p>
        <ol class="hg-steps">
            @foreach($g['steps'] as $step)<li>{{ $step }}</li>@endforeach
        </ol>

        @if($g['image'] && is_file(public_path($g['image']['src'])))
            <figure class="hg-figure">
                <img src="{{ asset($g['image']['src']) }}" alt="{{ $g['image']['alt'] }}" loading="lazy" decoding="async">
                <figcaption>{{ $g['image']['caption'] }}</figcaption>
            </figure>
        @endif

        @if($g['after'])
            <p class="hg-label">Qué sucede después</p>
            <p class="hg-after">{{ $g['after'] }}</p>
        @endif

        @foreach($g['notes'] as $note)
            <p class="hg-note">{{ $note }}</p>
        @endforeach

        @if($g['problems'] !== [])
            <p class="hg-label">Problemas frecuentes</p>
            <dl class="hg-problems">
                @foreach($g['problems'] as [$question, $answer])
                    <dt>{{ $question }}</dt><dd>{{ $answer }}</dd>
                @endforeach
            </dl>
        @endif

        @if($related !== [] || $openUrl)
            <div class="hg-foot">
                @if($related !== [])
                    <p class="hg-label">Artículos relacionados</p>
                    <ul class="hg-related">
                        @foreach($related as $r)
                            <li><a href="{{ \App\Support\Help\HelpGuides::url($r['audience'], $r['key'], $from ? ['desde' => $from] : []) }}" data-related="{{ $r['key'] }}">{{ $r['title'] }}</a></li>
                        @endforeach
                    </ul>
                @endif
                @if($openUrl)
                    <a class="hg-open" href="{{ $openUrl }}" data-help-open="{{ $g['key'] }}">Abrir la sección →</a>
                @endif
            </div>
        @endif
    </div>
</details>

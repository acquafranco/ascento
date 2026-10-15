{{-- Una pregunta frecuente (HelpFaq), con enlace a la guía completa si la persona puede verla. --}}
<details class="hg" data-faq="{{ $f['key'] }}">
    <summary class="hg-summary"><span class="hg-title">{{ $f['q'] }}</span></summary>
    <p class="hc-faq-a">{{ $f['a'] }}</p>
    @if($f['guide'] && ($g = \App\Support\Help\HelpGuides::find(auth()->user(), $f['audience'], $f['guide'])))
        <a class="hg-open" href="{{ \App\Support\Help\HelpGuides::url($f['audience'], $g['key']) }}">Ver la guía: {{ $g['title'] }} →</a>
    @endif
</details>

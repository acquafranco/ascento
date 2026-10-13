{{-- Una guía (HelpGuides). Se abre sola si viene en el enlace (?guia=). Sirve en el panel, la app del técnico y el portal. --}}
<details class="hg" id="guia-{{ $g['key'] }}" data-guide="{{ $g['key'] }}" @if($open ?? false) open @endif>
    <summary class="hg-summary">
        <span class="hg-title">{{ $g['title'] }}</span>
        <span class="hg-sub">{{ $g['summary'] }}</span>
    </summary>
    <ol class="hg-steps">
        @foreach($g['steps'] as $step)<li>{{ $step }}</li>@endforeach
    </ol>
    @foreach($g['notes'] as $note)
        <p class="hg-note">{{ $note }}</p>
    @endforeach
</details>

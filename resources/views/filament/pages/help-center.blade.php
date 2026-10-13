<x-filament-panels::page>
    @include('filament.partials.insights-styles')
    @include('partials.help-guides-styles')

    @if($usage)
        <div class="asc-card" data-help-usage>
            <p class="asc-q" style="font-weight:600">Tu plan: {{ $usage['plan'] }}</p>
            <p class="asc-c">{{ implode(' · ', $usage['lines']) }}</p>
        </div>
    @endif

    <div class="asc-card" style="display:flex; gap:.5rem; flex-wrap:wrap" data-help-filters>
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="¿Qué querés hacer? (por ejemplo: remito, QR, límite)" aria-label="Buscar en la ayuda"
               style="flex:1 1 220px; min-width:0; border-radius:.5rem; padding:.45rem .65rem; border:1px solid rgba(148,163,184,.4); background:transparent">
        <select wire:model.live="module" aria-label="Tema" style="border-radius:.5rem; padding:.45rem 2rem .45rem .65rem; border:1px solid rgba(148,163,184,.4); background:transparent; max-width:100%">
            <option value="">Todos los temas</option>
            @foreach($modules as $m)<option value="{{ $m }}">{{ $m }}</option>@endforeach
        </select>
    </div>

    @forelse($grouped as $module => $guides)
        <section class="asc-card" data-help-module="{{ $module }}">
            <p class="asc-q" style="font-weight:600; margin-bottom:.25rem">{{ $module }}</p>
            @foreach($guides as $g)
                @include('partials.help-guide', ['g' => $g, 'open' => $guide === $g['key']])
            @endforeach
        </section>
    @empty
        <div class="asc-card"><p class="asc-c">No encontramos ayuda para eso. Probá con otra palabra.</p></div>
    @endforelse

    <p class="asc-small asc-muted">¿No encontrás lo que buscás? Escribinos a {{ config('app.support_email') }}.</p>
</x-filament-panels::page>

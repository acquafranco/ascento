<x-portal-layout title="Ayuda">
    @include('partials.help-guides-styles')
    <h1 class="p-h1">Ayuda</h1>
    <p class="p-sub">Cómo usar el portal de {{ $portalCompany?->name }}.</p>

    @foreach($grouped as $module => $guides)
        <section class="p-sec" data-help-module="{{ $module }}">
            <div class="p-sec-head"><h2>{{ $module }}</h2></div>
            <div class="p-card" style="display:block">
                @foreach($guides as $g)
                    @include('partials.help-guide', ['g' => $g, 'open' => $open === $g['key']])
                @endforeach
            </div>
        </section>
    @endforeach

    @if($portalCompany && (filled($portalCompany->phone) || filled($portalCompany->email)))
        <p class="p-muted">Para consultas sobre tus edificios, contactá a {{ $portalCompany->name }}: {{ collect([$portalCompany->phone, $portalCompany->email])->filter()->implode(' · ') }}.</p>
    @endif
</x-portal-layout>

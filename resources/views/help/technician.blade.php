<x-app-layout>
@include('partials.help-guides-styles')
<div class="t-page max-w-2xl lg:max-w-2xl" data-help-technician>
    <header class="mb-4">
        <h1 class="t-h1">Ayuda</h1>
        <p class="t-sub">Cómo hacer cada cosa desde el celular.</p>
    </header>

    <div class="space-y-4">
        @foreach($grouped as $module => $guides)
            <section class="t-card p-4 sm:p-6" data-help-module="{{ $module }}">
                <h2 class="text-base font-bold text-ink">{{ $module }}</h2>
                @foreach($guides as $g)
                    @include('partials.help-guide', ['g' => $g, 'open' => $open === $g['key']])
                @endforeach
            </section>
        @endforeach
        <p class="text-sm text-ink-500">Si algo no funciona como dice acá, avisale a la oficina de tu empresa.</p>
    </div>
</div>
</x-app-layout>

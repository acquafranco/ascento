<x-app-layout>
@include('partials.help-guides-styles')
<div class="t-page max-w-2xl lg:max-w-2xl" data-help-technician>
    <header class="mb-4">
        <h1 class="t-h1">Ayuda</h1>
        <p class="t-sub">Cómo hacer cada cosa desde el celular.</p>
    </header>

    @include('partials.help-library', ['action' => route('help.technician', ['company' => auth()->user()->company?->slug]), 'cardClass' => 't-card p-4 sm:p-6'])

    <p class="text-sm text-ink-500 mt-4">Si algo no funciona como dice acá, avisale a la oficina de tu empresa.</p>
</div>
</x-app-layout>

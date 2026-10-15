<x-portal-layout title="Ayuda">
    @include('partials.help-guides-styles')
    <h1 class="p-h1">Ayuda</h1>
    <p class="p-sub">Cómo usar el portal de {{ $portalCompany?->name }}.</p>

    @include('partials.help-library', ['action' => route('portal.help'), 'cardClass' => 'p-card p-help-card'])
    <style>.p-help-card { display: block; }</style>

    @if($portalCompany && (filled($portalCompany->phone) || filled($portalCompany->email)))
        <p class="p-muted" style="margin-top:1rem">Para consultas sobre tus edificios, contactá a {{ $portalCompany->name }}: {{ collect([$portalCompany->phone, $portalCompany->email])->filter()->implode(' · ') }}.</p>
    @endif
</x-portal-layout>

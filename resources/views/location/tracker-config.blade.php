{{-- Configuración del seguimiento (resources/js/tech-location.js): solo para el técnico que lo activó. --}}
@php
    $locUser = auth()->user();
    $locRow = \App\Services\Location\TechnicianLocationService::canTrack($locUser)
        ? app(\App\Services\Location\TechnicianLocationService::class)->of($locUser) : null;
    $locConfig = $locRow?->tracking_enabled ? [
        'enabled' => true,
        'interval' => (int) config('location.send_interval', 60),
        'storeUrl' => route('location.store', ['company' => $locUser->company->slug]),
        'reportUrl' => route('location.report', ['company' => $locUser->company->slug]),
    ] : null;
@endphp
@if($locConfig)
    <script type="application/json" id="ascento-location">@json($locConfig)</script>
@endif

{{-- Sección de otro plan: no se muestran datos, solo qué agrega. --}}
@include('filament.partials.insights-styles')
<div class="asc-locked">
    <strong>{{ $title }}</strong> — {{ $text }}
    <a href="{{ $url }}">Disponible en el plan {{ $plan }} →</a>
</div>

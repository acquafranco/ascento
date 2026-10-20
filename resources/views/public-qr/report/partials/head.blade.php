{{-- Encabezado de las pantallas del reporte: la empresa y el edificio que identifica el QR. --}}
<header class="qr-head">
    <div style="min-width:0">
        @if(! empty($companyName))<p class="qr-company">Mantenimiento a cargo de <strong>{{ $companyName }}</strong></p>@endif
        <h1 class="qr-building">{{ $buildingName ?? 'Edificio' }}</h1>
        @if(! empty($buildingAddress))<p class="qr-company">{{ $buildingAddress }}</p>@endif
    </div>
</header>

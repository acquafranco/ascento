{{-- Vista previa del documento que recibe el cliente (fondo blanco también en modo oscuro). --}}
@php($quote = $getRecord()->loadMissing(['items', 'company', 'client', 'building.client']))
<div style="background: #fff; border-radius: 12px; padding: 22px; color: #1F2430;">
    @include('quotes.partials.web', ['quote' => $quote])
</div>

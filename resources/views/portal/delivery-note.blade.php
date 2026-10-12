{{--
    Remito en el portal del cliente. Solo lo que el cliente puede ver
    (DeliveryNoteDocument) y sin acciones de la empresa; el acceso ya lo
    controló PortalAccess::ensureShared.
--}}
<x-portal-layout :title="'Remito #'.$doc['number']">
    <a class="p-back" href="{{ route('portal.documents', ['type' => 'delivery_note']) }}">← Remitos</a>

    @include('delivery-notes.partials.document', ['doc' => $doc])

    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px">
        <a class="p-btn" href="{{ route('portal.delivery-note-pdf', $deliveryNote) }}" target="_blank" rel="noopener">⬇ Descargar PDF</a>
        @if($deliveryNote->company?->phone)<a class="p-btn secondary" href="tel:{{ preg_replace('/[^\d+]/', '', $deliveryNote->company->phone) }}">📞 Llamar</a>@endif
    </div>
</x-portal-layout>

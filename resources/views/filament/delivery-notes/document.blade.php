{{--
    Remito completo en el panel (dueño / administrativo con Documentos): el
    mismo documento que ven el técnico, el cliente y el PDF, con firmas,
    materiales, paracaídas y participantes (DeliveryNoteDocument). Estilos
    propios (rm-*), no dependen del tema del panel.
--}}
@php
    $note = $getRecord()->loadMissing(\App\Http\Controllers\DeliveryNoteController::RELATIONS);
    $doc = \App\Support\DeliveryNotes\DeliveryNoteDocument::for($note);
@endphp
<div style="max-width: 760px; margin: 0 auto;">
    @include('delivery-notes.partials.document', ['doc' => $doc])
</div>

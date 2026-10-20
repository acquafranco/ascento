{{--
    Paso 1 de «Reportar un problema»: qué se observó (categoría, ascensor si el
    QR publica los equipos, descripción). El edificio ya viene del QR.
--}}
@php
    $title = 'Reportar un problema';
    $old = $old ?? [];
@endphp
@include('public-qr.report.partials.document', ['title' => $title, 'slot' => view('public-qr.report.partials.form-body', $__data)->render()])

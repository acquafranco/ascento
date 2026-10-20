{{-- Paso 2: el resumen antes de enviar (todavía no se guardó nada) y la foto opcional. --}}
@include('public-qr.report.partials.document', ['title' => 'Revisá tu reporte', 'slot' => view('public-qr.report.partials.review-body', $__data)->render()])

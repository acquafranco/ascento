{{-- Resultado REAL del reporte (lo decide el servidor). --}}
@include('public-qr.report.partials.document', ['title' => 'Reporte', 'buildingName' => $buildingName ?? null, 'slot' => view('public-qr.report.partials.result', $__data)->render().view('public-qr.report.partials.safety', $__data)->render()])

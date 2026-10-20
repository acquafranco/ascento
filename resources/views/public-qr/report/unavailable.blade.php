{{-- El QR no recibe reportes (o no existe): sin pistas, con la indicación de seguridad. --}}
@include('public-qr.report.partials.document', ['title' => 'Reportar un problema', 'buildingName' => null, 'slot' => view('public-qr.report.partials.result', ['state' => 'unavailable', 'back' => $back ?? null])->render().view('public-qr.report.partials.safety', ['phone' => null])->render()])

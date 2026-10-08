@php
    $priorities = ['baja' => 'Baja', 'media' => 'Media', 'alta' => 'Alta', 'critica' => 'Crítica'];
    $statuses = ['pendiente' => 'Pendiente', 'en_revision' => 'En revisión', 'resuelto' => 'Resuelto'];
    $company = $report->company;
    $building = $report->building;
@endphp
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Reporte #{{ $report->id }}</title>
<style>
    @page { margin: 18mm 14mm 20mm 14mm; }
    * { font-family: 'DejaVu Sans', sans-serif; }
    body { font-size: 10pt; color: #1f2937; line-height: 1.45; }
    .footer { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 8pt; color: #6b7280; border-top: 1px solid #e5e7eb; padding-top: 2mm; }
    .header { width: 100%; border-bottom: 2px solid #ea580c; padding-bottom: 4mm; margin-bottom: 5mm; }
    .header td { vertical-align: middle; }
    .company { font-size: 14pt; font-weight: bold; color: #111827; }
    .muted { color: #6b7280; font-size: 8.5pt; }
    .doc-title { font-size: 13pt; font-weight: bold; text-align: right; color: #ea580c; }
    .section { margin-top: 5mm; }
    .section h2 { font-size: 10.5pt; margin: 0 0 2mm; padding-bottom: 1mm; border-bottom: 1px solid #e5e7eb; color: #111827; text-transform: uppercase; letter-spacing: .4px; }
    table.data { width: 100%; border-collapse: collapse; }
    table.data td { padding: 1.6mm 2mm; border: 1px solid #e5e7eb; vertical-align: top; }
    table.data td.label { width: 28%; background: #f9fafb; color: #4b5563; font-weight: bold; font-size: 9pt; }
    .text { white-space: pre-line; word-wrap: break-word; }
    .badge { display: inline-block; padding: .4mm 2mm; border-radius: 2mm; font-size: 8.5pt; font-weight: bold; }
    .p-baja { background: #dcfce7; color: #166534; } .p-media { background: #fef9c3; color: #854d0e; }
    .p-alta { background: #ffedd5; color: #9a3412; } .p-critica { background: #fee2e2; color: #991b1b; }
    table.photos { width: 100%; border-collapse: separate; border-spacing: 0 3mm; }
    table.photos th { font-weight: normal; padding: 0; }
    table.photos td { width: 50%; text-align: center; vertical-align: top; page-break-inside: avoid; }
    .photo-caption { font-size: 8pt; color: #6b7280; margin-top: 1mm; }
</style>
</head>
<body>

<div class="footer">
    {{ $company?->name }} · Reporte #{{ $report->id }} · Generado el {{ now()->format('d/m/Y H:i') }} con Ascento
</div>

<table class="header">
    <tr>
        <td style="width: 60%;">
            @if($logo)
                <img src="{{ $logo }}" style="max-height: 16mm; max-width: 50mm;"><br>
            @endif
            <div class="company">{{ $company?->business_name ?: $company?->name }}</div>
            <div class="muted">
                @if($company?->cuit) CUIT {{ $company->cuit }} · @endif
                {{ collect([$company?->address, $company?->phone, $company?->email])->filter()->implode(' · ') }}
            </div>
        </td>
        <td style="width: 40%;">
            <div class="doc-title">Reporte técnico #{{ $report->id }}</div>
            <div class="muted" style="text-align: right;">{{ $report->created_at?->format('d/m/Y H:i') }}</div>
        </td>
    </tr>
</table>

<div class="section">
    <h2>Datos</h2>
    <table class="data">
        <tr><td class="label">Cliente</td><td>{{ $building?->client?->name ?? '—' }}</td></tr>
        <tr><td class="label">Edificio</td><td>{{ $building?->name ?? '—' }}@if($building?->address) — {{ $building->address }}@endif</td></tr>
        <tr><td class="label">Equipo</td><td>{{ $report->elevator_number ?: '—' }}</td></tr>
        <tr><td class="label">Técnico</td><td>{{ $report->user?->name ?? '—' }}</td></tr>
        <tr><td class="label">Fecha</td><td>{{ $report->created_at?->format('d/m/Y H:i') }}</td></tr>
        <tr>
            <td class="label">Tipo</td>
            <td>Reporte de problema · Prioridad <span class="badge p-{{ $report->priority }}">{{ $priorities[$report->priority] ?? $report->priority }}</span></td>
        </tr>
        <tr><td class="label">Estado</td><td>{{ $statuses[$report->status] ?? ($report->status ?: 'Pendiente') }}</td></tr>
    </table>
</div>

<div class="section">
    <h2>Descripción del problema</h2>
    <div class="text">{{ $report->description }}</div>
</div>

<div class="section">
    <h2>Observaciones</h2>
    <div class="text">{{ $report->observations ?: 'Sin observaciones.' }}</div>
</div>

@if($photos->isEmpty())
    <div class="section">
        <h2>Fotos</h2>
        <div class="muted">El reporte no tiene fotos.</div>
    </div>
@else
    {{-- El título va pegado a la primera fila de fotos (bloque que no se
         parte): nunca queda solo al pie de una página. --}}
    @foreach($photos->chunk(2) as $row)
        <div class="section" style="page-break-inside: avoid;{{ $loop->first ? '' : ' margin-top: 3mm;' }}">
            @if($loop->first)<h2>Fotos ({{ $photos->count() }})</h2>@endif
            <table class="photos">
                <tr>
                    @foreach($row as $index => $photo)
                        <td>
                            <img src="{{ $photo['src'] }}" style="width: {{ $photo['width'] }}mm; height: {{ $photo['height'] }}mm;">
                            <div class="photo-caption">Foto {{ $index + 1 }}</div>
                        </td>
                    @endforeach
                    @if($row->count() === 1)<td></td>@endif
                </tr>
            </table>
        </div>
    @endforeach
@endif

</body>
</html>

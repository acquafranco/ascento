{{--
    PDF del remito (dompdf, A4). Mismos datos que la vista web
    (DeliveryNoteDocument::for($note, pdf: true)): logo embebido, color de la
    empresa, firmas. Tablas y estilos en línea (dompdf no entiende flex ni grid);
    solo regular y bold de DejaVu Sans.
    - Pie fijo con el número de página en todas las hojas.
    - Los bloques cortos (datos, firmas) no se parten entre páginas; el
      trabajo realizado sí puede seguir en la hoja siguiente (si no, un texto
      largo se cortaría o dejaría una hoja vacía).
    $doc (array)
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Remito #{{ $doc['number'] }}</title>
    <style>
        @page { margin: 26px 30px 46px; }
        body { margin: 0; font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #11161D; line-height: 1.45; }
        table { width: 100%; border-collapse: collapse; }
        .label { font-size: 8px; font-weight: bold; letter-spacing: 1px; color: #6B7280; text-transform: uppercase; }
        .value { font-size: 11px; font-weight: bold; }
        .box { border: 1px solid #E7E9EE; border-radius: 8px; padding: 10px 12px; margin-top: 10px; }
        .keep { page-break-inside: avoid; }
        .text { white-space: pre-line; font-size: 10.5px; color: #1F2937; }
        .chip { display: inline-block; padding: 2px 8px; border-radius: 9px; font-size: 9px; font-weight: bold; }
        .footer { position: fixed; bottom: -30px; left: 0; right: 0; height: 20px; font-size: 8px; color: #9CA3AF; border-top: 1px solid #E7E9EE; padding-top: 5px; }
        .footer .page:after { content: "Página " counter(page); }
    </style>
</head>
<body>
    <div class="footer">
        <table><tr>
            <td>{{ $doc['company']['name'] }} · Remito #{{ $doc['number'] }} · {{ $doc['date'] }} · Emitido con Ascento</td>
            <td style="text-align: right;"><span class="page"></span></td>
        </tr></table>
    </div>

    {{-- Encabezado con el color de la empresa --}}
    <table class="keep" style="background: {{ $doc['company']['color'] }}; color: {{ $doc['company']['on_color'] }};">
        <tr>
            @if ($doc['company']['logo'])
                <td style="width: 74px; padding: 12px 0 12px 14px; vertical-align: middle;">
                    <div style="background: #FFFFFF; border-radius: 8px; padding: 5px; text-align: center;">
                        <img src="{{ $doc['company']['logo'] }}" style="max-width: 52px; max-height: 52px;" alt="">
                    </div>
                </td>
            @endif
            <td style="padding: 12px 14px; vertical-align: middle;">
                <div style="font-size: 10px; font-weight: bold;">{{ $doc['company']['name'] }}</div>
                <div style="font-size: 19px; font-weight: bold; margin-top: 2px;">Remito #{{ $doc['number'] }}</div>
            </td>
            <td style="padding: 12px 14px; vertical-align: middle; text-align: right;">
                @if ($doc['kind'])
                    <span class="chip" style="background: #FFFFFF; color: #11161D;">{{ $doc['kind']['label'] }}</span><br>
                @endif
                <span class="chip" style="margin-top: 4px; {{ $doc['performed'] ? 'background:#DCFCE7;color:#166534;' : 'background:#FEE2E2;color:#991B1B;' }}">{{ $doc['performed'] ? 'Realizado' : 'No realizado' }}</span>
            </td>
        </tr>
    </table>

    {{-- Datos --}}
    <div class="box keep">
        <table>
            <tr>
                <td style="width: 50%; vertical-align: top; padding-bottom: 8px;"><div class="label">Cliente</div><div class="value">{{ $doc['client'] ?: '—' }}</div></td>
                <td style="vertical-align: top; padding-bottom: 8px;"><div class="label">Edificio y dirección</div><div class="value">{{ $doc['building'] ?: '—' }}</div></td>
            </tr>
        </table>
        <table>
            <tr>
                <td style="width: 25%; vertical-align: top;"><div class="label">Fecha</div><div class="value">{{ $doc['date'] }}</div></td>
                <td style="width: 25%; vertical-align: top;"><div class="label">Período</div><div class="value">{{ $doc['period'] }}</div></td>
                <td style="width: 25%; vertical-align: top;"><div class="label">Equipos</div><div class="value">{{ $doc['equipment'] }}</div></td>
                <td style="vertical-align: top;"><div class="label">Técnico</div><div class="value">{{ $doc['technician'] ?: '—' }}</div></td>
            </tr>
        </table>
    </div>

    {{-- Trabajo realizado: puede seguir en la hoja siguiente --}}
    <div class="box">
        <div class="label" style="margin-bottom: 4px;">Trabajo realizado</div>
        <div class="text">{{ $doc['description'] ?: '—' }}</div>
    </div>

    @if ($doc['materials'])
        <div class="box">
            <div class="label" style="margin-bottom: 4px;">Materiales utilizados</div>
            <table>
                @foreach ($doc['materials'] as $material)
                    <tr class="keep" style="border-top: 1px solid #F1F2F5;">
                        <td style="padding: 4px 0;">{{ $material['name'] }}</td>
                        <td style="padding: 4px 0; text-align: right; font-weight: bold; white-space: nowrap;">{{ $material['quantity'] }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif

    @if ($doc['parachute'] ?? [])
        <div class="box">
            <div class="label" style="margin-bottom: 4px;">Prueba de paracaídas</div>
            <table>
                @foreach ($doc['parachute'] as $test)
                    <tr class="keep" style="border-top: 1px solid #F1F2F5;">
                        <td style="padding: 4px 0;">{{ $test['elevator'] }}</td>
                        <td style="padding: 4px 0; text-align: right; font-weight: bold; white-space: nowrap;">{{ $test['result'] }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif

    @if ($doc['participants'])
        <div class="box keep">
            <div class="label" style="margin-bottom: 4px;">Técnicos participantes</div>
            {{ collect($doc['participants'])->map(fn ($p) => $p['name'].($p['responsible'] ? ' (Responsable)' : ''))->implode(' · ') }}
        </div>
    @endif

    {{-- Firmas: siempre juntas --}}
    <div class="box keep">
        <div class="label" style="margin-bottom: 6px;">Firmas</div>
        <table>
            <tr>
                @foreach ($doc['signatures'] as $sign)
                    <td style="width: 50%; vertical-align: top; {{ $loop->first ? 'padding-right: 6px;' : 'padding-left: 6px;' }}">
                        <div style="border: 1px solid #E7E9EE; border-radius: 6px;">
                            <div style="height: 78px; text-align: center; padding: 4px;">
                                @if ($sign['image'])
                                    <img src="{{ $sign['image'] }}" style="max-height: 70px; max-width: 220px;" alt="">
                                @else
                                    <div style="padding-top: 30px; color: #9CA3AF;">Sin firma</div>
                                @endif
                            </div>
                            <div style="border-top: 1px solid #E7E9EE; background: #F8F9FB; padding: 5px 8px;">
                                <div class="label">{{ $sign['who'] }} · aclaración</div>
                                <div class="value">{{ $sign['name'] ?: '—' }}</div>
                            </div>
                        </div>
                    </td>
                @endforeach
            </tr>
        </table>
    </div>
</body>
</html>

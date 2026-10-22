{{-- Pedido de repuestos (SuperAdmin): productos, contacto compartido y el historial completo (solo lectura). --}}
@php($record = $getRecord()->loadMissing(['items', 'events']))
<div style="display:grid; gap:1.25rem; font-size:.875rem">
    <div style="overflow-x:auto">
        <table style="width:100%; border-collapse:collapse">
            <thead><tr style="text-align:left; opacity:.7"><th style="padding:.3rem">Producto (copia al pedir)</th><th>Cantidad</th><th>Precio publicado</th><th>Cotizado (unitario)</th></tr></thead>
            <tbody>
                @foreach($record->items as $item)
                    <tr style="border-top:1px solid rgba(127,127,127,.2)">
                        <td style="padding:.35rem">{{ $item->name }}<span style="opacity:.7">{{ $item->part_number ? ' · Cód. '.$item->part_number : '' }}</span></td>
                        <td>{{ $item->quantity }}</td>
                        <td>{{ $item->listed_price !== null ? $record->money((float) $item->listed_price) : 'A cotizar' }}</td>
                        <td>{{ $item->quoted_unit_price !== null ? $record->money((float) $item->quoted_unit_price) : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div>
        <p style="font-weight:600; margin:0 0 .3rem">Contacto que compartió el comprador</p>
        <p style="margin:0">{{ $record->contact_name }} · {{ $record->contact_email ?: '—' }}{{ $record->contact_phone ? ' · '.$record->contact_phone : '' }}{{ $record->delivery_area ? ' · Entrega: '.$record->delivery_area : '' }}</p>
        @if($record->supplier_note)<p style="margin:.3rem 0 0"><strong>Nota del proveedor:</strong> {{ $record->supplier_note }}</p>@endif
    </div>
    <div>
        <p style="font-weight:600; margin:0 0 .3rem">Historial (no se edita)</p>
        @foreach($record->events as $e)
            <div style="border-top:1px solid rgba(127,127,127,.2); padding:.35rem 0">
                <strong>{{ $e->label() }}</strong> · {{ $e->actorLabel() }} · {{ $e->created_at->format('d/m/Y H:i') }}{{ $e->kind === 'status' && $e->from ? ' · de «'.(\App\Models\Marketplace\MarketplaceRequest::STATUSES[$e->from] ?? $e->from).'»' : '' }}
                @if($e->note)<div style="white-space:pre-line; opacity:.85">{{ $e->note }}</div>@endif
            </div>
        @endforeach
    </div>
</div>

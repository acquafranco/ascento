@php
    use App\Models\Marketplace\MarketplaceRequest;
    $canQuote = $supplier->isApproved() && $row->canMove('supplier', MarketplaceRequest::QUOTED);
@endphp
<x-supplier-layout :title="'Pedido '.$row->reference">
    <a class="s-link" href="{{ route('suppliers.requests.index') }}">← Pedidos</a>
    <div class="s-row" style="margin:8px 0 14px">
        <h1 class="s-h1">Pedido {{ $row->reference }}</h1>
        <span class="s-tag {{ $row->statusTone() }}" data-request-status="{{ $row->status }}">{{ $row->statusLabel() }}</span>
    </div>
    @include('suppliers.partials.status-notice')

    <div class="s-cols" style="align-items:start">
        <section class="s-card">
            <h2 class="s-h2">Contacto del comprador</h2>
            <p style="margin:0; font-size:14px; line-height:1.7">
                <strong>{{ $row->company?->name }}</strong><br>
                {{ $row->contact_name }}<br>
                <a class="s-link" href="mailto:{{ $row->contact_email }}">{{ $row->contact_email }}</a>
                @if($row->contact_phone)<br><a class="s-link" href="tel:{{ preg_replace('/[^0-9+]/', '', $row->contact_phone) }}">{{ $row->contact_phone }}</a>@endif
                @if($row->delivery_area)<br><span class="s-muted">Entrega en: {{ $row->delivery_area }}</span>@endif
            </p>
            @if($row->notes)<p class="s-muted" style="white-space:pre-line; margin-top:10px">{{ $row->notes }}</p>@endif
            <p class="s-muted" style="margin-top:10px">Pedido el {{ $row->created_at->format('d/m/Y H:i') }}.</p>
        </section>

        <section class="s-card">
            <h2 class="s-h2">Historial</h2>
            <ol style="margin:0; padding-left:18px; font-size:14px; line-height:1.6">
                @foreach($row->events as $e)
                    <li><strong>{{ MarketplaceRequest::STATUSES[$e->to] ?? $e->to }}</strong> <span class="s-muted">· {{ $e->actorLabel() }} · {{ $e->created_at->format('d/m/Y H:i') }}</span>@if($e->note)<br><span class="s-muted" style="white-space:pre-line">{{ $e->note }}</span>@endif</li>
                @endforeach
            </ol>
        </section>
    </div>

    <form method="POST" action="{{ route('suppliers.requests.quote', $row->reference) }}" class="s-card s-form" data-request-quote>
        @csrf
        <h2 class="s-h2">Productos {{ $canQuote ? 'y cotización' : '' }}</h2>
        @error('precios')<p class="s-err">{{ $message }}</p>@enderror
        <div class="s-scroll">
            <table class="s-table s-stack-sm">
                <thead><tr><th>Producto</th><th>Cantidad</th><th>Precio publicado</th><th>Precio unitario cotizado</th></tr></thead>
                <tbody>
                    @foreach($row->items as $item)
                        <tr>
                            <td><strong>{{ $item->name }}</strong><br><span class="s-muted">{{ collect([$item->brand, $item->part_number ? 'Cód. '.$item->part_number : null])->filter()->implode(' · ') }}</span></td>
                            <td>{{ $item->quantity }}</td>
                            <td>{{ $item->listed_price !== null ? $row->money((float) $item->listed_price) : 'A cotizar' }}</td>
                            <td>
                                @if($canQuote)
                                    <label class="sr-only" for="precio-{{ $item->id }}" style="position:absolute;left:-9999px">Precio de {{ $item->name }}</label>
                                    <input id="precio-{{ $item->id }}" type="number" name="precios[{{ $item->id }}]" value="{{ old('precios.'.$item->id, $item->quoted_unit_price ?? $item->listed_price) }}" min="0" step="0.01" inputmode="decimal" placeholder="Sin cotizar">
                                    @error('precios.'.$item->id)<p class="s-err">{{ $message }}</p>@enderror
                                @else
                                    {{ $item->quoted_unit_price !== null ? $row->money((float) $item->quoted_unit_price) : '—' }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($row->quoted_total !== null)
            <p style="margin-top:10px"><strong>Total cotizado: {{ $row->money((float) $row->quoted_total) }}</strong>
                @if($row->quoteExpiresAt())<span class="s-muted"> · válida hasta el {{ $row->quoteExpiresAt()->format('d/m/Y') }}</span>@endif</p>
        @endif
        @if($row->supplier_note && ! $canQuote)<p class="s-muted" style="white-space:pre-line">Tu nota: {{ $row->supplier_note }}</p>@endif

        @if($canQuote)
            <div class="s-cols" style="margin-top:12px">
                <div class="s-field"><label class="s-label" for="validez">Validez (días)</label><input id="validez" type="number" name="validez" value="{{ old('validez', $row->quote_valid_days ?? 7) }}" min="1" max="90">@error('validez')<p class="s-err">{{ $message }}</p>@enderror</div>
            </div>
            <div class="s-field"><label class="s-label" for="nota">Nota para el comprador</label><textarea id="nota" name="nota" maxlength="2000" placeholder="Plazo de entrega, forma de pago, envío…">{{ old('nota', $row->supplier_note) }}</textarea></div>
            <p class="s-hint">El total lo calcula Ascento con tus precios y las cantidades pedidas. Dejá vacío lo que no tengas.</p>
            <button class="s-btn" type="submit" style="margin-top:8px">{{ $row->status === 'quoted' ? 'Actualizar cotización' : 'Enviar cotización' }}</button>
        @endif
    </form>

    @if($supplier->isApproved() && $row->canMove('supplier', MarketplaceRequest::DECLINED))
        <details class="s-card">
            <summary style="cursor:pointer; font-weight:600">No puedo cotizarlo</summary>
            <form method="POST" action="{{ route('suppliers.requests.decline', $row->reference) }}" class="s-form" style="margin-top:10px">
                @csrf
                <div class="s-field"><label class="s-label" for="nota-rechazo">Motivo (lo ve el comprador)</label><textarea id="nota-rechazo" name="nota" maxlength="2000"></textarea></div>
                <button class="s-btn danger" type="submit" data-request-decline>Avisar que no puedo cotizar</button>
            </form>
        </details>
    @endif
    @if($row->status === 'accepted')
        <div class="s-notice ok" style="margin-top:14px">El comprador aceptó la cotización. Coordiná con {{ $row->contact_name }} el pago y la entrega; cuando lo reciba, lo marca como completado.</div>
    @endif
</x-supplier-layout>

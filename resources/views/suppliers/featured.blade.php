@php
    use App\Models\Marketplace\FeaturedPlacement;
    use App\Support\Marketplace\Money;
@endphp
<x-supplier-layout title="Destacados">
    <h1 class="s-h1">Publicaciones destacadas</h1>
    <p class="s-sub">Visibilidad adicional para un producto: aparece antes que otros <strong>igual de relevantes</strong> para la búsqueda del comprador, con la etiqueta «Destacado». No salta la relevancia ni la moderación, y no hay comisión por venta.</p>

    @include('suppliers.partials.status-notice')

    <section class="s-card">
        <h2 class="s-h2">Destacar un producto</h2>
        @if($offers === [])
            <p class="s-muted" style="margin:0">{{ $billable ? 'Los destacados todavía no están disponibles. Te vamos a avisar cuando haya precios publicados.' : 'Los destacados pagos por ahora solo están disponibles para proveedores registrados en Argentina.' }}</p>
        @elseif($products->isEmpty())
            <p class="s-muted" style="margin:0">Para destacar, necesitás al menos un producto publicado y visible en el catálogo.</p>
        @else
            <form method="POST" action="{{ route('suppliers.featured.purchase') }}" class="s-form">@csrf
                <div class="s-cols">
                    <div class="s-field"><label class="s-label" for="producto">Producto</label>
                        <select id="producto" name="producto" required>@foreach($products as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></div>
                    <div class="s-field"><label class="s-label" for="dias">Duración</label>
                        <select id="dias" name="dias" required>@foreach($offers as $days => $price)<option value="{{ $days }}">{{ $days }} días · {{ Money::format($price, $currency) }}</option>@endforeach</select></div>
                </div>
                <button class="s-btn" type="submit" data-featured-buy>Pagar con Mercado Pago</button>
                <p class="s-hint">Se activa cuando Mercado Pago confirma el pago. Si el producto ya tiene un destacado vigente, el nuevo empieza cuando termina el actual (renovación). Si el producto se oculta por moderación, el destacado se cancela.</p>
            </form>
        @endif
    </section>

    <section class="s-card">
        <h2 class="s-h2">Historial</h2>
        @if($placements->isEmpty())
            <p class="s-muted">Todavía no destacaste productos.</p>
        @else
            <div class="s-scroll"><table class="s-table s-stack-sm">
                <thead><tr><th>Producto</th><th>Período</th><th>Importe</th><th>Estado</th></tr></thead>
                <tbody>
                    @foreach($placements as $f)
                        <tr data-placement="{{ $f->id }}">
                            <td><strong>{{ $f->product_name }}</strong><br><span class="s-muted">{{ $f->sourceLabel() }} · {{ $f->days }} días</span></td>
                            <td>{{ $f->starts_at ? $f->starts_at->format('d/m/Y').' al '.$f->ends_at->format('d/m/Y') : '—' }}</td>
                            <td>{{ $f->amount !== null ? Money::format((float) $f->amount, $f->currency) : 'Sin cargo' }}</td>
                            <td><span class="s-tag {{ $f->status === FeaturedPlacement::ACTIVE ? 'ok' : ($f->status === FeaturedPlacement::PENDING_PAYMENT ? 'warn' : '') }}">{{ $f->statusLabel() }}</span>
                                @if($f->status === FeaturedPlacement::PENDING_PAYMENT && $f->checkout_url)<br><a class="s-link" href="{{ $f->checkout_url }}" rel="noopener">Completar el pago</a>@endif
                                @if($f->status_reason)<br><span class="s-muted">{{ $f->status_reason }}</span>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    </section>
</x-supplier-layout>

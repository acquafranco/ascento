@php
    use App\Models\Marketplace\SupplierPayment;
    use App\Models\Marketplace\SupplierSubscription;
    use App\Support\Marketplace\Money;
    $premiumDetail = \App\Support\Marketplace\SupplierStanding::premiumDetail($supplier);
@endphp
<x-supplier-layout title="Plan">
    <h1 class="s-h1">Tu plan</h1>
    <p class="s-sub">Publicar en Ascento es gratis para siempre. Premium es opcional y amplía cuántos productos podés tener publicados. Nada de esto es una comisión: Ascento no cobra por tus ventas ni procesa los pagos de los repuestos.</p>

    @include('suppliers.partials.status-notice')
    @include('suppliers.partials.usage', ['usage' => $usage])
    @if($usage['over_limit'] > 0 && $usage['remaining'] > 0)
        <form method="POST" action="{{ route('suppliers.products.restore') }}" class="s-notice warn">@csrf
            Tenés {{ $usage['over_limit'] }} {{ $usage['over_limit'] === 1 ? 'producto' : 'productos' }} fuera del límite y lugar para publicar.
            <button class="s-btn secondary" type="submit" style="margin-left:6px" data-restore-over-limit>Volver a publicar los que entran</button>
        </form>
    @endif

    <div class="s-cols" style="align-items:start">
        <section class="s-card" data-plan="free">
            <h2 class="s-h2">Plan gratuito {{ $usage['premium'] ? '' : '· el tuyo' }}</h2>
            <ul style="margin:0; padding-left:18px; font-size:14px; line-height:1.7">
                <li>Sin costo, para siempre y sin cargar una tarjeta.</li>
                <li>Hasta <strong>{{ number_format($settings->freeLimit(), 0, ',', '.') }}</strong> productos publicados a la vez.</li>
                <li>Perfil comercial, pedidos de cotización y contacto directo con las empresas.</li>
                <li>Podés contratar destacados aparte{{ $billable ? '' : ' (cuando haya pagos en tu país)' }}.</li>
            </ul>
        </section>

        <section class="s-card" data-plan="premium" data-billing-state="{{ $usage['premium'] ? 'premium' : 'free' }}">
            <h2 class="s-h2">Premium {{ $usage['premium'] ? '· el tuyo' : '' }}</h2>
            <ul style="margin:0 0 10px; padding-left:18px; font-size:14px; line-height:1.7">
                <li>Todo lo del plan gratuito.</li>
                <li>Hasta <strong>{{ number_format($settings->premiumLimit(), 0, ',', '.') }}</strong> productos publicados a la vez.</li>
                <li>Si lo cancelás o vence, volvés al plan gratuito sin perder nada: elegís qué {{ $settings->freeLimit() }} productos quedan publicados.</li>
            </ul>
            @if($premiumDetail)<p style="font-size:14px; margin:0 0 10px"><span class="s-tag {{ $usage['premium'] ? 'ok' : '' }}">{{ $premiumDetail }}</span></p>@endif

            @if(! $billable)
                <p class="s-muted" style="margin:0">Premium por ahora solo se puede contratar desde Argentina. Mientras tanto seguís publicando gratis.</p>
            @elseif(! $settings->sellsPremium())
                <p class="s-muted" style="margin:0">Premium todavía no está disponible. Te vamos a avisar el precio y las condiciones antes de ofrecerlo.</p>
            @else
                <p style="font-size:14px; margin:0 0 10px"><strong>{{ Money::format((float) $settings->premium_price, $settings->pricing_currency) }}</strong> por mes, con débito automático en Mercado Pago (los datos de pago se cargan en Mercado Pago, no en Ascento). Se cancela cuando quieras; no hay permanencia mínima.</p>
                @if($blockReason)
                    <p class="s-muted">{{ $blockReason }}</p>
                @else
                    <form method="POST" action="{{ route('suppliers.billing.subscribe') }}">@csrf
                        <button class="s-btn" type="submit" data-billing-subscribe>{{ $subscription && $subscription->current_period_end ? 'Reactivar Premium con Mercado Pago' : 'Contratar Premium con Mercado Pago' }}</button>
                    </form>
                    <p class="s-hint">Premium se activa cuando Mercado Pago nos confirma el cobro (puede tardar unos minutos). Hasta entonces seguís en el plan gratuito.</p>
                @endif
            @endif

            @if($subscription?->isBillable() && in_array($subscription->status, [SupplierSubscription::AUTHORIZED, SupplierSubscription::PAST_DUE, SupplierSubscription::PENDING], true))
                <form method="POST" action="{{ route('suppliers.billing.cancel') }}" style="margin-top:14px" onsubmit="return confirm('¿Cancelar Premium? Mercado Pago deja de cobrarte. Premium sigue hasta el fin del período pago y después volvés al plan gratuito.')">@csrf
                    <button class="s-btn danger" type="submit" data-billing-cancel>Cancelar Premium</button>
                </form>
            @endif
        </section>
    </div>

    @if($featuredOffers !== [])
        <section class="s-card">
            <h2 class="s-h2">Destacados</h2>
            <p style="font-size:14px; margin:0">Visibilidad adicional para un producto, con pago único: {{ collect($featuredOffers)->map(fn ($price, $days) => $days.' días por '.Money::format($price, $settings->pricing_currency))->implode(' · ') }}. <a class="s-link" href="{{ route('suppliers.featured') }}">Destacar un producto</a></p>
        </section>
    @endif

    <section class="s-card">
        <h2 class="s-h2">Historial de cobros</h2>
        @if($payments->isEmpty())
            <p class="s-muted">Todavía no hay cobros.</p>
        @else
            <div class="s-scroll"><table class="s-table s-stack-sm">
                <thead><tr><th>Fecha</th><th>Concepto</th><th>Importe</th><th>Estado</th></tr></thead>
                <tbody>
                    @foreach($payments as $p)
                        <tr>
                            <td>{{ ($p->paid_at ?? $p->created_at)->format('d/m/Y') }}</td>
                            <td>{{ $p->kind === 'featured' ? 'Destacado: '.($p->placement?->product_name ?? '—') : 'Premium'.($p->period_end ? ' (hasta el '.$p->period_end->format('d/m/Y').')' : '') }}</td>
                            <td>{{ Money::format($p->amount !== null ? (float) $p->amount : null, $p->currency) }}</td>
                            <td><span class="s-tag {{ $p->status === SupplierPayment::APPROVED ? 'ok' : ($p->status === SupplierPayment::PENDING ? 'warn' : 'bad') }}">{{ $p->statusLabel() }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            <p class="s-hint">Estados informados por Mercado Pago. Para la factura de cada cobro, escribinos a {{ config('legal.email') }}.</p>
        @endif
    </section>
</x-supplier-layout>

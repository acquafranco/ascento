@php
    use App\Models\Marketplace\SupplierPayment;
    use App\Models\Marketplace\SupplierSubscription;
    use App\Support\Marketplace\Money;
    use App\Support\Marketplace\SupplierStanding;
    $operating = in_array($state, SupplierStanding::OPERATING, true);
@endphp
<x-supplier-layout title="Suscripción">
    <h1 class="s-h1">Suscripción</h1>
    <p class="s-sub">Lo que pagás a Ascento para publicar en el marketplace. Es aparte de tus ventas: Ascento no cobra comisiones ni procesa los pagos de los repuestos.</p>

    @include('suppliers.partials.status-notice')

    <div class="s-cols" style="align-items:start">
        <section class="s-card" data-billing-state="{{ $state }}">
            <h2 class="s-h2">Estado de tu cuenta</h2>
            <p style="margin:0 0 8px"><span class="s-tag {{ $operating ? 'ok' : 'bad' }}">{{ SupplierStanding::label($state) }}</span></p>
            <dl style="margin:0; font-size:14px; line-height:1.7">
                @if($supplier->trial_ends_at)
                    <div><span class="s-muted">Prueba gratuita:</span> {{ $supplier->trial_started_at?->format('d/m/Y') }} al {{ $supplier->trial_ends_at->format('d/m/Y') }}{{ $supplier->trial_ends_at->isPast() ? ' (terminada)' : '' }}</div>
                @elseif($supplier->status === \App\Models\Marketplace\Supplier::PENDING)
                    <div class="s-muted">Tu prueba gratuita de {{ $settings->trialDays() }} días empieza cuando aprobemos tu cuenta.</div>
                @endif
                @if($subscription)
                    <div><span class="s-muted">Suscripción:</span> {{ $subscription->statusLabel() }} · {{ Money::format((float) $subscription->amount, $subscription->currency) }} por mes</div>
                    @if($subscription->current_period_end)<div><span class="s-muted">Pagada hasta:</span> {{ $subscription->current_period_end->format('d/m/Y') }}</div>@endif
                    @if($subscription->next_payment_at && in_array($subscription->status, [SupplierSubscription::AUTHORIZED, SupplierSubscription::PAST_DUE], true))<div><span class="s-muted">Próximo cobro:</span> {{ $subscription->next_payment_at->format('d/m/Y') }}</div>@endif
                    @if($subscription->status === SupplierSubscription::AUTHORIZED && ! $subscription->current_period_end)<div class="s-muted">Mercado Pago autorizó la suscripción: esperamos la confirmación del primer cobro para activarla.</div>@endif
                @endif
            </dl>
            <p class="s-muted" style="margin-top:10px">Si la prueba o la suscripción terminan, tu catálogo deja de verse y no recibís pedidos nuevos, pero <strong>no borramos nada</strong>: tus productos, fotos, perfil y pedidos quedan guardados y vuelven a verse cuando reactivás la cuenta. Las publicaciones ocultas por moderación siguen ocultas.</p>
        </section>

        <section class="s-card">
            <h2 class="s-h2">Contratar o reactivar</h2>
            @if(! $billable)
                <p style="font-size:14px; margin:0">Los cobros del marketplace por ahora solo están disponibles para proveedores registrados en Argentina.
                    @if($settings->international_free) Mientras tanto, tu cuenta ({{ \App\Support\Countries::name($supplier->country) }}) opera <strong>sin cargo</strong>. @else Por ahora no hay una modalidad disponible para tu país: escribinos a {{ config('legal.email') }}. @endif</p>
            @elseif(! $settings->chargesSubscription())
                <p style="font-size:14px; margin:0">La suscripción para proveedores todavía no está disponible: mientras tanto publicás <strong>sin cargo</strong>. Te vamos a avisar con anticipación el precio y las condiciones antes de empezar a cobrar.</p>
            @else
                <p style="font-size:14px; margin:0 0 10px">Suscripción mensual: <strong>{{ Money::format((float) $settings->plan_price, $settings->plan_currency) }}</strong> por mes, con débito automático en Mercado Pago (los datos de pago se cargan en Mercado Pago, no en Ascento). Se cancela cuando quieras y no hay permanencia mínima.
                    @if($supplier->trial_ends_at?->isFuture()) Si te suscribís durante la prueba, el primer mes pago empieza cuando termina la prueba. @endif</p>
                @if($blockReason)
                    <p class="s-muted">{{ $blockReason }}</p>
                @else
                    <form method="POST" action="{{ route('suppliers.billing.subscribe') }}">@csrf
                        <button class="s-btn" type="submit" data-billing-subscribe>{{ $subscription && $subscription->current_period_end ? 'Reactivar con Mercado Pago' : 'Suscribirme con Mercado Pago' }}</button>
                    </form>
                    <p class="s-hint">La suscripción se activa cuando Mercado Pago nos confirma el cobro (puede tardar unos minutos).</p>
                @endif
            @endif

            @if($subscription?->isBillable() && in_array($subscription->status, [SupplierSubscription::AUTHORIZED, SupplierSubscription::PAST_DUE, SupplierSubscription::PENDING], true))
                <form method="POST" action="{{ route('suppliers.billing.cancel') }}" style="margin-top:14px" onsubmit="return confirm('¿Cancelar la suscripción? Mercado Pago deja de cobrarte. Tu catálogo sigue visible hasta el fin del período pago.')">@csrf
                    <button class="s-btn danger" type="submit" data-billing-cancel>Cancelar suscripción</button>
                </form>
            @endif
        </section>
    </div>

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
                            <td>{{ $p->kind === 'featured' ? 'Destacado: '.($p->placement?->product_name ?? '—') : 'Suscripción'.($p->period_end ? ' (hasta el '.$p->period_end->format('d/m/Y').')' : '') }}</td>
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

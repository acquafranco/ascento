<x-filament-panels::page>
    @php
        $plan = $this->getPlan();
        $subscription = $this->getSubscription();
        [$statusLabel, $statusColor, $statusText] = $this->getStatusInfo();
        $payments = $this->getPayments();
        $hasAccess = $this->getCompany()->hasActiveAccess();
        $daysRemaining = $this->daysRemaining();
        $price = $plan ? number_format((float) $plan->price, 0, ',', '.') : null;
    @endphp

    <style>
        .sub-grid { display: grid; gap: 1.5rem; }
        @media (min-width: 1024px) { .sub-grid { grid-template-columns: minmax(0, 3fr) minmax(0, 2fr); } }
        .sub-status { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; }
        .sub-status-text { margin-top: .5rem; color: var(--gray-600); font-size: .9375rem; line-height: 1.5; }
        .dark .sub-status-text { color: var(--gray-300); }
        .sub-price { font-size: 2.25rem; font-weight: 700; line-height: 1; color: var(--gray-950); }
        .dark .sub-price { color: #fff; }
        .sub-muted { color: var(--gray-500); font-size: .875rem; }
        .sub-actions { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; margin-top: 1.25rem; }
        .sub-features { margin-top: 1rem; display: grid; gap: .5rem; font-size: .875rem; color: var(--gray-600); }
        .dark .sub-features { color: var(--gray-300); }
        .sub-features li::before { content: '✓'; color: var(--success-600); font-weight: 700; margin-right: .5rem; }
        .sub-alert { display: flex; gap: .5rem; padding: .875rem 1rem; border-radius: .75rem; font-size: .875rem;
            background: color-mix(in oklab, var(--warning-500) 12%, transparent); color: var(--warning-800); }
        .dark .sub-alert { color: var(--warning-300); }
        .sub-table { width: 100%; font-size: .875rem; border-collapse: collapse; }
        .sub-table th { text-align: left; color: var(--gray-500); font-weight: 500; padding: .5rem .5rem .5rem 0; }
        .sub-table td { padding: .6rem .5rem .6rem 0; border-top: 1px solid var(--gray-100); }
        .dark .sub-table td { border-color: var(--gray-800); }
        .sub-secure { margin-top: .75rem; }
    </style>

    @if ($hasAccess && $daysRemaining !== null && $daysRemaining <= 5 && ! $this->canCancel())
        <div class="sub-alert" role="status">
            <span aria-hidden="true">⏰</span>
            <span>
                @if ($this->isInFreePeriod())
                    <strong>{{ $daysRemaining === 0 ? 'Tu prueba gratis termina hoy.' : ($daysRemaining === 1 ? 'Te queda 1 día de prueba gratis.' : "Te quedan {$daysRemaining} días de prueba gratis.") }}</strong>
                    Cuando termine, suscribite desde esta pantalla para seguir usando Ascento.
                @else
                    <strong>{{ $daysRemaining === 0 ? 'Tu acceso vence hoy.' : ($daysRemaining === 1 ? 'Te queda 1 día de acceso.' : "Te quedan {$daysRemaining} días de acceso.") }}</strong>
                    Suscribite con Mercado Pago para no perder el acceso.
                @endif
            </span>
        </div>
    @endif

    <div class="sub-grid">
        {{-- ESTADO + ACCIONES --}}
        <x-filament::section>
            <x-slot name="heading">Estado</x-slot>

            <div class="sub-status">
                <x-filament::badge :color="$statusColor" size="lg">{{ $statusLabel }}</x-filament::badge>
                @if ($subscription?->isMercadoPago() && $subscription->last_synced_at)
                    <span class="sub-muted">Actualizado {{ $subscription->last_synced_at->diffForHumans() }}</span>
                @endif
            </div>

            <p class="sub-status-text">{{ $statusText }}</p>

            <div class="sub-actions">
                @if ($this->canStartCheckout())
                    <x-filament::button
                        size="lg"
                        icon="heroicon-m-credit-card"
                        wire:click="checkout"
                        wire:loading.attr="disabled"
                        wire:target="checkout"
                    >
                        <span wire:loading.remove wire:target="checkout">
                            {{ $subscription?->status === \App\Models\Subscription::PENDING ? 'Continuar en Mercado Pago' : 'Suscribirme con Mercado Pago' }}
                        </span>
                        <span wire:loading wire:target="checkout">Abriendo Mercado Pago…</span>
                    </x-filament::button>
                @endif

                @if ($subscription?->isMercadoPago() && $subscription->provider_subscription_id)
                    <x-filament::button
                        color="gray"
                        icon="heroicon-m-arrow-path"
                        wire:click="refreshStatus"
                        wire:loading.attr="disabled"
                        wire:target="refreshStatus"
                    >
                        Actualizar estado
                    </x-filament::button>
                @endif

                @if ($this->canCancel())
                    {{ $this->cancelAction }}
                @endif
            </div>

            @if ($this->canStartCheckout())
                <p class="sub-muted sub-secure">
                    Pagás en Mercado Pago con tarjeta de crédito o débito. Se cobra automáticamente
                    todos los meses y podés cancelar cuando quieras desde acá.
                </p>
            @elseif ($this->isInFreePeriod() && $plan)
                <p class="sub-muted sub-secure">
                    Durante la prueba gratis no se cobra nada ni hace falta cargar una tarjeta.
                    El {{ $this->getCompany()->trial_ends_at?->format('d/m/Y') }} vas a ver acá el botón para suscribirte por ${{ $price }}/mes.
                </p>
            @elseif (! $this->canPayOnline() && $plan)
                <p class="sub-muted sub-secure">El pago con Mercado Pago no está disponible en este momento. Probá de nuevo más tarde.</p>
            @endif
        </x-filament::section>

        {{-- PLAN --}}
        <x-filament::section>
            <x-slot name="heading">{{ $plan?->name ?? 'Ascento' }}</x-slot>

            @if ($plan)
                <div class="sub-price">${{ $price }}</div>
                <div class="sub-muted">{{ $plan->currency }} por mes</div>

                <ul class="sub-features">
                    <li>Técnicos, clientes y edificios</li>
                    <li>Órdenes de trabajo con aviso al celular del técnico</li>
                    <li>Mantenimientos, inspecciones y remitos firmados</li>
                    <li>Presupuestos y mapa de edificios</li>
                </ul>
            @else
                <x-filament::badge color="danger">No hay un plan configurado</x-filament::badge>
            @endif
        </x-filament::section>
    </div>

    {{-- HISTORIAL DE COBROS --}}
    @if ($payments->isNotEmpty())
        <x-filament::section>
            <x-slot name="heading">Cobros</x-slot>

            <table class="sub-table">
                <thead>
                    <tr><th>Fecha</th><th>Importe</th><th>Estado</th><th>Cubre</th></tr>
                </thead>
                <tbody>
                    @foreach ($payments as $payment)
                        <tr>
                            <td>{{ ($payment->paid_at ?? $payment->created_at)->format('d/m/Y') }}</td>
                            <td>{{ $payment->amount ? '$'.number_format((float) $payment->amount, 0, ',', '.') : '—' }}</td>
                            <td>
                                <x-filament::badge :color="match ($payment->status) { 'approved' => 'success', 'rejected', 'amount_mismatch' => 'danger', default => 'warning' }">
                                    {{ match ($payment->status) { 'approved' => 'Aprobado', 'rejected' => 'Rechazado', 'amount_mismatch' => 'En revisión', default => 'Pendiente' } }}
                                </x-filament::badge>
                            </td>
                            <td>
                                @if ($payment->period_start && $payment->period_end)
                                    {{ $payment->period_start->format('d/m') }} – {{ $payment->period_end->format('d/m/Y') }}
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>
    @endif

</x-filament-panels::page>

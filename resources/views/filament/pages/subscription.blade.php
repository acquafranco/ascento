<x-filament-panels::page>
    @php
        $plan = $this->getPlan();
        $plans = $this->getPlans();
        $subscription = $this->getSubscription();
        [$statusLabel, $statusColor, $statusText] = $this->getStatusInfo();
        $payments = $this->getPayments();
        $hasAccess = $this->getCompany()->hasActiveAccess();
        $daysRemaining = $this->daysRemaining();
        $usage = $this->getUsage();
        $reason = $this->getUpgradeReason();
        $inTrial = $this->isInFreePeriod();
    @endphp

    <style>
        .sub-grid { display: grid; gap: 1.5rem; }
        @media (min-width: 1024px) { .sub-grid { grid-template-columns: minmax(0, 3fr) minmax(0, 2fr); } }
        .sub-status { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; }
        .sub-status-text { margin-top: .5rem; color: var(--gray-600); font-size: .9375rem; line-height: 1.5; }
        .dark .sub-status-text { color: var(--gray-300); }
        .sub-muted { color: var(--gray-500); font-size: .875rem; }
        .sub-actions { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; margin-top: 1.25rem; }
        .sub-alert { display: flex; gap: .5rem; padding: .875rem 1rem; border-radius: .75rem; font-size: .875rem;
            background: color-mix(in oklab, var(--warning-500) 12%, transparent); color: var(--warning-800); }
        .dark .sub-alert { color: var(--warning-300); }
        .sub-reason { padding: 1.25rem; border-radius: 1rem; border: 1px solid color-mix(in oklab, var(--primary-500) 45%, transparent);
            background: color-mix(in oklab, var(--primary-500) 10%, transparent); }
        .sub-reason h2 { font-size: 1.125rem; font-weight: 700; color: var(--gray-950); }
        .dark .sub-reason h2 { color: #fff; }
        .sub-reason p { margin-top: .35rem; color: var(--gray-700); }
        .dark .sub-reason p { color: var(--gray-200); }

        .sub-usage { display: grid; gap: 1rem; }
        @media (min-width: 640px) { .sub-usage { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        .sub-meter-top { display: flex; justify-content: space-between; gap: .5rem; font-size: .875rem; color: var(--gray-700); }
        .dark .sub-meter-top { color: var(--gray-200); }
        .sub-meter { height: .5rem; border-radius: 999px; background: var(--gray-100); overflow: hidden; margin-top: .35rem; }
        .dark .sub-meter { background: var(--gray-800); }
        .sub-meter span { display: block; height: 100%; border-radius: 999px; background: var(--primary-500); }
        .sub-meter span.is-near { background: var(--warning-500); }
        .sub-meter span.is-full { background: var(--danger-500); }
        .sub-meter-note { margin-top: .25rem; font-size: .8125rem; color: var(--warning-700); }
        .dark .sub-meter-note { color: var(--warning-400); }

        .sub-plans { display: grid; gap: 1rem; align-items: stretch; }
        @media (min-width: 1024px) { .sub-plans { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        .sub-plan { position: relative; display: flex; flex-direction: column; gap: 1rem; padding: 1.5rem; border-radius: 1rem;
            border: 1px solid var(--gray-200); background: #fff; }
        .dark .sub-plan { background: var(--gray-900); border-color: var(--gray-700); }
        .sub-plan.is-recommended { border: 2px solid var(--primary-500); box-shadow: 0 18px 40px -20px color-mix(in oklab, var(--primary-500) 70%, transparent); }
        @media (min-width: 1024px) { .sub-plan.is-recommended { transform: translateY(-.5rem); } }
        .sub-plan.is-current { outline: 2px solid var(--success-500); outline-offset: 2px; }
        .sub-plan-badges { display: flex; flex-wrap: wrap; gap: .4rem; min-height: 1.5rem; }
        .sub-plan h3 { font-size: 1.25rem; font-weight: 700; color: var(--gray-950); }
        .dark .sub-plan h3 { color: #fff; }
        .sub-plan-desc { font-size: .875rem; color: var(--gray-500); }
        .sub-plan-price { font-size: 2rem; font-weight: 800; line-height: 1; color: var(--gray-950); }
        .dark .sub-plan-price { color: #fff; }
        .sub-plan-price small { font-size: .875rem; font-weight: 500; color: var(--gray-500); }
        .sub-plan ul { display: grid; gap: .45rem; font-size: .875rem; color: var(--gray-700); flex: 1; }
        .dark .sub-plan ul { color: var(--gray-200); }
        .sub-plan li::before { content: '✓'; color: var(--success-600); font-weight: 700; margin-right: .5rem; }
        .sub-plan-warn { font-size: .8125rem; color: var(--warning-700); }
        .dark .sub-plan-warn { color: var(--warning-400); }

        .sub-table { width: 100%; font-size: .875rem; border-collapse: collapse; }
        .sub-table th { text-align: left; color: var(--gray-500); font-weight: 500; padding: .5rem .5rem .5rem 0; }
        .sub-table td { padding: .6rem .5rem .6rem 0; border-top: 1px solid var(--gray-100); }
        .dark .sub-table td { border-color: var(--gray-800); }
    </style>

    {{-- MOTIVO: llegó acá por un límite o una función del plan --}}
    @if ($reason)
        <div class="sub-reason" role="status" data-upgrade-reason>
            <h2>{{ $reason[0] }}</h2>
            @if ($reason[1])
                <p>{{ $reason[1] }}</p>
            @endif
            <p>
                <a href="#planes" style="font-weight:600;text-decoration:underline">Ver los planes</a>
            </p>
        </div>
    @endif

    @if ($hasAccess && $daysRemaining !== null && $daysRemaining <= 5 && ! $this->canCancel())
        <div class="sub-alert" role="status">
            <span aria-hidden="true">⏰</span>
            <span>
                @if ($inTrial)
                    <strong>{{ $daysRemaining === 0 ? 'Tu prueba gratis termina hoy.' : ($daysRemaining === 1 ? 'Te queda 1 día de prueba gratis.' : "Te quedan {$daysRemaining} días de prueba gratis.") }}</strong>
                    Cuando termine, elegí tu plan desde esta pantalla para seguir usando Ascento.
                @else
                    <strong>{{ $daysRemaining === 0 ? 'Tu acceso vence hoy.' : ($daysRemaining === 1 ? 'Te queda 1 día de acceso.' : "Te quedan {$daysRemaining} días de acceso.") }}</strong>
                    Suscribite con Mercado Pago para no perder el acceso.
                @endif
            </span>
        </div>
    @endif

    <div class="sub-grid">
        {{-- ESTADO --}}
        <x-filament::section>
            <x-slot name="heading">Estado</x-slot>

            <div class="sub-status">
                <x-filament::badge :color="$statusColor" size="lg">{{ $statusLabel }}</x-filament::badge>
                <span class="sub-muted">Plan {{ $plan->shortName() }}{{ $inTrial ? ' (durante la prueba gratis)' : '' }}</span>
            </div>

            <p class="sub-status-text">{{ $statusText }}</p>

            @if ($subscription?->isMercadoPago() && $subscription->provider_subscription_id)
                <div class="sub-actions">
                    <x-filament::button color="gray" icon="heroicon-m-arrow-path" wire:click="refreshStatus" wire:loading.attr="disabled" wire:target="refreshStatus">
                        Actualizar estado
                    </x-filament::button>

                    @if ($this->canCancel())
                        {{ $this->cancelAction }}
                    @endif
                </div>
                @if ($subscription->last_synced_at)
                    <p class="sub-muted" style="margin-top:.5rem">Actualizado {{ $subscription->last_synced_at->diffForHumans() }}</p>
                @endif
            @endif
        </x-filament::section>

        {{-- USO DEL PLAN --}}
        <x-filament::section>
            <x-slot name="heading">Uso de tu plan</x-slot>

            <div class="sub-usage" data-plan-usage>
                @foreach ($usage as $row)
                    <div>
                        <div class="sub-meter-top">
                            <span>{{ $row['label'] }}</span>
                            <strong>{{ $row['max'] === null ? $row['used'].' · sin límite' : $row['used'].' / '.$row['max'] }}</strong>
                        </div>
                        @if ($row['max'] !== null)
                            <div class="sub-meter" role="progressbar" aria-valuenow="{{ $row['used'] }}" aria-valuemax="{{ $row['max'] }}" aria-label="{{ $row['label'] }}">
                                <span class="{{ $row['percent'] >= 100 ? 'is-full' : ($row['warning'] ? 'is-near' : '') }}" style="width: {{ $row['percent'] }}%"></span>
                            </div>
                        @endif
                        @if ($row['warning'])
                            <p class="sub-meter-note">{{ $row['warning'] }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    </div>

    {{-- PLANES --}}
    <x-filament::section id="planes">
        <x-slot name="heading">Planes</x-slot>
        <x-slot name="description">
            @if ($inTrial)
                Durante la prueba gratis usás Ascento con el plan Profesional, sin cargar tarjeta. Cuando termine, elegís tu plan.
            @else
                Pagás con Mercado Pago (tarjeta de crédito o débito). Se cobra todos los meses y podés cambiar de plan o cancelar cuando quieras.
            @endif
        </x-slot>

        <div class="sub-plans">
            @foreach ($plans as $option)
                @php
                    $state = $this->planState($option);
                    $over = $this->overLimitsFor($option);
                @endphp

                <div class="sub-plan {{ $option->is_recommended ? 'is-recommended' : '' }} {{ $state === 'current' ? 'is-current' : '' }}" data-plan="{{ $option->slug }}">
                    <div class="sub-plan-badges">
                        @if ($option->is_recommended)
                            <x-filament::badge color="primary">Recomendado</x-filament::badge>
                        @endif
                        @if ($state === 'current')
                            <x-filament::badge color="success">Tu plan</x-filament::badge>
                        @endif
                    </div>

                    <div>
                        <h3>{{ $option->shortName() }}</h3>
                        <p class="sub-plan-desc">{{ $option->description }}</p>
                    </div>

                    <div class="sub-plan-price">{{ $option->formattedPrice() }} <small>{{ $option->currency }}/mes</small></div>

                    <ul>
                        @foreach ($option->highlights() as $line)
                            <li>{{ $line }}</li>
                        @endforeach
                        <li>Mantenimientos, órdenes de trabajo, historial y mapa</li>
                    </ul>

                    @if ($over && $state !== 'current')
                        <p class="sub-plan-warn">Ojo: {{ implode('; ', $over) }}. No se borra nada, pero no vas a poder agregar más.</p>
                    @endif

                    <div>
                        @switch($state)
                            @case('current')
                                <x-filament::button color="gray" disabled style="width:100%">Plan actual</x-filament::button>
                                @break
                            @case('change')
                                <div style="width:100%">{{ ($this->changePlanAction)(['plan' => $option->slug]) }}</div>
                                @break
                            @case('subscribe')
                                <x-filament::button
                                    :color="$option->is_recommended ? 'primary' : 'gray'"
                                    icon="heroicon-m-credit-card"
                                    wire:click="checkout('{{ $option->slug }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="checkout"
                                    style="width:100%"
                                >
                                    {{ $subscription?->status === \App\Models\Subscription::PENDING && $subscription->plan === $option->slug ? 'Continuar en Mercado Pago' : 'Suscribirme a '.$option->shortName() }}
                                </x-filament::button>
                                @break
                            @case('trial')
                                <x-filament::button color="gray" disabled style="width:100%">Disponible al terminar la prueba</x-filament::button>
                                @break
                            @default
                                <x-filament::button color="gray" disabled style="width:100%">Pago no disponible por ahora</x-filament::button>
                        @endswitch
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>

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

<x-filament-panels::page>

    @php
        $plans = $this->getPlans();

        $subscription = auth()->user()?->company?->subscriptions()
            ->whereIn('status', ['authorized', 'active', 'trialing'])
            ->whereNotNull('provider_subscription_id')
            ->latest('id')
            ->first();

        $isActive = $subscription !== null;

        $statusLabel = match ($subscription?->status) {
            'authorized', 'active' => 'Activo',
            'trialing' => 'Período de prueba',
            'pending' => 'Pendiente',
            'paused' => 'Pausado',
            'cancelled', 'canceled' => 'Cancelado',
            default => 'Sin suscripción',
        };

        $plan = $plans->first();
    @endphp


    {{-- ===================================================== --}}
    {{-- SUSCRIPCIÓN ACTUAL --}}
    {{-- ===================================================== --}}

    @if ($isActive)

        <x-filament::section>

            <div class="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">

                <div>

                    <div class="flex items-center gap-3">

                        <div>

                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                Tu plan
                            </p>

                            <h2 class="text-3xl font-bold text-gray-950 dark:text-white">
                                {{ $plan?->name ?? ucfirst($subscription->plan) }}
                            </h2>

                        </div>

                        <x-filament::badge color="success">
                            {{ $statusLabel }}
                        </x-filament::badge>

                    </div>


                    <div class="mt-4 flex flex-wrap gap-x-6 gap-y-2 text-sm">

                        @if ($subscription->amount)

                            <span class="text-gray-600 dark:text-gray-300">

                                <strong>
                                    ${{ number_format((float) $subscription->amount, 0, ',', '.') }}
                                </strong>

                                {{ $subscription->currency }}/mes

                            </span>

                        @endif


                        @if ($subscription->current_period_end)

                            <span class="text-gray-500 dark:text-gray-400">

                                Próximo cobro:

                                <strong class="text-gray-700 dark:text-gray-200">

                                    {{ $subscription->current_period_end->format('d/m/Y') }}

                                </strong>

                            </span>

                        @endif

                    </div>

                </div>


                {{-- ================================================= --}}
                {{-- CANCELAR --}}
                {{-- ================================================= --}}

                <div>

                    @if ($subscription->cancel_at_period_end)

                        <x-filament::button
                            color="gray"
                            disabled
                        >
                            Cancelación programada
                        </x-filament::button>

                    @else

                        <x-filament::button
                            color="danger"
                            wire:click="cancelSubscription"
                            wire:loading.attr="disabled"
                            wire:target="cancelSubscription"
                        >

                            <span
                                wire:loading.remove
                                wire:target="cancelSubscription"
                            >
                                Cancelar suscripción
                            </span>

                            <span
                                wire:loading
                                wire:target="cancelSubscription"
                            >
                                Cancelando...
                            </span>

                        </x-filament::button>

                    @endif

                </div>

            </div>


            {{-- ================================================= --}}
            {{-- AVISO DE CANCELACIÓN --}}
            {{-- ================================================= --}}

            @if ($subscription->cancel_at_period_end)

                <div class="mt-5 rounded-lg border border-warning-300 bg-warning-50 p-4 text-sm text-warning-800 dark:border-warning-700 dark:bg-warning-950 dark:text-warning-200">

                    Tu suscripción fue cancelada y no se renovará.

                    @if ($subscription->current_period_end)

                        Vas a seguir teniendo acceso hasta

                        <strong>
                            {{ $subscription->current_period_end->format('d/m/Y') }}
                        </strong>.

                    @endif

                </div>

            @endif

        </x-filament::section>


    @else

        {{-- ===================================================== --}}
        {{-- SIN SUSCRIPCIÓN --}}
        {{-- ================================================= --}}

        <x-filament::section>

            <div class="text-center">

                <h2 class="text-2xl font-bold text-gray-950 dark:text-white">
                    Ascento
                </h2>

                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                    Sistema de gestión y mantenimiento para empresas de ascensores.
                </p>


                @if ($plan)

                    <div class="mx-auto mt-8 max-w-md">

                        <div class="rounded-xl border border-gray-200 p-6 dark:border-gray-700">

                            <h3 class="text-xl font-bold text-gray-950 dark:text-white">
                                {{ $plan->name }}
                            </h3>


                            <div class="mt-4">

                                <span class="text-4xl font-bold text-gray-950 dark:text-white">
                                    ${{ number_format((float) $plan->price, 0, ',', '.') }}
                                </span>

                                <span class="text-sm text-gray-500 dark:text-gray-400">
                                    / mes
                                </span>

                            </div>


                            @if (!empty($plan->features))

                                <ul class="mt-6 space-y-3 text-left">

                                    @foreach ($plan->features as $feature)

                                        <li class="flex gap-2 text-sm text-gray-600 dark:text-gray-300">

                                            <span class="text-success-500">
                                                ✓
                                            </span>

                                            <span>
                                                {{ $feature }}
                                            </span>

                                        </li>

                                    @endforeach

                                </ul>

                            @endif


                            <div class="mt-8">

                                <x-filament::button
                                    wire:click="checkout"
                                >

                                    <span
                                        wire:loading.remove
                                        wire:target="checkout"
                                    >
                                        Suscribirme
                                    </span>

                                    <span
                                        wire:loading
                                        wire:target="checkout"
                                    >
                                        Procesando...
                                    </span>

                                </x-filament::button>

                            </div>

                        </div>

                    </div>

                @else

                    <div class="mt-6">

                        <x-filament::badge color="danger">
                            No hay un plan activo configurado.
                        </x-filament::badge>

                    </div>

                @endif

            </div>

        </x-filament::section>

    @endif

</x-filament-panels::page>

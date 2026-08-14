<x-filament-panels::page>
    @php
        $plans = $this->getPlans();

       $subscription = auth()->user()?->company?->subscription;

        $isActive = $subscription &&
            in_array($subscription->status, [
                'authorized',
                'active',
                'trialing',
            ], true);

        $currentPlan = $isActive ? $subscription->plan : null;

        $statusLabel = match ($subscription?->status) {
            'authorized', 'active' => 'Activo',
            'trialing' => 'Período de prueba',
            'pending' => 'Pendiente',
            'paused' => 'Pausado',
            'cancelled', 'canceled' => 'Cancelado',
            default => ucfirst($subscription?->status ?? 'Sin suscripción'),
        };
    @endphp


    {{-- ======================================== --}}
    {{-- PLAN ACTUAL --}}
    {{-- ======================================== --}}

    @if ($subscription && $currentPlan)

        <x-filament::section>

            <div class="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">

                <div>

                    <div class="flex items-center gap-3">

                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                Tu plan actual
                            </p>

                            <h2 class="text-3xl font-bold text-gray-950 dark:text-white">
                                {{ ucfirst($currentPlan) }}
                            </h2>
                        </div>

                        @if ($isActive)
                            <x-filament::badge color="success">
                                {{ $statusLabel }}
                            </x-filament::badge>
                        @else
                            <x-filament::badge color="warning">
                                {{ $statusLabel }}
                            </x-filament::badge>
                        @endif

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


                {{-- ACCIONES DEL PLAN ACTUAL --}}

                <div class="flex flex-col gap-2 sm:flex-row">

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
                            <span wire:loading.remove wire:target="cancelSubscription">
                                Cancelar suscripción
                            </span>

                            <span wire:loading wire:target="cancelSubscription">
                                Cancelando...
                            </span>
                        </x-filament::button>

                    @endif

                </div>

            </div>


            @if ($subscription->cancel_at_period_end)

                <div class="mt-5 rounded-lg border border-warning-300 bg-warning-50 p-4 text-sm text-warning-800 dark:border-warning-700 dark:bg-warning-950 dark:text-warning-200">

                    Tu suscripción está cancelada y seguirá activa hasta

                    <strong>
                        {{ $subscription->current_period_end?->format('d/m/Y') }}
                    </strong>.

                </div>

            @endif

        </x-filament::section>

    @else

        <x-filament::section>

            <div class="text-center">

                <h2 class="text-xl font-bold text-gray-950 dark:text-white">
                    No tenés una suscripción activa
                </h2>

                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                    Elegí uno de los planes disponibles para comenzar.
                </p>

            </div>

        </x-filament::section>

    @endif



    {{-- ======================================== --}}
    {{-- PLANES --}}
    {{-- ======================================== --}}

    <div>

        <h2 class="mb-4 text-xl font-bold text-gray-950 dark:text-white">
            Planes
        </h2>


        <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">

            @foreach ($plans as $plan)

                @php
                    $isCurrent = $currentPlan === $plan->slug;
                @endphp


                <x-filament::section>

                    <div class="flex h-full flex-col">

                        {{-- NOMBRE --}}

                        <div class="flex items-center justify-between">

                            <h3 class="text-xl font-bold text-gray-950 dark:text-white">
                                {{ $plan->name }}
                            </h3>

                            @if ($isCurrent)

                                <x-filament::badge color="success">
                                    Actual
                                </x-filament::badge>

                            @endif

                        </div>


                        {{-- PRECIO --}}

                        <div class="mt-5">

                            <span class="text-3xl font-bold text-gray-950 dark:text-white">
                                ${{ number_format((float) $plan->price, 0, ',', '.') }}
                            </span>

                            <span class="text-sm text-gray-500 dark:text-gray-400">
                                / mes
                            </span>

                        </div>


                        {{-- FEATURES --}}

                        <div class="mt-6 flex-1">

                            @if (!empty($plan->features))

                                <ul class="space-y-3">

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

                        </div>


                        {{-- BOTÓN --}}

                        <div class="mt-8">

                            @if ($isCurrent)

                                <x-filament::button
                                    color="gray"
                                    disabled
                                    class="w-full"
                                >
                                    Plan actual
                                </x-filament::button>

                            @else

                                <x-filament::button
                                    wire:click="changePlan({{ $plan->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="changePlan"
                                    class="w-full"
                                >

                                    <span wire:loading.remove wire:target="changePlan">
                                        Cambiar a {{ $plan->name }}
                                    </span>

                                    <span wire:loading wire:target="changePlan">
                                        Procesando...
                                    </span>

                                </x-filament::button>

                            @endif

                        </div>

                    </div>

                </x-filament::section>

            @endforeach

        </div>

    </div>

</x-filament-panels::page>

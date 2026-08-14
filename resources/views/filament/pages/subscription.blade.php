<x-filament-panels::page>
    @php
        $plan = $this->getPlan();

        $subscription = $this->getActiveSubscription();

        $isActive = $subscription &&
            in_array($subscription->status, [
                'authorized',
                'active',
                'trialing',
            ], true);

        $statusLabel = match ($subscription?->status) {
            'authorized', 'active' => 'Activo',
            'trialing' => 'Período de prueba',
            'cancelled', 'canceled' => 'Cancelado',
            default => 'Sin suscripción',
        };
    @endphp

    {{-- PLAN ACTUAL --}}
    @if ($subscription && $isActive)

        <x-filament::section>
            <div class="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">

                <div>
                    <div class="flex items-center gap-3">

                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                Tu plan actual
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

                        <span class="text-gray-600 dark:text-gray-300">
                            <strong>
                                ${{ number_format((float) $subscription->amount, 0, ',', '.') }}
                            </strong>
                            {{ $subscription->currency }}/mes
                        </span>

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

                    Tu suscripción seguirá activa hasta

                    <strong>
                        {{ $subscription->current_period_end?->format('d/m/Y') }}
                    </strong>.

                    Después de esa fecha no se renovará.

                </div>

            @endif

        </x-filament::section>

    @else

        {{-- SIN SUSCRIPCIÓN --}}

        <x-filament::section>

            <div class="mx-auto max-w-2xl text-center">

                <h2 class="text-2xl font-bold text-gray-950 dark:text-white">
                    Ascento
                </h2>

                <p class="mt-2 text-gray-500 dark:text-gray-400">
                    Todo lo que necesitás para gestionar tu empresa de ascensores.
                </p>

                @if ($plan)

                    <div class="mt-8">

                        <div class="text-4xl font-bold text-gray-950 dark:text-white">
                            ${{ number_format((float) $plan->price, 0, ',', '.') }}
                        </div>

                        <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            {{ $plan->currency }} / mes
                        </div>

                    </div>

                    @if (!empty($plan->features))

                        <div class="mt-8 text-left">

                            <ul class="mx-auto max-w-md space-y-3">

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

                        </div>

                    @endif

                    <div class="mt-8">

                        <x-filament::button
                            wire:click="checkout"
                            wire:loading.attr="disabled"
                            wire:target="checkout"
                            size="lg"
                        >

                            <span wire:loading.remove wire:target="checkout">
                                Contratar Ascento
                            </span>

                            <span wire:loading wire:target="checkout">
                                Procesando...
                            </span>

                        </x-filament::button>

                    </div>

                @else

                    <div class="mt-6">

                        <x-filament::badge color="danger">
                            No hay un plan configurado
                        </x-filament::badge>

                    </div>

                @endif

            </div>

        </x-filament::section>

    @endif

</x-filament-panels::page>

<x-filament-panels::page>
    @php
        $plans = $this->getPlans();

        $currentSubscription = auth()->user()?->company?->subscription;

        $activeStatuses = [
            'active',
            'trialing',
            'authorized',
        ];

        $currentPlanSlug = null;

        if (
            $currentSubscription &&
            in_array($currentSubscription->status, $activeStatuses, true)
        ) {
            $currentPlanSlug = $currentSubscription->plan;
        }

        $statusLabel = match ($currentSubscription?->status) {
            'authorized', 'active' => 'Activo',
            'trialing' => 'Período de prueba',
            'pending' => 'Pendiente',
            'paused' => 'Pausado',
            'cancelled', 'canceled' => 'Cancelado',
            default => ucfirst($currentSubscription?->status ?? ''),
        };

        $statusColor = match ($currentSubscription?->status) {
            'authorized', 'active' => 'success',
            'trialing' => 'warning',
            'pending' => 'gray',
            'paused' => 'warning',
            'cancelled', 'canceled' => 'danger',
            default => 'gray',
        };
    @endphp

    <div class="space-y-6">

        {{-- ENCABEZADO --}}
        <div>
            <h2 class="text-xl font-bold tracking-tight text-gray-950 dark:text-white">
                Mi suscripción
            </h2>

            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Administrá el plan de tu empresa.
            </p>
        </div>


        {{-- SUSCRIPCIÓN ACTUAL --}}
        @if ($currentSubscription && $currentPlanSlug)

            <x-filament::section>

                <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">

                    <div>

                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">
                            Plan actual
                        </p>

                        <p class="mt-1 text-2xl font-bold text-gray-950 dark:text-white">
                            {{ ucfirst($currentPlanSlug) }}
                        </p>

                        <div class="mt-2 flex flex-wrap items-center gap-2">

                            <span class="text-sm text-gray-500 dark:text-gray-400">
                                Estado:
                            </span>

                            <x-filament::badge :color="$statusColor">
                                {{ $statusLabel }}
                            </x-filament::badge>

                        </div>

                        @if ($currentSubscription->amount)

                            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                                ${{ number_format((float) $currentSubscription->amount, 0, ',', '.') }}
                                {{ $currentSubscription->currency }}
                                / mes
                            </p>

                        @endif

                        @if ($currentSubscription->current_period_end)

                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                Próximo cobro:
                                <span class="font-medium text-gray-700 dark:text-gray-200">
                                    {{ $currentSubscription->current_period_end->format('d/m/Y') }}
                                </span>
                            </p>

                        @endif

                    </div>


                    {{-- ESTADO / CANCELACIÓN --}}
                    <div class="sm:text-right">

                        @if ($currentSubscription->cancel_at_period_end)

                            <x-filament::badge color="warning">
                                Cancelación programada
                            </x-filament::badge>

                            @if ($currentSubscription->current_period_end)

                                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                    Acceso hasta
                                    {{ $currentSubscription->current_period_end->format('d/m/Y') }}
                                </p>

                            @endif

                        @else

                            <x-filament::badge color="success">
                                {{ $statusLabel }}
                            </x-filament::badge>

                        @endif

                    </div>

                </div>

            </x-filament::section>

        @endif


        {{-- PLANES --}}
        <div>

            <h3 class="mb-4 text-lg font-semibold text-gray-950 dark:text-white">
                Planes disponibles
            </h3>

            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">

                @foreach ($plans as $plan)

                    @php
                        $isCurrent = $currentPlanSlug === $plan->slug;
                    @endphp

                    <x-filament::section :heading="$plan->name">

                        <div class="flex h-full flex-col">

                            {{-- PRECIO --}}
                            <div>

                                <div class="text-3xl font-bold tracking-tight text-gray-950 dark:text-white">
                                    ${{ number_format((float) $plan->price, 0, ',', '.') }}
                                </div>

                                <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    por mes
                                </div>

                            </div>


                            {{-- CARACTERÍSTICAS --}}
                            <div class="mt-6 flex-1">

                                @if (!empty($plan->features))

                                    <ul class="space-y-3 text-sm text-gray-600 dark:text-gray-300">

                                        @foreach ($plan->features as $feature)

                                            <li>
                                                {{ $feature }}
                                            </li>

                                        @endforeach

                                    </ul>

                                @else

                                    <p class="text-sm text-gray-500 dark:text-gray-400">
                                        Todas las funciones incluidas en este plan.
                                    </p>

                                @endif

                            </div>


                            {{-- BOTONES --}}
                            <div class="mt-8">

                                @if ($isCurrent)

                                    @if ($currentSubscription?->cancel_at_period_end)

                                        <x-filament::button
                                            color="gray"
                                            disabled
                                            class="w-full"
                                        >
                                            Cancelación programada
                                        </x-filament::button>

                                    @else

                                        <x-filament::button
                                            type="button"
                                            color="danger"
                                            wire:click="cancelSubscription"
                                            wire:loading.attr="disabled"
                                            wire:target="cancelSubscription"
                                            class="w-full"
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

                                @else

                                    <x-filament::button
                                        type="button"
                                        wire:click="changePlan({{ $plan->getKey() }})"
                                        wire:loading.attr="disabled"
                                        wire:target="changePlan"
                                        class="w-full"
                                    >

                                        <span
                                            wire:loading.remove
                                            wire:target="changePlan"
                                        >
                                            Cambiar a {{ $plan->name }}
                                        </span>

                                        <span
                                            wire:loading
                                            wire:target="changePlan"
                                        >
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

    </div>
</x-filament-panels::page>

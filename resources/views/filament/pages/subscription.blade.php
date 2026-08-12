<x-filament-panels::page>
    @php
        $plans = $this->getPlans();

        $currentSubscription = auth()->user()?->company?->subscription;

        $currentPlanSlug = null;

        if (
    $currentSubscription &&
    in_array($currentSubscription->status, [
        'active',
        'trialing',
        'authorized',
        'pending',
    ])
) {
    $currentPlanSlug = $currentSubscription->plan;
}
    @endphp

    <div class="space-y-6">

        {{-- ENCABEZADO --}}
        <div>
            <h2 class="text-xl font-bold tracking-tight text-gray-950 dark:text-white">
                Mi suscripción
            </h2>

            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Elegí el plan que mejor se adapte a tu empresa.
            </p>
        </div>

        {{-- SUSCRIPCIÓN ACTUAL --}}
        @if ($currentSubscription && $currentPlanSlug)
            <x-filament::section>
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">

                    <div>
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">
                            Plan actual
                        </p>

                        <p class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">
                            {{ ucfirst($currentPlanSlug) }}
                        </p>

                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            Estado:
                            <span class="font-medium">
                                {{ $currentSubscription->status }}
                            </span>
                        </p>
                    </div>

                    <x-filament::badge color="success">
                        Activo
                    </x-filament::badge>

                </div>
            </x-filament::section>
        @endif


        {{-- PLANES --}}
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

                                       <li class="text-sm text-gray-600 dark:text-gray-300">
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


                        {{-- BOTÓN --}}
                        <div class="mt-8">

                            @if ($isCurrent)

                                @if ($currentSubscription->cancel_at_period_end)

                                    <x-filament::button
                                        color="gray"
                                        disabled
                                        class="w-full"
                                    >
                                        Cancelación programada
                                    </x-filament::button>

                                    @if ($currentSubscription->current_period_end)
                                        <p class="mt-2 text-center text-xs text-gray-500 dark:text-gray-400">
                                            Acceso hasta
                                            {{ $currentSubscription->current_period_end->format('d/m/Y') }}
                                        </p>
                                    @elseif ($currentSubscription->trial_ends_at)
                                        <p class="mt-2 text-center text-xs text-gray-500 dark:text-gray-400">
                                            Acceso hasta
                                            {{ $currentSubscription->trial_ends_at->format('d/m/Y') }}
                                        </p>
                                    @endif

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
                                    wire:target="changePlan({{ $plan->getKey() }})"
                                    class="w-full"
                                >

                                    <span
                                        wire:loading.remove
                                        wire:target="changePlan({{ $plan->getKey() }})"
                                    >
                                        Cambiar a {{ $plan->name }}
                                    </span>

                                    <span
                                        wire:loading
                                        wire:target="changePlan({{ $plan->getKey() }})"
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
</x-filament-panels::page>

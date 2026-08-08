<x-filament-panels::page>
    @php
        $plans = $this->getPlans();
        $currentSubscription = auth()->user()?->company?->subscription;
        $currentPlanSlug = $currentSubscription?->plan;
    @endphp

    <div class="space-y-6">
        <div>
            <h2 class="text-xl font-bold tracking-tight text-gray-950 dark:text-white">
                Mi suscripción
            </h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Elegí el plan que mejor se adapte a tu empresa.
            </p>
        </div>

        @if ($currentSubscription)
            <x-filament::section>
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">
                            Plan actual
                        </p>
                        <p class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">
                            {{ ucfirst($currentPlanSlug ?? 'Sin plan') }}
                        </p>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            Estado: {{ $currentSubscription->status }}
                        </p>
                    </div>
                </div>
            </x-filament::section>
        @endif

        <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
            @foreach ($plans as $plan)
                @php
                    $isCurrent = $currentPlanSlug === $plan->slug;
                @endphp

                <x-filament::section
                    :heading="$plan->name"
                    class="relative"
                >
                    <div class="flex h-full flex-col">
                        <div>
                            <div class="text-3xl font-bold tracking-tight text-gray-950 dark:text-white">
                                ${{ number_format((float) $plan->price, 0, ',', '.') }}
                            </div>
                            <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                por mes
                            </div>
                        </div>

                        <div class="mt-6 flex-1">
                            @if (!empty($plan->features))
                                <ul class="space-y-3 text-sm text-gray-600 dark:text-gray-300">
                                    @foreach ($plan->features as $feature)
                                        <li class="flex items-start gap-2">
                                            <x-heroicon-m-check class="mt-0.5 h-5 w-5 shrink-0 text-primary-600" />
                                            <span>{{ $feature }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @else
                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    Todas las funciones incluidas en este plan.
                                </p>
                            @endif
                        </div>

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
                                    type="button"
                                    wire:click="checkout({{ $plan->getKey() }})"
                                    wire:loading.attr="disabled"
                                    wire:target="checkout({{ $plan->getKey() }})"
                                    class="w-full"
                                >
                                    <span wire:loading.remove wire:target="checkout({{ $plan->getKey() }})">
                                        Elegir plan
                                    </span>
                                    <span wire:loading wire:target="checkout({{ $plan->getKey() }})">
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

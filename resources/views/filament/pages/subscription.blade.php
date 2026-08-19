<x-filament-panels::page>

    @php
        $plan = $this->getPlan();
        $subscription = $this->getActiveSubscription();

        $status = $subscription?->status;

        $statusLabel = match ($status) {
            'authorized',
            'active' => 'Activo',

            'trialing' => 'Período de prueba',

            'past_due' => 'Pago pendiente',

            'paused' => 'Pausado',

            'cancelled',
            'canceled' => 'Cancelado',

            default => 'Sin suscripción',
        };

        $isPaused = $status === 'paused';

        $isCancelled = in_array($status, [
            'cancelled',
            'canceled',
        ], true);

        $statusLabel = match ($status) {
            'authorized',
            'active' => 'Activo',

            'trialing' => 'Período de prueba',

            'paused' => 'Pausado',

            'cancelled',
            'canceled' => 'Cancelado',

            default => 'Sin suscripción',
        };
    @endphp


    {{-- ========================================================= --}}
    {{-- EXISTE SUSCRIPCIÓN --}}
    {{-- ========================================================= --}}

    @if ($subscription)

        <x-filament::section>

            <div class="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">

                {{-- INFORMACIÓN --}}
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

                        <x-filament::badge
                            :color="
                                $isCancelled
                                    ? 'danger'
                                    : (
                                        $isPaused
                                            ? 'warning'
                                            : (
                                                $status === 'trialing'
                                                    ? 'warning'
                                                    : 'success'
                                            )
                                    )
                            "
                        >
                            {{ $statusLabel }}
                        </x-filament::badge>

                    </div>


                    {{-- PRECIO / PERÍODO --}}
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
                {{-- BOTONES --}}
                {{-- ================================================= --}}

                <div>

                    {{-- CANCELADA DEFINITIVAMENTE --}}
                    @if ($isCancelled)

                        <x-filament::button
                            color="gray"
                            disabled
                        >
                            Suscripción cancelada
                        </x-filament::button>


                    {{-- PAUSADA = SE PUEDE REACTIVAR --}}
                    @elseif ($isPaused)

                        <x-filament::button
                            color="success"
                            wire:click="resumeSubscription"
                            wire:loading.attr="disabled"
                            wire:target="resumeSubscription"
                        >

                            <span
                                wire:loading.remove
                                wire:target="resumeSubscription"
                            >
                                Reactivar suscripción
                            </span>

                            <span
                                wire:loading
                                wire:target="resumeSubscription"
                            >
                                Reactivando...
                            </span>

                        </x-filament::button>


                    {{-- ACTIVA = SE PUEDE PAUSAR --}}
                    @elseif($this->isActive())

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
            {{-- MENSAJE CANCELADA --}}
            {{-- ================================================= --}}

            @if ($isCancelled)

                <div class="mt-5 rounded-lg border border-danger-300 bg-danger-50 p-4 text-sm text-danger-800 dark:border-danger-700 dark:bg-danger-950 dark:text-danger-200">

                    Esta suscripción fue
                    <strong>cancelada definitivamente</strong>
                    en Mercado Pago.

                    @if ($subscription->canceled_at)

                        <div class="mt-1">

                            Cancelada el

                            <strong>
                                {{ $subscription->canceled_at->format('d/m/Y H:i') }}
                            </strong>.

                        </div>

                    @endif

                    <div class="mt-2">
                        Esta suscripción no puede reactivarse.
                    </div>

                </div>

            @endif


            {{-- ================================================= --}}
            {{-- MENSAJE PAUSADA --}}
            {{-- ================================================= --}}

            @if ($isPaused)

                <div class="mt-5 rounded-lg border border-warning-300 bg-warning-50 p-4 text-sm text-warning-800 dark:border-warning-700 dark:bg-warning-950 dark:text-warning-200">

                    Tu suscripción está
                    <strong>pausada</strong>.

                    Podés reactivarla cuando quieras usando el botón
                    <strong>Reactivar suscripción</strong>.

                </div>

            @endif


            {{-- ================================================= --}}
            {{-- CANCELACIÓN PROGRAMADA --}}
            {{-- ================================================= --}}

            @if (
                !$isCancelled &&
                !$isPaused &&
                $subscription->cancel_at_period_end
            )

                <div class="mt-5 rounded-lg border border-warning-300 bg-warning-50 p-4 text-sm text-warning-800 dark:border-warning-700 dark:bg-warning-950 dark:text-warning-200">

                    Tu suscripción seguirá activa hasta

                    <strong>
                        {{ $subscription->current_period_end?->format('d/m/Y') }}
                    </strong>.

                    Después de esa fecha no se renovará.

                </div>

            @endif

        </x-filament::section>


    {{-- ========================================================= --}}
    {{-- NO EXISTE SUSCRIPCIÓN --}}
    {{-- ========================================================= --}}

    @else

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

                            <span
                                wire:loading.remove
                                wire:target="checkout"
                            >
                                Contratar Ascento
                            </span>

                            <span
                                wire:loading
                                wire:target="checkout"
                            >
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

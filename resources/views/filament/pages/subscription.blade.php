<x-filament-panels::page>

    @php
        $plan = $this->getPlan();
        $subscription = $this->getActiveSubscription();

        $status = $subscription?->status;

        $isPending = $this->isPending();
        $isActive = $this->isActive();
        $isPaused = $this->isPaused();
        $isCanceled = $this->isCanceled();

        $statusLabel = match ($status) {
            'authorized',
            'active' => 'Activo',

            'trialing' => 'Período de prueba',

            'past_due' => 'Pago pendiente',

            'paused' => 'Pausado',

            'pending' => 'Pago pendiente',

            'cancelled',
            'canceled' => 'Cancelado',

            default => 'Sin suscripción',
        };

        $statusColor = match (true) {
            $isCanceled => 'danger',
            $isPaused => 'warning',
            $isPending => 'warning',
            $isActive => 'success',
            default => 'gray',
        };
    @endphp


    {{-- ========================================================= --}}
    {{-- SIN SUSCRIPCIÓN --}}
    {{-- ========================================================= --}}

    @if (!$subscription)

        <x-filament::section>

            <div class="mx-auto max-w-2xl text-center">

                <h2 class="text-2xl font-bold text-gray-950 dark:text-white">
                    Ascento
                </h2>

                <p class="mt-2 text-gray-500 dark:text-gray-400">
                    Todo lo que necesitás para gestionar tu empresa de ascensores.
                </p>

                @if ($plan)

                    {{-- PRECIO --}}
                    <div class="mt-8">

                        <div class="text-4xl font-bold text-gray-950 dark:text-white">
                            ${{ number_format((float) $plan->price, 0, ',', '.') }}
                        </div>

                        <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            {{ $plan->currency }} / mes
                        </div>

                    </div>


                    {{-- FEATURES --}}
                    @if (!empty($plan->features))

                        <div class="mt-8 text-left">

                            <ul class="mx-auto max-w-md space-y-3">

                                @foreach ($plan->features as $feature)

                                    <li class="flex items-start gap-2 text-sm text-gray-600 dark:text-gray-300">

                                        <span class="font-bold text-success-500">
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


                    {{-- CONTRATAR --}}
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


    {{-- ========================================================= --}}
    {{-- CON SUSCRIPCIÓN --}}
    {{-- ========================================================= --}}

    @else

        <x-filament::section>

            <div class="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">


                {{-- ================================================= --}}
                {{-- INFORMACIÓN DEL PLAN --}}
                {{-- ================================================= --}}

                <div>

                    <div class="flex flex-wrap items-center gap-3">

                        <div>

                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                Tu plan actual
                            </p>

                            <h2 class="text-3xl font-bold text-gray-950 dark:text-white">
                                {{ $plan?->name ?? ucfirst($subscription->plan ?? 'Ascento') }}
                            </h2>

                        </div>


                        {{-- ESTADO --}}

                        <x-filament::badge :color="$statusColor">
                            {{ $statusLabel }}
                        </x-filament::badge>

                    </div>


                    {{-- ================================================= --}}
                    {{-- PRECIO / FECHAS --}}
                    {{-- ================================================= --}}

                    <div class="mt-4 flex flex-wrap gap-x-6 gap-y-2 text-sm">

                        @if ($subscription->amount)

                            <span class="text-gray-600 dark:text-gray-300">

                                <strong class="text-gray-950 dark:text-white">
                                    ${{ number_format((float) $subscription->amount, 0, ',', '.') }}
                                </strong>

                                {{ $subscription->currency }}/mes

                            </span>

                        @endif


                        @if ($subscription->current_period_start)

                            <span class="text-gray-500 dark:text-gray-400">

                                Inicio:

                                <strong class="text-gray-700 dark:text-gray-200">
                                    {{ $subscription->current_period_start->format('d/m/Y') }}
                                </strong>

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


                        @if ($subscription->trial_ends_at && $status === 'trialing')

                            <span class="text-gray-500 dark:text-gray-400">

                                Prueba hasta:

                                <strong class="text-gray-700 dark:text-gray-200">
                                    {{ $subscription->trial_ends_at->format('d/m/Y') }}
                                </strong>

                            </span>

                        @endif

                    </div>

                </div>


                {{-- ================================================= --}}
                {{-- BOTONES --}}
                {{-- ================================================= --}}

                <div class="flex flex-wrap items-center gap-2">


                    {{-- ================================================= --}}
                    {{-- PENDIENTE --}}
                    {{-- ================================================= --}}

                    @if ($isPending)

                        <x-filament::button
                            wire:click="checkout"
                            wire:loading.attr="disabled"
                            wire:target="checkout"
                            color="warning"
                        >

                            <span
                                wire:loading.remove
                                wire:target="checkout"
                            >
                                Continuar contratación
                            </span>

                            <span
                                wire:loading
                                wire:target="checkout"
                            >
                                Procesando...
                            </span>

                        </x-filament::button>


                    {{-- ================================================= --}}
                    {{-- CANCELADA --}}
                    {{-- ================================================= --}}

                    @elseif ($isCanceled)

                        <x-filament::button
                            wire:click="checkout"
                            wire:loading.attr="disabled"
                            wire:target="checkout"
                            color="primary"
                        >

                            <span
                                wire:loading.remove
                                wire:target="checkout"
                            >
                                Contratar nuevamente
                            </span>

                            <span
                                wire:loading
                                wire:target="checkout"
                            >
                                Procesando...
                            </span>

                        </x-filament::button>


                    {{-- ================================================= --}}
                    {{-- PAUSADA --}}
                    {{-- ================================================= --}}

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


                        {{-- CANCELAR DESDE PAUSADA --}}

                        <x-filament::button
                            color="danger"
                            wire:click="cancelSubscription"
                            wire:loading.attr="disabled"
                            wire:target="cancelSubscription"
                            wire:confirm="¿Cancelar tu suscripción? Esta acción es DEFINITIVA: para volver a usar Ascento vas a tener que contratar de nuevo."
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


                    {{-- ================================================= --}}
                    {{-- ACTIVA --}}
                    {{-- ================================================= --}}

                    @elseif ($isActive)

                        {{-- PAUSAR --}}

                        <x-filament::button
                            color="warning"
                            wire:click="pauseSubscription"
                            wire:loading.attr="disabled"
                            wire:target="pauseSubscription"
                            wire:confirm="¿Pausar tu suscripción? Vas a poder reactivarla cuando quieras."
                        >

                            <span
                                wire:loading.remove
                                wire:target="pauseSubscription"
                            >
                                Pausar suscripción
                            </span>

                            <span
                                wire:loading
                                wire:target="pauseSubscription"
                            >
                                Pausando...
                            </span>

                        </x-filament::button>


                        {{-- CANCELAR --}}

                        <x-filament::button
                            color="danger"
                            wire:click="cancelSubscription"
                            wire:loading.attr="disabled"
                            wire:target="cancelSubscription"
                            wire:confirm="¿Cancelar tu suscripción? Esta acción es DEFINITIVA: para volver a usar Ascento vas a tener que contratar de nuevo."
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


                    {{-- ================================================= --}}
                    {{-- ESTADO NO CONTEMPLADO (red de seguridad) --}}
                    {{-- ================================================= --}}

                    @else

                        <x-filament::button
                            wire:click="checkout"
                            wire:loading.attr="disabled"
                            wire:target="checkout"
                            color="primary"
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

                    @endif

                </div>

            </div>


            {{-- ========================================================= --}}
            {{-- MENSAJE: PAGO PENDIENTE --}}
            {{-- ========================================================= --}}

            @if ($isPending)

                <div class="mt-5 rounded-lg border border-warning-300 bg-warning-50 p-4 text-sm text-warning-800 dark:border-warning-700 dark:bg-warning-950 dark:text-warning-200">

                    <strong>Tu contratación está pendiente.</strong>

                    <div class="mt-1">
                        Continuá con el pago para activar tu suscripción.
                    </div>

                </div>

            @endif


            {{-- ========================================================= --}}
            {{-- MENSAJE: CANCELADA --}}
            {{-- ========================================================= --}}

            @if ($isCanceled)

                <div class="mt-5 rounded-lg border border-danger-300 bg-danger-50 p-4 text-sm text-danger-800 dark:border-danger-700 dark:bg-danger-950 dark:text-danger-200">

                    <strong>Esta suscripción fue cancelada definitivamente.</strong>

                    <div class="mt-1">
                        La cancelación fue realizada en Mercado Pago.
                    </div>

                    @if ($subscription->canceled_at)

                        <div class="mt-1">

                            Cancelada el

                            <strong>
                                {{ $subscription->canceled_at->format('d/m/Y H:i') }}
                            </strong>.

                        </div>

                    @endif

                    <div class="mt-2">
                        Podés contratar nuevamente utilizando el botón de arriba.
                    </div>

                </div>

            @endif


            {{-- ========================================================= --}}
            {{-- MENSAJE: PAUSADA --}}
            {{-- ========================================================= --}}

            @if ($isPaused)

                <div class="mt-5 rounded-lg border border-warning-300 bg-warning-50 p-4 text-sm text-warning-800 dark:border-warning-700 dark:bg-warning-950 dark:text-warning-200">

                    <strong>Tu suscripción está pausada.</strong>

                    <div class="mt-1">
                        No se realizarán nuevos cobros mientras permanezca pausada.
                    </div>

                    <div class="mt-1">
                        Podés reactivarla cuando quieras utilizando
                        <strong>Reactivar suscripción</strong>,
                        o cancelarla definitivamente con
                        <strong>Cancelar suscripción</strong>.
                    </div>

                </div>

            @endif


            {{-- ========================================================= --}}
            {{-- CANCELACIÓN PROGRAMADA --}}
            {{-- ========================================================= --}}
            {{--
                Solo tiene sentido mostrarla cuando la suscripción está
                activa (no pausada, no cancelada): "paused" ya tiene su
                propio mensaje arriba, y mostrar ambos era redundante.
            --}}

            @if (
                $isActive &&
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

    @endif

</x-filament-panels::page>

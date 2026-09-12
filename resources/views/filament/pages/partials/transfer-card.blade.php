{{--
    Tarjeta de pago por transferencia. Se usa en los lugares donde
    antes iba un botón de Mercado Pago (sin suscripción, pendiente,
    cancelada, pausada) mientras el checkout esté comentado.
--}}
@php
    $cbu = '0070159030004039958247';
    $alias = 'ascento';
    $titular = 'Franco Leonel Acqua';
    $banco = 'Banco Galicia';
    $whatsappNumero = '5491178233886';

    $user = auth()->user();
    $company = $user?->company;

    $empresa = $company?->name ?? 'mi empresa';
    $cuit = $company?->cuit;
    $monto = isset($plan) && $plan?->price
        ? number_format((float) $plan->price, 0, ',', '.')
        : null;

    $mensajeWhatsapp = "Hola! Quiero avisar un pago por transferencia de Ascento.\n\n"
        . "Empresa: {$empresa}\n"
        . ($cuit ? "CUIT: {$cuit}\n" : '')
        . "Usuario: {$user?->name} ({$user?->email})\n\n"
        . "Ahora te mando el comprobante.";
@endphp

<div class="mt-5 rounded-lg border border-gray-200 bg-gray-50 p-5 dark:border-gray-700 dark:bg-gray-800/50">

    <h3 class="text-base font-semibold text-gray-950 dark:text-white">
        Pagar por transferencia
    </h3>

    @if ($monto)
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
            Monto a transferir: <strong class="text-gray-950 dark:text-white">${{ $monto }} ARS / mes</strong>
        </p>
    @endif

    <div class="mt-3 grid grid-cols-1 gap-1.5 text-sm sm:grid-cols-2">

        <div>
            <span class="text-gray-500 dark:text-gray-400">CBU:</span>
            <strong class="ml-1 text-gray-900 dark:text-gray-100">{{ $cbu }}</strong>
        </div>

        <div>
            <span class="text-gray-500 dark:text-gray-400">Alias:</span>
            <strong class="ml-1 text-gray-900 dark:text-gray-100">{{ $alias }}</strong>
        </div>

        <div>
            <span class="text-gray-500 dark:text-gray-400">Titular:</span>
            <strong class="ml-1 text-gray-900 dark:text-gray-100">{{ $titular }}</strong>
        </div>

        <div>
            <span class="text-gray-500 dark:text-gray-400">Banco:</span>
            <strong class="ml-1 text-gray-900 dark:text-gray-100">{{ $banco }}</strong>
        </div>

    </div>

    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
        Al avisar por WhatsApp se identifica como <strong>{{ $empresa }}</strong> ({{ $user?->email }}).
    </p>

    <div class="mt-4">

        <x-filament::button
            tag="a"
            href="https://wa.me/{{ $whatsappNumero }}?text={{ urlencode($mensajeWhatsapp) }}"
            target="_blank"
            color="success"
            icon="heroicon-o-chat-bubble-left-right"
        >
            Avisar por WhatsApp
        </x-filament::button>

    </div>

</div>

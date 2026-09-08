{{--
    Tarjeta de pago por transferencia. Se usa en los 3 lugares donde
    antes iba el botón de Mercado Pago (sin suscripción, pendiente,
    cancelada) mientras el checkout de Mercado Pago no esté disponible.

    OJO Franco: completá estos 5 datos antes de subir:
--}}
@php
    $cbu = 'TU-CBU-ACA';
    $alias = 'TU-ALIAS-ACA';
    $titular = 'NOMBRE DEL TITULAR';
    $banco = 'NOMBRE DEL BANCO';
    $whatsappNumero = '549XXXXXXXXXX'; // con código de país, sin +, sin espacios

    $empresa = auth()->user()?->company?->name ?? 'mi empresa';

    $mensajeWhatsapp = "Hola! Soy {$empresa} y quiero activar mi suscripción a Ascento. Te mando el comprobante de la transferencia.";
@endphp

<div class="mt-5 rounded-lg border border-gray-200 bg-gray-50 p-5 dark:border-gray-700 dark:bg-gray-800/50">

    <h3 class="text-base font-semibold text-gray-950 dark:text-white">
        Pagar por transferencia
    </h3>

    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
        Transferí el monto de tu plan a estos datos y avisanos por WhatsApp con el comprobante. Activamos tu cuenta apenas lo recibimos.
    </p>

    <div class="mt-4 grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">

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

    <div class="mt-5">

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

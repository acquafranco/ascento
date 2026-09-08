{{--
    Tarjeta de pago por transferencia. Se usa en los lugares donde
    antes iba un botón de Mercado Pago (sin suscripción, pendiente,
    cancelada, pausada) mientras el checkout de Mercado Pago esté
    comentado / no disponible.
--}}
@php
    $cbu = 'TU-CBU-ACA';
    $alias = 'TU-ALIAS-ACA';
    $titular = 'NOMBRE DEL TITULAR';
    $banco = 'NOMBRE DEL BANCO';
    $whatsappNumero = '5491178233886';

    $user = auth()->user();
    $company = $user?->company;

    $empresa = $company?->name ?? 'mi empresa';
    $razonSocial = $company?->business_name;
    $cuit = $company?->cuit;
    $contacto = $user?->name;
    $emailContacto = $user?->email;

    $mensajeWhatsapp = "Hola! Quiero avisar un pago por transferencia para activar mi suscripción a Ascento.\n\n"
        . "Empresa: {$empresa}\n"
        . ($razonSocial ? "Razón social: {$razonSocial}\n" : '')
        . ($cuit ? "CUIT: {$cuit}\n" : '')
        . "Usuario: {$contacto}\n"
        . "Email: {$emailContacto}\n\n"
        . "Ahora te mando el comprobante.";
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

    {{-- Datos que se van a incluir en el mensaje de WhatsApp, para que
         se vea antes de mandarlo qué está identificando. --}}
    <div class="mt-4 rounded-md bg-white p-3 text-xs text-gray-500 dark:bg-gray-900 dark:text-gray-400">

        <div class="font-medium text-gray-700 dark:text-gray-300">
            Se va a identificar con estos datos:
        </div>

        <div class="mt-1">Empresa: <strong class="text-gray-700 dark:text-gray-300">{{ $empresa }}</strong></div>

        @if ($razonSocial)
            <div>Razón social: <strong class="text-gray-700 dark:text-gray-300">{{ $razonSocial }}</strong></div>
        @endif

        @if ($cuit)
            <div>CUIT: <strong class="text-gray-700 dark:text-gray-300">{{ $cuit }}</strong></div>
        @endif

        <div>Usuario: <strong class="text-gray-700 dark:text-gray-300">{{ $contacto }}</strong> ({{ $emailContacto }})</div>

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

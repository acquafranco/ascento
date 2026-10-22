<?php

use App\Services\Marketplace\Billing\MercadoPagoSupplierGateway;

/*
|--------------------------------------------------------------------------
| Marketplace de repuestos
|--------------------------------------------------------------------------
|
| gateways: país (ISO 3166-1, el registrado en la cuenta del proveedor) =>
| pasarela que le cobra la suscripción y los destacados. Un país que no está
| acá no tiene cobros (el SuperAdmin decide si opera gratis). Para sumar otro
| país, implementar SupplierBillingGateway y agregarlo acá.
|
| Los precios, la prueba y la modalidad gratuita NO están acá: se editan en
| «Configuración del marketplace» (SuperAdmin).
*/

return [
    'gateways' => [
        'AR' => MercadoPagoSupplierGateway::class,
    ],

    // Monedas por país: los precios de cada proveedor se guardan y muestran en la de su país.
    'currencies' => [
        'AR' => 'ARS', 'BO' => 'BOB', 'BR' => 'BRL', 'CL' => 'CLP', 'CO' => 'COP', 'CR' => 'CRC', 'CU' => 'CUP',
        'EC' => 'USD', 'SV' => 'USD', 'GT' => 'GTQ', 'HN' => 'HNL', 'MX' => 'MXN', 'NI' => 'NIO', 'PA' => 'USD',
        'PY' => 'PYG', 'PE' => 'PEN', 'PR' => 'USD', 'DO' => 'DOP', 'UY' => 'UYU', 'VE' => 'VES',
    ],
];

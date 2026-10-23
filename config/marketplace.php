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

    // Precios comerciales OBJETIVO para proveedores de Argentina (definidos por el dueño el
    // 23/10/2026). No se cargan solos ni encienden ninguna venta: en «Configuración del
    // marketplace» el SuperAdmin los pasa al formulario con «Usar precios objetivo» y guarda.
    // Solo ARS: no hay precio ni conversión para otras monedas.
    'target_prices' => [
        'currency' => 'ARS',
        'premium' => 19900,
        'featured_7' => 4900,
        'featured_30' => 9900,
    ],

    // Monedas por país: los precios de cada proveedor se guardan y muestran en la de su país.
    'currencies' => [
        'AR' => 'ARS', 'BO' => 'BOB', 'BR' => 'BRL', 'CL' => 'CLP', 'CO' => 'COP', 'CR' => 'CRC', 'CU' => 'CUP',
        'EC' => 'USD', 'SV' => 'USD', 'GT' => 'GTQ', 'HN' => 'HNL', 'MX' => 'MXN', 'NI' => 'NIO', 'PA' => 'USD',
        'PY' => 'PYG', 'PE' => 'PEN', 'PR' => 'USD', 'DO' => 'DOP', 'UY' => 'UYU', 'VE' => 'VES',
    ],
];

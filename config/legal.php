<?php

/*
|--------------------------------------------------------------------------
| Páginas legales (términos, privacidad, cookies, eliminación de datos)
|--------------------------------------------------------------------------
|
| Los datos del titular de Ascento se completan por entorno: las páginas
| NUNCA inventan una razón social, un CUIT o un domicilio. Mientras falten,
| se muestran como «pendiente de completar».
|
| reviewed: false muestra el aviso «Borrador pendiente de revisión legal».
| Pasarlo a true solo cuando un abogado haya revisado esta versión.
|
| updated_at: fecha de la versión vigente de los textos (no la de hoy).
|
*/

return [
    'owner_name' => env('LEGAL_OWNER_NAME'),
    'owner_tax_id' => env('LEGAL_OWNER_CUIT'),
    'owner_address' => env('LEGAL_OWNER_ADDRESS'),
    'email' => env('LEGAL_EMAIL', env('SUPPORT_EMAIL', 'contacto@ascento.online')),
    'updated_at' => '2026-10-21',
    'reviewed' => (bool) env('LEGAL_REVIEWED', false),
];

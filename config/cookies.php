<?php

/*
|--------------------------------------------------------------------------
| Cookies y consentimiento
|--------------------------------------------------------------------------
|
| Hoy Ascento usa SOLO cookies estrictamente necesarias (sesión, protección
| de formularios y "Recordarme"). No hay analítica ni publicidad.
|
| Si algún día se agrega una herramienta opcional, se declara acá (por
| ejemplo 'analytics' => ['label' => 'Analítica', 'description' => '...']) y
| su script se carga con:
|     <script type="text/plain" data-consent="analytics" src="..."></script>
| El aviso pasa a pedir Aceptar / Rechazar / Configurar y el script recién se
| activa con el consentimiento (ver partials/cookie-notice).
|
*/

return [

    // Versión de la política: si cambia, se vuelve a mostrar el aviso.
    'version' => 1,

    /** @var array<string, array{label: string, description: string}> */
    'optional' => [],

];

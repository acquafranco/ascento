<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Ascento'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'America/Argentina/Buenos_Aires',


    'super_admin_email' => env('SUPER_ADMIN_EMAIL'),
    'super_admin_password' => env('SUPER_ADMIN_PASSWORD'),
    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'es'),

    // Contacto de soporte que se muestra a los usuarios (registro, landing).
    'support_email' => env('SUPPORT_EMAIL', 'contacto@ascento.online'),

    // A quién avisar por correo cuando se registra una empresa nueva (el
    // equipo de Ascento). Vacío: solo la campanita/push de los SuperAdmin.
    'admin_notification_email' => env('ASCENTO_ADMIN_NOTIFICATION_EMAIL'),

    // Segundo factor obligatorio para el SuperAdmin (Etapa 11). Solo para una
    // emergencia: ASCENTO_SUPERADMIN_2FA=false lo apaga sin desplegar código.
    'superadmin_two_factor' => (bool) env('ASCENTO_SUPERADMIN_2FA', true),

    // CSP en modo solo reporte (ver docs/csp.md). Apagada por defecto.
    'csp_report_only' => (bool) env('ASCENTO_CSP_REPORT_ONLY', false),

    // WhatsApp Business oficial de Ascento, confirmado por el dueño el
    // 13/10/2026 (formato internacional, solo dígitos: +54 9 11 7372-8421).
    // Lo usan el asistente de consultas y el pie de las páginas públicas: no
    // envía nada solo, abre WhatsApp con el mensaje armado. Sin la variable
    // se usa este número; definida vacía o con un número internacional
    // inválido, no hay WhatsApp y el contacto es por correo.
    'whatsapp_contact' => preg_replace('/\D/', '', (string) env('ASCENTO_WHATSAPP_NUMBER', '5491173728421')),

    // Atención por WhatsApp que muestra el asistente, como texto libre que
    // completa la frase «Horario de atención: …». Confirmado el 13/10/2026:
    // todos los días, las 24 horas. Sin la variable se usa este texto;
    // definida vacía (ASCENTO_WHATSAPP_HOURS=) no se muestra horario.
    'whatsapp_hours' => trim((string) env('ASCENTO_WHATSAPP_HOURS', 'todos los días, las 24 horas, incluidos fines de semana y feriados')),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];

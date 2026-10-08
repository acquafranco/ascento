<?php

use NotificationChannels\WebPush\PushSubscription;

return [

    /**
     * These are the keys for authentication (VAPID).
     * These keys must be safely stored and should not change.
     */
    'vapid' => [
        'subject' => env('VAPID_SUBJECT'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'pem_file' => env('VAPID_PEM_FILE'),
    ],

    /**
     * This is model that will be used to for push subscriptions.
     */
    'model' => PushSubscription::class,

    /**
     * This is the name of the table that will be created by the migration and
     * used by the PushSubscription model shipped with this package.
     */
    'table_name' => env('WEBPUSH_DB_TABLE', 'push_subscriptions'),

    /**
     * This is the database connection that will be used by the migration and
     * the PushSubscription model shipped with this package.
     */
    'database_connection' => env('WEBPUSH_DB_CONNECTION', env('DB_CONNECTION', 'mysql')),

    /**
     * The HTTP client options used to deliver push notifications.
     */
    // Ascento: no dejar colgado el proceso si un servicio de push no responde.
    'client_options' => [
        'connect_timeout' => 5,
        'timeout' => 10,
    ],

    /**
     * The automatic padding in bytes used by Minishlink\WebPush.
     * Set to false to support Firefox Android with v1 endpoint.
     */
    'automatic_padding' => env('WEBPUSH_AUTOMATIC_PADDING', true),

    /*
    | Ascento: hosts permitidos para el endpoint de una suscripción. El servidor
    | hace un POST a ese endpoint al enviar cada push, así que aceptar
    | cualquier URL permitiría usar a Ascento para pegarle a servicios
    | internos (SSRF). Son los servicios de push de los navegadores.
    */
    'allowed_endpoint_hosts' => [
        'fcm.googleapis.com',               // Chrome, Edge (Android), Samsung Internet, Opera, Brave
        'updates.push.services.mozilla.com', // Firefox
        'web.push.apple.com',               // Safari (macOS) y iPhone/iPad (PWA)
        '*.push.apple.com',
        '*.notify.windows.com',             // Edge (Windows)
    ],

];

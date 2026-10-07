<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

        'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
    ],

        'whatsapp' => [
        'version' => env('WHATSAPP_API_VERSION', 'v23.0'),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        // Meta firma cada webhook con el App Secret (X-Hub-Signature-256).
        // Si la app de WhatsApp es la misma que la de Facebook Login,
        // alcanza con FACEBOOK_CLIENT_SECRET.
        'app_secret' => env('WHATSAPP_APP_SECRET', env('FACEBOOK_CLIENT_SECRET')),
    ],

        'mercadopago' => [
        'access_token' => env('MERCADOPAGO_ACCESS_TOKEN'),
        'public_key' => env('MERCADOPAGO_PUBLIC_KEY'),
        'basic_plan_id' => env('MERCADOPAGO_BASIC_PLAN_ID'),
        'pro_plan_id' => env('MERCADOPAGO_PRO_PLAN_ID'),
    ],

    /*
    | Geoapify: geocodificación de edificios y mosaicos del mapa.
    | La API key SOLO se usa del lado del servidor (los mosaicos pasan por
    | MapTileController); nunca se manda al navegador.
    */
    'geoapify' => [
        'api_key' => env('GEOAPIFY_API_KEY'),
        'country_code' => env('GEOAPIFY_COUNTRY_CODE', 'ar'),
        // Confianza mínima (0-1) para aceptar un resultado. Por debajo,
        // el edificio queda "a revisar" en vez de guardar un punto dudoso.
        'min_confidence' => (float) env('GEOAPIFY_MIN_CONFIDENCE', 0.8),
        // Tope diario de geocodificaciones (el plan gratis da 3000 créditos/día,
        // compartidos con los mosaicos del mapa: 4 mosaicos = 1 crédito).
        'daily_limit' => (int) env('GEOAPIFY_DAILY_LIMIT', 1500),
        'map_style' => env('GEOAPIFY_MAP_STYLE', 'osm-bright'),
    ],

];

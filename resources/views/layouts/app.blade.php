<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    @include('partials.brand-head')
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="ascento-user" content="{{ auth()->id() }}">

        <title>{{ config('app.name', 'Ascento') }}</title>

        {{-- PWA: permite instalar Ascento en la pantalla de inicio (en iPhone es
             requisito para recibir notificaciones). --}}
        <link rel="manifest" href="/manifest.webmanifest">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-title" content="Ascento">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

        @if (auth()->user()?->canReceiveWorkOrderPush() && auth()->user()->company)
            {{-- Clave PÚBLICA VAPID (pública por diseño) y URLs del técnico. --}}
            <meta name="ascento-push" content="{{ json_encode([
                'userId' => auth()->id(),
                'vapidPublicKey' => config('webpush.vapid.public_key'),
                'storeUrl' => route('push-subscriptions.store', ['company' => auth()->user()->company->slug]),
                'destroyUrl' => route('push-subscriptions.destroy', ['company' => auth()->user()->company->slug]),
                'testUrl' => route('push-subscriptions.test', ['company' => auth()->user()->company->slug]),
            ]) }}">
        @endif

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <style>
            [x-cloak] { display: none !important; }
            /* iOS no hace zoom al enfocar campos con 16px o más. */
            input, textarea, select { font-size: 16px !important; }
            textarea { line-height: 1.4; }
        </style>
    </head>
    <body class="font-sans antialiased">
        <div class="min-h-screen bg-ink-50">
            @include('layouts.navigation')

            @if(auth()->check() && auth()->user()->role !== 'admin')
                @include('layouts.mobile-nav')
            @endif

            @if(auth()->check() && auth()->user()->role === 'technician')
                {{-- Contadores de avisos (barra de arriba y menú del celular): se actualizan solos. --}}
                @include('partials.realtime-inbox', ['countUrl' => route('notifications.count'), 'inboxUrl' => route('notifications.index')])
            @endif

            @if(auth()->check() && auth()->user()->role !== 'admin')
                <div class="px-4">@include('partials.verify-email-notice')</div>
            @endif

            <!-- Page Heading -->
            @isset($header)
                <header class="bg-white shadow">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            <!-- Page Content -->
            <main>
                {{ $slot }}
            </main>
        </div>
    </body>
</html>

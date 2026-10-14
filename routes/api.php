<?php

use App\Http\Controllers\MercadoPagoWebhookController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

// La integración de WhatsApp por empresa (webhook de Meta y botones de
// órdenes para técnicos) se retiró en la Etapa 11: estaba en desuso.

// Notificaciones de Mercado Pago (configurar esta URL en la app de MP,
// eventos "Planes y suscripciones"). Ver MercadoPagoWebhookController.
Route::post('/mercadopago/webhook', MercadoPagoWebhookController::class)
    ->middleware('throttle:120,1,mercadopago.webhook')
    ->name('mercadopago.webhook');

// Bot de Telegram (avisos). Se registra con: php artisan telegram:setup
Route::post('/telegram/webhook', TelegramWebhookController::class)
    ->middleware('throttle:120,1,telegram.webhook')
    ->name('telegram.webhook');

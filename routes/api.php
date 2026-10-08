<?php

use App\Http\Controllers\MercadoPagoWebhookController;
use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/whatsapp/webhook', [WhatsAppWebhookController::class, 'verify']);
Route::post('/whatsapp/webhook', [WhatsAppWebhookController::class, 'handle']);

// Notificaciones de Mercado Pago (configurar esta URL en la app de MP,
// eventos "Planes y suscripciones"). Ver MercadoPagoWebhookController.
Route::post('/mercadopago/webhook', MercadoPagoWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('mercadopago.webhook');

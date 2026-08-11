<?php

use App\Http\Controllers\WhatsAppWebhookController;
use App\Http\Controllers\SubscriptionController;
use Illuminate\Support\Facades\Route;

Route::get('/whatsapp/webhook', [WhatsAppWebhookController::class, 'verify']);
Route::post('/whatsapp/webhook', [WhatsAppWebhookController::class, 'handle']);
Route::post('/mercadopago/webhook', [SubscriptionController::class, 'webhook']);

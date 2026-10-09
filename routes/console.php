<?php

use App\Models\WebhookEvent;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('subscription:expire-manual')->daily();

// Ubica en el mapa los edificios que quedaron pendientes o con error
// (la geocodificación normal ocurre al guardar el edificio).
Schedule::command('buildings:geocode --limit=100')->hourly()->withoutOverlapping();

// Mercado Pago: reconciliación por si se perdió algún webhook.
Schedule::command('subscriptions:reconcile-mercadopago')->everySixHours()->withoutOverlapping();

// Auditoría de webhooks: se conservan 90 días.
Schedule::command('model:prune', ['--model' => [WebhookEvent::class]])->daily();

// Cobranzas: obligaciones periódicas de los servicios activos (idempotente).
Schedule::command('billing:generate')->dailyAt('06:00')->withoutOverlapping();

// Exportaciones de datos de empresa: se generan acá (CLI, sin límite de
// tiempo de PHP-FPM) y los archivos vencidos se borran (queda el historial).
Schedule::command('exports:process')->everyMinute()->withoutOverlapping(30);
Schedule::command('exports:prune')->dailyAt('03:30');

// Recordatorios de la agenda (pendientes desde el día 20, vencidos del mes
// anterior los días 1 a 5). Cada aviso sale una sola vez por mes.
Schedule::command('notifications:visits')->dailyAt('08:00')->withoutOverlapping();

// Videos de reportes: compresión en segundo plano (solo si hay FFmpeg).
Schedule::command('media:process-videos')->everyMinute()->withoutOverlapping(15);

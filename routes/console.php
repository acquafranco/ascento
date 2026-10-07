<?php

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

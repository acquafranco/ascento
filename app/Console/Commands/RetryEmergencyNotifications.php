<?php

namespace App\Console\Commands;

use App\Services\Emergency\EmergencyRequestService;
use Illuminate\Console\Command;

/** Reintenta los correos fallidos de pedidos de ayuda (hasta 3 intentos, sin duplicar los ya enviados). */
class RetryEmergencyNotifications extends Command
{
    protected $signature = 'emergency:retry-mail';

    protected $description = 'Reintenta los correos de pedidos de ayuda que fallaron';

    public function handle(EmergencyRequestService $service): int
    {
        $this->info('Correos reintentados: '.$service->retryFailedMail());

        return self::SUCCESS;
    }
}

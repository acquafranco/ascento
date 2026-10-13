<?php

namespace App\Console\Commands;

use App\Services\Parachute\ParachuteService;
use Illuminate\Console\Command;

/** Avisos de pruebas de paracaídas próximas o vencidas (Etapa 7). */
class SendParachuteReminders extends Command
{
    protected $signature = 'parachute:remind';

    protected $description = 'Avisa las pruebas de paracaídas próximas o vencidas (sin duplicar)';

    public function handle(ParachuteService $service): int
    {
        $this->info('Avisos enviados: '.$service->sendReminders());

        return self::SUCCESS;
    }
}

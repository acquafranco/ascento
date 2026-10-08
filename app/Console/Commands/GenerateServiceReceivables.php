<?php

namespace App\Console\Commands;

use App\Services\Billing\ServiceBillingService;
use Illuminate\Console\Command;

class GenerateServiceReceivables extends Command
{
    protected $signature = 'billing:generate {--company= : Solo esta empresa (id)}';

    protected $description = 'Genera las obligaciones de cobro de los servicios de mantenimiento activos (sin duplicar).';

    public function handle(ServiceBillingService $billing): int
    {
        $created = $billing->generateAll($this->option('company') ? (int) $this->option('company') : null);

        $this->info("Obligaciones creadas: {$created}");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\Exports\CompanyExportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ProcessCompanyExports extends Command
{
    protected $signature = 'exports:process {--max=3 : Cuántas generar por corrida}';

    protected $description = 'Genera las exportaciones de datos solicitadas por las empresas.';

    public function handle(CompanyExportService $service): int
    {
        // Corre cada minuto: sirve de señal de que el scheduler está vivo
        // (la muestra ascento:check-production).
        Cache::put('scheduler:heartbeat', now()->toIso8601String(), now()->addDay());

        $this->info('Exportaciones generadas: '.$service->processPending((int) $this->option('max')));

        return self::SUCCESS;
    }
}

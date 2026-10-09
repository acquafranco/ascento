<?php

namespace App\Console\Commands;

use App\Services\Exports\CompanyExportService;
use Illuminate\Console\Command;

class ProcessCompanyExports extends Command
{
    protected $signature = 'exports:process {--max=3 : Cuántas generar por corrida}';

    protected $description = 'Genera las exportaciones de datos solicitadas por las empresas.';

    public function handle(CompanyExportService $service): int
    {
        $this->info('Exportaciones generadas: '.$service->processPending((int) $this->option('max')));

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\Exports\CompanyExportService;
use Illuminate\Console\Command;

class PruneCompanyExports extends Command
{
    protected $signature = 'exports:prune';

    protected $description = 'Borra los archivos de exportaciones vencidas (el historial queda).';

    public function handle(CompanyExportService $service): int
    {
        $this->info('Archivos vencidos borrados: '.$service->prune());

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\Companies\CompanyEraser;
use Illuminate\Console\Command;

/**
 * Red de seguridad del borrado definitivo de empresas: si un proceso
 * concurrente (exportación, video, foto) terminó de escribir un archivo
 * después de eliminar la empresa, su carpeta se borra acá.
 */
class PruneErasedCompanyFiles extends Command
{
    protected $signature = 'companies:prune-erased-files';

    protected $description = 'Borra las carpetas de archivos de empresas que ya no existen';

    public function handle(CompanyEraser $eraser): int
    {
        $this->info('Carpetas borradas: '.count($eraser->pruneOrphanFiles()));

        return self::SUCCESS;
    }
}

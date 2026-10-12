<?php

namespace App\Console\Commands;

use App\Services\Visits\VisitPhotoService;
use Illuminate\Console\Command;

/**
 * Borra las fotos de visitas que se subieron mientras se completaba un
 * remito que nunca se firmó (más de 24 horas sin vincular), con sus
 * archivos. Las fotos ya vinculadas a una visita nunca se tocan.
 */
class PruneStagedVisitPhotos extends Command
{
    protected $signature = 'visit-photos:prune {--hours=24}';

    protected $description = 'Borra fotos de visitas en preparación que nunca se vincularon a un remito';

    public function handle(VisitPhotoService $photos): int
    {
        $count = $photos->prune(max(1, (int) $this->option('hours')));

        $this->info("Fotos sin vincular borradas: {$count}");

        return self::SUCCESS;
    }
}

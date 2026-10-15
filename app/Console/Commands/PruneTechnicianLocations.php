<?php

namespace App\Console\Commands;

use App\Services\Location\TechnicianLocationService;
use Illuminate\Console\Command;

/** Borra las posiciones de técnicos sin novedades por más de location.keep_hours (sin historial). */
class PruneTechnicianLocations extends Command
{
    protected $signature = 'location:prune';

    protected $description = 'Borra las últimas posiciones viejas de los técnicos';

    public function handle(TechnicianLocationService $locations): int
    {
        $this->info('Posiciones borradas: '.$locations->prune());

        return self::SUCCESS;
    }
}

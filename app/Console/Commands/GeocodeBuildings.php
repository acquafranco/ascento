<?php

namespace App\Console\Commands;

use App\Models\Building;
use App\Services\Geocoding\BuildingGeocoder;
use App\Support\Geocoding\BuildingAddress;
use Illuminate\Console\Command;

class GeocodeBuildings extends Command
{
    protected $signature = 'buildings:geocode
        {--company= : Solo los edificios de esta empresa (id)}
        {--limit=100 : Máximo de edificios a procesar en esta corrida}
        {--retry-review : Reintentar también los que quedaron "a revisar"}
        {--dry-run : Mostrar qué se enviaría a Geoapify sin consultar ni guardar}';

    protected $description = 'Ubica en el mapa los edificios pendientes, con error o con la dirección cambiada.';

    public function handle(BuildingGeocoder $geocoder): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! $geocoder->isConfigured()) {
            $this->warn('GEOAPIFY_API_KEY no está configurada: no se geocodifica nada.');

            return self::SUCCESS;
        }

        $statuses = [Building::GEO_PENDING, Building::GEO_ERROR];

        if ($this->option('retry-review')) {
            $statuses[] = Building::GEO_NEEDS_REVIEW;
        }

        $limit = max(1, (int) $this->option('limit'));
        $counts = [];
        $processed = 0;

        Building::query()
            ->withoutGlobalScope('company')
            ->with('company')
            ->when($this->option('company'), fn ($q, $id) => $q->where('company_id', (int) $id))
            ->orderBy('id')
            ->chunkById(200, function ($buildings) use ($geocoder, $statuses, $limit, $dryRun, &$counts, &$processed) {
                foreach ($buildings as $building) {
                    // Pendientes/errores, o ya ubicados cuya dirección cambió.
                    if (! in_array($building->geocoding_status, $statuses, true)
                        && ! ($building->geocoding_status === Building::GEO_GEOCODED && $building->isGeocodingStale())
                    ) {
                        continue;
                    }

                    if ($processed >= $limit) {
                        return false;
                    }

                    $processed++;

                    if ($dryRun) {
                        $this->line("#{$building->id}: ".json_encode(
                            BuildingAddress::fromBuilding($building)->queries() ?: 'dirección incompleta',
                            JSON_UNESCAPED_UNICODE
                        ));

                        continue;
                    }

                    $outcome = $geocoder->geocode($building, force: (bool) $this->option('retry-review'));
                    $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;

                    // Plan gratis: máximo 5 requests/segundo.
                    if (! app()->runningUnitTests()) {
                        usleep(250_000);
                    }
                }
            });

        $this->info("Procesados: {$processed}");

        foreach ($counts as $outcome => $count) {
            $this->line("  {$outcome}: {$count}");
        }

        return self::SUCCESS;
    }
}

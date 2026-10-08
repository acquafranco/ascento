<?php

namespace App\Jobs;

use App\Models\Building;
use App\Services\Geocoding\BuildingGeocoder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;

/**
 * Ubica en segundo plano los edificios pendientes de UNA empresa.
 *
 * Se lanza al abrir el mapa (después de responder, sin worker de colas) y
 * hay un solo lote por empresa a la vez (lock en cache). Lo que no entre en
 * este lote lo toman el próximo lote o el comando programado.
 */
class GeocodePendingBuildings
{
    use Dispatchable;

    public const BATCH = 40;

    public const LOCK_SECONDS = 180;

    public function __construct(public int $companyId) {}

    public static function lockKey(int $companyId): string
    {
        return "geocoding:company:{$companyId}";
    }

    public static function isRunning(int $companyId): bool
    {
        return Cache::has(static::lockKey($companyId));
    }

    /** Lanza el lote si no hay otro en curso para la empresa. */
    public static function start(int $companyId): bool
    {
        if (! Cache::add(static::lockKey($companyId), true, static::LOCK_SECONDS)) {
            return false;
        }

        static::dispatchAfterResponse($companyId);

        return true;
    }

    public function handle(BuildingGeocoder $geocoder): void
    {
        try {
            $buildings = Building::withoutGlobalScope('company')
                ->with('company:id,province')
                ->where('company_id', $this->companyId)
                ->whereIn('geocoding_status', [Building::GEO_PENDING, Building::GEO_ERROR])
                ->orderBy('id')
                ->limit(self::BATCH)
                ->get();

            foreach ($buildings as $i => $building) {
                // Plan gratis de Geoapify: máximo 5 requests/segundo.
                if ($i > 0 && ! app()->runningUnitTests()) {
                    usleep(220_000);
                }

                $geocoder->geocode($building);
            }
        } finally {
            Cache::forget(static::lockKey($this->companyId));
        }
    }
}

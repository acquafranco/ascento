<?php

namespace App\Jobs;

use App\Models\Building;
use App\Services\Geocoding\BuildingGeocoder;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Ubica un edificio en el mapa. Se despacha "after response" al guardar el
 * edificio: corre en el mismo proceso, después de responder, así que no
 * necesita un worker de colas. Lo que falle queda en "error" y lo retoma
 * el comando programado buildings:geocode.
 */
class GeocodeBuilding
{
    use Dispatchable;

    public function __construct(public int $buildingId) {}

    public function handle(BuildingGeocoder $geocoder): void
    {
        // Sin scope de empresa: corre fuera del request y recibe un id
        // que solo pudo despachar el propio modelo al guardarse.
        $building = Building::withoutGlobalScope('company')->with('company')->find($this->buildingId);

        if ($building) {
            $geocoder->geocode($building);
        }
    }
}

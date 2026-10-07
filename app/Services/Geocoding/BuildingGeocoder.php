<?php

namespace App\Services\Geocoding;

use App\Models\Building;
use App\Support\Geocoding\BuildingAddress;
use Illuminate\Support\Facades\Log;

/**
 * Ubica un edificio en el mapa a partir de su dirección.
 *
 * Reglas:
 * - Si ya está ubicado (o se ubicó a mano) con la MISMA dirección, no se
 *   vuelve a consultar a Geoapify.
 * - Si la dirección ya se intentó y no se encontró, tampoco se reintenta
 *   sola: hace falta corregir la dirección o marcarlo a mano.
 * - Solo se guarda un punto si el resultado es a nivel edificio/número
 *   (precisión de ~100 m) y con confianza suficiente. Si no, el edificio
 *   queda "a revisar" SIN coordenadas: nunca se inventa una ubicación.
 */
class BuildingGeocoder
{
    public const OUTCOME_SKIPPED = 'skipped';

    public function __construct(private GeoapifyClient $client) {}

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * @return string El estado resultante (Building::GEO_*) o OUTCOME_SKIPPED.
     */
    public function geocode(Building $building, bool $force = false): string
    {
        $address = BuildingAddress::fromBuilding($building);
        $canonical = $address->canonical();

        $sameAddress = $building->geocoded_address === $canonical;

        // Una ubicación marcada a mano para esta dirección no se pisa nunca.
        if ($sameAddress && $building->geocoding_status === Building::GEO_MANUAL) {
            return self::OUTCOME_SKIPPED;
        }

        if (! $force && ! $this->needsLookup($building, $canonical)) {
            return self::OUTCOME_SKIPPED;
        }

        if (! $address->isGeocodable()) {
            return $this->store($building, Building::GEO_NEEDS_REVIEW, $canonical);
        }

        try {
            foreach ($address->queries() as $query) {
                $result = $this->client->search($query);

                if ($result !== null && $this->isReliable($result, $address)) {
                    return $this->store(
                        $building,
                        Building::GEO_GEOCODED,
                        $canonical,
                        (float) $result['lat'],
                        (float) $result['lon'],
                        (float) data_get($result, 'rank.confidence'),
                    );
                }
            }
        } catch (GeocodingUnavailableException $e) {
            Log::warning('Geoapify no disponible', [
                'building_id' => $building->id,
                'error' => $e->getMessage(),
            ]);

            // Un corte de Geoapify no borra una ubicación válida existente.
            if ($sameAddress && $building->latitude !== null) {
                return Building::GEO_ERROR;
            }

            return $this->store($building, Building::GEO_ERROR, $canonical);
        }

        return $this->store($building, Building::GEO_NEEDS_REVIEW, $canonical);
    }

    private function needsLookup(Building $building, string $canonical): bool
    {
        if ($building->geocoded_address !== $canonical) {
            return true;
        }

        return in_array($building->geocoding_status, [Building::GEO_PENDING, Building::GEO_ERROR], true);
    }

    /**
     * ¿El punto está razonablemente cerca (~100 m) de la dirección real?
     */
    private function isReliable(array $result, BuildingAddress $address): bool
    {
        if (! is_numeric($result['lat'] ?? null) || ! is_numeric($result['lon'] ?? null)) {
            return false;
        }

        // "street", "city", etc. significan que Geoapify NO encontró la altura:
        // el punto podría estar a kilómetros (centro de la calle o de la ciudad).
        if (! in_array($result['result_type'] ?? null, ['building', 'amenity'], true)) {
            return false;
        }

        if ((float) data_get($result, 'rank.confidence', 0) < (float) config('services.geoapify.min_confidence', 0.8)) {
            return false;
        }

        // Misma calle pero en otra ciudad ("San Martín 123" hay en todo el país).
        $cityConfidence = data_get($result, 'rank.confidence_city_level');

        if ($cityConfidence !== null && (float) $cityConfidence < 0.5) {
            return false;
        }

        // Si devolvió una altura, tiene que ser la pedida.
        $housenumber = $result['housenumber'] ?? null;

        if ($housenumber !== null && preg_replace('/\D/', '', (string) $housenumber) !== $address->number) {
            return false;
        }

        return true;
    }

    private function store(
        Building $building,
        string $status,
        string $canonical,
        ?float $latitude = null,
        ?float $longitude = null,
        ?float $confidence = null,
    ): string {
        $building->forceFill([
            'latitude' => $latitude,
            'longitude' => $longitude,
            'geocoding_status' => $status,
            'geocoding_confidence' => $confidence,
            'geocoded_address' => $canonical,
            'geocoded_at' => now(),
        ]);

        // Sin eventos (no dispara otra geocodificación) ni tocar updated_at:
        // ubicar el edificio no es una edición del usuario.
        Building::withoutTimestamps(fn () => $building->saveQuietly());

        return $status;
    }
}

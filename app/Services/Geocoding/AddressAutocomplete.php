<?php

namespace App\Services\Geocoding;

use App\Models\Building;
use App\Support\Geocoding\BuildingAddress;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Buscador de direcciones del formulario de edificios.
 *
 * Las sugerencias vienen de Geoapify (consultado por el servidor) y se
 * guardan en cache bajo un token aleatorio y por usuario. El formulario solo
 * maneja ese token: las coordenadas nunca vienen del navegador.
 */
class AddressAutocomplete
{
    private const PICK_TTL = 3600;

    public function __construct(private GeoapifyClient $client) {}

    public function isAvailable(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * @return array<string, string> token => dirección legible
     */
    public function search(string $text, ?int $companyId): array
    {
        $text = Str::squish($text);

        // Con menos de 4 letras no hay nada útil (y se ahorran créditos).
        if (mb_strlen($text) < 4 || ! $this->isAvailable()) {
            return [];
        }

        $near = $companyId ? $this->companyCenter($companyId) : null;

        try {
            $results = Cache::remember(
                'geo:ac:'.md5(mb_strtolower($text).'|'.json_encode($near)),
                now()->addDay(),
                fn () => $this->client->autocomplete($text, $near),
            );
        } catch (GeocodingUnavailableException $e) {
            Log::info('Autocompletado de direcciones no disponible', ['error' => $e->getMessage()]);

            return [];
        }

        $options = [];

        foreach ($results as $result) {
            $fields = $this->toBuildingFields($result);

            if ($fields['name'] === null) {
                continue; // ciudades, provincias, etc.: no sirven para un edificio
            }

            $token = Str::random(24);
            $label = (string) ($result['formatted'] ?? trim($fields['name'].' '.$fields['address']));

            Cache::put($this->pickKey($token), [
                'label' => $label,
                'fields' => $fields,
                'lat' => is_numeric($result['lat'] ?? null) ? (float) $result['lat'] : null,
                'lon' => is_numeric($result['lon'] ?? null) ? (float) $result['lon'] : null,
                'confidence' => data_get($result, 'rank.confidence'),
            ], self::PICK_TTL);

            $options[$token] = $label;
        }

        return $options;
    }

    /** Lo que eligió ESTE usuario (null si el token no es suyo o venció). */
    public function pick(?string $token): ?array
    {
        if (! $token || ! preg_match('/^[A-Za-z0-9]{24}$/', $token)) {
            return null;
        }

        return Cache::get($this->pickKey($token));
    }

    /**
     * Si el edificio guardado tiene EXACTAMENTE la dirección elegida (con
     * altura), le pone las coordenadas de esa sugerencia: queda en el mapa
     * sin otra consulta. Si el admin cambió algo después, no se toca y lo
     * resuelve la geocodificación normal.
     */
    public function applyToBuilding(Building $building, ?string $token): bool
    {
        $pick = $this->pick($token);

        if (! $pick || $pick['lat'] === null || $pick['lon'] === null || blank($pick['fields']['address'])) {
            return false;
        }

        $chosen = new Building($pick['fields']);
        $chosen->setRelation('company', $building->company);

        $canonical = BuildingAddress::fromBuilding($building)->canonical();

        if (BuildingAddress::fromBuilding($chosen)->canonical() !== $canonical) {
            return false;
        }

        $building->forceFill([
            'latitude' => round($pick['lat'], 7),
            'longitude' => round($pick['lon'], 7),
            'geocoding_status' => Building::GEO_GEOCODED,
            'geocoding_confidence' => is_numeric($pick['confidence']) ? (float) $pick['confidence'] : null,
            'geocoded_address' => $canonical,
            'geocoded_at' => now(),
        ]);

        Building::withoutTimestamps(fn () => $building->saveQuietly());

        Cache::forget($this->pickKey($token));

        return true;
    }

    /**
     * Resultado de Geoapify → campos del formulario de edificios.
     *
     * @return array{name: ?string, address: ?string, locality: ?string, municipality: ?string, neighborhood: ?string, province: ?string}
     */
    public function toBuildingFields(array $result): array
    {
        $clean = fn ($value) => filled($value) ? Str::squish((string) $value) : null;

        $municipality = $clean($result['county'] ?? null);

        // "Partido de Tigre" → "Tigre"; las comunas de CABA no son municipios.
        if ($municipality !== null) {
            $municipality = preg_replace('/^(Partido|Departamento)\s+(de\s+)?/iu', '', $municipality);
            if (preg_match('/^Comuna\b/iu', $municipality)) {
                $municipality = null;
            }
        }

        $housenumber = $clean($result['housenumber'] ?? null);

        return [
            'name' => $clean($result['street'] ?? null),
            'address' => $housenumber !== null && preg_match('/\d+/', $housenumber, $m) ? $m[0] : null,
            'locality' => $clean($result['city'] ?? null),
            'municipality' => $municipality,
            'neighborhood' => $clean($result['suburb'] ?? ($result['district'] ?? null)),
            'province' => $clean($result['state'] ?? null),
        ];
    }

    /** Centro aproximado de los edificios de la empresa (para priorizar su zona). */
    private function companyCenter(int $companyId): ?array
    {
        return Cache::remember("geo:center:{$companyId}", now()->addHours(6), function () use ($companyId) {
            $row = Building::withoutGlobalScope('company')
                ->where('company_id', $companyId)
                ->whereNotNull('latitude')
                ->selectRaw('AVG(latitude) as lat, AVG(longitude) as lon, COUNT(*) as n')
                ->first();

            return $row && $row->n > 0 ? [round((float) $row->lat, 4), round((float) $row->lon, 4)] : null;
        });
    }

    private function pickKey(string $token): string
    {
        return 'geo:pick:'.auth()->id().':'.$token;
    }
}

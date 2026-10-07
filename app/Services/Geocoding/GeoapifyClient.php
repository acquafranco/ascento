<?php

namespace App\Services\Geocoding;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Cliente mínimo de Geoapify. Toda llamada sale del servidor: la API key
 * no se expone nunca al navegador.
 *
 * Límites del plan gratis: 3000 créditos/día y 5 requests/segundo
 * (1 geocodificación = 1 crédito). Llevamos un contador diario propio
 * (services.geoapify.daily_limit) para no pasarnos.
 */
class GeoapifyClient
{
    private const GEOCODE_URL = 'https://api.geoapify.com/v1/geocode/search';

    public function isConfigured(): bool
    {
        return filled(config('services.geoapify.api_key'));
    }

    /**
     * Búsqueda estructurada. Devuelve el mejor resultado o null si no hubo
     * ninguno.
     *
     * @param  array<string, string>  $address  street, housenumber, city, state
     *
     * @throws GeocodingUnavailableException
     */
    public function search(array $address): ?array
    {
        if (! $this->isConfigured()) {
            throw new GeocodingUnavailableException('GEOAPIFY_API_KEY no está configurada.');
        }

        $this->consumeDailyBudget();

        try {
            $response = Http::acceptJson()
                ->connectTimeout(5)
                ->timeout(10)
                ->get(self::GEOCODE_URL, [
                    ...$address,
                    'filter' => 'countrycode:'.config('services.geoapify.country_code', 'ar'),
                    'lang' => 'es',
                    'limit' => 1,
                    'format' => 'json',
                    'apiKey' => config('services.geoapify.api_key'),
                ]);
        } catch (ConnectionException $e) {
            throw new GeocodingUnavailableException('No se pudo conectar con Geoapify.', previous: $e);
        }

        if ($response->failed()) {
            // Nunca loguear la URL: lleva la API key.
            throw new GeocodingUnavailableException('Geoapify respondió '.$response->status().'.');
        }

        $result = $response->json('results.0');

        return is_array($result) ? $result : null;
    }

    public function remainingToday(): int
    {
        return max(0, $this->dailyLimit() - (int) Cache::get($this->budgetKey(), 0));
    }

    private function consumeDailyBudget(): void
    {
        $key = $this->budgetKey();

        Cache::add($key, 0, now()->endOfDay());

        if (Cache::increment($key) > $this->dailyLimit()) {
            throw new GeocodingUnavailableException('Se alcanzó el tope diario de geocodificaciones.');
        }
    }

    private function dailyLimit(): int
    {
        return (int) config('services.geoapify.daily_limit', 1500);
    }

    private function budgetKey(): string
    {
        return 'geoapify:geocode:'.now()->format('Y-m-d');
    }
}

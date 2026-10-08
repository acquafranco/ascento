<?php

namespace App\Services\Geocoding;

use RuntimeException;

/**
 * Geoapify no respondió (red, 429, 5xx, key inválida o tope diario
 * alcanzado). Es transitorio: el edificio queda en "error" y se reintenta.
 */
class GeocodingUnavailableException extends RuntimeException {}

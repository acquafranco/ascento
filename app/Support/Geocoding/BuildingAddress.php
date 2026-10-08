<?php

namespace App\Support\Geocoding;

use App\Models\Building;
use Illuminate\Support\Str;

/**
 * Dirección de un edificio lista para geocodificar.
 *
 * Cómo se guarda hoy un edificio (BuildingForm, igual desde el primer commit):
 *   name          → CALLE (ej. "Av. Cabildo")
 *   address       → NÚMERO (el form lo valida como entero)
 *   locality      → localidad (ej. "Benavídez"), opcional
 *   municipality  → municipio / partido (ej. "Tigre"), opcional
 *   neighborhood  → barrio (ej. "Nordelta", "Palermo"), opcional
 *   province      → provincia, opcional (texto libre: "CABA", "Bs As"...)
 * No hay código postal ni país: Ascento opera en Argentina, y si falta la
 * provincia se usa la de la empresa.
 *
 * Con eso se arma una búsqueda ESTRUCTURADA (street / housenumber / city /
 * state) en vez de concatenar todo en un texto libre: es más precisa y
 * evita que "San Martín 123" se resuelva en cualquier ciudad del país.
 */
class BuildingAddress
{
    private const CABA_STATE = 'Ciudad Autónoma de Buenos Aires';

    private const CABA_ALIASES = [
        'caba',
        'capital federal',
        'capital',
        'ciudad autonoma de buenos aires',
        'cdad autonoma de buenos aires',
        'ciudad de buenos aires',
        'cdad de buenos aires',
    ];

    private const PBA_ALIASES = [
        'buenos aires',
        'bs as',
        'bsas',
        'pba',
        'pcia de buenos aires',
        'pcia de bs as',
        'pcia bs as',
        'provincia de buenos aires',
        'provincia de bs as',
        'prov de buenos aires',
        'prov bs as',
    ];

    /**
     * @param  list<string>  $cities  Candidatos de ciudad, en orden de preferencia.
     */
    public function __construct(
        public readonly string $street,
        public readonly ?string $number,
        public readonly array $cities,
        public readonly ?string $state,
        public readonly string $countryCode,
    ) {}

    public static function fromBuilding(Building $building): self
    {
        $street = self::clean($building->name) ?? '';
        $number = self::extractNumber($building->address);

        // Compatibilidad: si el número quedó pegado a la calle ("Cabildo 1234").
        if ($number === null && preg_match('/^(.*\D)\s+(\d{1,6})$/u', $street, $m)) {
            $street = trim($m[1]);
            $number = $m[2];
        }

        $province = self::clean($building->province)
            ?? self::clean($building->company?->province);

        $state = null;
        $isCaba = false;

        if ($province !== null) {
            if (self::isCaba($province)) {
                $state = self::CABA_STATE;
                $isCaba = true;
            } elseif (self::isPba($province)) {
                $state = 'Buenos Aires';
            } else {
                $state = $province;
            }
        }

        $cities = [];

        foreach ([$building->locality, $building->municipality] as $value) {
            $value = self::clean($value);

            if ($value === null) {
                continue;
            }

            if (self::isCaba($value)) {
                $isCaba = true;
                $state = self::CABA_STATE;

                continue;
            }

            $cities[] = $value;
        }

        // En CABA los barrios no son ciudades: se busca primero "Buenos Aires"
        // y el barrio queda como segundo intento.
        if ($isCaba) {
            array_unshift($cities, 'Buenos Aires');
        }

        // El barrio solo sirve como último recurso (ej. "Nordelta" sin localidad).
        if ($cities === [] && ($neighborhood = self::clean($building->neighborhood)) !== null) {
            $cities[] = $neighborhood;
        }

        $cities = array_values(array_unique($cities));

        return new self(
            street: $street,
            number: $number,
            cities: $cities,
            state: $state,
            countryCode: strtolower((string) config('services.geoapify.country_code', 'ar')),
        );
    }

    /**
     * Sin calle, número y alguna localidad, la dirección es ambigua: no se
     * consulta a Geoapify (no gastamos créditos ni arriesgamos un punto falso).
     */
    public function isGeocodable(): bool
    {
        return $this->street !== '' && $this->number !== null && $this->cities !== [];
    }

    /**
     * Qué le falta a la dirección para poder ubicarla, en lenguaje de usuario.
     */
    public function missingParts(): array
    {
        return array_values(array_filter([
            $this->street === '' ? 'calle' : null,
            $this->number === null ? 'número' : null,
            $this->cities === [] ? 'localidad' : null,
        ]));
    }

    /**
     * Búsquedas estructuradas a probar, en orden (máximo 2 para cuidar créditos).
     *
     * @return list<array<string, string>>
     */
    public function queries(): array
    {
        if (! $this->isGeocodable()) {
            return [];
        }

        return array_map(fn (string $city) => array_filter([
            'street' => $this->street,
            'housenumber' => $this->number,
            'city' => $city,
            'state' => $this->state,
        ]), array_slice($this->cities, 0, 2));
    }

    /**
     * Texto canónico de la dirección. Se guarda en buildings.geocoded_address
     * y sirve para detectar que las coordenadas quedaron desactualizadas.
     */
    public function canonical(): string
    {
        $parts = array_filter([
            trim($this->street.' '.$this->number),
            ...$this->cities,
            $this->state,
            $this->countryCode === 'ar' ? 'Argentina' : strtoupper($this->countryCode),
        ]);

        return mb_substr(implode(', ', $parts), 0, 255);
    }

    private static function clean(?string $value): ?string
    {
        $value = Str::squish((string) $value);

        return $value === '' ? null : $value;
    }

    private static function extractNumber(?string $value): ?string
    {
        if ($value !== null && preg_match('/\d{1,6}/', $value, $m)) {
            return ltrim($m[0], '0') ?: null;
        }

        return null;
    }

    private static function key(string $value): string
    {
        return Str::of($value)->ascii()->lower()->replace('.', '')->squish()->toString();
    }

    private static function isCaba(string $value): bool
    {
        return in_array(self::key($value), self::CABA_ALIASES, true);
    }

    private static function isPba(string $value): bool
    {
        return in_array(self::key($value), self::PBA_ALIASES, true);
    }
}

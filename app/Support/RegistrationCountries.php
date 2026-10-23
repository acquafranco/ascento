<?php

namespace App\Support;

use App\Models\PlatformSettings;

/**
 * Desde qué países se aceptan registros NUEVOS de empresas de mantenimiento y
 * de proveedores del marketplace. Única fuente para los dos formularios, sus
 * controladores y cualquier otro alta pública.
 *
 * - Switch internacional apagado (por defecto): solo Argentina, sin selector;
 *   el servidor asigna Argentina y rechaza otro país aunque se envíe a mano.
 * - Encendido: Argentina más los países que el SuperAdmin habilitó uno por
 *   uno (Countries::LATAM). Sin países adicionales, sigue siendo solo
 *   Argentina: no aparece ninguna opción que no esté habilitada.
 *
 * Habilitar el registro de un país NO habilita cobros ni facturación ahí
 * (eso depende de config/marketplace.php y de cada pasarela). Nunca cambia
 * el país de una cuenta existente.
 */
final class RegistrationCountries
{
    public const HOME = Countries::DEFAULT;

    public static function internationalEnabled(): bool
    {
        return (bool) PlatformSettings::current()->international_registration_enabled;
    }

    /**
     * Países habilitados individualmente por el SuperAdmin, además de Argentina
     * (se guardan aunque el switch esté apagado, para no perder la selección).
     *
     * @return list<string>
     */
    public static function configuredExtra(): array
    {
        return self::clean((array) PlatformSettings::current()->registration_countries);
    }

    /**
     * Países en los que hoy se acepta un registro nuevo (Argentina primero).
     *
     * @return list<string>
     */
    public static function enabled(): array
    {
        return self::internationalEnabled() ? [self::HOME, ...self::configuredExtra()] : [self::HOME];
    }

    /** @return array<string, string> código => nombre, para el selector */
    public static function options(): array
    {
        return collect(self::enabled())->mapWithKeys(fn (string $c) => [$c => Countries::name($c)])->all();
    }

    public static function allows(?string $code): bool
    {
        return in_array(strtoupper(trim((string) $code)), self::enabled(), true);
    }

    /** ¿Se muestra el selector de país? Solo si hay más de un país para elegir. */
    public static function showsSelector(): bool
    {
        return count(self::enabled()) > 1;
    }

    /**
     * Opciones para editar el país de una cuenta EXISTENTE: los habilitados
     * hoy más el que ya tiene (nunca se le saca ni se le cambia solo).
     *
     * @return array<string, string>
     */
    public static function optionsForExisting(?string $current): array
    {
        $codes = self::enabled();
        $current = strtoupper(trim((string) $current));
        if (array_key_exists($current, Countries::LATAM) && ! in_array($current, $codes, true)) {
            $codes[] = $current;
        }

        return collect($codes)->mapWithKeys(fn (string $c) => [$c => Countries::name($c)])->all();
    }

    /** Texto para los formularios cuando solo se acepta Argentina. */
    public static function homeOnlyNotice(): string
    {
        return 'Por ahora el registro está disponible para '.(self::showsSelector() ? 'los países de la lista' : 'Argentina').'.';
    }

    public static function rejectedMessage(): string
    {
        return self::showsSelector()
            ? 'Por ahora no aceptamos registros desde ese país. Elegí uno de la lista.'
            : 'Por ahora el registro está disponible solo para Argentina.';
    }

    /**
     * @param  array<int, mixed>  $codes
     * @return list<string> códigos válidos de Latinoamérica, sin Argentina ni repetidos
     */
    public static function clean(array $codes): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn ($c) => strtoupper(trim((string) $c)), $codes),
            fn (string $c) => $c !== self::HOME && array_key_exists($c, Countries::LATAM),
        )));
    }
}

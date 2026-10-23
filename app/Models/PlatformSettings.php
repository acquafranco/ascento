<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Configuración general de la plataforma (una sola fila; la edita el
 * SuperAdmin en «Configuración de la plataforma»). Sin fila, los valores
 * seguros: registro solo de Argentina y cuenta de Mercado Pago sin verificar.
 *
 * Registro por país: ver App\Support\RegistrationCountries (única fuente).
 * Verificación de Mercado Pago: ver App\Support\Billing\MercadoPagoAccount.
 */
class PlatformSettings extends Model
{
    protected $table = 'platform_settings';

    protected $guarded = ['id'];

    protected $casts = [
        'international_registration_enabled' => 'boolean',
        'registration_countries' => 'array',
        'mp_account_checked_at' => 'datetime',
        'mp_account_is_test' => 'boolean',
    ];

    public static function current(): self
    {
        return static::query()->orderBy('id')->first() ?? new static([
            'international_registration_enabled' => false,
            'registration_countries' => [],
        ]);
    }

    /** La fila que se guarda (se crea la primera vez). */
    public static function row(): self
    {
        return static::query()->orderBy('id')->first() ?? new static;
    }
}

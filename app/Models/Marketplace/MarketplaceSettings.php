<?php

namespace App\Models\Marketplace;

use Illuminate\Database\Eloquent\Model;

/**
 * Configuración comercial del marketplace (una sola fila, la edita el
 * SuperAdmin en «Configuración del marketplace»). Sin precios cargados no se
 * cobra ni se anuncia nada: no hay importes por defecto.
 */
class MarketplaceSettings extends Model
{
    public const DEFAULT_TRIAL_DAYS = 30;

    /** Duraciones de destacado que se venden (días). */
    public const FEATURED_DAYS = [7, 30];

    protected $table = 'marketplace_settings';

    protected $guarded = ['id'];

    protected $casts = [
        'trial_days' => 'integer',
        'billing_enabled' => 'boolean',
        'plan_price' => 'decimal:2',
        'international_free' => 'boolean',
        'featured_enabled' => 'boolean',
        'featured_price_7' => 'decimal:2',
        'featured_price_30' => 'decimal:2',
    ];

    public static function current(): self
    {
        return static::query()->orderBy('id')->first() ?? new static([
            'trial_days' => self::DEFAULT_TRIAL_DAYS,
            'billing_enabled' => false,
            'plan_currency' => 'ARS',
            'international_free' => true,
            'featured_enabled' => false,
        ]);
    }

    /** ¿Se cobra la suscripción? Habilitada y con precio (los proveedores sin pasarela nunca pagan). */
    public function chargesSubscription(): bool
    {
        return $this->billing_enabled && $this->plan_price !== null && (float) $this->plan_price > 0;
    }

    /** Precio de un destacado por esa duración, o null si no se vende. */
    public function featuredPrice(int $days): ?float
    {
        if (! $this->featured_enabled || ! in_array($days, self::FEATURED_DAYS, true)) {
            return null;
        }
        $price = $this->{'featured_price_'.$days};

        return $price !== null && (float) $price > 0 ? (float) $price : null;
    }

    /** @return array<int, float> días => precio de lo que se vende hoy */
    public function featuredOffers(): array
    {
        $offers = [];
        foreach (self::FEATURED_DAYS as $days) {
            if (($price = $this->featuredPrice($days)) !== null) {
                $offers[$days] = $price;
            }
        }

        return $offers;
    }

    public function trialDays(): int
    {
        return max(1, min(365, (int) ($this->trial_days ?: self::DEFAULT_TRIAL_DAYS)));
    }
}

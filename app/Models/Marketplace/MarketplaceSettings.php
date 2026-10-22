<?php

namespace App\Models\Marketplace;

use App\Services\Marketplace\Billing\SupplierBilling;
use Illuminate\Database\Eloquent\Model;

/**
 * Configuración comercial del marketplace (una sola fila, la edita el
 * SuperAdmin en «Configuración del marketplace»). ÚNICA fuente de límites y
 * precios: ningún otro lugar del código tiene números de planes.
 *
 * - Plan gratuito: para siempre, con free_product_limit publicaciones.
 * - Premium: suscripción mensual con premium_product_limit publicaciones; se
 *   vende solo con precio y premium_enabled.
 * - Destacados: pago único por 7 o 30 días; se venden solo con featured_enabled.
 * - Cobros: solo en billing_countries que además tengan pasarela
 *   (config/marketplace.php) y en la moneda de esos países (pricing_currency).
 */
class MarketplaceSettings extends Model
{
    public const DEFAULT_FREE_LIMIT = 20;

    public const DEFAULT_PREMIUM_LIMIT = 1000;

    /** Duraciones de destacado que se venden (días). */
    public const FEATURED_DAYS = [7, 30];

    /** Qué publicaciones quedan visibles al volver al plan gratuito, si el proveedor no eligió. */
    public const OVER_LIMIT_RULES = [
        'recent' => 'Las publicadas más recientemente',
        'oldest' => 'Las publicadas hace más tiempo',
    ];

    protected $table = 'marketplace_settings';

    protected $guarded = ['id'];

    protected $casts = [
        'free_product_limit' => 'integer',
        'premium_product_limit' => 'integer',
        'premium_enabled' => 'boolean',
        'premium_price' => 'decimal:2',
        'billing_countries' => 'array',
        'featured_enabled' => 'boolean',
        'featured_price_7' => 'decimal:2',
        'featured_price_30' => 'decimal:2',
    ];

    public static function current(): self
    {
        return static::query()->orderBy('id')->first() ?? new static([
            'free_product_limit' => self::DEFAULT_FREE_LIMIT,
            'premium_product_limit' => self::DEFAULT_PREMIUM_LIMIT,
            'premium_enabled' => false,
            'pricing_currency' => 'ARS',
            'billing_countries' => ['AR'],
            'over_limit_rule' => 'recent',
            'featured_enabled' => false,
        ]);
    }

    public function freeLimit(): int
    {
        return max(1, (int) ($this->free_product_limit ?: self::DEFAULT_FREE_LIMIT));
    }

    public function premiumLimit(): int
    {
        return max($this->freeLimit(), (int) ($this->premium_product_limit ?: self::DEFAULT_PREMIUM_LIMIT));
    }

    public function overLimitRule(): string
    {
        return array_key_exists((string) $this->over_limit_rule, self::OVER_LIMIT_RULES) ? $this->over_limit_rule : 'recent';
    }

    /** ¿Se cobra en este país? Habilitado acá, con pasarela y en la moneda de los precios. */
    public function chargesIn(?string $country): bool
    {
        $country = strtoupper((string) $country);

        return in_array($country, (array) $this->billing_countries, true)
            && SupplierBilling::supportsCountry($country)
            && Supplier::currencyFor($country) === strtoupper($this->pricing_currency ?: 'ARS');
    }

    /** ¿Se vende Premium? Habilitado y con precio (y, para cada proveedor, chargesIn su país). */
    public function sellsPremium(): bool
    {
        return $this->premium_enabled && $this->premium_price !== null && (float) $this->premium_price > 0;
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
}

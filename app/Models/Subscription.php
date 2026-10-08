<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Suscripción de una empresa (una fila por empresa).
 *
 * provider = 'mercadopago': suscripción mensual (preapproval) cuyo estado
 *   lo determina SOLO lo que informa la API de Mercado Pago
 *   (MercadoPagoSubscriptionSync), nunca el navegador.
 * provider = 'manual': pago por transferencia que activa el SuperAdmin.
 */
class Subscription extends Model
{
    use HasFactory;

    public const PENDING = 'pending';       // checkout iniciado, sin autorizar

    public const AUTHORIZED = 'authorized'; // Mercado Pago cobra todos los meses

    public const ACTIVE = 'active';         // manual (transferencia)

    public const PAST_DUE = 'past_due';     // último cobro rechazado; MP reintenta

    public const PAUSED = 'paused';         // acceso cortado (SuperAdmin o MP)

    public const CANCELED = 'canceled';     // no se renueva; vale hasta fin del período

    /** Días de tolerancia tras un cobro rechazado (MP reintenta hasta 4 veces en ~10 días). */
    public const PAST_DUE_GRACE_DAYS = 5;

    /** Tiempo que se espera el primer cobro después de autorizar (MP cobra ~1 h después). */
    public const FIRST_PAYMENT_WAIT_HOURS = 48;

    protected $fillable = [
        'company_id',
        'provider',
        'provider_subscription_id',
        'provider_plan_id',
        'external_reference',
        'payer_email',
        'checkout_url',
        'plan',
        'status',
        'amount',
        'currency',
        'trial_ends_at',
        'authorized_at',
        'current_period_start',
        'current_period_end',
        'next_payment_at',
        'last_payment_status',
        'last_payment_at',
        'canceled_at',
        'cancel_at_period_end',
        'last_synced_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'trial_ends_at' => 'datetime',
        'authorized_at' => 'datetime',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'next_payment_at' => 'datetime',
        'last_payment_at' => 'datetime',
        'canceled_at' => 'datetime',
        'cancel_at_period_end' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class)->latest('id');
    }

    public function isMercadoPago(): bool
    {
        return $this->provider === 'mercadopago';
    }

    public function hasPaidPeriod(): bool
    {
        return $this->current_period_end?->isFuture() ?? false;
    }

    /** Hasta cuándo dura la tolerancia de un cobro rechazado. */
    public function graceEndsAt(): ?CarbonInterface
    {
        return $this->current_period_end?->copy()->addDays(self::PAST_DUE_GRACE_DAYS);
    }

    /**
     * Recién autorizada en Mercado Pago y todavía sin el primer cobro
     * confirmado: se da acceso un rato para no bloquear a quien acaba de
     * pagar mientras llega la notificación.
     */
    public function isAwaitingFirstPayment(): bool
    {
        return $this->status === self::AUTHORIZED
            && $this->current_period_end === null
            && $this->authorized_at !== null
            && $this->authorized_at->copy()->addHours(self::FIRST_PAYMENT_WAIT_HOURS)->isFuture();
    }

    /**
     * ¿Esta suscripción, por sí sola, da acceso hoy?
     */
    public function grantsAccess(): bool
    {
        if ($this->provider === 'manual') {
            return in_array($this->status, [self::ACTIVE, self::AUTHORIZED, 'trialing'], true)
                ? $this->hasPaidPeriod()
                : ($this->isCanceled() && $this->hasPaidPeriod());
        }

        return match (true) {
            in_array($this->status, [self::AUTHORIZED, self::ACTIVE, 'trialing'], true) => $this->hasPaidPeriod() || $this->isAwaitingFirstPayment(),
            $this->status === self::PAST_DUE => $this->graceEndsAt()?->isFuture() ?? false,
            $this->isCanceled() => $this->hasPaidPeriod(),
            default => false, // pending, paused
        };
    }

    public function isCanceled(): bool
    {
        return in_array($this->status, [self::CANCELED, 'cancelled'], true);
    }
}

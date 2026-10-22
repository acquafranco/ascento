<?php

namespace App\Models\Marketplace;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Suscripción de un proveedor al marketplace (una fila por proveedor), aparte
 * de las suscripciones de las empresas. El estado lo decide SOLO lo que
 * informa la pasarela (SupplierBillingSync), nunca el navegador. El período
 * pago avanza únicamente con cobros aprobados.
 */
class SupplierSubscription extends Model
{
    public const PENDING = 'pending';       // checkout iniciado, sin autorizar

    public const AUTHORIZED = 'authorized'; // la pasarela cobra cada mes

    public const PAST_DUE = 'past_due';     // último cobro rechazado; la pasarela reintenta

    public const PAUSED = 'paused';

    public const CANCELED = 'canceled';     // no se renueva; vale hasta el fin del período pago

    public const STATUSES = [
        self::PENDING => 'Pendiente de pago',
        self::AUTHORIZED => 'Activa',
        self::PAST_DUE => 'Cobro rechazado (reintentando)',
        self::PAUSED => 'Pausada',
        self::CANCELED => 'Cancelada',
    ];

    /** Días de tolerancia tras un cobro rechazado (igual que las empresas). */
    public const PAST_DUE_GRACE_DAYS = 5;

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'decimal:2',
        'authorized_at' => 'datetime',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'next_payment_at' => 'datetime',
        'last_payment_at' => 'datetime',
        'canceled_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class)->latest('id');
    }

    /** ¿Da acceso hoy? Solo con un período pagado (o la tolerancia de un cobro rechazado). */
    public function grantsAccess(): bool
    {
        if ($this->current_period_end === null) {
            return false;
        }

        return $this->current_period_end->isFuture()
            || ($this->status === self::PAST_DUE && $this->current_period_end->copy()->addDays(self::PAST_DUE_GRACE_DAYS)->isFuture());
    }

    /** ¿La pasarela puede seguir cobrando? (hay que cancelarla antes de cerrar la cuenta) */
    public function isBillable(): bool
    {
        return in_array($this->status, [self::PENDING, self::AUTHORIZED, self::PAST_DUE, self::PAUSED], true) && $this->provider_subscription_id !== null;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}

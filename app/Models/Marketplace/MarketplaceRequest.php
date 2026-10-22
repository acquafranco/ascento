<?php

namespace App\Models\Marketplace;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Pedido de cotización de una empresa de mantenimiento a UN proveedor.
 *
 * Una cotización no es una compra: el comprador la acepta, el pago y la
 * entrega se coordinan entre las partes (Ascento no cobra) y, al recibir, el
 * comprador la marca como completada. No existe un estado «pagado».
 *
 * Sin scope global de empresa a propósito: lo leen la empresa compradora y el
 * proveedor. Cada pantalla filtra por su lado (forCompany / forSupplier).
 */
class MarketplaceRequest extends Model
{
    public const SENT = 'sent';

    public const QUOTED = 'quoted';

    public const ACCEPTED = 'accepted';

    public const COMPLETED = 'completed';

    public const DECLINED = 'declined';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [
        self::SENT => 'Enviado, esperando cotización',
        self::QUOTED => 'Cotizado',
        self::ACCEPTED => 'Cotización aceptada',
        self::COMPLETED => 'Completado',
        self::DECLINED => 'El proveedor no puede cotizarlo',
        self::REJECTED => 'Cotización rechazada',
        self::CANCELLED => 'Cancelado',
    ];

    /** Quién puede llevarlo de un estado a otro. */
    public const TRANSITIONS = [
        'supplier' => [
            self::SENT => [self::QUOTED, self::DECLINED],
            self::QUOTED => [self::QUOTED, self::DECLINED], // puede corregir la cotización mientras no la acepten
        ],
        'buyer' => [
            self::SENT => [self::CANCELLED],
            self::QUOTED => [self::ACCEPTED, self::REJECTED, self::CANCELLED],
            self::ACCEPTED => [self::COMPLETED, self::CANCELLED],
        ],
    ];

    public const OPEN = [self::SENT, self::QUOTED, self::ACCEPTED];

    protected $guarded = ['id'];

    protected $casts = [
        'quoted_total' => 'decimal:2',
        'quoted_at' => 'datetime',
        'accepted_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MarketplaceRequestItem::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(MarketplaceRequestEvent::class)->orderBy('id');
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeForSupplier(Builder $query, int $supplierId): Builder
    {
        return $query->where('supplier_id', $supplierId);
    }

    public function canMove(string $actor, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$actor][$this->status] ?? [], true);
    }

    public function quoteExpiresAt(): ?Carbon
    {
        return $this->quoted_at && $this->quote_valid_days ? $this->quoted_at->copy()->addDays($this->quote_valid_days)->endOfDay() : null;
    }

    public function quoteExpired(): bool
    {
        return $this->status === self::QUOTED && ($expires = $this->quoteExpiresAt()) !== null && $expires->isPast();
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            self::QUOTED => 'info',
            self::ACCEPTED => 'warn',
            self::COMPLETED => 'ok',
            self::DECLINED, self::REJECTED, self::CANCELLED => 'bad',
            default => '',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function money(?float $value): string
    {
        return $value === null ? '—' : '$ '.number_format($value, 2, ',', '.').($this->currency !== 'ARS' ? ' '.$this->currency : '');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Support\Str;


class Quote extends Model
{
    use \App\Models\Concerns\SharesWithClient;


    use HasFactory, SoftDeletes;
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'building_id',
        'client_id',
        'created_by',
        'title',
        'description',
        'amount',
        'status',
        'priority',
        'public_token',
        'unit',
        'issued_at',
        'valid_until',
        'conditions',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'issued_at' => 'date',
        'valid_until' => 'date',
        'voided_at' => 'datetime',
    ];

    public const DRAFT = 'draft';

    public const SENT = 'sent';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const VOID = 'void';

    /** Estado calculado: borrador/enviado con la validez vencida. No se guarda. */
    public const EXPIRED = 'expired';

    /** Estados que se guardan (los que se eligen). */
    public const STATUSES = [
        self::DRAFT => 'Borrador',
        self::SENT => 'Enviado',
        self::APPROVED => 'Aprobado',
        self::REJECTED => 'Rechazado',
        self::VOID => 'Anulado',
    ];

    public const STATUS_COLORS = [
        self::DRAFT => 'gray',
        self::SENT => 'info',
        self::APPROVED => 'success',
        self::REJECTED => 'danger',
        self::VOID => 'gray',
        self::EXPIRED => 'warning',
    ];

    protected static function booted()
    {
        static::creating(function ($quote) {
            $quote->public_token = Str::uuid();
            $quote->issued_at ??= today();
            $quote->status ??= self::DRAFT;
        });

        static::saving(function (Quote $quote) {
            // "pending" (código viejo) = borrador.
            if ($quote->status === 'pending') {
                $quote->status = self::DRAFT;
            }

            if ($quote->isDirty('status')) {
                $quote->voided_at = $quote->status === self::VOID ? now() : null;
            }
        });
    }

    /** Ítems del presupuesto, en orden. */
    public function items()
    {
        return $this->hasMany(QuoteItem::class)->orderBy('position')->orderBy('id');
    }

    /** Total = suma de los subtotales (calculado en el servidor). */
    public function refreshTotal(): void
    {
        $this->forceFill(['amount' => round((float) $this->items()->sum('subtotal'), 2)])->saveQuietly();
    }

    public function isExpired(): bool
    {
        return in_array($this->status, [self::DRAFT, self::SENT], true)
            && $this->valid_until !== null
            && $this->valid_until->lt(today());
    }

    /** Estado para mostrar (incluye "vencido"). */
    public function displayStatus(): string
    {
        return $this->isExpired() ? self::EXPIRED : $this->status;
    }

    public function displayStatusLabel(): string
    {
        return [...self::STATUSES, self::EXPIRED => 'Vencido', 'pending' => 'Borrador'][$this->displayStatus()] ?? (string) $this->status;
    }

    public function scopeExpired($query)
    {
        return $query->whereIn('status', [self::DRAFT, self::SENT])
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '<', today());
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', [self::DRAFT, self::SENT])
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', today()));
    }

    /** ¿Tiene un cobro generado (no anulado)? Entonces su importe ya no se toca. */
    public function hasActiveReceivable(): bool
    {
        return $this->relationLoaded('receivables')
            ? $this->receivables->contains(fn ($r) => $r->status !== Receivable::VOID)
            : $this->receivables()->where('status', '!=', Receivable::VOID)->exists();
    }

    public function building()
    {
        return $this->belongsTo(Building::class)->withTrashed();
    }

    public function client()
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

        public function company()
    {
        return $this->belongsTo(Company::class)->withTrashed();
    }

    public function receivables()
    {
        return $this->hasMany(Receivable::class);
    }
}

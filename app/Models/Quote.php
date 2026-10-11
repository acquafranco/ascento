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

        // Número correlativo por empresa, asignado con la empresa bloqueada
        // (dos presupuestos a la vez no comparten número; además hay índice único).
        static::created(function (Quote $quote) {
            \Illuminate\Support\Facades\DB::transaction(function () use ($quote) {
                Company::whereKey($quote->company_id)->lockForUpdate()->first();
                $next = (int) static::withoutGlobalScopes()->withTrashed()->where('company_id', $quote->company_id)->max('number') + 1;
                $quote->forceFill(['number' => $next])->saveQuietly();
            });

            $quote->log('created');
        });

        static::updated(function (Quote $quote) {
            if ($quote->wasChanged('status')) {
                $labels = [...self::STATUSES, 'pending' => 'Borrador'];
                $quote->log('status', ($labels[$quote->getOriginal('status')] ?? $quote->getOriginal('status')).' → '.($labels[$quote->status] ?? $quote->status));
            }
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

    /** "P-000123": lo que ve el cliente. */
    public function numberLabel(): string
    {
        return 'P-'.str_pad((string) ($this->number ?? $this->id), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Se puede modificar solo mientras es una propuesta abierta (borrador o
     * enviado) y sin cobro activo. Aprobado, rechazado o anulado quedan
     * cerrados para no perder la trazabilidad (se puede duplicar).
     */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::DRAFT, self::SENT, 'pending'], true) && ! $this->hasActiveReceivable();
    }

    /** Estados a los que se puede pasar desde el actual. */
    public function allowedTransitions(): array
    {
        return match ($this->status) {
            self::DRAFT, 'pending' => [self::SENT, self::APPROVED, self::REJECTED, self::VOID],
            self::SENT => [self::APPROVED, self::REJECTED, self::VOID],
            self::APPROVED => $this->hasActiveReceivable() ? [] : [self::VOID],
            self::REJECTED => [self::VOID],
            default => [],
        };
    }

    /** Lo ve el cliente por enlace: solo propuestas emitidas (no borradores ni anulados). */
    public function isPubliclyVisible(): bool
    {
        return in_array($this->status, [self::SENT, self::APPROVED, self::REJECTED], true);
    }

    /**
     * Enlace para el cliente: FIRMADO y con vencimiento (no un enlace
     * permanente). Vence a los 30 días de la validez del presupuesto, con un
     * mínimo de 30 y un máximo de 120 días desde hoy. "Renovar enlace" cambia
     * el token y anula los enlaces anteriores.
     */
    public function signedPublicUrl(): string
    {
        $until = $this->valid_until ? $this->valid_until->copy()->addDays(30)->endOfDay() : now()->addDays(60);
        $until = $until->max(now()->addDays(30))->min(now()->addDays(120));

        return \Illuminate\Support\Facades\URL::temporarySignedRoute('quotes.public', $until, [
            'company' => $this->company?->slug ?? Company::whereKey($this->company_id)->value('slug'),
            'token' => $this->public_token,
        ]);
    }

    public function events()
    {
        return $this->hasMany(QuoteEvent::class)->orderBy('id');
    }

    public function log(string $action, ?string $detail = null, ?User $user = null): void
    {
        $event = new QuoteEvent;
        $event->forceFill([
            'quote_id' => $this->id, 'company_id' => $this->company_id, 'user_id' => ($user ?? auth()->user())?->id,
            'action' => $action, 'detail' => $detail ? Str::limit($detail, 250, '') : null, 'created_at' => now(),
        ])->save();
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

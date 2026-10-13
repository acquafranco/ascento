<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Componente enviado a reparación (Etapa 8.4). El estado solo avanza por
 * las transiciones de TRANSITIONS (RepairService): nunca se registra como
 * reparado un componente que solo fue enviado. No se borra; se cancela, y
 * los cambios quedan en el registro de actividad.
 */
class RepairComponent extends Model
{
    use BelongsToCompany;

    public const TYPES = [
        'drive' => 'Variador',
        'motor' => 'Motor',
        'board' => 'Placa electrónica',
        'controller' => 'Control',
        'other' => 'Otro componente',
    ];

    public const PENDING = 'pending_shipment';

    public const SENT = 'sent';

    public const DIAGNOSIS = 'in_diagnosis';

    public const AWAITING_QUOTE = 'awaiting_quote';

    public const AWAITING_APPROVAL = 'awaiting_approval';

    public const IN_REPAIR = 'in_repair';

    public const REPAIRED = 'repaired';

    public const RECEIVED = 'received';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [
        self::PENDING => 'Pendiente de envío',
        self::SENT => 'Enviado',
        self::DIAGNOSIS => 'En diagnóstico',
        self::AWAITING_QUOTE => 'Esperando presupuesto',
        self::AWAITING_APPROVAL => 'Esperando autorización',
        self::IN_REPAIR => 'En reparación',
        self::REPAIRED => 'Reparado',
        self::RECEIVED => 'Recibido',
        self::CANCELLED => 'Cancelado',
    ];

    /**
     * A qué estado se puede pasar desde cada uno. «Reparado» solo desde «En
     * reparación» (nunca desde «Enviado»); «Recibido» (volvió a la empresa)
     * solo desde «Reparado». «Recibido» y «Cancelado» son finales.
     */
    public const TRANSITIONS = [
        self::PENDING => [self::SENT, self::CANCELLED],
        self::SENT => [self::DIAGNOSIS, self::AWAITING_QUOTE, self::IN_REPAIR, self::CANCELLED],
        self::DIAGNOSIS => [self::AWAITING_QUOTE, self::IN_REPAIR, self::CANCELLED],
        self::AWAITING_QUOTE => [self::AWAITING_APPROVAL, self::CANCELLED],
        self::AWAITING_APPROVAL => [self::IN_REPAIR, self::CANCELLED],
        self::IN_REPAIR => [self::REPAIRED, self::CANCELLED],
        self::REPAIRED => [self::RECEIVED],
        self::RECEIVED => [],
        self::CANCELLED => [],
    ];

    /**
     * Cómo se informa cada estado en el QR público (y su vista previa): sin
     * revelar la negociación comercial ni el detalle del taller. El panel
     * privado sigue mostrando el estado real (STATUSES).
     */
    public const PUBLIC_STATUSES = [
        self::SENT => 'Enviado a reparación',
        self::DIAGNOSIS => 'En reparación',
        self::AWAITING_QUOTE => 'En reparación',
        self::AWAITING_APPROVAL => 'En reparación',
        self::IN_REPAIR => 'En reparación',
        // Reparado en el taller no es devuelto: hasta que vuelva, sigue «en reparación».
        self::REPAIRED => 'En reparación',
    ];

    /** Fuera de la empresa (en el proveedor o taller): lo que puede informar el QR. */
    public const AWAY = [self::SENT, self::DIAGNOSIS, self::AWAITING_QUOTE, self::AWAITING_APPROVAL, self::IN_REPAIR, self::REPAIRED];

    /** Abiertos: todavía no volvieron ni se cancelaron. */
    public const OPEN = [self::PENDING, ...self::AWAY];

    protected $fillable = [];

    protected $casts = [
        'sent_at' => 'date',
        'expected_return_at' => 'date',
        'returned_at' => 'date',
        'quote_amount' => 'decimal:2',
        'final_cost' => 'decimal:2',
    ];

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class)->withTrashed();
    }

    public function elevator(): BelongsTo
    {
        return $this->belongsTo(Elevator::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function followUp(): BelongsTo
    {
        return $this->belongsTo(User::class, 'follow_up_user_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function files(): HasMany
    {
        return $this->hasMany(RepairComponentFile::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN);
    }

    public function componentLabel(): string
    {
        return trim((self::TYPES[$this->component_type] ?? 'Componente').($this->description ? ' · '.$this->description : ''));
    }

    /** Estado para el QR público; null si no se publica (pendiente, recibido o cancelado). */
    public static function publicStatus(string $status): ?string
    {
        return self::PUBLIC_STATUSES[$status] ?? null;
    }

    /** @return list<string> estados a los que puede pasar */
    public function nextStatuses(): array
    {
        return self::TRANSITIONS[$this->status] ?? [];
    }

    /** Vuelta estimada vencida (sigue fuera de la empresa). */
    public function isLate(): bool
    {
        return in_array($this->status, self::AWAY, true) && $this->expected_return_at !== null && $this->expected_return_at->lt(today());
    }
}

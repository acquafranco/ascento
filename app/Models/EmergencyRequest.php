<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Solicitud de ayuda por posible atrapamiento, registrada desde el QR público
 * de un edificio. NO es una confirmación de que alguien esté atrapado ni de
 * que alguien la haya visto: es un pedido registrado. La crea solo
 * EmergencyRequestService (validaciones, límites e idempotencia).
 */
class EmergencyRequest extends Model
{
    use BelongsToCompany;

    public const OPEN = 'open';

    public const STATUSES = [
        self::OPEN => 'Abierta',
        'attended' => 'Atendida',
        'false_alarm' => 'Falsa alarma',
        'closed' => 'Cerrada',
    ];

    protected $fillable = [];

    protected $hidden = ['idempotency_key', 'ip_hash'];

    protected $casts = [
        'last_repeat_at' => 'datetime',
        'last_notified_at' => 'datetime',
        'closed_at' => 'datetime',
        'repeat_count' => 'integer',
        'notify_rounds' => 'integer',
        'recipients_count' => 'integer',
    ];

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class)->withTrashed();
    }

    public function elevator(): BelongsTo
    {
        return $this->belongsTo(Elevator::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by')->withTrashed();
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(EmergencyDelivery::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::OPEN);
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /** «Torre Norte · Ascensor 2» */
    public function placeLabel(): string
    {
        return trim(($this->building?->name ?? 'Edificio').($this->elevator ? ' · '.$this->elevator->displayName() : ''));
    }
}

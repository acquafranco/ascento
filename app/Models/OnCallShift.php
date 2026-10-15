<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Asignación efectiva de una guardia: un técnico durante un período (ends_at
 * null = permanente). Dentro del período, cubre las ventanas de la guardia.
 * Nunca se borra ni se reescribe: un cambio la marca «reemplazada» o
 * «cancelada» y crea los turnos nuevos (OnCallService). Así, lo que ya
 * ocurrió sigue a nombre de quien estaba asignado.
 */
class OnCallShift extends Model
{
    use BelongsToCompany;

    public const ACTIVE = 'active';

    public const REPLACED = 'replaced';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::ACTIVE => 'Vigente', self::REPLACED => 'Modificado', self::CANCELLED => 'Cancelado'];

    public const ORIGINS = ['manual' => 'Asignación', 'rotation' => 'Rotación', 'replacement' => 'Cambio de guardia'];

    protected $fillable = ['on_call_schedule_id', 'user_id', 'starts_at', 'ends_at', 'origin', 'parent_shift_id'];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'closed_at' => 'datetime',
        'reminded_at' => 'datetime',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(OnCallSchedule::class, 'on_call_schedule_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_shift_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_shift_id');
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'on_call_shift_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE);
    }

    /** Turnos que se cruzan con [$from, $to) ($to null = sin fin). */
    public function scopeOverlapping(Builder $query, CarbonInterface $from, ?CarbonInterface $to): Builder
    {
        return $query
            ->when($to, fn ($q) => $q->where('starts_at', '<', $to))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $from));
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function includes(CarbonInterface $at): bool
    {
        return $this->starts_at->lte($at) && ($this->ends_at === null || $this->ends_at->gt($at));
    }

    public function periodLabel(): string
    {
        return $this->starts_at->format('d/m/Y H:i').' – '.($this->ends_at ? $this->ends_at->format('d/m/Y H:i') : 'sin fecha de fin');
    }

    public function label(): string
    {
        return trim(($this->schedule?->name ?? 'Guardia').' · '.($this->user?->name ?? 'Técnico').' · '.$this->periodLabel());
    }
}

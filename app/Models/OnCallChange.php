<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Registro inmutable de un cambio en una guardia (quién, cuándo, qué período y por qué). */
class OnCallChange extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    public const ACTIONS = [
        'assigned' => 'Asignación',
        'replaced' => 'Cambio de guardia',
        'cancelled' => 'Cancelación',
        'ended' => 'Finalización',
        'rotation_updated' => 'Rotación actualizada',
        'schedule_updated' => 'Guardia modificada',
        'schedule_finished' => 'Guardia finalizada',
    ];

    protected $fillable = [
        'on_call_schedule_id', 'on_call_shift_id', 'action', 'from_user_id', 'to_user_id',
        'period_start', 'period_end', 'reason', 'summary', 'user_id',
    ];

    protected $casts = [
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Historial: no se modifica ni se borra una vez escrito.
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(OnCallSchedule::class, 'on_call_schedule_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(OnCallShift::class, 'on_call_shift_id');
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id')->withTrashed();
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id')->withTrashed();
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    public function actionLabel(): string
    {
        return self::ACTIONS[$this->action] ?? $this->action;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Historial de un reclamo. Inmutable: solo se agregan filas. */
class ClientClaimEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['public' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withoutGlobalScopes()->withTrashed();
    }

    /** Texto del evento (para el panel; el portal usa solo los públicos). */
    public function label(): string
    {
        return match ($this->type) {
            'created' => 'Reclamo registrado',
            'status' => 'Estado: '.(ClientClaim::STATUSES[$this->from] ?? $this->from).' → '.(ClientClaim::STATUSES[$this->to] ?? $this->to),
            'assigned' => $this->to ? 'Asignado a '.$this->to : 'Sin responsable',
            'note' => 'Nota interna',
            'reply' => 'Respuesta al cliente',
            'work_order' => 'Orden de trabajo creada',
            default => $this->type,
        };
    }
}

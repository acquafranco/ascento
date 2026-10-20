<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Aviso de un reporte a una persona: se registra el intento, no la lectura.
 * «Leído» solo si esa persona abrió el aviso en Ascento (read_at real).
 */
class ClientClaimDelivery extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    public const AUDIENCES = ['company' => 'Empresa', 'technician' => 'Técnico del edificio', 'client' => 'Administración'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(DatabaseNotification::class, 'notification_id');
    }

    /** En palabras, sin afirmar más de lo que se sabe. */
    public function stateLabel(): string
    {
        return match (true) {
            $this->failure !== null => 'No se pudo avisar ('.$this->failure.')',
            $this->notification?->read_at !== null => 'Aviso abierto el '.$this->notification->read_at->format('d/m H:i'),
            default => 'Aviso registrado en Ascento (sin abrir)',
        };
    }
}

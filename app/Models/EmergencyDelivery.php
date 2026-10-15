<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Aviso de una solicitud de ayuda a un destinatario: si se creó el aviso
 * interno, si se intentó el push y el estado del correo (que se registra en
 * la notificación: enviado o fallido). Sirve para auditar y reintentar; NO
 * indica que la persona lo haya leído.
 */
class EmergencyDelivery extends Model
{
    use BelongsToCompany;

    public const AUDIENCES = ['company' => 'Empresa', 'technician' => 'Técnico de guardia', 'consortium' => 'Administración del edificio'];

    protected $fillable = [];

    protected $casts = [
        'push_attempted' => 'boolean',
        'mail_expected' => 'boolean',
        'mail_attempts' => 'integer',
        'round' => 'integer',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(EmergencyRequest::class, 'emergency_request_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(DatabaseNotification::class, 'notification_id');
    }

    /** «Aviso en Ascento · correo aceptado por el servidor de correo» / «… · correo fallido». */
    public function summary(): string
    {
        if (! $this->notification_id) {
            return 'No se pudo avisar'.($this->failure ? ' ('.$this->failure.')' : '');
        }

        $parts = ['Aviso en Ascento'];
        if ($this->push_attempted) {
            $parts[] = 'push intentado';
        }
        if ($this->mail_expected) {
            $n = $this->notification;
            $parts[] = match (true) {
                (bool) $n?->mailed_at => 'correo aceptado por el servidor de correo',
                (bool) $n?->mail_failed_at => 'correo fallido'.($this->mail_attempts > 1 ? " ({$this->mail_attempts} intentos)" : ''),
                default => 'correo pendiente',
            };
        }
        if ($this->notification?->read_at) {
            $parts[] = 'leído en Ascento';
        }

        return implode(' · ', $parts);
    }
}

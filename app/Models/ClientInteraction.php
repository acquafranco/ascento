<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Contacto con un cliente (Etapa 6, PR 3): fecha, tipo, resultado,
 * observaciones y quién lo registró (con copia de su nombre, para que se
 * conserve aunque el usuario se desactive o se borre). Complementa los
 * presupuestos, órdenes y avisos: puede referenciarlos, no los copia. No se
 * borra desde la app (es historial); se puede corregir y queda registrado.
 */
class ClientInteraction extends Model
{
    use BelongsToCompany;

    public const TYPES = ['call' => 'Llamada', 'email' => 'Correo', 'whatsapp' => 'WhatsApp', 'visit' => 'Visita', 'meeting' => 'Reunión', 'note' => 'Nota'];

    public const RESULTS = [
        'resolved' => 'Resuelto',
        'follow_up' => 'Requiere seguimiento',
        'awaiting_reply' => 'Esperando respuesta',
        'no_answer' => 'Sin respuesta',
        'informative' => 'Informativo',
    ];

    protected $guarded = ['id', 'company_id', 'user_id', 'user_name'];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function building()
    {
        return $this->belongsTo(Building::class)->withTrashed();
    }

    public function quote()
    {
        return $this->belongsTo(Quote::class)->withTrashed();
    }

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class)->withTrashed();
    }

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** Días en los que un contacto «requiere seguimiento» o «esperando respuesta» sigue pendiente. */
    public const FOLLOW_UP_DAYS = 60;

    /** Contactos recientes que esperan una acción (ficha del cliente e inicio). */
    public function scopeNeedsFollowUp($query)
    {
        return $query->whereIn('result', ['follow_up', 'awaiting_reply'])
            ->where('occurred_at', '>=', now()->subDays(self::FOLLOW_UP_DAYS));
    }
}

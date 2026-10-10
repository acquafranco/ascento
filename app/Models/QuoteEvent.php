<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Historial de un presupuesto (quién hizo qué y cuándo). Solo se agregan filas. */
class QuoteEvent extends Model
{
    public const UPDATED_AT = null;

    public const LABELS = [
        'created' => 'Creado',
        'sent' => 'Enviado por correo',
        'link' => 'Enlace generado',
        'status' => 'Cambio de estado',
        'duplicated' => 'Duplicado',
        'link_revoked' => 'Enlaces anteriores anulados',
        'receivable' => 'Cobro generado',
    ];

    protected $fillable = [];

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Acción sensible registrada (Etapa 6): quién (con copia de su nombre y rol,
 * para que se conserve aunque el usuario se desactive o se borre), qué, sobre
 * qué registro y cuándo. Solo la escribe ActivityLogger; la app no la edita
 * ni la borra (se borra con la empresa, si el SuperAdmin la elimina).
 */
class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $casts = [
        'changes' => 'array',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * Registro de cada webhook recibido (auditoría). No guarda el body: no
 * hace falta, el estado real siempre se consulta a la API del proveedor.
 */
class WebhookEvent extends Model
{
    use MassPrunable;

    protected $fillable = [
        'provider',
        'topic',
        'resource_id',
        'request_id',
        'signature_valid',
        'result',
        'processed_at',
    ];

    protected $casts = [
        'signature_valid' => 'boolean',
        'processed_at' => 'datetime',
    ];

    public function prunable()
    {
        return static::where('created_at', '<', now()->subDays(90));
    }
}

<?php

namespace App\Models\Marketplace;

use Illuminate\Database\Eloquent\Model;

/**
 * Historial de un pedido: cambios de estado, comentarios entre las partes,
 * incidencias reportadas a Ascento y su resolución. Solo se agregan filas:
 * nada de esto se edita ni se borra desde la aplicación.
 */
class MarketplaceRequestEvent extends Model
{
    public const UPDATED_AT = null;

    public const STATUS = 'status';

    public const NOTE = 'note';

    public const INCIDENT = 'incident';

    public const RESOLVED = 'resolved';

    protected $guarded = ['id'];

    protected $casts = ['created_at' => 'datetime'];

    public function actorLabel(): string
    {
        return match ($this->actor) {
            'buyer' => 'Comprador',
            'supplier' => 'Proveedor',
            default => 'Ascento',
        };
    }

    /** Qué pasó, en palabras (para el historial de las dos partes y del SuperAdmin). */
    public function label(): string
    {
        return match ($this->kind) {
            self::NOTE => 'Comentario',
            self::INCIDENT => 'Incidencia reportada a Ascento',
            self::RESOLVED => 'Incidencia resuelta por Ascento',
            default => MarketplaceRequest::STATUSES[$this->to] ?? $this->to,
        };
    }
}

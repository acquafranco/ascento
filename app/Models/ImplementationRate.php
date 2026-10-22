<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tarifas del servicio de implementación inicial (una sola fila). Las edita
 * el SuperAdmin; sin publicar, la calculadora no muestra importes.
 */
class ImplementationRate extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'base_fee' => 'decimal:2', 'per_client' => 'decimal:2', 'per_building' => 'decimal:2', 'per_elevator' => 'decimal:2',
        'factor_tidy' => 'decimal:2', 'factor_mixed' => 'decimal:2', 'factor_paper' => 'decimal:2',
        'training_fee' => 'decimal:2', 'launch_support_fee' => 'decimal:2', 'max_quote' => 'decimal:2',
        'included_clients' => 'integer', 'included_buildings' => 'integer', 'included_elevators' => 'integer',
        'published' => 'boolean',
    ];

    public static function current(): self
    {
        return static::query()->orderBy('id')->first() ?? new static([
            'currency' => 'ARS', 'included_clients' => 0, 'included_buildings' => 0, 'included_elevators' => 0,
            'factor_tidy' => 1, 'factor_mixed' => 1.25, 'factor_paper' => 1.5, 'published' => false,
        ]);
    }

    /** ¿Están completas y publicadas las tarifas obligatorias? */
    public function isReady(): bool
    {
        return $this->published && $this->base_fee !== null && $this->per_client !== null && $this->per_building !== null && $this->per_elevator !== null;
    }
}

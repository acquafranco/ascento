<?php

namespace App\Models;

use App\Enums\PlanFeature;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Un cambio en el catálogo de planes (Etapa 9.2). Es un historial: las filas
 * solo se crean (PlanFeatureManager); no se editan ni se borran. Lo consulta
 * el SuperAdmin en «Planes».
 */
class PlanFeatureChange extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $casts = [
        'enabled_before' => 'boolean',
        'enabled_after' => 'boolean',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('El historial de planes no se modifica.'));
        static::deleting(fn () => throw new LogicException('El historial de planes no se borra.'));
    }

    public function featureLabel(): string
    {
        return PlanFeature::tryFrom($this->feature)?->label() ?? $this->feature;
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una prueba de paracaídas REALIZADA (Etapa 7): fecha, resultado, quién la
 * hizo, inspector o profesional responsable y observaciones. Una prueba
 * prevista no es un registro: es la próxima fecha que calcula
 * ParachuteService. No se borra (historial); los cambios quedan en el
 * registro de actividad.
 */
class ParachuteTest extends Model
{
    use BelongsToCompany;

    public const SATISFACTORY = 'satisfactory';

    public const UNSATISFACTORY = 'unsatisfactory';

    public const RESULTS = [
        self::SATISFACTORY => 'Satisfactoria',
        self::UNSATISFACTORY => 'No satisfactoria',
    ];

    protected $fillable = [];

    protected $casts = [
        'performed_on' => 'date',
    ];

    public function elevator(): BelongsTo
    {
        return $this->belongsTo(Elevator::class);
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** Remito en el que el técnico la registró (null: cargada desde el panel). */
    public function deliveryNote(): BelongsTo
    {
        return $this->belongsTo(DeliveryNote::class)->withoutGlobalScopes();
    }

    public function files(): HasMany
    {
        return $this->hasMany(ParachuteTestFile::class);
    }

    public function isSatisfactory(): bool
    {
        return $this->result === self::SATISFACTORY;
    }

    /** Quién la realizó: el usuario o el nombre cargado. */
    public function performerName(): ?string
    {
        return $this->performedBy?->name ?? $this->performed_by_name;
    }
}

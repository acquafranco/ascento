<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Política de la prueba de paracaídas de un ascensor (Etapa 7): su
 * periodicidad (si no tiene, la del cliente; si tampoco, ninguna: Ascento no
 * asume una periodicidad universal), cuántos días antes avisar, el inspector
 * asignado y el responsable. Se gestiona solo desde ParachuteService.
 */
class ParachuteSchedule extends Model
{
    use BelongsToCompany;

    public const DEFAULT_NOTICE_DAYS = 30;

    protected $fillable = [];

    protected $casts = [
        'interval_months' => 'integer',
        'notice_days' => 'integer',
    ];

    public function elevator(): BelongsTo
    {
        return $this->belongsTo(Elevator::class);
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspector_id');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }
}

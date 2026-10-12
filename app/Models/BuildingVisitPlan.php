<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Frecuencia OPERATIVA de visitas de un edificio para un tipo de trabajo
 * (mantenimiento o inspección). No tiene nada que ver con la frecuencia de
 * cobro del servicio (maintenance_services.frequency) y nunca crea servicios
 * ni cobros.
 *
 * - Mensual: 1 visita por mes (lo mismo que un edificio sin plan).
 * - Quincenal: 2 visitas en el mes.
 * - Cada N meses (2..12): 1 visita por período de N meses, contados desde
 *   el mes de inicio (starts_on).
 *
 * Cada cambio es una fila nueva que rige desde su mes de inicio: los meses
 * anteriores conservan la frecuencia que tenían.
 */
class BuildingVisitPlan extends Model
{
    use BelongsToCompany;

    public const TYPES = ['maintenance' => 'Mantenimiento', 'inspection' => 'Inspección'];

    /** Opciones de la interfaz: clave => [etiqueta, visitas por mes, cada cuántos meses]. */
    public const FREQUENCIES = [
        'monthly' => ['Mensual', 1, 1],
        'biweekly' => ['Quincenal (2 visitas por mes)', 2, 1],
        'months' => ['Cada N meses', 1, null],
    ];

    public const MONTH_NAMES = [2 => 'Bimestral', 3 => 'Trimestral', 4 => 'Cuatrimestral', 6 => 'Semestral', 12 => 'Anual'];

    protected $fillable = ['building_id', 'type', 'visits_per_month', 'every_months', 'starts_on'];

    protected $casts = [
        'visits_per_month' => 'integer',
        'every_months' => 'integer',
        'starts_on' => 'date',
    ];

    protected static function booted(): void
    {
        static::saving(function (BuildingVisitPlan $plan) {
            $plan->every_months = max(1, min(12, (int) $plan->every_months ?: 1));
            // Dos visitas por mes solo en un plan mensual.
            $plan->visits_per_month = $plan->every_months === 1 ? max(1, min(2, (int) $plan->visits_per_month ?: 1)) : 1;
            $plan->starts_on = Carbon::parse($plan->starts_on ?? now())->startOfMonth();

            abort_unless(array_key_exists($plan->type, self::TYPES), 422);

            // La empresa del plan es la de su edificio.
            $building = Building::withoutGlobalScopes()->withTrashed()->find($plan->building_id);
            abort_unless($building, 422);
            $plan->company_id = $building->company_id;
        });

        // Después del scope de empresa (que pone la empresa del usuario): un
        // plan nunca puede quedar en una empresa distinta de la del edificio.
        static::creating(function (BuildingVisitPlan $plan) {
            $building = Building::withoutGlobalScopes()->withTrashed()->find($plan->building_id);
            abort_unless($building && (int) $building->company_id === (int) $plan->company_id, 404);
        });
    }

    public function building()
    {
        return $this->belongsTo(Building::class)->withTrashed();
    }

    public function frequencyKey(): string
    {
        return match (true) {
            $this->visits_per_month === 2 => 'biweekly',
            $this->every_months > 1 => 'months',
            default => 'monthly',
        };
    }

    public function label(): string
    {
        return static::labelFor((int) $this->visits_per_month, (int) $this->every_months);
    }

    public static function labelFor(int $visitsPerMonth, int $everyMonths): string
    {
        return match (true) {
            $visitsPerMonth === 2 => 'Quincenal (2 por mes)',
            $everyMonths > 1 => (self::MONTH_NAMES[$everyMonths] ?? 'Cada '.$everyMonths.' meses').($everyMonths > 1 && isset(self::MONTH_NAMES[$everyMonths]) ? ' (cada '.$everyMonths.' meses)' : ''),
            default => 'Mensual',
        };
    }
}

<?php

namespace App\Services\Visits;

use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\User;
use App\Models\VisitPhoto;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ciclo de vida de una visita periódica (mantenimiento o inspección).
 *
 * - "Visita en curso": el técnico la abre desde Visitas para cargar fotos
 *   antes, durante o después del trabajo. Ocupa su lugar en el período
 *   (identidad única, como cualquier visita) pero NO cuenta como realizada.
 * - Al firmar el remito, el remito COMPLETA esa misma visita (no se crea
 *   otra). Sin visita en curso, el remito crea la visita como siempre.
 * - Mientras no tenga remito, se puede descartar (con sus fotos).
 *
 * Abrirla dos veces (doble toque, reintento, dos técnicos a la vez) devuelve
 * la misma visita: nunca se crean visitas adicionales.
 */
class VisitService
{
    public const TYPES = VisitSchedule::TYPES;

    /** Días sin completarse a partir de los cuales una visita en curso se avisa a la oficina. */
    public const STALE_DAYS = 7;

    /**
     * Número de visita libre del período (1 o 2), o el motivo por el que no
     * se puede registrar otra. Las visitas sin identidad (duplicados
     * históricos) no cuentan; los remitos viejos sin visita sí ocupan lugar.
     */
    public function slotFor(Building $building, string $type, int $month, int $year): int|string
    {
        $eval = VisitSchedule::load([$building->id], $month, $year, (int) $building->company_id)
            ->evaluate($building->id, $type, $month, $year);
        $period = $eval['period'];

        $legacy = DeliveryNote::withoutGlobalScopes()
            ->where('company_id', $building->company_id)
            ->where('building_id', $building->id)
            ->where('assignment_type', $type)
            ->whereNull('building_visit_id')
            ->whereRaw('(year * 12 + month) between ? and ?', [VisitPeriod::index($period->start), VisitPeriod::index($period->end)])
            ->count();

        if ($eval['free_slot'] !== null && $eval['visits']->count() + $legacy < $period->expected) {
            return $eval['free_slot'];
        }

        return match (true) {
            $period->expected > 1 => 'Ya se registraron las '.$period->expected.' visitas de este mes.',
            $period->isMultiMonth() => 'La visita de este período ('.$period->label().') ya se registró.',
            default => 'Este remito ya fue generado: el edificio ya tiene el registro de este mes.',
        };
    }

    /** La visita en curso del período que contiene ese mes (la de menor número), si hay. */
    public function openVisit(Building $building, string $type, int $month, int $year, bool $lock = false): ?BuildingVisit
    {
        $period = VisitSchedule::load([$building->id], $month, $year, (int) $building->company_id)
            ->period($building->id, $type, $month, $year);

        return BuildingVisit::withoutGlobalScopes()
            ->where('company_id', $building->company_id)
            ->where('building_id', $building->id)
            ->where('visit_type', 'fixed')
            ->where('assignment_type', $type)
            ->where('in_progress', true)
            ->whereRaw('(year * 12 + month) between ? and ?', [VisitPeriod::index($period->start), VisitPeriod::index($period->end)])
            ->orderBy('slot')->orderBy('id')
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->first();
    }

    /**
     * Abre (o devuelve, si ya está abierta) la visita en curso del período
     * para cargarle fotos. Revalida todo en el servidor: empresa, equipos,
     * asignación, fotos habilitadas para el tipo y lugar libre en el período.
     */
    public function start(User $user, Building $building, string $type, int $month, int $year): BuildingVisit
    {
        abort_unless(in_array($type, self::TYPES, true), 404);
        abort_unless((int) $building->company_id === (int) $user->company_id, 404);
        $this->ensureCanWork($user, $building, $type);

        $company = Company::findOrFail($building->company_id);

        if (! $company->photosEnabledFor($type)) {
            throw ValidationException::withMessages(['visit' => 'La carga de fotos no está habilitada para este tipo de trabajo.']);
        }

        if (! $building->hasEquipment()) {
            throw ValidationException::withMessages(['visit' => 'El edificio no tiene ascensores ni montacargas registrados. Avisale a la oficina.']);
        }

        return DB::transaction(function () use ($user, $building, $type, $month, $year) {
            // Mismo bloqueo que el alta de remitos: un remito y una visita
            // nueva del mismo período nunca se cruzan.
            Company::whereKey($building->company_id)->lockForUpdate()->first();

            if ($open = $this->openVisit($building, $type, $month, $year)) {
                return $open;
            }

            $slot = $this->slotFor($building, $type, $month, $year);

            if (is_string($slot)) {
                throw ValidationException::withMessages(['visit' => $slot]);
            }

            $visit = new BuildingVisit([
                'company_id' => $building->company_id,
                'building_id' => $building->id,
                'user_id' => $user->id,
                'visit_type' => 'fixed',
                'assignment_type' => $type,
                'month' => $month,
                'year' => $year,
                'slot' => $slot,
                'status' => 'done',
                'visited_at' => now(),
                'source' => 'building',
                'in_progress' => true,
            ]);
            $visit->started_at = now();
            $visit->save();
            $visit->participants()->syncWithPivotValues([$user->id], ['role' => 'creator']);

            return $visit;
        });
    }

    /**
     * Completa la visita en curso con el remito (dentro de la transacción del
     * remito, con la empresa ya bloqueada): misma visita, ahora realizada.
     */
    public function complete(BuildingVisit $visit, User $user, int $month, int $year, ?string $submissionToken): BuildingVisit
    {
        $visit->forceFill([
            'in_progress' => false,
            'user_id' => $user->id,
            'month' => $month,
            'year' => $year,
            'visited_at' => now(),
            'finished_at' => now(),
            'status' => 'done',
            'submission_token' => $submissionToken,
        ])->save();

        return $visit;
    }

    /**
     * Descarta una visita en curso sin remito (con sus fotos y archivos).
     * La puede descartar quien puede trabajar en ella; una visita con remito
     * es historial y no se toca.
     */
    public function discard(BuildingVisit $visit, User $user): void
    {
        DB::transaction(function () use ($visit, $user) {
            Company::whereKey($visit->company_id)->lockForUpdate()->first();

            $fresh = BuildingVisit::withoutGlobalScopes()->whereKey($visit->id)->where('in_progress', true)->lockForUpdate()->first();

            if (! $fresh || DeliveryNote::withoutGlobalScopes()->where('building_visit_id', $fresh->id)->exists()) {
                throw ValidationException::withMessages(['visit' => 'Esta visita ya tiene remito: no se puede descartar.']);
            }

            $this->ensureCanWork($user, Building::withoutGlobalScopes()->findOrFail($fresh->building_id), $fresh->assignment_type);

            // Un técnico no borra (al descartar) fotos que cargó otro: misma
            // regla que quitar una foto. La oficina puede descartarla entera.
            if (! $user->isAdmin() && VisitPhoto::withoutGlobalScopes()->where('building_visit_id', $fresh->id)
                ->where(fn ($q) => $q->whereNull('uploaded_by')->orWhere('uploaded_by', '!=', $user->id))->exists()) {
                throw ValidationException::withMessages(['visit' => 'Esta visita tiene fotos que cargó otra persona: solo la oficina puede descartarla.']);
            }

            $fresh->delete();
        });
    }

    /**
     * Visitas en curso (abiertas para cargar fotos, sin remito) que llevan
     * más de STALE_DAYS días sin completarse, de UNA empresa. Solo se
     * informan: nunca se cierran ni se borran solas (cerrarla inventaría un
     * trabajo sin remito; descartarla borraría fotos). La oficina las abre y
     * decide si se continúan o se descartan.
     *
     * @return Collection<int, BuildingVisit>
     */
    public function stale(int $companyId, int $days = self::STALE_DAYS): Collection
    {
        $cutoff = now()->subDays($days);

        return BuildingVisit::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('visit_type', 'fixed')
            ->whereIn('assignment_type', self::TYPES)
            ->where('in_progress', true)
            ->where(fn ($q) => $q->where('started_at', '<=', $cutoff)
                ->orWhere(fn ($w) => $w->whereNull('started_at')->where('created_at', '<=', $cutoff)))
            ->with(['building' => fn ($q) => $q->withoutGlobalScopes()->withTrashed()->select('id', 'name'), 'user:id,name'])
            ->withCount(['photos' => fn ($q) => $q->withoutGlobalScopes()->reorder()])
            ->orderByRaw('coalesce(started_at, created_at)')
            ->get();
    }

    /**
     * Técnico asignado al edificio para ese tipo de trabajo (los admins de la
     * empresa también pueden). Lo mismo que se exige para firmar el remito.
     */
    public function ensureCanWork(User $user, Building $building, string $type): void
    {
        abort_unless((int) $building->company_id === (int) $user->company_id, 404);

        if ($user->isAdmin()) {
            return;
        }

        abort_unless(
            in_array($type, self::TYPES, true)
                && $building->users()->where('users.id', $user->id)->wherePivot('type', $type)->exists(),
            403,
            'Este edificio no está asignado a vos para este trabajo.'
        );
    }
}

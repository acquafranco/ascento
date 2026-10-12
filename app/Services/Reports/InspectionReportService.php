<?php

namespace App\Services\Reports;

use App\Enums\PlanFeature;
use App\Models\BuildingVisit;
use App\Models\Company;
use App\Models\Report;
use App\Models\ReportPhoto;
use App\Models\User;
use App\Services\Visits\PhotoTarget;
use App\Services\Visits\VisitPhotoService;
use App\Support\Plans\PlanGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reporte de una inspección: observaciones y fotos del inspector, en un
 * reporte (el mismo sistema de reportes: fotos privadas, PDF, panel) de tipo
 * «inspección», uno por visita. No es una reparación: sin prioridad ni
 * estado a la vista, sin aviso de falla y sin consumir el cupo de reportes.
 *
 * - Quién: quien trabaja en esa inspección (inspector asignado al edificio,
 *   quien hizo la visita o participó) y el admin de la empresa. Lo edita
 *   quien lo creó o el admin.
 * - Fotos: solo con las fotos de inspecciones habilitadas en «Mi empresa»
 *   (apagadas: el reporte se hace igual, solo con observaciones). Las ya
 *   cargadas se conservan al desactivarlas.
 * - Todo verificado acá (empresa, visita de inspección, asignación), bajo
 *   bloqueo de la visita: dos envíos simultáneos no crean dos reportes.
 */
class InspectionReportService
{
    public function __construct(private VisitPhotoService $visitPhotos, private ReportPhotoService $photos) {}

    /** Visita de inspección de ESA empresa (también en curso). Otra cosa → 404. */
    public function visit(Company $company, int $visitId): BuildingVisit
    {
        // Reporte de inspección: exclusivo del plan Empresa (también ver uno ya hecho).
        PlanGuard::for($company)->ensureFeature(PlanFeature::WorkPhotos);

        $visit = BuildingVisit::withoutGlobalScopes()->where('company_id', $company->id)->findOrFail($visitId);

        abort_unless($visit->visit_type === 'fixed' && $visit->assignment_type === 'inspection', 404);

        return $visit;
    }

    public function canWorkOn(?User $user, BuildingVisit $visit): bool
    {
        return $this->visitPhotos->canWorkOn($user, PhotoTarget::forVisit($visit));
    }

    public function forVisit(BuildingVisit $visit): ?Report
    {
        return Report::withoutGlobalScopes()
            ->where('company_id', $visit->company_id)
            ->where('kind', Report::KIND_INSPECTION)
            ->where('building_visit_id', $visit->id)
            ->whereNull('deleted_at') // uno en la papelera no se muestra
            ->first();
    }

    public function canEdit(?User $user, BuildingVisit $visit, ?Report $report): bool
    {
        if (! $this->canWorkOn($user, $visit)) {
            return false;
        }

        return $report === null || $user->isAdmin() || (int) $report->user_id === (int) $user->id;
    }

    public function photosEnabled(BuildingVisit $visit): bool
    {
        return (bool) Company::find($visit->company_id)?->photosEnabledFor('inspection');
    }

    /**
     * Crea o actualiza el reporte de la visita y le agrega fotos.
     *
     * @param  array<int, UploadedFile>  $files
     */
    public function save(User $user, BuildingVisit $visit, string $observations, array $files = []): Report
    {
        abort_unless($this->canWorkOn($user, $visit), 404);

        $observations = trim($observations);

        if (mb_strlen($observations) < 5) {
            throw ValidationException::withMessages(['observations' => 'Escribí las observaciones de la inspección (al menos 5 caracteres).']);
        }

        if ($files !== [] && ! $this->photosEnabled($visit)) {
            throw ValidationException::withMessages(['photos' => 'Las fotos de inspecciones están desactivadas en «Mi empresa». Guardá el reporte sin fotos.']);
        }

        $report = DB::transaction(function () use ($user, $visit, $observations) {
            BuildingVisit::withoutGlobalScopes()->whereKey($visit->id)->lockForUpdate()->first();

            $report = Report::withoutGlobalScopes()->withTrashed()
                ->where('company_id', $visit->company_id)->where('building_visit_id', $visit->id)->first();

            if ($report && ! $report->isInspection()) {
                abort(409);
            }

            if ($report && ! $user->isAdmin() && (int) $report->user_id !== (int) $user->id) {
                throw ValidationException::withMessages(['observations' => 'Este reporte lo hizo otro inspector: solo él o la oficina pueden editarlo.']);
            }

            $report ??= new Report;

            if ($report->trashed()) {
                $report->restore();
            }

            $report->forceFill([
                'company_id' => $visit->company_id,
                'building_id' => $visit->building_id,
                'building_visit_id' => $visit->id,
                'kind' => Report::KIND_INSPECTION,
                'user_id' => $report->user_id ?? $user->id,
                'description' => mb_substr($observations, 0, 5000),
                // Sin prioridad ni estado de falla: valores neutros que no disparan alertas.
                'priority' => 'baja',
                'status' => 'resuelto',
            ])->save();

            return $report;
        });

        if ($files !== []) {
            $this->photos->add($report, $files);
        }

        return $report->fresh(['photos']);
    }

    /** Quitar una foto del reporte: quien puede editarlo, con las fotos habilitadas. */
    public function removePhoto(User $user, BuildingVisit $visit, int $photoId): void
    {
        $report = $this->forVisit($visit);

        abort_unless($report && $this->canEdit($user, $visit, $report), 404);

        if (! $this->photosEnabled($visit)) {
            throw ValidationException::withMessages(['photos' => 'Las fotos de inspecciones están desactivadas: las ya cargadas se conservan.']);
        }

        $photo = ReportPhoto::withoutGlobalScopes()->where('company_id', $report->company_id)->where('report_id', $report->id)->findOrFail($photoId);
        $photo->delete();
    }
}

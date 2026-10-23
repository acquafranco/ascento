<?php

namespace App\Models;

use App\Enums\PermissionArea;
use App\Enums\PlanLimit;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\ConsumesPlanLimit;
use App\Models\Concerns\SharesWithClient;
use App\Services\Visits\PhotoTarget;
use App\Services\Visits\VisitPhotoService;
use App\Support\Access\Access;
use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Report extends Model
{
    /** Estados del reporte (mismos textos en panel, portal y PDF). */
    public const STATUS_LABELS = ['pendiente' => 'Pendiente', 'en_revision' => 'En revisión', 'resuelto' => 'Resuelto'];

    public const PRIORITY_LABELS = ['baja' => 'Baja', 'media' => 'Media', 'alta' => 'Alta', 'critica' => 'Crítica'];

    /**
     * Tipo: reporte de falla (el de siempre: prioridad, estado, avisos, cupo
     * del plan) o reporte de una inspección (observaciones y fotos del
     * inspector, vinculado a su visita: no es una reparación, así que no
     * cuenta en fallas, alertas, indicadores ni en el cupo de reportes).
     * Ni el tipo ni la visita son asignables desde un formulario.
     */
    public const KIND_INCIDENT = 'incident';

    public const KIND_INSPECTION = 'inspection';

    /**
     * Un trabajo de una intervención que abrió el técnico (urgencia o trabajo
     * no programado): va vinculado a la orden de esa visita (work_order_id) y
     * se documenta en su remito. No consume el cupo de reportes: una urgencia
     * nunca se bloquea por el plan (ver InterventionService).
     */
    public const KIND_INTERVENTION = 'intervention';

    public const KIND_LABELS = [self::KIND_INCIDENT => 'Falla', self::KIND_INSPECTION => 'Inspección', self::KIND_INTERVENTION => 'Intervención'];

    /** Por dónde llegó el reclamo que atendió el técnico. */
    public const CHANNELS = [
        'whatsapp' => 'WhatsApp',
        'phone' => 'Llamada telefónica',
        'building' => 'Encargado o vecino en el edificio',
        'detected' => 'Detectado durante la visita',
        'other' => 'Otro',
    ];

    /** Cómo quedó cada trabajo (se indica al firmar el remito). */
    public const RESULTS = [
        'resolved' => 'Resuelto',
        'partial' => 'Resuelto parcialmente',
        'follow_up' => 'Requiere otra visita',
        'not_resolved' => 'No se pudo resolver',
    ];

    use BelongsToCompany;
    use ConsumesPlanLimit;
    use HasFactory, SoftDeletes;
    use SharesWithClient;

    protected $fillable = [

        'company_id',
        'user_id',
        // 'kind' y 'building_visit_id' no son asignables (ver InspectionReportService).
        'building_id',
        'elevator_number',
        'component',
        // 'photo' (columna vieja, una sola foto) ya no se escribe: ver photos().
        'description',
        'observations',
        'priority',
        'status',

    ];

    protected static function booted(): void
    {
        // Borrado definitivo: se borran sus fotos y archivos (sin huérfanos).
        // El borrado común (soft delete) conserva todo: es historial.
        static::forceDeleting(function (Report $report) {
            $report->photos()->get()->each->delete();
        });
    }

    /** Fotos del reporte, en orden. */
    /** Video del reporte (uno como máximo). */
    public function video()
    {
        return $this->hasOne(ReportVideo::class);
    }

    public function photos()
    {
        return $this->hasMany(ReportPhoto::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Quién puede ver el reporte, sus fotos y su PDF: los admins de la
     * empresa (y el SuperAdmin dentro de la empresa elegida) y el técnico que
     * lo hizo. La empresa ya la filtra el scope global al buscar el reporte.
     */
    public function canBeViewedBy(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        // Reporte de inspección: exclusivo del plan Empresa (se conserva, sin acceso).
        if ($this->isInspection() && ! Company::hasWorkPhotosById((int) $this->company_id)) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return (int) CompanyContext::currentId() === (int) $this->company_id;
        }

        if ((int) $user->company_id !== (int) $this->company_id) {
            return false;
        }

        // Dueño, o administrativo con «Inspecciones y reportes» (Access).
        if (Access::can($user, PermissionArea::Inspections) || (int) $this->user_id === (int) $user->id) {
            return true;
        }

        // Reporte de inspección: también quien trabaja en esa inspección
        // (inspector asignado al edificio o que participó de la visita).
        if ($this->isInspection() && $this->building_visit_id
            && ($visit = BuildingVisit::withoutGlobalScopes()->where('company_id', $this->company_id)->find($this->building_visit_id))) {
            return app(VisitPhotoService::class)->canWorkOn($user, PhotoTarget::forVisit($visit));
        }

        return false;
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function building()
    {
        return $this->belongsTo(Building::class)->withTrashed();
    }

    /**
     * Cupo mensual de reportes (al reactivar uno borrado no se vuelve a
     * contar: ya contaba). El reporte de una inspección es parte de la
     * inspección: no consume cupo.
     */
    public function planLimit(): ?PlanLimit
    {
        return $this->exists || $this->isInspection() || $this->isIntervention() ? null : PlanLimit::ReportsPerMonth;
    }

    public function isInspection(): bool
    {
        return $this->kind === self::KIND_INSPECTION;
    }

    public function isIntervention(): bool
    {
        return $this->kind === self::KIND_INTERVENTION;
    }

    /** Lo que ve el técnico en «Reportes»: sus fallas y sus intervenciones (las inspecciones van por Visitas). */
    public function scopeTechnicianVisible(Builder $query): Builder
    {
        return $query->whereIn($query->qualifyColumn('kind'), [self::KIND_INCIDENT, self::KIND_INTERVENTION]);
    }

    /** Trabajos de intervenciones (cada problema atendido, con su equipo). */
    public function scopeInterventionJobs(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('kind'), self::KIND_INTERVENTION);
    }

    /**
     * Trabajos de intervenciones cuya orden es de tipo «Reclamo»: los que
     * cuentan como reclamos en indicadores y análisis de fallas. Los de una
     * reparación, un mantenimiento o una instalación no son reclamos.
     */
    public function scopeClaimJobs(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('kind'), self::KIND_INTERVENTION)
            ->whereIn($query->qualifyColumn('work_order_id'), WorkOrder::withoutGlobalScopes()->withTrashed()->where('type', 'claim')->select('id'));
    }

    /** Orden de la visita en la que se hizo este trabajo (solo intervenciones). */
    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class)->withTrashed();
    }

    public function channelLabel(): ?string
    {
        return self::CHANNELS[$this->channel] ?? null;
    }

    public function resultLabel(): ?string
    {
        return self::RESULTS[$this->result] ?? null;
    }

    /** Solo reportes de falla (los de siempre). */
    public function scopeIncidents(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('kind'), self::KIND_INCIDENT);
    }

    /** Visita de la inspección (solo reportes de inspección). */
    public function visit()
    {
        return $this->belongsTo(BuildingVisit::class, 'building_visit_id')->withoutGlobalScopes();
    }
}

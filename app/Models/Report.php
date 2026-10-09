<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\ConsumesPlanLimit;
use App\Enums\PlanLimit;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;

class Report extends Model
{
    use \App\Models\Concerns\SharesWithClient;


    use HasFactory, SoftDeletes;
    use ConsumesPlanLimit;
    use BelongsToCompany;
    protected $fillable = [

        'company_id',
        'user_id',
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

        if ($user->isSuperAdmin()) {
            return (int) \App\Support\CompanyContext::currentId() === (int) $this->company_id;
        }

        if ((int) $user->company_id !== (int) $this->company_id) {
            return false;
        }

        return $user->isAdmin() || (int) $this->user_id === (int) $user->id;
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

    /** Cupo mensual de reportes (al reactivar uno borrado no se vuelve a contar: ya contaba). */
    public function planLimit(): ?PlanLimit
    {
        return $this->exists ? null : PlanLimit::ReportsPerMonth;
    }
}

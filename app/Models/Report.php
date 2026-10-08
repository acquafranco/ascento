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

    use HasFactory, SoftDeletes;
    use ConsumesPlanLimit;
    use BelongsToCompany;
    protected $fillable = [

        'company_id',
        'user_id',
        'building_id',
        'elevator_number',
        'photo',
        'description',
        'priority',
        'status',

    ];


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

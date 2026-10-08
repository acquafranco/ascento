<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\ConsumesPlanLimit;
use App\Enums\PlanLimit;

class Client extends Model
{
    use HasFactory, SoftDeletes;
    use BelongsToCompany;
    use ConsumesPlanLimit;
    protected $fillable = [
        'company_id',
        'name',
        'type',
        'contact_person',
        'phone',
        'email',
        'notes',
        'is_active'
    ];


    public function buildings(): HasMany
    {
        return $this->hasMany(Building::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function planLimit(): ?PlanLimit
    {
        return PlanLimit::Clients;
    }

    public function maintenanceServices(): HasMany
    {
        return $this->hasMany(MaintenanceService::class);
    }

    public function receivables(): HasMany
    {
        return $this->hasMany(Receivable::class);
    }
}

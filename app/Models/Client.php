<?php

namespace App\Models;

use App\Enums\PlanLimit;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\ConsumesPlanLimit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use BelongsToCompany;
    use ConsumesPlanLimit;
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'name',
        'type',
        'contact_person',
        'phone',
        'email',
        'notes',
        'is_active',
    ];

    /** Usuarios del portal del cliente (pueden ser varios). */
    /** Accesos al portal de este cliente (personas autorizadas). */
    public function portalMemberships()
    {
        return $this->hasMany(PortalMembership::class);
    }

    public function portalUsers()
    {
        return $this->hasMany(User::class)->where('role', User::ROLE_CLIENT);
    }

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

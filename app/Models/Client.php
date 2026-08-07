<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Concerns\BelongsToCompany;

class Client extends Model
{
    use BelongsToCompany;
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

//sefwfwf
    public function buildings(): HasMany
    {
        return $this->hasMany(Building::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }


}

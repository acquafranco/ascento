<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    protected $fillable = [
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


}

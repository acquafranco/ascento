<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class BuildingUser extends Pivot
{
    protected $table = 'building_user';

    protected $fillable = [
        'building_id',
        'user_id',
    ];
}


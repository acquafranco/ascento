<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Report extends Model
{

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
        return $this->belongsTo(User::class);
    }


    public function building()
    {
        return $this->belongsTo(Building::class);
    }

}

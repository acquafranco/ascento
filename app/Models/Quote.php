<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Support\Str;


class Quote extends Model
{

    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'building_id',
        'client_id',
        'created_by',
        'title',
        'description',
        'amount',
        'status',
        'priority',
        'public_token',
    ];

    protected static function booted()
    {
        static::creating(function ($quote) {
            $quote->public_token = Str::uuid();
        });
    }

    public function building()
    {
        return $this->belongsTo(Building::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

        public function company()
    {
        return $this->belongsTo(Company::class);
    }
}

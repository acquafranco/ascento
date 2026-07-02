<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Quote extends Model
{
    protected $fillable = [
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
}

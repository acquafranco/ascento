<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

use App\Models\Report;

class Company extends Model
{
    use HasFactory;



    protected $fillable = [

        'name',

        'business_name',

        'cuit',

        'tax_condition',

        'slug',

        'email',

        'phone',

        'address',

        'city',

        'province',

        'logo',

        'primary_color',

        'is_active',

        'whatsapp_access_token',

        'whatsapp_phone_number_id',

        'whatsapp_waba_id',

        'whatsapp_business_id',

        'whatsapp_connected',

    ];

    protected $casts = [
        'is_active' => 'boolean',
        'whatsapp_connected' => 'boolean',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function clients()

    {
        return $this->hasMany(Client::class);
    }

    public function workOrders()
    {
        return $this->hasMany(WorkOrder::class);
    }

    public function buildingVisits()
    {
        return $this->hasMany(BuildingVisit::class);
    }

    public function deliveryNotes()
    {
        return $this->hasMany(DeliveryNote::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

  public function subscriptions(): HasMany
{
    return $this->hasMany(Subscription::class);
}

public function subscription(): HasOne
{
    return $this->hasOne(Subscription::class)
        ->whereIn('status', [
            'authorized',
            'active',
            'trialing',
        ])
        ->whereNotNull('provider_subscription_id')
        ->orderByDesc('id');
}
}


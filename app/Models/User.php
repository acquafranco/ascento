<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use deliveryNotes;

class User extends Authenticatable implements FilamentUser
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'job_type',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->role === 'admin';
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function buildings()
    {
        return $this->belongsToMany(Building::class)
            ->withPivot('type')
            ->withTimestamps();
    }

    public function workOrders()
    {
        return $this->hasMany(WorkOrder::class);
    }

    public function isTechnician(): bool
    {
        return in_array($this->job_type, [
            'maintenance',
            'inspection',
        ]);
    }

    public function buildingVisits()
{
    return $this->hasMany(BuildingVisit::class);
}
public function deliveryNotes()
{
    return $this->hasMany(DeliveryNote::class);
}
}

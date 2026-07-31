<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;


class User extends Authenticatable implements FilamentUser
{

    use HasFactory, Notifiable;


    protected $fillable = [
        'company_id',
        'name',
        'email',
        'password',
        'avatar',
        'role',
        'job_type',
        'phone',
        'is_super_admin',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_super_admin' => 'boolean',
    ];

   public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() !== 'ascensores_app') {
            return false;
        }

        return $this->isSuperAdmin()
            || $this->isAdmin();
    }

        public function isSuperAdmin(): bool
    {
        return $this->is_super_admin;
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
    public function buildingAssignments()
    {
        return $this->belongsToMany(Building::class)
            ->withPivot('type')
            ->withTimestamps();
    }
    public function workOrders()
    {
        return $this->belongsToMany(WorkOrder::class, 'work_order_user')
            ->withTimestamps();
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
        return $this->belongsToMany(
            BuildingVisit::class,
            'building_visit_participants'
        );
    }
    public function deliveryNotes()
    {
        return $this->hasMany(DeliveryNote::class);
    }
    public function setPhoneAttribute($value)
{
    if (!$value) {
        $this->attributes['phone'] = null;
        return;
    }

    $phone = preg_replace('/\D/', '', $value);

    $phone = ltrim($phone, '0');

    if (str_starts_with($phone, '549')) {
        $this->attributes['phone'] = $phone;
        return;
    }

    if (str_starts_with($phone, '54')) {
        $phone = substr($phone, 2);
    }

    $this->attributes['phone'] = '549' . $phone;
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    }

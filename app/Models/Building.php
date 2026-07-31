<?php

namespace App\Models;

    use Illuminate\Database\Eloquent\Model;
    use App\Models\Concerns\BelongsToCompany;

    class Building extends Model
    {

        use BelongsToCompany;

        protected $fillable = [
            'company_id',
            'client_id',
            'name',
            'address',
            'client_name',
            'contact_person',
            'phone',

            'elevator_count',
            'traction_elevator_count',
            'hydraulic_elevator_count',
            'freight_elevator_count',

            'notes',
            'is_active',
            'province',

            'municipality',

            'locality',

            'neighborhood',
        ];

        protected $casts = [
            'is_active' => 'boolean',

            'elevator_count' => 'integer',
            'traction_elevator_count' => 'integer',
            'hydraulic_elevator_count' => 'integer',
            'freight_elevator_count' => 'integer',
        ];

        public function client()
        {
            return $this->belongsTo(Client::class);
        }

        public function users()
        {
            return $this->belongsToMany(User::class)
                ->withPivot('type')
                ->withTimestamps();
        }

        public function workOrders()
        {
            return $this->hasMany(WorkOrder::class);
        }

        public function deliveryNotes()
        {
            return $this->hasMany(DeliveryNote::class);
        }

        public function getFullNameAttribute(): string
        {
            return "{$this->name} - {$this->address}";
        }
        public function company()
        {
            return $this->belongsTo(Company::class);
        }

        public function buildingVisits()
        {
            return $this->hasMany(BuildingVisit::class);
        }



    }

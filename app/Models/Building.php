<?php

namespace App\Models;

    use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
    use Illuminate\Database\Eloquent\Model;
    use App\Models\Concerns\BelongsToCompany;
    use App\Jobs\GeocodeBuilding;
    use App\Services\Geocoding\GeoapifyClient;
    use App\Support\Geocoding\BuildingAddress;

    class Building extends Model
    {

        use HasFactory, SoftDeletes;
        use BelongsToCompany;

        /*
        | Estado de la ubicación en el mapa (geocoding_status).
        */
        public const GEO_PENDING = 'pending';
        public const GEO_GEOCODED = 'geocoded';
        public const GEO_MANUAL = 'manual';
        public const GEO_NEEDS_REVIEW = 'needs_review';
        public const GEO_ERROR = 'error';

        /**
         * Campos que forman la dirección: si cambia alguno, la ubicación
         * guardada deja de valer.
         */
        public const ADDRESS_FIELDS = [
            'name',
            'address',
            'locality',
            'municipality',
            'neighborhood',
            'province',
        ];

        // Las coordenadas y el estado de geocodificación NO son fillable:
        // solo los escriben BuildingGeocoder y la corrección manual del mapa.
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

        protected $attributes = [
            'geocoding_status' => self::GEO_PENDING,
        ];

        protected $casts = [
            'is_active' => 'boolean',

            'elevator_count' => 'integer',
            'traction_elevator_count' => 'integer',
            'hydraulic_elevator_count' => 'integer',
            'freight_elevator_count' => 'integer',

            'latitude' => 'float',
            'longitude' => 'float',
            'geocoding_confidence' => 'float',
            'geocoded_at' => 'datetime',
        ];

        protected static function booted(): void
        {
            // Si cambia la dirección, las coordenadas viejas ya no sirven:
            // se borran (nunca mostrar un punto equivocado) y vuelve a pending.
            static::saving(function (Building $building) {
                if (! $building->exists || ! $building->isDirty(self::ADDRESS_FIELDS)) {
                    return;
                }

                // Cambios que no alteran la dirección (espacios, etc.).
                if ($building->geocoded_address === $building->geocodingAddress()->canonical()) {
                    return;
                }

                $building->forceFill([
                    'latitude' => null,
                    'longitude' => null,
                    'geocoding_status' => self::GEO_PENDING,
                    'geocoding_confidence' => null,
                    'geocoded_at' => null,
                ]);
            });

            // Se geocodifica después de responder al usuario (no lo demora)
            // y una sola vez por cambio de dirección.
            static::saved(function (Building $building) {
                $addressChanged = $building->wasRecentlyCreated
                    || $building->wasChanged(self::ADDRESS_FIELDS);

                if ($addressChanged
                    && $building->geocoding_status === self::GEO_PENDING
                    && app(GeoapifyClient::class)->isConfigured()
                ) {
                    GeocodeBuilding::dispatchAfterResponse($building->id);
                }
            });
        }

        public function geocodingAddress(): BuildingAddress
        {
            return BuildingAddress::fromBuilding($this);
        }

        public function hasLocation(): bool
        {
            return $this->latitude !== null && $this->longitude !== null;
        }

        /**
         * La dirección cambió desde la última geocodificación (por ejemplo,
         * cambió la provincia de la empresa que se usa por defecto).
         */
        public function isGeocodingStale(): bool
        {
            return $this->geocoded_address !== null
                && $this->geocoded_address !== $this->geocodingAddress()->canonical();
        }

        public function client()
        {
            return $this->belongsTo(Client::class)->withTrashed();
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

        public function visits()
        {
            return $this->hasMany(BuildingVisit::class);
        }



    }

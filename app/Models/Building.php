<?php

namespace App\Models;

    use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
    use Illuminate\Database\Eloquent\Model;
    use App\Models\Concerns\BelongsToCompany;
    use App\Models\Concerns\ConsumesPlanLimit;
    use App\Enums\PlanLimit;
    use App\Jobs\GeocodeBuilding;
    use App\Services\Geocoding\GeoapifyClient;
    use App\Support\Geocoding\BuildingAddress;

    class Building extends Model
    {

        use HasFactory, SoftDeletes;
        use BelongsToCompany;
        use ConsumesPlanLimit;

        /*
        | Estado de la ubicación en el mapa (geocoding_status).
        */
        public const GEO_PENDING = 'pending';
        public const GEO_GEOCODED = 'geocoded';
        public const GEO_MANUAL = 'manual';
        public const GEO_NEEDS_REVIEW = 'needs_review';
        public const GEO_ERROR = 'error';

        /**
         * Paleta de colores del mapa: clave => [nombre, hex]. Paleta fija
         * (no un selector libre) para que los puntos se distingan bien.
         */
        public const MAP_COLORS = [
            'naranja' => ['Naranja', '#F97316'],
            'rojo' => ['Rojo', '#DC2626'],
            'amarillo' => ['Amarillo', '#EAB308'],
            'verde' => ['Verde', '#16A34A'],
            'celeste' => ['Celeste', '#0891B2'],
            'azul' => ['Azul', '#2563EB'],
            'violeta' => ['Violeta', '#7C3AED'],
            'rosa' => ['Rosa', '#DB2777'],
            'marron' => ['Marrón', '#92400E'],
            'negro' => ['Negro', '#1F2937'],
        ];

        public const DEFAULT_MAP_COLOR = 'naranja';

        public function mapColorKey(): string
        {
            return isset(self::MAP_COLORS[$this->map_color]) ? $this->map_color : self::DEFAULT_MAP_COLOR;
        }

        public function mapColorHex(): string
        {
            return self::MAP_COLORS[$this->mapColorKey()][1];
        }

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

            'map_color',
            'map_zone',
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

            // Un legajo técnico por cada equipo (no se borran: quedan inactivos).
            static::saved(function (Building $building) {
                if ($building->wasRecentlyCreated || $building->wasChanged(['elevator_count', 'freight_elevator_count'])) {
                    Elevator::syncForBuilding($building);
                }
            });

            // Un edificio restaurado (la migración no crea legajos para los
            // eliminados) recupera los suyos.
            static::restored(fn (Building $building) => Elevator::syncForBuilding($building));

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

        /**
         * Equipos del edificio tal como se guardan en reportes y órdenes:
         * "Ascensor 1", …, "Montacargas 1", …
         *
         * @return array<int, string>
         */
        public function unitLabels(): array
        {
            $labels = [];

            for ($i = 1; $i <= (int) $this->elevator_count; $i++) {
                $labels[] = "Ascensor {$i}";
            }

            for ($i = 1; $i <= (int) $this->freight_elevator_count; $i++) {
                $labels[] = "Montacargas {$i}";
            }

            return $labels;
        }

        public function planLimit(): ?PlanLimit
        {
            return PlanLimit::Buildings;
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

    public function maintenanceServices()
    {
        return $this->hasMany(MaintenanceService::class);
    }

    public function receivables()
    {
        return $this->hasMany(Receivable::class);
    }

        public function elevators()
        {
            return $this->hasMany(Elevator::class)->orderBy('kind')->orderBy('id');
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

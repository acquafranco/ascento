<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Buildings\BuildingResource;
use App\Jobs\GeocodePendingBuildings;
use App\Models\Building;
use App\Services\Geocoding\BuildingGeocoder;
use App\Support\CompanyContext;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

/**
 * Mapa de todos los edificios de la empresa actual.
 *
 * - Abrir el mapa no espera a Geoapify: usa las coordenadas guardadas.
 * - Si hay edificios pendientes, se ubican solos en segundo plano (después
 *   de responder) y aparecen en el mapa sin recargar (wire:poll).
 * - Los mosaicos se piden directo al CDN desde el navegador: no ocupan al
 *   servidor de Ascento.
 */
class BuildingsMap extends Page
{
    protected string $view = 'filament.pages.buildings-map';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationLabel = 'Mapa';

    protected static ?string $title = 'Mapa de edificios';

    protected static ?string $slug = 'mapa';

    protected static string|\UnitEnum|null $navigationGroup = 'Gestión';

    protected static ?int $navigationSort = 3;

    /** Máximo de edificios "sin ubicar" que se listan (el resto se cuenta). */
    public const LIST_LIMIT = 25;

    /** Desde cuándo buscar puntos nuevos en cada poll (ISO 8601). */
    public string $lastPollAt = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->isAdmin() || $user->isSuperAdmin());
    }

    public function mount(): void
    {
        $this->lastPollAt = now()->subSecond()->toIso8601String();

        $this->startBackgroundGeocoding();
    }

    public function getSubheading(): ?string
    {
        return '¿Dónde están todos tus edificios? Tocá un punto para ver el detalle.';
    }

    /**
     * Edificios de la empresa actual. El filtro explícito por company_id se
     * suma al scope global (defensa en profundidad, como el resto del panel).
     */
    protected function buildingsQuery(): Builder
    {
        $companyId = CompanyContext::currentId();

        return Building::query()
            ->when(
                $companyId,
                fn (Builder $q) => $q->where('buildings.company_id', $companyId),
                fn (Builder $q) => $q->whereRaw('1 = 0'),
            );
    }

    public function hasCompany(): bool
    {
        return CompanyContext::currentId() !== null;
    }

    /*
    |--------------------------------------------------------------------------
    | PUNTOS
    |--------------------------------------------------------------------------
    */

    public function getMarkers(): array
    {
        return $this->markersQuery()->get()->map(fn (Building $b) => $this->toMarker($b))->all();
    }

    protected function markersQuery(): Builder
    {
        return $this->buildingsQuery()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->with('client:id,name')
            ->select([
                'buildings.id', 'client_id', 'name', 'address', 'locality', 'municipality', 'neighborhood',
                'elevator_count', 'freight_elevator_count', 'is_active',
                'latitude', 'longitude', 'geocoding_status', 'geocoded_at',
            ]);
    }

    protected function toMarker(Building $building): array
    {
        return [
            'id' => $building->id,
            'lat' => round($building->latitude, 6),
            'lng' => round($building->longitude, 6),
            'title' => trim("{$building->name} {$building->address}"),
            'area' => $building->locality ?: ($building->municipality ?: $building->neighborhood),
            'client' => $building->client?->name,
            'clientId' => $building->client_id,
            'elevators' => (int) $building->elevator_count,
            'freight' => (int) $building->freight_elevator_count,
            'active' => (bool) $building->is_active,
            'manual' => $building->geocoding_status === Building::GEO_MANUAL,
        ];
    }

    public function markerCount(): int
    {
        return $this->buildingsQuery()->whereNotNull('latitude')->whereNotNull('longitude')->count();
    }

    /*
    |--------------------------------------------------------------------------
    | SIN UBICAR
    |--------------------------------------------------------------------------
    */

    protected function unlocatedQuery(): Builder
    {
        return $this->buildingsQuery()->where(fn (Builder $q) => $q->whereNull('latitude')->orWhereNull('longitude'));
    }

    public function unlocatedCount(): int
    {
        return $this->unlocatedQuery()->count();
    }

    /**
     * Los primeros LIST_LIMIT edificios sin ubicación (primero los que
     * necesitan una corrección), con el motivo en lenguaje de usuario.
     */
    #[Computed]
    public function unlocated(): Collection
    {
        $editUrl = BuildingResource::getUrl('edit', ['record' => '__ID__']);

        return $this->unlocatedQuery()
            ->with(['client:id,name', 'company:id,province'])
            ->orderByRaw("CASE geocoding_status WHEN 'needs_review' THEN 0 WHEN 'error' THEN 1 ELSE 2 END")
            ->orderBy('name')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(function (Building $building) use ($editUrl) {
                $missing = $building->geocodingAddress()->missingParts();

                $reason = match (true) {
                    $missing !== [] => 'Falta '.implode(', ', $missing).' en la dirección',
                    $building->geocoding_status === Building::GEO_NEEDS_REVIEW => 'No se encontró la dirección con precisión',
                    $building->geocoding_status === Building::GEO_ERROR => 'No se pudo consultar el servicio de mapas; se reintenta solo',
                    default => 'Ubicando…',
                };

                return [
                    'id' => $building->id,
                    'title' => trim("{$building->name} {$building->address}"),
                    'client' => $building->client?->name,
                    'area' => $building->locality ?: ($building->municipality ?: $building->neighborhood),
                    'reason' => $reason,
                    'needsReview' => $missing !== [] || $building->geocoding_status === Building::GEO_NEEDS_REVIEW,
                    'editUrl' => str_replace('__ID__', (string) $building->id, $editUrl),
                ];
            });
    }

    public function clients(): array
    {
        return $this->buildingsQuery()
            ->whereNotNull('latitude')
            ->join('clients', 'clients.id', '=', 'buildings.client_id')
            ->distinct()
            ->orderBy('clients.name')
            ->pluck('clients.name', 'clients.id')
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | UBICACIÓN AUTOMÁTICA EN SEGUNDO PLANO
    |--------------------------------------------------------------------------
    */

    public function isGeocodingInProgress(): bool
    {
        $companyId = CompanyContext::currentId();

        return $companyId !== null && GeocodePendingBuildings::isRunning($companyId);
    }

    protected function startBackgroundGeocoding(): void
    {
        $companyId = CompanyContext::currentId();

        if ($companyId === null || ! app(BuildingGeocoder::class)->isConfigured()) {
            return;
        }

        $hasPending = $this->buildingsQuery()
            ->whereIn('geocoding_status', [Building::GEO_PENDING, Building::GEO_ERROR])
            ->exists();

        if ($hasPending) {
            GeocodePendingBuildings::start($companyId);
        }
    }

    /**
     * Lo llama wire:poll mientras se ubican edificios: manda al mapa los
     * puntos nuevos (sin recargar la página).
     */
    public function pollNewMarkers(): void
    {
        $since = $this->lastPollAt ?: now()->subMinute()->toIso8601String();
        $this->lastPollAt = now()->toIso8601String();

        $markers = $this->markersQuery()
            ->where('geocoded_at', '>=', Carbon::parse($since)->subSecond())
            ->get()
            ->map(fn (Building $b) => $this->toMarker($b))
            ->all();

        if ($markers !== []) {
            $this->dispatch('buildings-map-markers', markers: $markers);
        }

        unset($this->unlocated);
    }

    /*
    |--------------------------------------------------------------------------
    | CONFIGURACIÓN DEL MAPA
    |--------------------------------------------------------------------------
    */

    public function getMapConfig(): array
    {
        $mapKey = config('services.geoapify.map_key');
        $style = preg_replace('/[^a-z0-9\-]/', '', (string) config('services.geoapify.map_style', 'osm-bright'));

        return [
            // Mosaicos directo desde el navegador (CDN de Geoapify u OSM):
            // no ocupan al servidor de Ascento.
            'tileUrl' => filled($mapKey)
                ? "https://maps.geoapify.com/v1/tile/{$style}/{z}/{x}/{y}{r}.png?apiKey=".rawurlencode($mapKey)
                : 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
            'attribution' => filled($mapKey)
                ? 'Powered by <a href="https://www.geoapify.com/" target="_blank" rel="noopener">Geoapify</a> | © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>'
                : '© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>',
            'maxZoom' => filled($mapKey) ? 20 : 19,
            'retina' => filled($mapKey),
            'editUrl' => BuildingResource::getUrl('edit', ['record' => '__ID__']),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | CORRECCIÓN MANUAL
    |--------------------------------------------------------------------------
    */

    public function placeBuilding(int $buildingId, mixed $latitude, mixed $longitude): ?array
    {
        if (! is_numeric($latitude) || ! is_numeric($longitude)
            || abs((float) $latitude) > 90 || abs((float) $longitude) > 180
        ) {
            Notification::make()->title('Ubicación inválida')->danger()->send();

            return null;
        }

        // findOrFail dentro de la empresa actual: un id de otra empresa da 404.
        $building = $this->buildingsQuery()->with(['client:id,name', 'company:id,province'])->findOrFail($buildingId);

        $building->forceFill([
            'latitude' => round((float) $latitude, 7),
            'longitude' => round((float) $longitude, 7),
            'geocoding_status' => Building::GEO_MANUAL,
            'geocoding_confidence' => null,
            'geocoded_address' => $building->geocodingAddress()->canonical(),
            'geocoded_at' => now(),
        ]);

        Building::withoutTimestamps(fn () => $building->saveQuietly());

        unset($this->unlocated);

        Notification::make()
            ->title('Ubicación guardada')
            ->body($building->name.' '.$building->address)
            ->success()
            ->send();

        return $this->toMarker($building);
    }
}

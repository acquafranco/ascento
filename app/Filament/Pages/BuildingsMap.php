<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Buildings\BuildingResource;
use App\Models\Building;
use App\Services\Geocoding\BuildingGeocoder;
use App\Support\CompanyContext;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

/**
 * Mapa de todos los edificios de la empresa actual.
 *
 * Nunca llama a Geoapify al abrirse: usa las coordenadas ya guardadas en
 * buildings. Los mosaicos pasan por MapTileController (API key en el
 * servidor).
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

    /** Cuántos edificios se ubican por clic en "Ubicar pendientes". */
    public const BATCH_SIZE = 20;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->isAdmin() || $user->isSuperAdmin());
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

    /**
     * Puntos del mapa. Solo los datos mínimos para el popup.
     */
    public function getMarkers(): array
    {
        return $this->buildingsQuery()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->with('client:id,name')
            ->get([
                'id', 'client_id', 'name', 'address', 'locality', 'municipality', 'neighborhood',
                'elevator_count', 'freight_elevator_count', 'is_active',
                'latitude', 'longitude', 'geocoding_status',
            ])
            ->map(fn (Building $building) => $this->toMarker($building))
            ->all();
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

    /**
     * Edificios sin ubicación, con el motivo en lenguaje de usuario.
     */
    #[Computed]
    public function unlocated(): Collection
    {
        return $this->buildingsQuery()
            ->where(fn (Builder $q) => $q->whereNull('latitude')->orWhereNull('longitude'))
            ->with(['client:id,name', 'company:id,province'])
            ->orderByRaw("CASE geocoding_status WHEN 'needs_review' THEN 0 WHEN 'error' THEN 1 ELSE 2 END")
            ->orderBy('name')
            ->get()
            ->map(function (Building $building) {
                $missing = $building->geocodingAddress()->missingParts();

                $reason = match (true) {
                    $missing !== [] => 'Falta '.implode(', ', $missing).' en la dirección',
                    $building->geocoding_status === Building::GEO_NEEDS_REVIEW => 'No se encontró la dirección con precisión',
                    $building->geocoding_status === Building::GEO_ERROR => 'No se pudo consultar el servicio de mapas; se reintenta solo',
                    default => 'Pendiente de ubicar',
                };

                return [
                    'id' => $building->id,
                    'title' => trim("{$building->name} {$building->address}"),
                    'client' => $building->client?->name,
                    'area' => $building->locality ?: ($building->municipality ?: $building->neighborhood),
                    'reason' => $reason,
                    'needsReview' => $missing !== [] || $building->geocoding_status === Building::GEO_NEEDS_REVIEW,
                    'editUrl' => BuildingResource::getUrl('edit', ['record' => $building->id]),
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

    public function getMapConfig(): array
    {
        $useGeoapify = filled(config('services.geoapify.api_key'));

        return [
            // Con key: proxy propio (la key no viaja al navegador).
            // Sin key: mosaicos públicos de OpenStreetMap.
            'tileUrl' => $useGeoapify
                ? url('/map-tiles').'/{z}/{x}/{y}.png'
                : 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
            'attribution' => $useGeoapify
                ? 'Powered by <a href="https://www.geoapify.com/" target="_blank" rel="noopener">Geoapify</a> | © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>'
                : '© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>',
            'maxZoom' => $useGeoapify ? 20 : 19,
            'editUrl' => BuildingResource::getUrl('edit', ['record' => '__ID__']),
        ];
    }

    public function pendingCount(): int
    {
        return $this->buildingsQuery()
            ->whereIn('geocoding_status', [Building::GEO_PENDING, Building::GEO_ERROR])
            ->count();
    }

    public function canGeocode(): bool
    {
        return app(BuildingGeocoder::class)->isConfigured();
    }

    /*
    |--------------------------------------------------------------------------
    | ACCIONES
    |--------------------------------------------------------------------------
    */

    /**
     * Corrección manual: el admin marca el punto en el mapa.
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

    /**
     * Ubica ahora un lote de edificios pendientes (máx. BATCH_SIZE por clic,
     * para no demorar la pantalla ni gastar créditos de golpe).
     */
    public function geocodePending(BuildingGeocoder $geocoder): void
    {
        if (! $geocoder->isConfigured()) {
            Notification::make()->title('El servicio de mapas no está configurado')->warning()->send();

            return;
        }

        $buildings = $this->buildingsQuery()
            ->with('company:id,province')
            ->whereIn('geocoding_status', [Building::GEO_PENDING, Building::GEO_ERROR])
            ->orderBy('id')
            ->limit(self::BATCH_SIZE)
            ->get();

        $located = 0;

        foreach ($buildings as $i => $building) {
            // Plan gratis de Geoapify: máximo 5 requests/segundo.
            if ($i > 0 && ! app()->runningUnitTests()) {
                usleep(200_000);
            }

            if ($geocoder->geocode($building) === Building::GEO_GEOCODED) {
                $located++;
            }
        }

        $notLocated = $buildings->count() - $located;

        Notification::make()
            ->title("Se ubicaron {$located} edificios")
            ->body($notLocated > 0 ? "{$notLocated} quedaron para revisar." : null)
            ->success()
            ->send();

        // Recarga para dibujar los puntos nuevos.
        $this->redirect(static::getUrl());
    }
}

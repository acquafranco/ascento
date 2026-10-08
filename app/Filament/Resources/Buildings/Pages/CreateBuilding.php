<?php

namespace App\Filament\Resources\Buildings\Pages;

use App\Filament\Resources\Buildings\BuildingResource;
use App\Services\Geocoding\AddressAutocomplete;
use Filament\Resources\Pages\CreateRecord;

class CreateBuilding extends CreateRecord
{
    use \App\Filament\Concerns\ChecksPlanLimitOnCreate;

    protected static \App\Enums\PlanLimit $planLimit = \App\Enums\PlanLimit::Buildings;

    protected static string $resource = BuildingResource::class;

    protected function afterCreate(): void
    {
        // Dirección elegida del buscador: queda ubicado en el mapa ya mismo.
        app(AddressAutocomplete::class)->applyToBuilding($this->record, $this->data['address_search'] ?? null);
    }
}

<?php

namespace App\Filament\Resources\Buildings\Pages;

use App\Filament\Resources\Buildings\BuildingResource;
use App\Filament\Resources\Buildings\Schemas\BuildingForm;
use Filament\Resources\Pages\CreateRecord;

class CreateBuilding extends CreateRecord
{
    use \App\Filament\Concerns\ChecksPlanLimitOnCreate;

    protected static \App\Enums\PlanLimit $planLimit = \App\Enums\PlanLimit::Buildings;

    protected static string $resource = BuildingResource::class;

    /** Equipos del formulario (se guardan como legajos, no como columnas del edificio). */
    protected array $equipment = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->equipment = array_values($data['equipment'] ?? []);
        unset($data['equipment'], $data['equipment_elevators'], $data['equipment_freight']);

        return $data;
    }

    protected function afterCreate(): void
    {
        // Cada ascensor / montacargas con su tracción (las cantidades salen de
        // ellos) y la dirección elegida del buscador (queda ubicado en el mapa).
        BuildingForm::afterCreate($this->record, $this->equipment, $this->data['address_search'] ?? null);
    }
}

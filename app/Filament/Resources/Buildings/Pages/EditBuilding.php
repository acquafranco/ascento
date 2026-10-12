<?php

namespace App\Filament\Resources\Buildings\Pages;

use App\Filament\Resources\Buildings\BuildingResource;
use App\Models\Elevator;
use App\Services\Geocoding\AddressAutocomplete;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Str;

class EditBuilding extends EditRecord
{
    protected static string $resource = BuildingResource::class;

    /** Equipos del formulario (se guardan como legajos, no como columnas del edificio). */
    protected array $equipment = [];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Los equipos activos, cada uno con su tipo y su tracción.
        $data['equipment'] = Elevator::withoutGlobalScopes()
            ->where('building_id', $this->record->id)->where('is_active', true)
            ->orderBy('kind')->orderBy('id')
            ->get(['id', 'label', 'kind', 'machine_type'])
            ->mapWithKeys(fn ($e) => [(string) Str::uuid() => ['id' => $e->id, 'label' => $e->label, 'kind' => $e->kind, 'machine_type' => $e->machine_type]])
            ->all();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->equipment = array_values($data['equipment'] ?? []);
        unset($data['equipment'], $data['equipment_elevators'], $data['equipment_freight']);

        return $data;
    }

    protected function afterSave(): void
    {
        // Agrega, actualiza o da de baja (inactivo, con historial) los equipos.
        Elevator::syncFromForm($this->record, $this->equipment);
        $this->record->refresh();

        // Si cambió la dirección con el buscador, se ubica con esa sugerencia.
        app(AddressAutocomplete::class)->applyToBuilding($this->record, $this->data['address_search'] ?? null);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('Desactivar')
                ->modalHeading('Desactivar edificio')
                ->modalDescription('Deja de aparecer en los listados y en la app de técnicos. Su historial de remitos, órdenes y mantenimientos se conserva y se puede reactivar.')
                ->modalSubmitActionLabel('Desactivar')
                ->successNotificationTitle('Desactivado'),
        ];
    }
}

<?php

namespace App\Filament\Resources\Buildings\Pages;

use App\Filament\Resources\Buildings\BuildingResource;
use App\Services\Geocoding\AddressAutocomplete;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBuilding extends EditRecord
{
    protected static string $resource = BuildingResource::class;

    protected function afterSave(): void
    {
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

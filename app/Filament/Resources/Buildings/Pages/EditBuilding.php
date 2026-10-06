<?php

namespace App\Filament\Resources\Buildings\Pages;

use App\Filament\Resources\Buildings\BuildingResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBuilding extends EditRecord
{
    protected static string $resource = BuildingResource::class;

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

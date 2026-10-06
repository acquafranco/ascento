<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditClient extends EditRecord
{
    protected static string $resource = ClientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('Desactivar')
                ->modalHeading('Desactivar cliente')
                ->modalDescription('Deja de aparecer en los listados. Sus edificios, remitos y presupuestos se conservan y se puede reactivar.')
                ->modalSubmitActionLabel('Desactivar')
                ->successNotificationTitle('Desactivado'),
        ];
    }
}

<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Tables\ClientsTable;
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
                ->modalDescription(ClientsTable::DEACTIVATE_DESCRIPTION)
                ->modalSubmitActionLabel('Desactivar')
                ->successNotificationTitle('Desactivado'),
            ClientsTable::eraseAction()
                ->after(fn () => $this->redirect(ClientResource::getUrl('index'))),
        ];
    }
}

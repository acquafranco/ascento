<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\Resources\Companies\CompanyResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCompany extends EditRecord
{
    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('Desactivar')
                ->modalHeading('Desactivar empresa')
                ->modalDescription('Sus usuarios pierden el acceso. Todos los datos se conservan y la empresa se puede reactivar.')
                ->modalSubmitActionLabel('Desactivar')
                ->successNotificationTitle('Desactivado'),
        ];
    }
}

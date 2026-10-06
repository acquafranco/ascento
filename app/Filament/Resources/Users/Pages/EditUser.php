<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('Desactivar')
                ->modalHeading('Desactivar técnico')
                ->modalDescription('No podrá iniciar sesión. Su historial (remitos, visitas, reportes) se conserva y se puede reactivar cuando quieras.')
                ->modalSubmitActionLabel('Desactivar')
                ->successNotificationTitle('Desactivado')
                ->hidden(fn ($record) => $record->is(auth()->user())),
        ];
    }
}

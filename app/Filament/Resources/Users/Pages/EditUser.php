<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Services\Technicians\TechnicianPhotoService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Foto de perfil (Etapa 8.2): el dueño la carga, reemplaza o quita (el servicio lo verifica).
            Action::make('photo')
                ->label(fn () => $this->record->avatar ? 'Cambiar foto' : 'Subir foto')
                ->icon('heroicon-o-camera')
                ->color('gray')
                ->visible(fn () => app(TechnicianPhotoService::class)->canChange(auth()->user(), $this->record))
                ->modalHeading('Foto de perfil')
                ->modalDescription('JPG, PNG o WebP de hasta 2 MB. La ven tu empresa y los clientes de los edificios que tiene asignados.')
                ->schema([
                    FileUpload::make('photo')->label('Foto')->image()->required()->storeFiles(false)
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(TechnicianPhotoService::MAX_KB),
                ])
                ->action(function (array $data) {
                    app(TechnicianPhotoService::class)->replace(auth()->user(), $this->record, $data['photo']);
                    Notification::make()->title('Foto actualizada')->success()->send();
                }),
            Action::make('removePhoto')
                ->label('Quitar foto')
                ->icon('heroicon-o-trash')
                ->color('gray')
                ->visible(fn () => $this->record->avatar && app(TechnicianPhotoService::class)->canChange(auth()->user(), $this->record))
                ->requiresConfirmation()
                ->modalHeading('Quitar la foto')
                ->modalDescription('Se vuelven a mostrar sus iniciales.')
                ->modalSubmitActionLabel('Quitar')
                ->action(function () {
                    app(TechnicianPhotoService::class)->remove(auth()->user(), $this->record);
                    Notification::make()->title('Foto quitada')->success()->send();
                }),
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

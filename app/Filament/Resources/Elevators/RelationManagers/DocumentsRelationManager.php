<?php

namespace App\Filament\Resources\Elevators\RelationManagers;

use App\Enums\PermissionArea;
use App\Filament\Concerns\GuardedRelation;
use App\Filament\Concerns\OwnerRecordOfCurrentCompany;
use App\Filament\Support\ClientSharing;
use App\Models\ElevatorDocument;
use App\Services\Elevators\ElevatorDocumentService;
use App\Support\Access\Access;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Planos, manuales, certificados y fotos del legajo (disco privado). */
class DocumentsRelationManager extends RelationManager
{
    use GuardedRelation;

    /** Área de permisos de administrativos (Etapa 6). */
    public static function permissionArea(): ?PermissionArea
    {
        return PermissionArea::Documents;
    }

    use OwnerRecordOfCurrentCompany;

    protected static string $relationship = 'documents';

    protected static ?string $title = 'Documentación';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')->label('Tipo')->badge()->formatStateUsing(fn ($state) => ElevatorDocument::TYPES[$state] ?? $state),
                TextColumn::make('title')->label('Título')->url(fn (ElevatorDocument $record) => $record->url(), shouldOpenInNewTab: true),
                TextColumn::make('expires_at')->label('Vence')->date('d/m/Y')->placeholder('—')
                    ->color(fn (ElevatorDocument $record) => $record->isExpired() ? 'danger' : ($record->expiresSoon() ? 'warning' : null))
                    ->description(fn (ElevatorDocument $record) => $record->isExpired() ? 'Vencido' : ($record->expiresSoon() ? 'Vence pronto' : null)),
                TextColumn::make('created_at')->label('Subido')->date('d/m/Y'),
                ClientSharing::column(),
                TextColumn::make('public_on_qr')->label('En el QR')->badge()
                    ->state(fn (ElevatorDocument $record) => $record->public_on_qr ? 'Publicado' : 'No')
                    ->color(fn (string $state) => $state === 'Publicado' ? 'warning' : 'gray')
                    ->tooltip('Publicado: cualquiera que escanee el QR del edificio puede abrirlo, si el QR tiene el bloque «Documentación».'),
            ])
            ->emptyStateHeading('Sin documentación')
            ->emptyStateDescription('Subí planos, manuales, certificados o fotos del equipo. Los certificados con fecha de vencimiento avisan en el Centro de atención.')
            ->headerActions([
                Action::make('upload')
                    ->label('Subir documento')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->schema([
                        Select::make('type')->label('Tipo')->options(ElevatorDocument::TYPES)->required()->default('certificate')->native(false)->live(),
                        TextInput::make('title')->label('Título')->maxLength(250)->placeholder('Certificado de habilitación 2026'),
                        DatePicker::make('expires_at')->label('Vence (opcional)')->native(false)->displayFormat('d/m/Y'),
                        FileUpload::make('file')->label('Archivo')->required()->storeFiles(false)
                            ->acceptedFileTypes(array_keys(ElevatorDocumentService::MIMES))->maxSize(ElevatorDocumentService::MAX_KB),
                    ])
                    ->action(function (array $data) {
                        app(ElevatorDocumentService::class)->store(
                            $this->getOwnerRecord(), $data['file'], $data['type'], (string) ($data['title'] ?? ''), $data['expires_at'] ?? null, auth()->user(),
                        );

                        Notification::make()->title('Documento subido')->success()->send();
                    }),
            ])
            ->recordActions([
                Action::make('publishOnQr')
                    ->label('Publicar en el QR')
                    ->icon('heroicon-o-qr-code')
                    ->visible(fn (ElevatorDocument $record) => ! $record->public_on_qr && $this->canPublishOnQr())
                    ->requiresConfirmation()
                    ->modalHeading('Publicar en el QR del edificio')
                    ->modalDescription('Cualquier persona que escanee el QR podrá abrir y descargar este archivo, sin cuenta (si el QR tiene encendido el bloque «Documentación»). Revisá que no tenga datos personales ni información privada.')
                    ->modalSubmitActionLabel('Publicar')
                    ->action(function (ElevatorDocument $record) {
                        app(ElevatorDocumentService::class)->publishOnQr($record, auth()->user(), true);
                        Notification::make()->title('Publicado en el QR')->success()->send();
                    }),
                Action::make('unpublishOnQr')
                    ->label('Quitar del QR')
                    ->icon('heroicon-o-eye-slash')
                    ->visible(fn (ElevatorDocument $record) => (bool) $record->public_on_qr && $this->canPublishOnQr())
                    ->action(function (ElevatorDocument $record) {
                        app(ElevatorDocumentService::class)->publishOnQr($record, auth()->user(), false);
                        Notification::make()->title('Quitado del QR')->success()->send();
                    }),
                Action::make('delete')
                    ->label('Borrar')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn (ElevatorDocument $record) => $record->delete()),
            ]);
    }

    private function canPublishOnQr(): bool
    {
        $user = auth()->user();

        return Access::can($user, PermissionArea::Documents, PermissionArea::MANAGE)
            && Access::can($user, PermissionArea::PublicPages, PermissionArea::MANAGE);
    }
}

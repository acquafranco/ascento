<?php

namespace App\Filament\Resources\Elevators\RelationManagers;

use App\Models\ElevatorDocument;
use App\Services\Elevators\ElevatorDocumentService;
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
                Action::make('delete')
                    ->label('Borrar')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn (ElevatorDocument $record) => $record->delete()),
            ]);
    }
}

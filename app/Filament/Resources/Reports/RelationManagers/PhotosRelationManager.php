<?php

namespace App\Filament\Resources\Reports\RelationManagers;

use App\Enums\PermissionArea;
use App\Filament\Concerns\GuardedRelation;
use App\Filament\Concerns\OwnerRecordOfCurrentCompany;
use App\Models\ReportPhoto;
use App\Services\Reports\ReportPhotoService;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Fotos del reporte: se ven en grande y se borran (con su archivo). */
class PhotosRelationManager extends RelationManager
{
    use GuardedRelation;

    /** Área de permisos de administrativos (Etapa 6). */
    public static function permissionArea(): ?PermissionArea
    {
        return PermissionArea::Inspections;
    }

    use OwnerRecordOfCurrentCompany;

    protected static string $relationship = 'photos';

    protected static ?string $title = 'Fotos';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (ReportPhoto $record) => 'Foto '.($record->position + 1))
            ->columns([
                ImageColumn::make('preview')
                    ->label('Foto')
                    ->state(fn (ReportPhoto $record) => $record->thumbUrl())
                    ->url(fn (ReportPhoto $record) => $record->url(), shouldOpenInNewTab: true) // la original, al tocarla
                    ->imageHeight(120),
                TextColumn::make('size')
                    ->label('Tamaño')
                    ->formatStateUsing(fn ($state, ReportPhoto $record) => trim(($record->width ? "{$record->width}×{$record->height} px · " : '').($state ? number_format($state / 1024, 0, ',', '.').' KB' : ''), ' ·')),
            ])
            ->paginated(false)
            ->recordActions([
                Action::make('deletePhoto')
                    ->label('Borrar')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Borrar esta foto')
                    ->modalDescription('La foto se borra definitivamente.')
                    ->action(fn (ReportPhoto $record) => app(ReportPhotoService::class)->delete($record)),
            ]);
    }
}

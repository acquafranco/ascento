<?php

namespace App\Filament\Resources\Reports\Tables;

use App\Filament\Support\ClientSharing;
use App\Models\Report;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ReportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Eager loading de lo que usan las columnas (evita N+1).
            ->modifyQueryUsing(fn ($query) => $query->with(['building', 'user', 'photos']))
            ->columns([
                TextColumn::make('kind')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => Report::KIND_LABELS[$state] ?? 'Falla')
                    ->color(fn (?string $state) => $state === Report::KIND_INSPECTION ? 'info' : 'gray'),

                TextColumn::make('building.name')
                    ->label('Edificio')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('user.name')
                    ->label('Técnico')
                    ->searchable(),

                TextColumn::make('elevator_number')
                    ->label('Ascensor')
                    ->searchable(),

                ImageColumn::make('first_photo')
                    ->label('Foto')
                    // Miniatura: la tabla mostraba la foto original (hasta 2000 px) en 60 px.
                    ->state(fn ($record) => $record->photos->first()?->thumbUrl())
                    ->size(60)
                    ->square(),

                TextColumn::make('priority')
                    ->label('Prioridad')
                    ->badge()
                    // Una inspección no es una reparación: sin prioridad ni estado.
                    ->formatStateUsing(fn (string $state, $record) => $record->isInspection() ? '—' : $state)
                    ->color(fn (string $state): string => match ($state) {
                        'baja' => 'gray',
                        'media' => 'warning',
                        'alta' => 'danger',
                        'critica' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('status')
                    ->label('Estado')
                    ->formatStateUsing(fn (string $state, $record) => $record->isInspection() ? '—' : $state)
                    ->badge(),

                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                // Por defecto, los reportes de falla (como siempre); los de inspección, eligiéndolos.
                SelectFilter::make('kind')
                    ->label('Tipo')
                    ->options(Report::KIND_LABELS)
                    ->default(Report::KIND_INCIDENT),

                SelectFilter::make('priority')
                    ->options([
                        'baja' => 'Baja',
                        'media' => 'Media',
                        'alta' => 'Alta',
                        'critica' => 'Crítica',
                    ]),

                SelectFilter::make('status')
                    ->options([
                        'pendiente' => 'Pendiente',
                        'en_revision' => 'En revisión',
                        'resuelto' => 'Resuelto',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            // Compartir con el portal del cliente (privado por defecto).
            ->pushColumns([ClientSharing::column()])
            ->pushToolbarActions([BulkActionGroup::make(ClientSharing::bulkActions())->label('Portal del cliente')]);
    }
}

<?php

namespace App\Filament\Resources\Reports\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Filters\SelectFilter;

class ReportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Eager loading de lo que usan las columnas (evita N+1).
            ->modifyQueryUsing(fn ($query) => $query->with(['building', 'user', 'photos']))
            ->columns([
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
                    ->state(fn ($record) => $record->photos->first()?->url())
                    ->size(60)
                    ->square(),

                TextColumn::make('priority')
                    ->label('Prioridad')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'baja' => 'gray',
                        'media' => 'warning',
                        'alta' => 'danger',
                        'critica' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge(),

                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
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
            ->pushColumns([\App\Filament\Support\ClientSharing::column()])
            ->pushToolbarActions([\Filament\Actions\BulkActionGroup::make(\App\Filament\Support\ClientSharing::bulkActions())->label('Portal del cliente')]);
    }
}

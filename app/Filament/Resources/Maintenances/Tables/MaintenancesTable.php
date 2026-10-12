<?php

namespace App\Filament\Resources\Maintenances\Tables;

use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Filament\Support\ClientSharing;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MaintenancesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Lo más reciente primero (antes no había orden y lo último quedaba en otra página).
            ->defaultSort('number', 'desc')
            ->columns([

                TextColumn::make('number')
                    ->label('Remito')
                    ->formatStateUsing(fn ($state) => '#'.str_pad($state, 6, '0', STR_PAD_LEFT))
                    ->searchable(),

                TextColumn::make('building.name')
                    ->label('Edificio')
                    ->searchable(),

                TextColumn::make('building.address')
                    ->label('Dirección')
                    ->searchable(),

                TextColumn::make('user.name')
                    ->label('Técnico'),

                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i'),

                TextColumn::make('performed')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? 'Realizado' : 'No realizado')
                    ->colors([
                        'success' => fn ($state) => $state,
                        'danger' => fn ($state) => ! $state,
                    ]),

                TextColumn::make('assignment_type')
                    ->label('Origen')
                    ->badge()
                    ->formatStateUsing(function ($state, $record) {
                        if ($record->assignment_type === 'maintenance') {
                            return 'Mantenimiento mensual';
                        }

                        if ($record->assignment_type === 'inspection') {
                            return 'Orden de trabajo · Inspección';
                        }

                        if ($record->assignment_type === 'work_order') {
                            return 'Orden de trabajo · Mantenimiento';
                        }

                        return $state;
                    }),

            ])->recordUrl(fn ($record) => DeliveryNoteResource::getUrl('view', [
                'record' => $record,
            ]))
            // Compartir con el portal del cliente (privado por defecto).
            ->pushColumns([ClientSharing::column()])
            ->pushToolbarActions([BulkActionGroup::make(ClientSharing::bulkActions())->label('Portal del cliente')]);

    }
}

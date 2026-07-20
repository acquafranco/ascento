<?php

namespace App\Filament\Resources\Inspections\Tables;

use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;

class InspectionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([

                TextColumn::make('number')
                    ->label('Remito')
                    ->formatStateUsing(
                        fn ($state) => '#' . str_pad($state, 6, '0', STR_PAD_LEFT)
                    )
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
                            return 'Inspección mensual';
                        }

                        if ($record->assignment_type === 'work_order') {

                            if ($record->workOrder?->type === 'maintenance') {
                                return 'Orden de trabajo · Mantenimiento';
                            }

                            if ($record->workOrder?->type === 'inspection') {
                                return 'Orden de trabajo · Inspección';
                            }

                            return 'Orden de trabajo';
                        }

                        return $state;
                    }),

            ])
            ->recordUrl(fn ($record) => route('delivery-notes.show', $record));
    }
}

<?php

namespace App\Filament\Resources\Maintenances\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Table;

use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Columns\TextColumn;
use App\Support\WorkOrderLabels;

class MaintenancesTable
{
    public static function configure(Table $table): Table
    {
        return $table
           ->columns([

               TextColumn::make('number')
                    ->label('Remito')
                    ->formatStateUsing(fn ($state) => '#' . str_pad($state, 6, '0', STR_PAD_LEFT))
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
                        })


           ])->recordUrl(fn ($record) => route('delivery-notes.show', $record));

    }

}

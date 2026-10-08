<?php

namespace App\Filament\Resources\MaintenanceServices\RelationManagers;

use App\Models\BuildingVisit;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Mantenimientos e inspecciones realizados bajo el contrato. Solo lectura:
 * las visitas se registran en la app del técnico (remito firmado).
 */
class VisitsRelationManager extends RelationManager
{
    protected static string $relationship = 'visits';

    protected static ?string $title = 'Visitas realizadas';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['user', 'building']))
            ->defaultSort('visited_at', 'desc')
            ->columns([
                TextColumn::make('visited_at')->label('Fecha')->date('d/m/Y')->sortable(),
                TextColumn::make('assignment_type')->label('Tipo')->badge()
                    ->formatStateUsing(fn ($state) => $state === 'inspection' ? 'Inspección' : 'Mantenimiento')
                    ->color(fn ($state) => $state === 'inspection' ? 'info' : 'success'),
                TextColumn::make('period')->label('Período')
                    ->state(fn (BuildingVisit $record) => str_pad((string) $record->month, 2, '0', STR_PAD_LEFT).'/'.$record->year),
                TextColumn::make('building.name')->label('Edificio'),
                TextColumn::make('user.name')->label('Técnico'),
            ])
            ->filters([
                SelectFilter::make('assignment_type')->label('Tipo')->options([
                    'maintenance' => 'Mantenimientos',
                    'inspection' => 'Inspecciones',
                ]),
            ]);
    }
}

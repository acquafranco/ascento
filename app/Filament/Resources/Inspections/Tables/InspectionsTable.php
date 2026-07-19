<?php

namespace App\Filament\Resources\Inspections\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Table;

use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Columns\TextColumn;
use App\Support\WorkOrderLabels;

class InspectionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
           ->columns([

                TextColumn::make('deliveryNote.number')
                        ->label('Remito')
                        ->formatStateUsing(fn ($state) => $state ? '#' . str_pad($state, 6, '0', STR_PAD_LEFT) : '-')
                        ->url(fn ($record) => $record->deliveryNote
                            ? route('delivery-notes.show', $record->deliveryNote)
                            : null)
                        ->openUrlInNewTab(false)
                        ->weight('bold'),

                TextColumn::make('building.name')
                    ->label('Edificio')
                    ->searchable(),

                TextColumn::make('building.address')
                    ->label('Dirección')
                    ->searchable(),

                TextColumn::make('user.name')
                    ->label('Técnico'),

                TextColumn::make('visited_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i'),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'done' => 'Realizado',
                        'failed' => 'No realizado',
                        default => $state,
                    })
                    ->colors([
                        'success' => 'done',
                        'danger' => 'failed',
                    ]),
                 TextColumn::make('deliveryNote.number')
                    ->label('Remito')
                    ->formatStateUsing(fn ($state) => '#' . str_pad($state, 6, '0', STR_PAD_LEFT))
                    ->searchable(),
                        ])->recordUrl(fn ($record) => $record->deliveryNote
                            ? route('delivery-notes.show', $record->deliveryNote)
                            : null);
                    }
}

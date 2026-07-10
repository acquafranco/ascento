<?php

namespace App\Filament\Resources\DeliveryNotes\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\ViewAction;
use Filament\Tables\Table;
use App\Support\WorkOrderLabels;

class DeliveryNotesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
            TextColumn::make('number')
                ->label('Remito')
                ->searchable()
                ->sortable(),

           TextColumn::make('building.name')
    ->label('Edificio')
    ->formatStateUsing(fn ($state, $record) =>
        "{$record->building->name} {$record->building->address}"
    )
    ->searchable(query: function ($query, $search) {
        $query->whereHas('building', function ($query) use ($search) {
            $query->where('name', 'like', "%{$search}%")
                  ->orWhere('address', 'like', "%{$search}%");
        });
    })
    ->sortable(),

            TextColumn::make('user.name')
                ->label('Técnico')
                ->searchable()
                ->sortable(),

            TextColumn::make('trabajo')
                ->label('Trabajo')
                ->badge()
                ->state(function ($record) {

                    if ($record->workOrder) {

                        return WorkOrderLabels::type(
                            $record->workOrder->type
                        );

                    }

                    if ($record->buildingVisit) {

                        return WorkOrderLabels::type(
                            $record->buildingVisit->assignment_type
                        );

                    }

                    return '-';
                })
                ->colors([
                    'primary' => 'maintenance',
                    'warning' => 'inspection',
                    'danger' => 'claim',
                    'success' => 'installation',
                    'gray' => 'modernization',
                ]),

            TextColumn::make('equipos')
                ->label('Equipos')
                ->state(fn ($record) =>
                    "{$record->elevator_quantity} ASC / {$record->freight_elevator_quantity} MT"
                ),

            TextColumn::make('month')
                ->label('Mes'),

            TextColumn::make('year')
                ->label('Año'),

            IconColumn::make('performed')
                ->label('Realizado')
                ->boolean(),

            TextColumn::make('created_at')
                ->label('Fecha')
                ->dateTime('d/m/Y H:i')
                ->sortable(),

            ])
            ->filters([
                    SelectFilter::make('month')
            ->label('Mes')
            ->options([
                1 => 'Enero',
                2 => 'Febrero',
                3 => 'Marzo',
                4 => 'Abril',
                5 => 'Mayo',
                6 => 'Junio',
                7 => 'Julio',
                8 => 'Agosto',
                9 => 'Septiembre',
                10 => 'Octubre',
                11 => 'Noviembre',
                12 => 'Diciembre',
            ]),

        SelectFilter::make('performed')->label('Estado')->options([

                1 => 'Realizado',

                0 => 'No realizado',

            ]),


            ])

            ->defaultSort('number', 'desc')
          ->recordActions([
                ViewAction::make(),
                EditAction::make(),

                  \Filament\Actions\Action::make('pdf')

                    ->label('PDF')

                    ->icon('heroicon-o-document-arrow-down')

                    ->url(fn ($record) =>

                        route('delivery-notes.pdf', $record)

                    )

                    ->openUrlInNewTab(),
            ]);

    }
}

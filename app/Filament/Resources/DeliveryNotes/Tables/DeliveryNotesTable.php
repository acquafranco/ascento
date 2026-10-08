<?php

namespace App\Filament\Resources\DeliveryNotes\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
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
            // Eager loading de lo que usan las columnas/acciones (evita N+1).
            ->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->with(['building', 'user', 'workOrder', 'buildingVisit', 'company']))
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

                  \Filament\Actions\Action::make('pdf')

                    ->label('PDF')

                    ->icon('heroicon-o-document-arrow-down')

                    // El remito siempre pertenece a la empresa en la que se
                    // está operando: alcanza con su propia relación (ya
                    // cargada), sin un Company::find() por fila.
                    ->url(fn ($record) => self::canSharePdf()
                        ? route('delivery-notes.pdf', [
                            'company' => $record->company?->slug,
                            'deliveryNote' => $record,
                        ])
                        // Sin remitos digitales en el plan: explica y ofrece actualizar.
                        : \App\Support\Plans\PlanUpsell::url(feature: \App\Enums\PlanFeature::DigitalDeliveryNotes))
                    ->tooltip(fn ($record) => self::canSharePdf() ? null : 'Disponible desde el plan Profesional')
                    ->openUrlInNewTab(fn ($record) => (bool) self::canSharePdf()),
            ]);

    }

    /** Se resuelve una vez por request (todas las filas son de la misma empresa). */
    private static function canSharePdf(): bool
    {
        return once(fn () => (bool) \App\Support\Plans\PlanUpsell::currentCompany()?->plan()->allows(\App\Enums\PlanFeature::DigitalDeliveryNotes));
    }
}

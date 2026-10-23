<?php

namespace App\Filament\Resources\DeliveryNotes\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\ViewAction;
use Filament\Tables\Table;
use App\Support\DeliveryNotes\DeliveryNoteShare;
use App\Support\WorkOrderLabels;

class DeliveryNotesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Eager loading de lo que usan las columnas/acciones (evita N+1).
            ->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->with(['building.client', 'user', 'workOrder', 'buildingVisit', 'company']))
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
                ->color(fn ($record) => WorkOrderLabels::TYPE_COLORS[$record->workOrder?->type ?? $record->buildingVisit?->assignment_type] ?? 'gray'),

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
                ViewAction::make()->label('Ver remito'),

                \Filament\Actions\ActionGroup::make([
                    // Remito digital: en todos los planes.
                    \Filament\Actions\Action::make('pdf')
                        ->label('Descargar PDF')
                        ->icon('heroicon-o-document-arrow-down')
                        ->url(fn ($record) => DeliveryNoteShare::pdfUrl($record, download: true)),
                    \Filament\Actions\Action::make('whatsapp')
                        ->label('Enviar por WhatsApp')
                        ->icon('heroicon-o-chat-bubble-left-right')
                        ->url(fn ($record) => DeliveryNoteShare::whatsappUrl($record))
                        ->openUrlInNewTab()
                        ->visible(fn ($record) => DeliveryNoteShare::canShare($record)),
                    \Filament\Actions\Action::make('mail')
                        ->label('Enviar por correo')
                        ->icon('heroicon-o-envelope')
                        ->url(fn ($record) => DeliveryNoteShare::mailUrl($record))
                        ->visible(fn ($record) => DeliveryNoteShare::canShare($record)),
                ])->label('Descargar y compartir')->icon('heroicon-o-share')->tooltip('Descargar y compartir'),
            ])
            // Compartir con el portal del cliente (privado por defecto).
            ->pushColumns([\App\Filament\Support\ClientSharing::column()])
            ->pushToolbarActions([\Filament\Actions\BulkActionGroup::make(\App\Filament\Support\ClientSharing::bulkActions())->label('Portal del cliente')]);

    }
}

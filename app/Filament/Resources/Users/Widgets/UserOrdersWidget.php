<?php

namespace App\Filament\Resources\Users\Widgets;

use App\Models\WorkOrder;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class UserOrdersWidget extends TableWidget
{
    public ?object $record = null;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Órdenes activas')

            ->query(
                WorkOrder::query()
                    ->where(
                        'user_id',
                        $this->record->id
                    )
                    ->latest()
            )

            ->columns([

                Tables\Columns\TextColumn::make(
                    'building.name'
                )
                ->label('Edificio'),

                Tables\Columns\TextColumn::make(
                    'building.address'
                )
                ->label('Dirección'),

                Tables\Columns\TextColumn::make(
                    'type'
                )
                ->label('Tipo'),

                Tables\Columns\BadgeColumn::make(
                    'status'
                )
                ->colors([
                    'warning' => 'pending',
                    'primary' => 'in_progress',
                    'success' => 'completed',
                ]),
            ]);
    }
}

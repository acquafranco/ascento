<?php

namespace App\Filament\Resources\StockItems\RelationManagers;

use App\Filament\Resources\StockMovements\StockMovementResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

/** Historial de un material (solo lectura). */
class MovementsRelationManager extends RelationManager
{
    use \App\Filament\Concerns\OwnerRecordOfCurrentCompany;

    protected static string $relationship = 'movements';

    protected static ?string $title = 'Historial de movimientos';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return StockMovementResource::table($table)->filters([]);
    }
}

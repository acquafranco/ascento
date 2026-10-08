<?php

namespace App\Filament\Resources\Receivables\RelationManagers;

use App\Models\ReceivablePayment;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Pagos';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('paid_at')->label('Fecha')->date('d/m/Y'),
                TextColumn::make('amount')->label('Importe')->money('ARS', locale: 'es_AR'),
                TextColumn::make('method')->label('Medio')->formatStateUsing(fn ($state) => ReceivablePayment::METHODS[$state] ?? $state),
                TextColumn::make('notes')->label('Observación')->placeholder('—'),
                TextColumn::make('user.name')->label('Registró')->placeholder('—'),
            ])
            ->emptyStateHeading('Sin pagos todavía');
    }
}

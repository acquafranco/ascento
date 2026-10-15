<?php

namespace App\Filament\Resources\Emergency\RelationManagers;

use App\Enums\PermissionArea;
use App\Filament\Concerns\GuardedRelation;
use App\Filament\Concerns\OwnerRecordOfCurrentCompany;
use App\Models\EmergencyDelivery;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** A quién se avisó y qué pasó con cada canal (auditoría de la entrega; no confirma lectura salvo que figure). */
class DeliveriesRelationManager extends RelationManager
{
    use GuardedRelation;

    public static function permissionArea(): ?PermissionArea
    {
        return PermissionArea::WorkOrders;
    }

    use OwnerRecordOfCurrentCompany;

    protected static string $relationship = 'deliveries';

    protected static ?string $title = 'Avisos';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'notification']))
            ->defaultSort('id')
            ->columns([
                TextColumn::make('round')->label('Ronda')->alignCenter(),
                TextColumn::make('user.name')->label('Destinatario'),
                TextColumn::make('audience')->label('Quién')->formatStateUsing(fn ($state) => EmergencyDelivery::AUDIENCES[$state] ?? $state),
                TextColumn::make('result')->label('Resultado')->state(fn (EmergencyDelivery $r) => $r->summary())->wrap(),
                TextColumn::make('created_at')->label('Cuándo')->dateTime('d/m/Y H:i:s'),
            ])
            ->emptyStateHeading('Sin avisos registrados');
    }
}

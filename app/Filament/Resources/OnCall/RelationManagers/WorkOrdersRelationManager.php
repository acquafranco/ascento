<?php

namespace App\Filament\Resources\OnCall\RelationManagers;

use App\Enums\PermissionArea;
use App\Filament\Concerns\GuardedRelation;
use App\Filament\Concerns\OwnerRecordOfCurrentCompany;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\WorkOrder;
use App\Support\WorkOrderLabels;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Trabajos originados durante la guardia (órdenes de trabajo vinculadas a un
 * turno). Solo lectura: se gestionan en «Órdenes de trabajo». Exige ese permiso.
 */
class WorkOrdersRelationManager extends RelationManager
{
    use GuardedRelation;

    public static function permissionArea(): ?PermissionArea
    {
        return PermissionArea::WorkOrders;
    }

    use OwnerRecordOfCurrentCompany;

    protected static string $relationship = 'workOrders';

    protected static ?string $title = 'Trabajos durante la guardia';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['building', 'users', 'onCallShift.user', 'deliveryNote']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Cargada')->dateTime('d/m/Y H:i'),
                TextColumn::make('building.name')->label('Edificio')->formatStateUsing(fn ($state, WorkOrder $r) => trim($r->building?->name.' '.$r->building?->address))->wrap(),
                TextColumn::make('type')->label('Tipo')->formatStateUsing(fn ($state) => WorkOrderLabels::type($state)),
                TextColumn::make('guard')->label('De guardia')->state(fn (WorkOrder $r) => $r->onCallShift?->user?->name),
                TextColumn::make('users')->label('Lo hizo')->state(fn (WorkOrder $r) => $r->users->pluck('name')->implode(', '))->wrap(),
                TextColumn::make('status')->label('Estado')->badge()->formatStateUsing(fn ($state) => WorkOrderLabels::status($state))
                    ->color(fn ($state) => WorkOrderLabels::statusColor($state))->icon(fn ($state) => WorkOrderLabels::statusIcon($state))
                    ->description(fn (WorkOrder $r) => $r->deliveryNote ? 'Con remito' : null),
            ])
            ->recordUrl(fn (WorkOrder $r) => WorkOrderResource::canEdit($r) ? WorkOrderResource::getUrl('edit', ['record' => $r]) : null)
            ->emptyStateHeading('Sin trabajos vinculados')
            ->emptyStateDescription('Al cargar una orden de trabajo originada en una guardia, elegí el turno en «Guardia».');
    }
}

<?php

namespace App\Filament\RelationManagers;

use App\Enums\PermissionArea;
use App\Filament\Concerns\GuardedRelation;
use App\Filament\Concerns\OwnerRecordOfCurrentCompany;
use App\Filament\Resources\MaintenanceServices\MaintenanceServiceResource;
use App\Models\MaintenanceService;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Servicios de un cliente o edificio (se gestionan en "Servicios"). */
class ServicesRelationManager extends RelationManager
{
    use GuardedRelation;

    /** Área de permisos de administrativos (Etapa 6). */
    public static function permissionArea(): ?PermissionArea
    {
        return PermissionArea::Receivables;
    }

    use OwnerRecordOfCurrentCompany;

    protected static string $relationship = 'maintenanceServices';

    protected static ?string $title = 'Servicios';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('description')->label('Servicio'),
                TextColumn::make('amount')->label('Importe')->formatStateUsing(fn ($state, MaintenanceService $r) => $r->amountLabel()),
                TextColumn::make('start_date')->label('Desde')->date('d/m/Y'),
                TextColumn::make('status')->label('Estado')->badge()
                    ->formatStateUsing(fn ($state) => MaintenanceService::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'active' => 'success', 'paused' => 'warning', default => 'gray'
                    }),
            ])
            ->headerActions([
                Action::make('manage')->label('Gestionar servicios')->color('gray')->url(MaintenanceServiceResource::getUrl('index'))
                    ->visible(fn () => MaintenanceServiceResource::canAccess()),
            ])
            // Enlaces solo a lo que el usuario puede abrir (un administrativo con «ver»: la ficha de lectura).
            ->recordUrl(fn (MaintenanceService $r) => match (true) {
                MaintenanceServiceResource::canEdit($r) => MaintenanceServiceResource::getUrl('edit', ['record' => $r]),
                MaintenanceServiceResource::canView($r) => MaintenanceServiceResource::getUrl('view', ['record' => $r]),
                default => null,
            })
            ->emptyStateHeading('Sin servicio de mantenimiento')
            ->emptyStateActions([
                Action::make('create')->label('Crear servicio')->url(MaintenanceServiceResource::getUrl('create'))
                    ->visible(fn () => MaintenanceServiceResource::canCreate()),
            ]);
    }
}

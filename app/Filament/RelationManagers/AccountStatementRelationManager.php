<?php

namespace App\Filament\RelationManagers;

use App\Enums\PermissionArea;
use App\Filament\Concerns\GuardedRelation;
use App\Filament\Concerns\OwnerRecordOfCurrentCompany;
use App\Filament\Resources\Receivables\ReceivableActions;
use App\Filament\Resources\Receivables\ReceivableResource;
use App\Models\Building;
use App\Models\Client;
use App\Models\MaintenanceService;
use App\Models\Receivable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Cuenta corriente / estado de cuenta de un cliente o edificio. En una
 * contratación (Servicios contratados) muestra sus cobros, como «Cobros».
 */
class AccountStatementRelationManager extends RelationManager
{
    use GuardedRelation;

    /** Área de permisos de administrativos (Etapa 6). */
    public static function permissionArea(): ?PermissionArea
    {
        return PermissionArea::Receivables;
    }

    use OwnerRecordOfCurrentCompany;

    protected static string $relationship = 'receivables';

    protected static ?string $title = 'Cuenta corriente';

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return $ownerRecord instanceof MaintenanceService ? 'Cobros' : static::$title;
    }

    public function table(Table $table): Table
    {
        $owner = $this->getOwnerRecord();
        $open = $owner->receivables()->open();
        $balance = (float) (clone $open)->selectRaw('SUM(amount - paid_amount) as total')->value('total');
        $overdue = (float) $owner->receivables()->overdue()->selectRaw('SUM(amount - paid_amount) as total')->value('total');

        return $table
            ->defaultSort('due_date', 'desc')
            ->description('Saldo pendiente: '.ReceivableActions::money($balance).($overdue > 0 ? ' · Vencido: '.ReceivableActions::money($overdue) : ''))
            ->columns([
                TextColumn::make('concept')->label('Concepto')->wrap(),
                TextColumn::make('amount')->label('Importe')->money('ARS', locale: 'es_AR'),
                TextColumn::make('balance')->label('Saldo')->state(fn (Receivable $r) => $r->balance())->money('ARS', locale: 'es_AR'),
                TextColumn::make('due_date')->label('Vencimiento')->date('d/m/Y')->color(fn (Receivable $r) => $r->isOverdue() ? 'danger' : null),
                TextColumn::make('status')->label('Estado')->badge()
                    ->state(fn (Receivable $r) => $r->displayStatusLabel())
                    ->color(fn (Receivable $r) => ReceivableResource::statusColor($r->displayStatus())),
            ])
            // En una contratación sus cobros los genera el servicio: no se cargan cobros manuales acá.
            ->headerActions($owner instanceof MaintenanceService ? [] : [
                ReceivableActions::createManual(
                    client: $owner instanceof Client ? $owner : null,
                    building: $owner instanceof Building ? $owner : null,
                ),
            ])
            ->recordActions([ReceivableActions::pay()])
            ->recordUrl(fn (Receivable $r) => ReceivableResource::getUrl('view', ['record' => $r]))
            ->emptyStateHeading('Sin cobros registrados');
    }
}

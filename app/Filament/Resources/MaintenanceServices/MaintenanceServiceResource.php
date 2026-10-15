<?php

namespace App\Filament\Resources\MaintenanceServices;

use App\Enums\PermissionArea;
use App\Filament\Concerns\GuardedByPermission;
use App\Filament\Concerns\ScopedToCurrentCompany;
use App\Filament\Resources\MaintenanceServices\Pages\CreateMaintenanceService;
use App\Filament\Resources\MaintenanceServices\Pages\EditMaintenanceService;
use App\Filament\Resources\MaintenanceServices\Pages\ListMaintenanceServices;
use App\Filament\Resources\MaintenanceServices\Pages\ViewMaintenanceService;
use App\Filament\Resources\MaintenanceServices\Schemas\ServiceAssignmentForm;
use App\Filament\Resources\Receivables\ReceivableResource;
use App\Models\MaintenanceService;
use App\Models\Receivable;
use App\Models\ServiceCatalogItem;
use App\Services\Catalog\ServicePricing;
use App\Support\CompanyContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Servicios / contratos de mantenimiento. Cada período de un servicio
 * activo genera una obligación de cobro (ver ServiceBillingService).
 */
class MaintenanceServiceResource extends Resource
{
    use GuardedByPermission;

    /** Área de permisos de administrativos (Etapa 6). */
    public static function permissionArea(): ?PermissionArea
    {
        return PermissionArea::Receivables;
    }

    use ScopedToCurrentCompany;

    protected static ?string $model = MaintenanceService::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $navigationLabel = 'Servicios';

    protected static ?string $modelLabel = 'Servicio';

    protected static ?string $pluralModelLabel = 'Servicios';

    protected static string|\UnitEnum|null $navigationGroup = 'Comercial';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'description';

    public static function form(Schema $schema): Schema
    {
        // Mismo formulario que «Servicios contratados» de la ficha del cliente y del edificio.
        return $schema->components(ServiceAssignmentForm::components(creating: $schema->getOperation() === 'create'));
    }

    public static function infolist(Schema $schema): Schema
    {
        $visit = function (MaintenanceService $record, string $type): string {
            $status = $record->visitStatus($type);
            $last = $status['last'];
            $text = $last ? 'Última: '.$last->visited_at?->format('d/m/Y').' ('.($last->user?->name ?? '—').')' : 'Todavía no hay';

            if ($status['next']) {
                $month = ucfirst($status['next']->locale('es')->translatedFormat('F Y'));
                $text .= $status['done_this_month'] ? " · Este período: hecho · Próximo: {$month}" : " · Pendiente: {$month}";
            }

            return $text;
        };

        return $schema->components([
            Section::make('Contrato')->columns(3)->schema([
                TextEntry::make('client.name')->label('Cliente'),
                TextEntry::make('building.name')->label('Edificio')->placeholder('Todos los edificios del cliente'),
                TextEntry::make('units')->label('Equipos')->badge()->placeholder('Todos'),
                TextEntry::make('description')->label('Servicio'),
                TextEntry::make('category')->label('Tipo')->formatStateUsing(fn ($state) => ServiceCatalogItem::categoryLabel($state))->placeholder('Mantenimiento'),
                TextEntry::make('catalogItem.name')->label('Del catálogo')->placeholder('Sin catálogo'),
                TextEntry::make('amount')->label('Importe')->formatStateUsing(fn ($state, MaintenanceService $record) => $record->amountLabel()),
                TextEntry::make('pricing_explanation')->label('Cómo se calculó')->state(fn (MaintenanceService $record) => $record->pricingExplanation())
                    ->helperText(fn (MaintenanceService $record) => self::equipmentChangedNote($record)),
                // Pago único: estado de su cobro (informativo; el estado del contrato no cambia).
                TextEntry::make('one_time_charge')->label('Cobro del pago único')
                    ->state(fn (MaintenanceService $record) => $record->oneTimeCharge()?->displayStatusLabel())
                    ->badge()->color(fn (MaintenanceService $record) => self::chargeColor($record->oneTimeCharge()))
                    ->helperText(fn (MaintenanceService $record) => ($charge = $record->oneTimeCharge())
                        ? 'Vence el '.$charge->due_date?->format('d/m/Y').' · saldo '.ServicePricing::money($charge->balance())
                        : null)
                    ->url(fn (MaintenanceService $record) => self::chargeUrl($record->oneTimeCharge()))
                    ->visible(fn (MaintenanceService $record) => $record->oneTimeCharge() !== null),
                TextEntry::make('frequency')->label('Frecuencia de cobro')->formatStateUsing(fn ($state, MaintenanceService $record) => $record->frequencyLabel()),
                TextEntry::make('status')->label('Estado')->badge()->formatStateUsing(fn ($state) => MaintenanceService::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'active' => 'success', 'paused' => 'warning', default => 'gray'
                    }),
                TextEntry::make('start_date')->label('Inicio')->date('d/m/Y'),
                TextEntry::make('end_date')->label('Finalización')->date('d/m/Y')->placeholder('Sin fecha de fin'),
            ]),
            Section::make('Visitas realizadas')->columns(2)->schema([
                TextEntry::make('maintenance_summary')->label('Mantenimientos')
                    ->state(fn (MaintenanceService $record) => $record->maintenanceVisits()->count().' realizados · '.$visit($record, 'maintenance')),
                TextEntry::make('inspection_summary')->label('Inspecciones')
                    ->state(fn (MaintenanceService $record) => $record->inspectionVisits()->count().' realizadas · '.$visit($record, 'inspection')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['client', 'building', 'oneTimeReceivable']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('client.name')->label('Cliente')->searchable()->sortable(),
                TextColumn::make('building.name')->label('Edificio')
                    ->formatStateUsing(fn ($state, MaintenanceService $r) => trim($r->building?->name.' '.$r->building?->address))->placeholder('—'),
                TextColumn::make('description')->label('Servicio')->wrap()
                    ->description(fn (MaintenanceService $r) => ServiceCatalogItem::categoryLabel($r->category).' · '.$r->scopeLabel()),
                TextColumn::make('amount')->label('Importe')->formatStateUsing(fn ($state, MaintenanceService $r) => $r->amountLabel())
                    ->description(fn (MaintenanceService $r) => $r->pricing_mode ? $r->pricingExplanation() : null),
                static::oneTimeChargeColumn(),
                TextColumn::make('start_date')->label('Desde')->date('d/m/Y')->toggleable(),
                TextColumn::make('end_date')->label('Hasta')->date('d/m/Y')->placeholder('—')->toggleable(),
                TextColumn::make('status')->label('Estado')->badge()
                    ->formatStateUsing(fn ($state) => MaintenanceService::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'active' => 'success', 'paused' => 'warning', default => 'gray'
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(MaintenanceService::STATUSES),
                SelectFilter::make('category')->label('Tipo')->options(collect(ServiceCatalogItem::CATEGORIES)->map(fn ($c) => $c[0])),
                SelectFilter::make('catalog_item_id')->label('Servicio del catálogo')->relationship('catalogItem', 'name', fn ($query) => $query->where('company_id', CompanyContext::currentId())),
                SelectFilter::make('client_id')->label('Cliente')->relationship('client', 'name', fn ($query) => $query->where('company_id', CompanyContext::currentId()))->searchable()->preload(),
            ])
            ->recordActions([
                static::statusAction(MaintenanceService::PAUSED, 'Pausar', 'heroicon-o-pause', 'warning')
                    ->visible(fn (MaintenanceService $r) => $r->status === MaintenanceService::ACTIVE),
                static::statusAction(MaintenanceService::ACTIVE, 'Activar', 'heroicon-o-play', 'success')
                    ->visible(fn (MaintenanceService $r) => $r->status !== MaintenanceService::ACTIVE),
                ViewAction::make(),
                EditAction::make(),
            ]);
    }

    /** Si los equipos del alcance cambiaron desde el cálculo, se avisa (el importe acordado no cambia solo). */
    public static function equipmentChangedNote(MaintenanceService $record): ?string
    {
        if (! in_array($record->pricing_mode, [ServiceCatalogItem::PER_EQUIPMENT, ServiceCatalogItem::PER_STOP], true) || $record->quantity === null) {
            return null;
        }

        $now = app(ServicePricing::class)->calculate((int) $record->company_id, $record->pricing_mode, (float) $record->unit_price, (int) $record->client_id, $record->building_id, $record->units, $record->pricing_detail['equipment_kind'] ?? 'all');

        return $now['error'] === null && $now['quantity'] !== (int) $record->quantity
            ? "Hoy el alcance tiene {$now['quantity']} (se calculó con {$record->quantity}). El importe acordado no cambia solo: editá la contratación para recalcularlo."
            : null;
    }

    /** Columna «Cobro» de los servicios de pago único: estado de su cobro, con enlace. Vacía si no hay cobro. */
    public static function oneTimeChargeColumn(): TextColumn
    {
        return TextColumn::make('one_time_charge')->label('Cobro (pago único)')
            ->state(fn (MaintenanceService $r) => $r->oneTimeCharge()?->displayStatusLabel())
            ->badge()->color(fn (MaintenanceService $r) => self::chargeColor($r->oneTimeCharge()))
            ->url(fn (MaintenanceService $r) => self::chargeUrl($r->oneTimeCharge()))
            ->placeholder('—')->toggleable();
    }

    private static function chargeColor(?Receivable $charge): string
    {
        return match ($charge?->displayStatus()) {
            Receivable::PAID => 'success',
            'overdue' => 'danger',
            Receivable::PARTIAL => 'warning',
            Receivable::PENDING => 'info',
            default => 'gray',
        };
    }

    /** Enlace al cobro, solo si el usuario puede abrirlo. */
    private static function chargeUrl(?Receivable $charge): ?string
    {
        return $charge && ReceivableResource::canView($charge) ? ReceivableResource::getUrl('view', ['record' => $charge]) : null;
    }

    public static function statusAction(string $status, string $label, string $icon, string $color): Action
    {
        return Action::make('set_'.$status)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->requiresConfirmation()
            ->modalDescription($status === MaintenanceService::ACTIVE
                ? 'Se generan los cobros pendientes desde ahora (los períodos anteriores no).'
                : 'Mientras esté pausado no se generan cobros nuevos. Los ya generados no cambian.')
            ->action(function (MaintenanceService $record) use ($status) {
                $record->update(['status' => $status]);

                Notification::make()->title('Servicio '.mb_strtolower(MaintenanceService::STATUSES[$status]))->success()->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMaintenanceServices::route('/'),
            'create' => CreateMaintenanceService::route('/create'),
            'view' => ViewMaintenanceService::route('/{record}'),
            'edit' => EditMaintenanceService::route('/{record}/edit'),
        ];
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\VisitsRelationManager::class,
        ];
    }
}

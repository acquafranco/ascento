<?php

namespace App\Filament\Resources\MaintenanceServices;

use App\Filament\Concerns\ScopedToCurrentCompany;
use App\Filament\Resources\MaintenanceServices\Pages\CreateMaintenanceService;
use App\Filament\Resources\MaintenanceServices\Pages\EditMaintenanceService;
use App\Filament\Resources\MaintenanceServices\Pages\ListMaintenanceServices;
use App\Models\Building;
use App\Models\MaintenanceService;
use App\Support\CompanyContext;
use BackedEnum;
use Filament\Actions\Action;
use App\Filament\Resources\MaintenanceServices\Pages\ViewMaintenanceService;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
        $companyId = fn () => CompanyContext::currentId();

        return $schema->components([
            Grid::make(2)->schema([
                Select::make('client_id')
                    ->label('Cliente')
                    ->relationship('client', 'name', fn ($query) => $query->withoutTrashed()->where('company_id', $companyId()))
                    ->searchable()->preload()->required()->live()
                    ->afterStateUpdated(fn (Set $set) => $set('building_id', null)),
                Select::make('building_id')
                    ->label('Edificio')
                    ->relationship('building', 'name', fn ($query, Get $get) => $query->withoutTrashed()
                        ->where('company_id', $companyId())
                        ->where('client_id', $get('client_id')))
                    ->getOptionLabelFromRecordUsing(fn (Building $record) => trim("{$record->name} {$record->address}"))
                    ->searchable()->preload()->live()
                    ->afterStateUpdated(fn (Set $set) => $set('units', []))
                    ->helperText('Opcional: si el servicio cubre un edificio puntual del cliente.'),
            ]),
            Select::make('units')
                ->label('Equipos del contrato (opcional)')
                ->multiple()
                ->options(fn (Get $get) => collect(Building::find($get('building_id'))?->unitLabels() ?? [])->mapWithKeys(fn ($u) => [$u => $u]))
                ->visible(fn (Get $get) => filled($get('building_id')))
                ->helperText('Vacío = todos los equipos del edificio.')
                ->columnSpanFull(),
            TextInput::make('description')->label('Servicio')->required()->maxLength(255)->default('Mantenimiento mensual de ascensores')->columnSpanFull(),
            Grid::make(3)->schema([
                TextInput::make('amount')->label('Importe por período')->numeric()->minValue(1)->prefix('$')->required(),
                Select::make('frequency')->label('Frecuencia')->options(collect(MaintenanceService::FREQUENCIES)->map(fn ($f) => $f[0]))->default('monthly')->required()->native(false),
                TextInput::make('payment_due_day')->label('Día de vencimiento')->numeric()->minValue(1)->maxValue(28)->default(10)->required()
                    ->helperText('Del 1 al 28 de cada período.'),
            ]),
            Grid::make(3)->schema([
                DatePicker::make('start_date')->label('Inicio')->required()->default(today())->native(false)->displayFormat('d/m/Y'),
                DatePicker::make('end_date')->label('Finalización (opcional)')->native(false)->displayFormat('d/m/Y')->afterOrEqual('start_date'),
                Select::make('status')->label('Estado')->options(MaintenanceService::STATUSES)->default(MaintenanceService::ACTIVE)->required()->native(false),
            ]),
            Textarea::make('notes')->label('Observaciones')->rows(2)->columnSpanFull(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        $visit = function (MaintenanceService $record, string $type): string {
            $status = $record->visitStatus($type);
            $last = $status['last'];
            $text = $last ? 'Última: '.$last->visited_at?->format('d/m/Y').' ('.($last->user?->name ?? '—').')' : 'Todavía no hay';

            if ($status['next']) {
                $month = ucfirst($status['next']->locale('es')->translatedFormat('F Y'));
                $text .= $status['done_this_month'] ? " · Este mes: hecho · Próximo: {$month}" : " · Pendiente: {$month}";
            }

            return $text;
        };

        return $schema->components([
            Section::make('Contrato')->columns(3)->schema([
                TextEntry::make('client.name')->label('Cliente'),
                TextEntry::make('building.name')->label('Edificio')->placeholder('Todos los edificios del cliente'),
                TextEntry::make('units')->label('Equipos')->badge()->placeholder('Todos'),
                TextEntry::make('description')->label('Servicio'),
                TextEntry::make('amount')->label('Importe')->formatStateUsing(fn ($state, MaintenanceService $record) => $record->amountLabel()),
                TextEntry::make('frequency')->label('Frecuencia de cobro')->formatStateUsing(fn ($state, MaintenanceService $record) => $record->frequencyLabel()),
                TextEntry::make('status')->label('Estado')->badge()->formatStateUsing(fn ($state) => MaintenanceService::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) { 'active' => 'success', 'paused' => 'warning', default => 'gray' }),
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
            ->modifyQueryUsing(fn ($query) => $query->with(['client', 'building']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('client.name')->label('Cliente')->searchable()->sortable(),
                TextColumn::make('building.name')->label('Edificio')
                    ->formatStateUsing(fn ($state, MaintenanceService $r) => trim($r->building?->name.' '.$r->building?->address))->placeholder('—'),
                TextColumn::make('description')->label('Servicio')->wrap(),
                TextColumn::make('amount')->label('Importe')->formatStateUsing(fn ($state, MaintenanceService $r) => $r->amountLabel()),
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

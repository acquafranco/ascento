<?php

namespace App\Filament\Resources\Reports;

use App\Enums\PermissionArea;
use App\Filament\Concerns\GuardedByPermission;
use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Filament\Resources\Reports\Pages\CreateReport;
use App\Filament\Resources\Reports\Pages\EditReport;
use App\Filament\Resources\Reports\Pages\ListReports;
use App\Filament\Resources\Reports\Pages\ViewReport;
use App\Filament\Resources\Reports\Schemas\ReportForm;
use App\Filament\Resources\Reports\Tables\ReportsTable;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\Company;
use App\Models\Report;
use BackedEnum;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ReportResource extends Resource
{
    use GuardedByPermission;

    /** Área de permisos de administrativos (Etapa 6). */
    public static function permissionArea(): ?PermissionArea
    {
        return PermissionArea::Inspections;
    }

    protected static ?string $model = Report::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Reportes';

    protected static string|\UnitEnum|null $navigationGroup = 'Operaciones';

    protected static ?int $navigationSort = 6;

    protected static ?string $modelLabel = 'reporte';

    protected static ?string $pluralModelLabel = 'reportes';

    public static function form(Schema $schema): Schema
    {
        return ReportForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ReportsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Información del reporte')
                ->columns(2)
                ->schema([
                    TextEntry::make('created_at')
                        ->label('Fecha')
                        ->dateTime('d/m/Y H:i'),

                    TextEntry::make('kind')
                        ->label('Tipo')
                        ->badge()
                        ->formatStateUsing(fn (?string $state) => Report::KIND_LABELS[$state] ?? 'Falla'),

                    TextEntry::make('status')
                        ->label('Estado')
                        ->visible(fn (Report $record) => ! $record->isInspection())
                        ->badge(),

                    TextEntry::make('building.client.name')
                        ->label('Cliente'),

                    TextEntry::make('building.name')
                        ->label('Edificio'),

                    TextEntry::make('elevator_number')
                        ->label('Ascensor')
                        ->placeholder('General del edificio'),

                    TextEntry::make('user.name')
                        ->label('Técnico'),
                ]),

            // Intervención que abrió el técnico: de dónde vino, en qué orden y remito quedó, y cómo terminó.
            Section::make('Intervención del técnico')
                ->visible(fn (Report $record) => $record->isIntervention())
                ->columns(2)
                ->schema([
                    TextEntry::make('channel')->label('Reclamo recibido por')->formatStateUsing(fn (?string $state) => Report::CHANNELS[$state] ?? '—'),
                    TextEntry::make('result')->label('Resultado')->badge()->placeholder('Pendiente del remito')
                        ->formatStateUsing(fn (?string $state) => Report::RESULTS[$state] ?? '—'),
                    TextEntry::make('workOrder.id')->label('Orden de trabajo')->placeholder('—')
                        ->formatStateUsing(fn ($state, Report $record) => 'N.º '.$state.($record->workOrder?->isTechnicianInitiated() ? ' · iniciada por el técnico' : ' · creada por la oficina'))
                        ->url(fn (Report $record) => $record->work_order_id ? WorkOrderResource::getUrl('edit', ['record' => $record->work_order_id]) : null),
                    TextEntry::make('workOrder.deliveryNote.number')->label('Remito')->placeholder('Todavía sin remito')
                        ->formatStateUsing(fn ($state) => 'N.º '.$state)
                        ->url(fn (Report $record) => $record->workOrder?->deliveryNote ? DeliveryNoteResource::getUrl('view', ['record' => $record->workOrder->deliveryNote]) : null),
                    TextEntry::make('work_done')->label('Trabajo realizado')->placeholder('Se completa al firmar el remito')->columnSpanFull(),
                ]),

            Section::make('Evidencia fotográfica')
                ->columns(2)
                ->schema([
                    // Siempre por la ruta autenticada (disco privado).
                    ImageEntry::make('photos')
                        ->label('Fotos')
                        ->state(fn ($record) => $record->photos->map->thumbUrl()->all())
                        ->imageHeight(140)
                        ->placeholder('Sin fotos'),

                    Grid::make(1)
                        ->schema([
                            TextEntry::make('priority')
                                ->label('Prioridad')
                                ->visible(fn (Report $record) => ! $record->isInspection())
                                ->badge(),
                        ]),
                ]),

            Section::make(fn (Report $record) => $record->isInspection() ? 'Observaciones de la inspección' : 'Descripción')
                ->schema([
                    TextEntry::make('description')
                        ->hiddenLabel(),
                ]),

            Section::make(fn (Report $record) => $record->isInspection() ? 'Notas de la oficina' : 'Observaciones')
                ->schema([
                    TextEntry::make('observations')
                        ->hiddenLabel()
                        ->placeholder('Sin observaciones.'),
                ]),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $user = auth()->user();
        $companyId = $user->isSuperAdmin() ? session('selected_company_id') : $user->company_id;

        if (! $companyId) {
            return $query->whereRaw('1 = 0');
        }

        $query->where('company_id', $companyId);

        // Reportes de inspección: exclusivos del plan Empresa (se conservan, sin acceso).
        if (! Company::hasWorkPhotosById((int) $companyId)) {
            $query->where('kind', '!=', Report::KIND_INSPECTION);
        }

        return $query;
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\PhotosRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReports::route('/'),
            'create' => CreateReport::route('/create'),
            'view' => ViewReport::route('/{record}'),
            'edit' => EditReport::route('/{record}/edit'),
        ];
    }
}

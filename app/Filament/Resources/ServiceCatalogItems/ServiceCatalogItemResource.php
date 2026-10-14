<?php

namespace App\Filament\Resources\ServiceCatalogItems;

use App\Enums\PermissionArea;
use App\Filament\Concerns\GuardedByPermission;
use App\Filament\Concerns\ScopedToCurrentCompany;
use App\Filament\Resources\ServiceCatalogItems\Pages\CreateServiceCatalogItem;
use App\Filament\Resources\ServiceCatalogItems\Pages\EditServiceCatalogItem;
use App\Filament\Resources\ServiceCatalogItems\Pages\ListServiceCatalogItems;
use App\Models\MaintenanceService;
use App\Models\ServiceCatalogItem;
use App\Services\Catalog\ServicePricing;
use App\Support\CompanyContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rule;

/**
 * Catálogo de servicios que ofrece la empresa. Crear uno no lo asigna a nadie:
 * se contrata desde la ficha del cliente o del edificio (o en «Servicios»).
 * Cambiar su precio no modifica las contrataciones existentes.
 */
class ServiceCatalogItemResource extends Resource
{
    use GuardedByPermission;

    /** Misma área que los servicios contratados y las cobranzas (Etapa 6). */
    public static function permissionArea(): ?PermissionArea
    {
        return PermissionArea::Receivables;
    }

    use ScopedToCurrentCompany;

    protected static ?string $model = ServiceCatalogItem::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Catálogo de servicios';

    protected static ?string $modelLabel = 'Servicio del catálogo';

    protected static ?string $pluralModelLabel = 'Catálogo de servicios';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|\UnitEnum|null $navigationGroup = 'Comercial';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'catalogo-servicios';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Servicio')->columnSpanFull()->schema([
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    TextInput::make('name')->label('Nombre')->required()->maxLength(120)
                        ->placeholder('Ej: Mantenimiento preventivo de ascensor')
                        ->rule(fn (?ServiceCatalogItem $record) => Rule::unique('service_catalog_items', 'name')
                            ->where('company_id', CompanyContext::currentId())->whereNull('deleted_at')->ignore($record?->id))
                        ->validationMessages(['unique' => 'Ya hay un servicio con ese nombre en el catálogo.']),
                    Select::make('category')->label('Tipo de servicio')->required()->native(false)
                        ->options(collect(ServiceCatalogItem::CATEGORIES)->map(fn ($c) => $c[0]))->default('maintenance')
                        ->helperText('Solo mantenimiento e inspección cubren las visitas periódicas de los edificios.'),
                ]),
                Textarea::make('description')->label('Descripción')->rows(2)->maxLength(2000),
                TagsInput::make('includes')->label('Qué incluye (opcional)')->placeholder('Ej: Revisión de frenos')
                    ->helperText('Escribí cada ítem y tocá Enter.'),
            ]),
            Section::make('Precio de referencia')->columnSpanFull()
                ->description('Se copia al contratar y se puede ajustar para cada cliente. Cambiarlo acá no modifica las contrataciones existentes.')
                ->schema([
                    Grid::make(['default' => 1, 'md' => 2])->schema([
                        Select::make('pricing_mode')->label('Modalidad')->required()->native(false)->live()
                            ->options(collect(ServiceCatalogItem::PRICING_MODES)->map(fn ($m) => $m[0]))->default(ServiceCatalogItem::PER_EQUIPMENT)
                            ->helperText(fn (Get $get) => ServiceCatalogItem::PRICING_MODES[$get('pricing_mode')][2] ?? null),
                        Select::make('equipment_kind')->label('Equipos que cuenta')->native(false)
                            ->options(ServiceCatalogItem::EQUIPMENT_KINDS)->default('all')
                            ->visible(fn (Get $get) => in_array($get('pricing_mode'), [ServiceCatalogItem::PER_EQUIPMENT, ServiceCatalogItem::PER_STOP], true))
                            ->required(fn (Get $get) => in_array($get('pricing_mode'), [ServiceCatalogItem::PER_EQUIPMENT, ServiceCatalogItem::PER_STOP], true)),
                        TextInput::make('base_price')
                            ->label(fn (Get $get) => ($get('pricing_mode') === ServiceCatalogItem::CUSTOM ? 'Importe sugerido (opcional)' : (ServiceCatalogItem::PRICING_MODES[$get('pricing_mode')][1] ?? 'Precio')))
                            ->numeric()->minValue(1)->maxValue(999999999)->prefix('$')
                            ->required(fn (Get $get) => $get('pricing_mode') !== ServiceCatalogItem::CUSTOM),
                        Select::make('billing_frequency')->label('Frecuencia de cobro sugerida')->required()->native(false)
                            ->options(collect(MaintenanceService::FREQUENCIES)->map(fn ($f) => $f[0]))->default('monthly')
                            ->helperText('Cada cuánto se cobra. No cambia cada cuánto se visita un edificio.'),
                    ]),
                ]),
            Section::make('Otros datos')->columnSpanFull()->schema([
                Textarea::make('notes')->label('Observaciones internas')->rows(2)->maxLength(2000),
                Toggle::make('is_active')->label('Disponible para contratar')->default(true)
                    ->helperText('Desactivarlo no cambia las contrataciones existentes: solo deja de ofrecerse.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount(['contracts as active_contracts_count' => fn ($q) => $q->where('status', MaintenanceService::ACTIVE)]))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Servicio')->searchable()->sortable()->wrap()
                    ->description(fn (ServiceCatalogItem $r) => $r->description ? str($r->description)->limit(90) : null),
                TextColumn::make('category')->label('Tipo')->badge()->color('gray')->formatStateUsing(fn ($state) => ServiceCatalogItem::categoryLabel($state)),
                TextColumn::make('base_price')->label('Precio de referencia')
                    ->state(fn (ServiceCatalogItem $r) => self::priceLabel($r))->wrap(),
                TextColumn::make('billing_frequency')->label('Cobro')->formatStateUsing(fn ($state) => MaintenanceService::FREQUENCIES[$state][0] ?? $state)->toggleable(),
                TextColumn::make('active_contracts_count')->label('Contratos activos')->alignCenter(),
                IconColumn::make('is_active')->label('Disponible')->boolean(),
            ])
            ->filters([
                SelectFilter::make('category')->label('Tipo')->options(collect(ServiceCatalogItem::CATEGORIES)->map(fn ($c) => $c[0])),
                TernaryFilter::make('is_active')->label('Disponible'),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('toggle')
                    ->label(fn (ServiceCatalogItem $r) => $r->is_active ? 'Desactivar' : 'Activar')
                    ->icon(fn (ServiceCatalogItem $r) => $r->is_active ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription(fn (ServiceCatalogItem $r) => $r->is_active
                        ? 'Deja de ofrecerse al contratar. Las contrataciones existentes siguen igual.'
                        : 'Vuelve a estar disponible para contratar.')
                    ->action(function (ServiceCatalogItem $record) {
                        $record->update(['is_active' => ! $record->is_active]);
                        Notification::make()->title($record->is_active ? 'Servicio disponible' : 'Servicio desactivado')->success()->send();
                    }),
                // Solo si nunca se contrató: lo contratado conserva su referencia.
                DeleteAction::make()->visible(fn (ServiceCatalogItem $r) => ! $r->contracts()->withTrashed()->exists()),
            ])
            ->emptyStateHeading('Todavía no hay servicios en el catálogo')
            ->emptyStateDescription('Creá los servicios que ofrecés (mantenimiento por equipo, guardia, prueba de paracaídas…) y después contratalos para cada cliente sin volver a cargarlos.');
    }

    /** «$50.000 por equipo», «$80.000 fijo», «A acordar». */
    public static function priceLabel(ServiceCatalogItem $item): string
    {
        if ($item->base_price === null) {
            return 'A acordar';
        }

        $money = ServicePricing::money((float) $item->base_price);

        return match ($item->pricing_mode) {
            ServiceCatalogItem::PER_EQUIPMENT => $money.' por equipo'.($item->equipment_kind && $item->equipment_kind !== 'all' ? ' ('.mb_strtolower(ServiceCatalogItem::EQUIPMENT_KINDS[$item->equipment_kind]).')' : ''),
            ServiceCatalogItem::PER_STOP => $money.' por parada',
            ServiceCatalogItem::FIXED => $money.' fijo',
            default => $money.' sugerido',
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServiceCatalogItems::route('/'),
            'create' => CreateServiceCatalogItem::route('/create'),
            'edit' => EditServiceCatalogItem::route('/{record}/edit'),
        ];
    }
}

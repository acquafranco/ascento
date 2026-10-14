<?php

namespace App\Filament\Resources\MaintenanceServices\Schemas;

use App\Models\Building;
use App\Models\MaintenanceService;
use App\Models\ServiceCatalogItem;
use App\Services\Catalog\ServicePricing;
use App\Support\CompanyContext;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Contratación de un servicio (del catálogo o con precio acordado) para un
 * cliente, uno o varios de sus edificios, o algunos equipos de un edificio.
 * Lo usan «Servicios» y la sección «Servicios contratados» de la ficha del
 * cliente y del edificio: un solo formulario, una sola regla de cálculo.
 *
 * - El importe lo calcula ServicePricing en el servidor al guardar (nunca el navegador).
 * - Varios edificios = una contratación por edificio (cada uno con sus equipos e
 *   importe): así lo entienden la facturación, las visitas y la agenda.
 * - El catálogo se copia al contratar: cambiarlo después no toca lo acordado.
 */
final class ServiceAssignmentForm
{
    public const SCOPE_CLIENT = 'client';

    public const SCOPE_BUILDINGS = 'buildings';

    public const SCOPE_EQUIPMENT = 'equipment';

    /**
     * @param  int|null  $fixedClientId  cliente de la ficha (no se elige)
     * @param  int|null  $fixedBuildingId  edificio de la ficha (no se elige)
     */
    public static function components(bool $creating, ?int $fixedClientId = null, ?int $fixedBuildingId = null): array
    {
        $companyId = fn () => CompanyContext::currentId();
        $clientOf = fn (Get $get) => $fixedClientId ?? (int) $get('client_id');

        return [
            Section::make('Servicio')->columnSpanFull()->schema([
                Select::make('catalog_item_id')
                    ->label('Servicio del catálogo')
                    ->placeholder('Sin catálogo (precio acordado)')
                    ->options(fn (?MaintenanceService $record) => ServiceCatalogItem::query()
                        ->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $record?->catalog_item_id))
                        ->orderBy('name')->pluck('name', 'id'))
                    ->searchable()->live()
                    ->helperText('El precio del catálogo es una referencia: se copia acá y lo podés ajustar para este cliente.')
                    ->afterStateUpdated(function (?string $state, Set $set) {
                        $item = $state ? ServiceCatalogItem::find($state) : null;
                        if (! $item) {
                            return;
                        }
                        $set('description', $item->name);
                        $set('category', $item->category);
                        $set('pricing_mode', $item->pricing_mode);
                        $set('equipment_kind', $item->equipment_kind ?: 'all');
                        $set('unit_price', $item->base_price !== null ? (float) $item->base_price : null);
                        $set('frequency', $item->billing_frequency ?: 'monthly');
                    }),
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    TextInput::make('description')->label('Nombre en el contrato')->required()->maxLength(255)->default('Mantenimiento mensual de ascensores'),
                    Select::make('category')->label('Tipo de servicio')->required()->native(false)
                        ->options(collect(ServiceCatalogItem::CATEGORIES)->map(fn ($c) => $c[0]))->default('maintenance')
                        ->helperText('Solo mantenimiento e inspección cubren las visitas periódicas.'),
                ]),
            ]),

            Section::make('A qué se aplica')->columnSpanFull()->schema([
                Select::make('client_id')->label('Cliente')
                    ->relationship('client', 'name', fn ($query) => $query->withoutTrashed()->where('company_id', $companyId()))
                    ->searchable()->preload()->required()->live()
                    ->default($fixedClientId)
                    ->hidden($fixedClientId !== null)
                    ->dehydratedWhenHidden()
                    ->afterStateUpdated(fn (Set $set) => [$set('building_id', null), $set('building_ids', []), $set('units', [])]),
                Radio::make('scope')->label('Alcance')
                    ->options(fn () => $fixedBuildingId
                        ? [self::SCOPE_BUILDINGS => 'Todo el edificio', self::SCOPE_EQUIPMENT => 'Algunos equipos del edificio']
                        : [self::SCOPE_CLIENT => 'Todo el cliente (todos sus edificios)', self::SCOPE_BUILDINGS => $creating ? 'Uno o varios edificios' : 'Un edificio', self::SCOPE_EQUIPMENT => 'Algunos equipos de un edificio'])
                    ->default($fixedBuildingId ? self::SCOPE_BUILDINGS : self::SCOPE_CLIENT)
                    ->required()->live()
                    ->afterStateHydrated(function (Radio $component, ?MaintenanceService $record) use ($fixedBuildingId) {
                        if ($record) {
                            $component->state(match (true) {
                                ! $record->building_id => self::SCOPE_CLIENT,
                                (bool) $record->units => self::SCOPE_EQUIPMENT,
                                default => self::SCOPE_BUILDINGS,
                            });
                        } elseif ($fixedBuildingId) {
                            $component->state(self::SCOPE_BUILDINGS);
                        }
                    }),
                // Alta: uno o varios edificios (una contratación por edificio).
                CheckboxList::make('building_ids')->label('Edificios')
                    ->options(fn (Get $get) => self::buildingOptions($companyId(), $clientOf($get)))
                    ->visible(fn (Get $get) => $creating && ! $fixedBuildingId && $get('scope') === self::SCOPE_BUILDINGS)
                    ->required(fn (Get $get) => $creating && ! $fixedBuildingId && $get('scope') === self::SCOPE_BUILDINGS)
                    ->columns(['default' => 1, 'md' => 2])->bulkToggleable()->live()
                    ->helperText('Se crea una contratación por edificio, cada una con sus equipos e importe.'),
                Select::make('building_id')->label('Edificio')
                    ->options(fn (Get $get) => self::buildingOptions($companyId(), $clientOf($get)))
                    ->default($fixedBuildingId)
                    ->hidden($fixedBuildingId !== null)->dehydratedWhenHidden()
                    ->visible(fn (Get $get) => $fixedBuildingId !== null || $get('scope') === self::SCOPE_EQUIPMENT || (! $creating && $get('scope') === self::SCOPE_BUILDINGS))
                    // En el alta con varios edificios se eligen arriba (building_ids).
                    ->required(fn (Get $get) => $get('scope') !== self::SCOPE_CLIENT && ! ($creating && ! $fixedBuildingId && $get('scope') === self::SCOPE_BUILDINGS))
                    ->searchable()->live()
                    ->afterStateUpdated(fn (Set $set) => $set('units', [])),
                CheckboxList::make('units')->label('Equipos')
                    ->options(fn (Get $get) => collect(Building::find($get('building_id'))?->unitLabels() ?? [])->mapWithKeys(fn ($u) => [$u => $u]))
                    ->visible(fn (Get $get) => $get('scope') === self::SCOPE_EQUIPMENT && filled($get('building_id')))
                    ->required(fn (Get $get) => $get('scope') === self::SCOPE_EQUIPMENT)
                    ->columns(['default' => 2, 'md' => 3])->live(),
            ]),

            Section::make('Precio')->columnSpanFull()->schema([
                Grid::make(['default' => 1, 'md' => 3])->schema([
                    Select::make('pricing_mode')->label('Modalidad')->required()->native(false)->live()
                        ->options(collect(ServiceCatalogItem::PRICING_MODES)->map(fn ($m) => $m[0]))
                        ->default(ServiceCatalogItem::CUSTOM)
                        ->afterStateHydrated(fn (Select $component, ?MaintenanceService $record) => $record && ! $record->pricing_mode ? $component->state(ServiceCatalogItem::CUSTOM) : null)
                        ->helperText(fn (Get $get) => ServiceCatalogItem::PRICING_MODES[$get('pricing_mode')][2] ?? null),
                    Select::make('equipment_kind')->label('Equipos que cuenta')->native(false)->live()
                        ->options(ServiceCatalogItem::EQUIPMENT_KINDS)->default('all')
                        ->visible(fn (Get $get) => in_array($get('pricing_mode'), [ServiceCatalogItem::PER_EQUIPMENT, ServiceCatalogItem::PER_STOP], true))
                        ->afterStateHydrated(fn (Select $component, ?MaintenanceService $record) => $record ? $component->state($record->pricing_detail['equipment_kind'] ?? 'all') : null),
                    TextInput::make('unit_price')
                        ->label(fn (Get $get) => ServiceCatalogItem::PRICING_MODES[$get('pricing_mode')][1] ?? 'Importe')
                        ->numeric()->minValue(1)->maxValue(999999999)->prefix('$')->required()->live(onBlur: true)
                        ->afterStateHydrated(fn (TextInput $component, ?MaintenanceService $record) => $record && $record->unit_price === null ? $component->state((float) $record->amount) : null),
                ]),
                View::make('filament.services.pricing-summary')
                    ->viewData(fn (Get $get) => ['summary' => self::preview($get, $creating, $fixedClientId, $fixedBuildingId)]),
            ]),

            Section::make('Cobro y vigencia')->columnSpanFull()->schema([
                Grid::make(['default' => 1, 'md' => 3])->schema([
                    Select::make('frequency')->label('Frecuencia de cobro')->options(collect(MaintenanceService::FREQUENCIES)->map(fn ($f) => $f[0]))->default('monthly')->required()->native(false)
                        ->helperText('Cada cuánto se le cobra al cliente. Cada cuánto se visita el edificio se configura aparte, en Edificios → «Frecuencia de visitas».'),
                    TextInput::make('payment_due_day')->label('Día de vencimiento')->numeric()->minValue(1)->maxValue(28)->default(10)->required()
                        ->helperText('Del 1 al 28 de cada período.'),
                    Select::make('status')->label('Estado')->options(MaintenanceService::STATUSES)->default(MaintenanceService::ACTIVE)->required()->native(false),
                    DatePicker::make('start_date')->label('Inicio')->required()->default(today())->native(false)->displayFormat('d/m/Y'),
                    DatePicker::make('end_date')->label('Finalización (opcional)')->native(false)->displayFormat('d/m/Y')->afterOrEqual('start_date'),
                ]),
                Textarea::make('notes')->label('Observaciones y condiciones particulares')->rows(2),
            ]),
            Hidden::make('amount'),
        ];
    }

    /** @return array<int, string> edificios activos del cliente, de la empresa actual */
    public static function buildingOptions(?int $companyId, ?int $clientId): array
    {
        if (! $companyId || ! $clientId) {
            return [];
        }

        return Building::query()->withoutTrashed()->where('company_id', $companyId)->where('client_id', $clientId)
            ->orderBy('name')->get(['id', 'name', 'address'])
            ->mapWithKeys(fn (Building $b) => [$b->id => trim($b->name.' '.$b->address)])->all();
    }

    /** Vista previa (la misma regla que al guardar). */
    private static function preview(Get $get, bool $creating, ?int $fixedClientId, ?int $fixedBuildingId): array
    {
        $clientId = $fixedClientId ?? (int) $get('client_id');
        $mode = (string) ($get('pricing_mode') ?: ServiceCatalogItem::CUSTOM);
        $price = (float) $get('unit_price');

        $scope = (string) $get('scope');
        if (! $clientId) {
            return ['ready' => false, 'hint' => 'Elegí el cliente para ver el importe.'];
        }

        $buildings = match (true) {
            $scope === self::SCOPE_CLIENT => [null],
            $creating && ! $fixedBuildingId && $scope === self::SCOPE_BUILDINGS => array_map('intval', (array) $get('building_ids')),
            default => [(int) ($fixedBuildingId ?? $get('building_id'))],
        };
        $buildings = array_values(array_filter($buildings, fn ($b) => $b === null || $b > 0));
        if ($buildings === []) {
            return ['ready' => false, 'hint' => $scope === self::SCOPE_EQUIPMENT ? 'Elegí el edificio y sus equipos para ver el importe.' : 'Elegí uno o más edificios para ver el importe.'];
        }
        // Igual que al guardar: con «algunos equipos» hay que elegirlos (vacío no es «todos»).
        if ($scope === self::SCOPE_EQUIPMENT && array_filter((array) $get('units')) === []) {
            return ['ready' => false, 'hint' => 'Elegí los equipos para ver el importe.'];
        }
        if ($price <= 0) {
            return ['ready' => false, 'hint' => 'Ingresá el precio para ver el importe.'];
        }

        $pricing = app(ServicePricing::class);
        $rows = collect($buildings)->map(fn (?int $buildingId) => ['building' => $buildingId ? Building::find($buildingId)?->name : 'Todo el cliente']
            + $pricing->calculate((int) CompanyContext::currentId(), $mode, $price, $clientId, $buildingId, $scope === self::SCOPE_EQUIPMENT ? (array) $get('units') : null, (string) ($get('equipment_kind') ?: 'all')));

        return ['ready' => true, 'rows' => $rows->all(), 'total' => (float) $rows->sum('total'), 'frequency' => MaintenanceService::FREQUENCIES[$get('frequency')][0] ?? null, 'several' => $rows->count() > 1];
    }

    /**
     * Datos de UNA contratación listos para guardar: alcance limpio, importe y
     * detalle recalculados en el servidor, y sin duplicar una contratación activa.
     *
     * @throws ValidationException
     */
    public static function prepare(array $data, string $scope, ?string $equipmentKind, ?MaintenanceService $record = null): array
    {
        // Datos del formulario que no son columnas.
        unset($data['scope'], $data['building_ids'], $data['equipment_kind']);
        $companyId = (int) CompanyContext::currentId();
        $data['building_id'] = $scope === self::SCOPE_CLIENT ? null : ((int) ($data['building_id'] ?? 0) ?: null);
        $data['units'] = $scope === self::SCOPE_EQUIPMENT ? array_values(array_filter((array) ($data['units'] ?? []))) : null;
        $data['pricing_mode'] = array_key_exists($data['pricing_mode'] ?? '', ServiceCatalogItem::PRICING_MODES) ? $data['pricing_mode'] : ServiceCatalogItem::CUSTOM;
        $data['category'] = array_key_exists($data['category'] ?? '', ServiceCatalogItem::CATEGORIES) ? $data['category'] : 'maintenance';
        $equipmentKind = array_key_exists((string) $equipmentKind, ServiceCatalogItem::EQUIPMENT_KINDS) ? $equipmentKind : 'all';

        // El catálogo debe ser de esta empresa (el scope ya lo filtra; nunca uno ajeno del request).
        $item = ! empty($data['catalog_item_id']) ? ServiceCatalogItem::withTrashed()->find($data['catalog_item_id']) : null;
        if (! empty($data['catalog_item_id']) && ! $item) {
            throw ValidationException::withMessages(['catalog_item_id' => 'Ese servicio no está en el catálogo.']);
        }
        $data['catalog_item_id'] = $item?->id;

        if ($scope !== self::SCOPE_CLIENT && ! $data['building_id']) {
            throw ValidationException::withMessages(['building_id' => 'Elegí el edificio.']);
        }
        if ($data['building_id'] && ! Building::withoutGlobalScopes()->where('company_id', $companyId)->where('client_id', (int) $data['client_id'])->whereKey($data['building_id'])->exists()) {
            throw ValidationException::withMessages(['building_id' => 'Ese edificio no es de este cliente.']);
        }
        if ($scope === self::SCOPE_EQUIPMENT && $data['units'] === []) {
            throw ValidationException::withMessages(['units' => 'Elegí al menos un equipo.']);
        }

        $pricing = app(ServicePricing::class)->calculate($companyId, $data['pricing_mode'], (float) ($data['unit_price'] ?? 0), (int) $data['client_id'], $data['building_id'], $data['units'], $equipmentKind);
        if ($pricing['error']) {
            throw ValidationException::withMessages(['unit_price' => $pricing['error']]);
        }
        if ($pricing['total'] <= 0) {
            throw ValidationException::withMessages(['unit_price' => 'El importe tiene que ser mayor que cero.']);
        }

        $data['unit_price'] = $pricing['unit_price'];
        $data['quantity'] = $pricing['quantity'];
        $data['amount'] = $pricing['total'];
        $data['pricing_detail'] = [
            'explanation' => $pricing['explanation'],
            'lines' => $pricing['lines'],
            'equipment_kind' => $equipmentKind,
            'catalog_base_price' => $item?->base_price !== null ? (float) $item->base_price : null,
            'calculated_at' => now()->toDateString(),
        ];

        self::guardDuplicate($data, $record);

        return $data;
    }

    /** Mismo servicio, mismo cliente y alcance, activo: es una contratación repetida. */
    private static function guardDuplicate(array $data, ?MaintenanceService $record): void
    {
        if (($data['status'] ?? MaintenanceService::ACTIVE) !== MaintenanceService::ACTIVE) {
            return;
        }

        $same = MaintenanceService::query()
            ->where('client_id', (int) $data['client_id'])
            ->where('status', MaintenanceService::ACTIVE)
            ->when($data['catalog_item_id'], fn ($q, $id) => $q->where('catalog_item_id', $id), fn ($q) => $q->whereNull('catalog_item_id')->where('description', $data['description']))
            ->when($data['building_id'], fn ($q, $id) => $q->where('building_id', $id), fn ($q) => $q->whereNull('building_id'))
            ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))
            ->get()
            // Equipos distintos del mismo edificio no son una repetición.
            ->filter(fn (MaintenanceService $s) => ! $data['units'] || ! $s->units || array_intersect($s->units, $data['units']));

        if ($same->isNotEmpty()) {
            throw ValidationException::withMessages(['catalog_item_id' => 'Este cliente ya tiene ese servicio activo para ese alcance. Editá la contratación existente o finalizala antes.']);
        }
    }

    /** Edición de una contratación: misma regla que el alta (un solo edificio). */
    public static function prepareUpdate(array $data, MaintenanceService $record, string $statePath = 'data'): array
    {
        $scope = in_array($data['scope'] ?? null, [self::SCOPE_CLIENT, self::SCOPE_BUILDINGS, self::SCOPE_EQUIPMENT], true) ? $data['scope'] : self::SCOPE_CLIENT;

        return self::errorsAt($statePath, fn () => self::prepare($data + ['client_id' => $record->client_id], $scope, $data['equipment_kind'] ?? null, $record));
    }

    /** Los errores de prepare() van al campo del formulario (data.x en la página, mountedActions.N.data.x en un modal). */
    private static function errorsAt(string $statePath, Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $key) => ["{$statePath}.{$key}" => $messages])->all());
        }
    }

    /**
     * Alta desde el formulario: una contratación por edificio elegido (o una sola).
     *
     * @return list<MaintenanceService>
     *
     * @throws ValidationException
     */
    public static function createAll(array $data, string $statePath = 'data'): array
    {
        $scope = $data['scope'] ?? null;
        $equipmentKind = $data['equipment_kind'] ?? null;
        $buildingIds = (array) ($data['building_ids'] ?? []);
        $scope = in_array($scope, [self::SCOPE_CLIENT, self::SCOPE_BUILDINGS, self::SCOPE_EQUIPMENT], true) ? $scope : self::SCOPE_CLIENT;
        $targets = $scope === self::SCOPE_BUILDINGS && $buildingIds !== [] ? array_values(array_unique(array_map('intval', $buildingIds))) : [$data['building_id'] ?? null];

        // Todo o nada: si un edificio no se puede (sin equipos, repetido), no se crea ninguno.
        $prepared = self::errorsAt($statePath, fn () => array_map(fn ($buildingId) => self::prepare(['building_id' => $buildingId] + $data, $scope, $equipmentKind), $targets));

        return DB::transaction(fn () => array_map(fn (array $row) => MaintenanceService::create($row), $prepared));
    }
}

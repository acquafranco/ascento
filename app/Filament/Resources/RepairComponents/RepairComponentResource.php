<?php

namespace App\Filament\Resources\RepairComponents;

use App\Enums\PermissionArea;
use App\Filament\Concerns\GuardedByPermission;
use App\Filament\Concerns\ScopedToCurrentCompany;
use App\Filament\Resources\RepairComponents\Pages\CreateRepairComponent;
use App\Filament\Resources\RepairComponents\Pages\EditRepairComponent;
use App\Filament\Resources\RepairComponents\Pages\ListRepairComponents;
use App\Filament\Resources\RepairComponents\Pages\ViewRepairComponent;
use App\Models\Building;
use App\Models\Elevator;
use App\Models\RepairComponent;
use App\Models\WorkOrder;
use App\Services\Repairs\RepairService;
use App\Support\CompanyContext;
use App\Support\WorkOrderLabels;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Componentes enviados a reparación (Etapa 8.4). Área «Reparaciones»: ver
 * el listado, la ficha, el historial y los archivos; registrar, corregir,
 * cambiar de estado y adjuntar pide «gestionar» (y lo verifica
 * RepairService). No se borran: se cancelan.
 */
class RepairComponentResource extends Resource
{
    use GuardedByPermission;
    use ScopedToCurrentCompany;

    protected static ?string $model = RepairComponent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|\UnitEnum|null $navigationGroup = 'Operaciones';

    protected static ?string $navigationLabel = 'Reparaciones';

    protected static ?string $modelLabel = 'componente en reparación';

    protected static ?string $pluralModelLabel = 'componentes en reparación';

    protected static ?string $slug = 'reparaciones';

    protected static ?int $navigationSort = 60;

    public static function permissionArea(): ?PermissionArea
    {
        return PermissionArea::Repairs;
    }

    public static function canDelete(Model $record): bool
    {
        return false; // se cancela, no se borra
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /** @return array<int, string> edificios activos y, al corregir, el que ya tenía aunque se haya desactivado */
    public static function buildingOptions(?RepairComponent $record = null): array
    {
        $options = Building::query()->orderBy('name')->pluck('name', 'id')->all();
        $current = $record?->building;
        if ($current && ! isset($options[$current->id]) && (int) $current->company_id === (int) $record->company_id) {
            $options[$current->id] = $current->name.' (desactivado)';
        }

        return $options;
    }

    /** @return array<int, string> ascensores del edificio */
    public static function elevatorOptions(?int $buildingId): array
    {
        return $buildingId ? Elevator::where('building_id', $buildingId)->orderBy('label')->pluck('label', 'id')->all() : [];
    }

    /** @return array<int, string> quienes pueden seguirlo y, al corregir, quien ya lo seguía aunque haya perdido el acceso */
    public static function followUpOptions(?RepairComponent $record = null): array
    {
        $options = app(RepairService::class)->people((int) CompanyContext::currentId());
        $current = $record?->followUp;
        if ($current && ! isset($options[$current->id]) && (int) $current->company_id === (int) $record->company_id) {
            $options[$current->id] = $current->name.' (sin acceso)';
        }

        return $options;
    }

    /** @return array<int, string> órdenes del edificio (las más recientes) y, al corregir, la que ya tenía */
    public static function workOrderOptions(?int $buildingId, ?RepairComponent $record = null): array
    {
        $label = fn (WorkOrder $o) => '#'.$o->id.' · '.WorkOrderLabels::type((string) $o->type).($o->unit ? ' · '.$o->unit : '').' · '.$o->created_at?->format('d/m/Y');
        $options = $buildingId ? WorkOrder::where('building_id', $buildingId)->latest('id')->limit(50)->get(['id', 'type', 'unit', 'created_at'])
            ->mapWithKeys(fn (WorkOrder $o) => [$o->id => $label($o)])->all() : [];
        $current = $record?->work_order_id ? WorkOrder::withTrashed()->find($record->work_order_id) : null;
        if ($current && ! isset($options[$current->id]) && (int) $current->building_id === (int) $buildingId) {
            $options[$current->id] = $label($current).($current->trashed() ? ' (borrada)' : '');
        }

        return $options;
    }

    public static function form(Schema $schema): Schema
    {
        $status = fn ($record, Get $get) => $record?->status ?? $get('status');

        return $schema->components([
            Section::make('Componente')->columnSpanFull()->columns(['default' => 1, 'md' => 2])->schema([
                Select::make('building_id')->label('Edificio')->options(fn ($record) => self::buildingOptions($record))->searchable()->required()->live()
                    ->afterStateUpdated(function (Set $set) {
                        $set('elevator_id', null);
                        $set('work_order_id', null);
                    }),
                Select::make('elevator_id')->label('Ascensor (opcional)')->options(fn (Get $get) => self::elevatorOptions((int) $get('building_id') ?: null))->native(false),
                Select::make('component_type')->label('Componente')->options(RepairComponent::TYPES)->required()->native(false),
                TextInput::make('description')->label('Detalle (opcional)')->maxLength(160)->placeholder('Placa de maniobra, variador de puertas…'),
                TextInput::make('brand')->label('Marca')->maxLength(120),
                TextInput::make('model')->label('Modelo')->maxLength(120),
                TextInput::make('serial_number')->label('Número de serie')->maxLength(120),
                Select::make('work_order_id')->label('Orden de trabajo (opcional)')->options(fn (Get $get, $record) => self::workOrderOptions((int) $get('building_id') ?: null, $record))->searchable(),
                Textarea::make('failure')->label('Falla')->required()->rows(3)->maxLength(5000)->columnSpanFull(),
            ]),
            Section::make('Envío y seguimiento')->columnSpanFull()->columns(['default' => 1, 'md' => 2])->schema([
                Radio::make('status')->label('Estado inicial')->options([RepairComponent::PENDING => 'Pendiente de envío', RepairComponent::SENT => 'Ya enviado'])
                    ->default(RepairComponent::PENDING)->required()->live()->visibleOn('create')->columnSpanFull(),
                DatePicker::make('sent_at')->label('Fecha de envío')->native(false)->displayFormat('d/m/Y')->maxDate(today())
                    ->visible(fn ($record, Get $get) => ! in_array($status($record, $get), [RepairComponent::PENDING, null], true))
                    ->required(fn ($record, Get $get) => ! in_array($status($record, $get), [RepairComponent::PENDING, RepairComponent::CANCELLED, null], true)),
                TextInput::make('provider')->label('Proveedor o taller')->maxLength(160),
                Select::make('follow_up_user_id')->label('Responsable del seguimiento')->options(fn ($record) => self::followUpOptions($record))->searchable(),
                DatePicker::make('expected_return_at')->label('Devolución estimada')->native(false)->displayFormat('d/m/Y'),
                TextInput::make('quote_amount')->label('Presupuesto de reparación')->numeric()->minValue(0)->prefix('$'),
                TextInput::make('final_cost')->label('Costo final')->numeric()->minValue(0)->prefix('$'),
                DatePicker::make('returned_at')->label('Fecha de devolución')->native(false)->displayFormat('d/m/Y')->maxDate(today())
                    ->visible(fn ($record) => $record?->status === RepairComponent::RECEIVED)->required(fn ($record) => $record?->status === RepairComponent::RECEIVED),
                Textarea::make('notes')->label('Observaciones')->rows(3)->maxLength(5000)->columnSpanFull(),
                FileUpload::make('files')->label('Documentos o fotos (opcional)')->multiple()->storeFiles(false)->maxFiles(10)
                    ->acceptedFileTypes(array_keys(RepairService::MIMES))->maxSize(RepairService::MAX_KB)
                    ->helperText('PDF o imágenes, hasta 20 MB cada uno. Quedan privados.')->columnSpanFull(),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            ViewEntry::make('detail')->hiddenLabel()->columnSpanFull()->view('filament.repairs.detail'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['building:id,name', 'elevator:id,label', 'followUp:id,name']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('component_type')->label('Componente')
                    ->state(fn (RepairComponent $record) => $record->componentLabel())
                    ->description(fn (RepairComponent $record) => trim(($record->brand ?? '').' '.($record->model ?? '')) ?: null),
                TextColumn::make('building.name')->label('Edificio')->description(fn (RepairComponent $record) => $record->elevator?->label),
                TextColumn::make('status')->label('Estado')->badge()
                    ->formatStateUsing(fn ($state) => RepairComponent::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        RepairComponent::RECEIVED => 'success',
                        RepairComponent::REPAIRED => 'info',
                        RepairComponent::CANCELLED => 'gray',
                        RepairComponent::PENDING => 'warning',
                        default => 'primary',
                    }),
                TextColumn::make('provider')->label('Proveedor')->placeholder('—')->toggleable(),
                TextColumn::make('sent_at')->label('Envío')->date('d/m/Y')->placeholder('—')->sortable(),
                TextColumn::make('expected_return_at')->label('Vuelve')->date('d/m/Y')->placeholder('—')->sortable()
                    ->color(fn (RepairComponent $record) => $record->isLate() ? 'danger' : null)
                    ->description(fn (RepairComponent $record) => $record->isLate() ? 'Demorado' : null),
                TextColumn::make('followUp.name')->label('Seguimiento')->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('state')->label('Estado')
                    ->options(['open' => 'Abiertos (no recibidos ni cancelados)'] + RepairComponent::STATUSES)
                    ->default('open')
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        null, '' => $query,
                        'open' => $query->whereIn('status', RepairComponent::OPEN),
                        default => $query->where('status', $data['value']),
                    }),
                SelectFilter::make('building_id')->label('Edificio')->options(fn () => self::buildingOptions())->searchable(),
                SelectFilter::make('component_type')->label('Componente')->options(RepairComponent::TYPES),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRepairComponents::route('/'),
            'create' => CreateRepairComponent::route('/create'),
            'view' => ViewRepairComponent::route('/{record}'),
            'edit' => EditRepairComponent::route('/{record}/edit'),
        ];
    }
}

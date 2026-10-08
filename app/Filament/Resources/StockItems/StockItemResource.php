<?php

namespace App\Filament\Resources\StockItems;

use App\Filament\Concerns\ScopedToCurrentCompany;
use App\Filament\Resources\StockItems\Pages\CreateStockItem;
use App\Filament\Resources\StockItems\Pages\EditStockItem;
use App\Filament\Resources\StockItems\Pages\ListStockItems;
use App\Filament\Resources\StockItems\RelationManagers\MovementsRelationManager;
use App\Models\StockItem;
use App\Services\Stock\StockService;
use App\Support\CompanyContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

class StockItemResource extends Resource
{
    use ScopedToCurrentCompany;

    protected static ?string $model = StockItem::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationLabel = 'Stock';

    protected static ?string $modelLabel = 'Material';

    protected static ?string $pluralModelLabel = 'Stock';

    protected static string|\UnitEnum|null $navigationGroup = 'Operaciones';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationBadge(): ?string
    {
        $low = static::getEloquentQuery()->active()->low()->count();

        return $low > 0 ? (string) $low : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Materiales con stock bajo';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->schema([
                TextInput::make('name')->label('Nombre')->required()->maxLength(255)->columnSpan(2)
                    ->placeholder('Ej: Contactor Schneider LC1D18'),
                TextInput::make('code')->label('Código')->maxLength(60)
                    ->rule(fn (?StockItem $record) => Rule::unique('stock_items', 'code')
                        ->where('company_id', CompanyContext::currentId())
                        ->ignore($record?->id)),
            ]),
            Textarea::make('description')->label('Descripción')->rows(2)->columnSpanFull(),
            Grid::make(3)->schema([
                Select::make('unit')->label('Unidad de medida')->options(StockItem::UNITS)->default('unidad')->required()->native(false),
                TextInput::make('cost')->label('Costo unitario')->numeric()->minValue(0)->prefix('$')->default(0),
                TextInput::make('min_stock')->label('Stock mínimo')->numeric()->minValue(0)->default(0)
                    ->helperText('Con esta cantidad o menos aparece la alerta de stock bajo.'),
            ]),
            TextInput::make('initial_stock')
                ->label('Stock inicial')
                ->numeric()
                ->minValue(0)
                ->default(0)
                ->helperText('Queda registrado como una entrada en el historial.')
                ->visibleOn('create')
                ->dehydrated(false),
            Toggle::make('is_active')->label('Activo')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Material')->description(fn (StockItem $r) => $r->code)->searchable(['name', 'code'])->sortable(),
                TextColumn::make('current_stock')
                    ->label('Stock actual')
                    ->formatStateUsing(fn ($state, StockItem $r) => $r->formatQuantity($state))
                    ->color(fn (StockItem $r) => $r->isLow() ? 'danger' : null)
                    ->weight(fn (StockItem $r) => $r->isLow() ? 'bold' : null)
                    ->description(fn (StockItem $r) => match (true) {
                        (float) $r->current_stock < 0 => 'Stock negativo: cargá la entrada o un ajuste',
                        $r->isLow() => 'Stock bajo',
                        default => null,
                    })
                    ->sortable(),
                TextColumn::make('min_stock')->label('Mínimo')->formatStateUsing(fn ($state, StockItem $r) => $r->formatQuantity($state))->toggleable(),
                TextColumn::make('cost')->label('Costo')->money('ARS', locale: 'es_AR')->toggleable(),
                IconColumn::make('is_active')->label('Activo')->boolean(),
            ])
            ->filters([
                Filter::make('low')->label('Stock bajo')->query(fn (Builder $query) => $query->whereColumn('current_stock', '<=', 'min_stock')),
                TernaryFilter::make('is_active')->label('Activos'),
                TrashedFilter::make()->label('Eliminados'),
            ])
            ->recordActions([
                ActionGroup::make([
                    static::movementAction('in', 'Entrada', 'heroicon-o-arrow-down-tray', 'success'),
                    static::movementAction('out', 'Salida', 'heroicon-o-arrow-up-tray', 'warning'),
                    static::movementAction('adjustment', 'Ajuste', 'heroicon-o-scale', 'gray'),
                ])->label('Movimiento')->icon('heroicon-o-arrows-up-down')->button()->size('sm'),
                EditAction::make(),
            ]);
    }

    /** Entrada / salida / ajuste: todo pasa por StockService. */
    public static function movementAction(string $type, string $label, string $icon, string $color): Action
    {
        return Action::make($type)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->modalHeading(fn (StockItem $record) => $label.': '.$record->name)
            ->modalSubmitActionLabel('Registrar')
            ->modalDescription(fn (StockItem $record) => 'Stock actual: '.$record->formatQuantity($record->current_stock))
            ->schema([
                TextInput::make('quantity')
                    ->label($type === 'adjustment' ? 'Cantidad real contada' : 'Cantidad')
                    ->numeric()
                    ->minValue($type === 'adjustment' ? 0 : 0.01)
                    ->required(),
                TextInput::make('reason')
                    ->label($type === 'adjustment' ? 'Motivo del ajuste' : 'Motivo / observación')
                    ->placeholder(match ($type) {
                        'in' => 'Ej: Compra a proveedor',
                        'out' => 'Ej: Retiro para taller',
                        default => 'Ej: Conteo de inventario',
                    })
                    ->required($type === 'adjustment')
                    ->maxLength(255),
            ])
            ->action(function (StockItem $record, array $data) use ($type) {
                $service = app(StockService::class);
                $user = auth()->user();
                $quantity = (float) $data['quantity'];

                match ($type) {
                    'in' => $service->receive($record, $quantity, $user, $data['reason'] ?? null),
                    'out' => $service->issue($record, $quantity, $user, $data['reason'] ?? null),
                    default => $service->adjustTo($record, $quantity, $user, (string) $data['reason']),
                };

                $record->refresh();

                Notification::make()
                    ->title('Movimiento registrado')
                    ->body('Stock actual: '.$record->formatQuantity($record->current_stock))
                    ->success()
                    ->send();
            });
    }

    public static function getRelations(): array
    {
        return [MovementsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockItems::route('/'),
            'create' => CreateStockItem::route('/create'),
            'edit' => EditStockItem::route('/{record}/edit'),
        ];
    }
}

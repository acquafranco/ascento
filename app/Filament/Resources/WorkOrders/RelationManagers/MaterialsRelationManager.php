<?php

namespace App\Filament\Resources\WorkOrders\RelationManagers;

use App\Models\StockItem;
use App\Models\WorkOrderMaterial;
use App\Support\CompanyContext;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * "Materiales utilizados" de una orden. Se descuentan del stock al
 * completarse la orden (o al agregarlos, si ya está completada). Los
 * renglones descontados no se editan ni se borran.
 */
class MaterialsRelationManager extends RelationManager
{
    protected static string $relationship = 'materials';

    protected static ?string $title = 'Materiales utilizados';

    protected static ?string $modelLabel = 'material';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('stock_item_id')
                ->label('Material')
                ->options(fn () => StockItem::query()
                    ->where('company_id', CompanyContext::currentId())
                    ->active()
                    ->orderBy('name')
                    ->get()
                    ->mapWithKeys(fn (StockItem $item) => [$item->id => $item->name.($item->code ? " ({$item->code})" : '').' — stock: '.$item->formatQuantity($item->current_stock)]))
                ->searchable()
                ->required()
                ->live(),
            TextInput::make('quantity')
                ->label('Cantidad')
                ->numeric()
                ->minValue(0.01)
                ->required()
                ->live(onBlur: true)
                ->suffix(fn (Get $get) => StockItem::find($get('stock_item_id'))?->unit)
                // Advertencia (no bloquea: el material ya se usó en la obra).
                ->helperText(function (Get $get) {
                    $item = StockItem::find($get('stock_item_id'));
                    $quantity = (float) $get('quantity');

                    if (! $item || $quantity <= 0 || $quantity <= (float) $item->current_stock) {
                        return null;
                    }

                    return '⚠️ Stock insuficiente: hay '.$item->formatQuantity($item->current_stock)
                        .'. Al completar la orden va a quedar en '.$item->formatQuantity((float) $item->current_stock - $quantity)
                        .'. Cargá la entrada cuando repongas.';
                }),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('stockItem'))
            ->description(fn () => $this->getOwnerRecord()->status === 'completed'
                ? 'La orden está completada: lo que agregues se descuenta del stock en el momento.'
                : 'Se descuentan del stock automáticamente cuando la orden se completa.')
            ->columns([
                TextColumn::make('stockItem.name')->label('Material'),
                TextColumn::make('quantity')->label('Cantidad')->formatStateUsing(fn ($state, WorkOrderMaterial $r) => $r->stockItem?->formatQuantity($state)),
                TextColumn::make('unit_cost')->label('Costo unit.')->money('ARS', locale: 'es_AR')->toggleable(),
                IconColumn::make('stock_movement_id')->label('Descontado')->boolean()
                    ->state(fn (WorkOrderMaterial $r) => $r->isConsumed())
                    ->tooltip(fn (WorkOrderMaterial $r) => $r->isConsumed() ? 'Ya se descontó del stock' : 'Se descuenta al completar la orden'),
            ])
            ->headerActions([
                CreateAction::make()->label('Agregar material')->modalHeading('Agregar material')->modalSubmitActionLabel('Agregar')->createAnother(false),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (WorkOrderMaterial $r) => ! $r->isConsumed()),
                DeleteAction::make()->visible(fn (WorkOrderMaterial $r) => ! $r->isConsumed()),
            ])
            ->emptyStateHeading('Sin materiales')
            ->emptyStateDescription('Agregá los repuestos y materiales que se usaron en este trabajo.');
    }
}

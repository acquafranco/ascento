<?php

namespace App\Filament\Resources\Quotes\Schemas;

use App\Models\Building;
use App\Models\Quote;
use App\Models\ServiceCatalogItem;
use App\Services\Catalog\ServicePricing;
use App\Support\CompanyContext;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * Formulario de presupuesto: encabezado corto, ítems en tabla y detalles
 * opcionales plegados. Los subtotales y el total que se ven acá son solo de
 * referencia: el servidor los calcula siempre a partir de los ítems.
 */
class QuoteForm
{
    public static function configure(Schema $schema): Schema
    {
        $money = fn (float $value) => '$'.number_format($value, 2, ',', '.');
        // El subtotal de la fila se recalcula al cambiar cantidad o precio (antes quedaba en
        // el valor de la carga, aunque el total de abajo sí cambiaba).
        $refreshSubtotal = fn (Get $get, Set $set) => $set('subtotal_preview', $money(round((float) $get('quantity') * (float) $get('unit_price'), 2)));

        return $schema
            ->components([
                Section::make('Cliente')
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        Select::make('client_id')
                            ->label('Cliente')
                            ->relationship('client', 'name', fn ($query) => $query->withoutTrashed()->where('company_id', CompanyContext::currentId()))
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('building_id', null)),

                        Select::make('building_id')
                            ->label('Edificio')
                            ->relationship('building', 'name', fn ($query, Get $get) => $query->withoutTrashed()
                                ->where('company_id', CompanyContext::currentId())
                                ->where('client_id', $get('client_id')))
                            ->getOptionLabelFromRecordUsing(fn (Building $record) => trim("{$record->name} {$record->address}"))
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('unit', null)),

                        Select::make('unit')
                            ->label('Equipo (opcional)')
                            ->placeholder('Todo el edificio')
                            ->options(function (Get $get, ?Quote $record) {
                                $labels = Building::find($get('building_id'))?->unitLabels() ?? [];

                                // Dato viejo que no está en la lista: se conserva.
                                if (filled($record?->unit) && ! in_array($record->unit, $labels, true)) {
                                    $labels[] = $record->unit;
                                }

                                return array_combine($labels, $labels) ?: [];
                            }),
                    ]),

                Section::make('Presupuesto')
                    ->columnSpanFull()
                    ->columns(4)
                    ->schema([
                        TextInput::make('title')
                            ->label('Título')
                            ->placeholder('Ej.: Cambio de operador de puertas')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(4),

                        DatePicker::make('issued_at')
                            ->label('Fecha')
                            ->default(today())
                            ->required()
                            ->native(false)
                            ->displayFormat('d/m/Y'),

                        DatePicker::make('valid_until')
                            ->label('Válido hasta')
                            ->default(today()->addDays(15))
                            ->afterOrEqual('issued_at')
                            ->native(false)
                            ->displayFormat('d/m/Y'),

                        Select::make('status')
                            ->label('Estado')
                            ->options(Quote::STATUSES)
                            ->default(Quote::DRAFT)
                            ->required()
                            ->native(false),

                        Select::make('priority')
                            ->label('Prioridad')
                            ->default('normal')
                            ->required()
                            ->native(false)
                            ->options([
                                'low' => '🟢 Baja',
                                'normal' => '🔵 Normal',
                                'high' => '🟠 Alta',
                                'urgent' => '🔴 Urgente',
                            ]),
                    ]),

                Section::make('Ítems')
                    ->columnSpanFull()
                    ->schema([
                        // Atajo: suma un servicio del catálogo como ítem (una copia: el presupuesto no cambia si cambia el catálogo).
                        Select::make('catalog_pick')
                            ->label('Agregar un servicio del catálogo')
                            ->placeholder('Elegí un servicio…')
                            ->options(fn () => ServiceCatalogItem::query()->active()->orderBy('name')->pluck('name', 'id'))
                            ->visible(fn () => ServiceCatalogItem::query()->active()->exists())
                            ->searchable()->live()->dehydrated(false)
                            ->helperText('Se agrega como ítem con su precio de referencia; lo podés ajustar.')
                            ->afterStateUpdated(function (?string $state, Get $get, Set $set) use ($money) {
                                $item = $state ? ServiceCatalogItem::find($state) : null;
                                $set('catalog_pick', null);
                                if (! $item) {
                                    return;
                                }

                                $quantity = 1;
                                if ($item->countsEquipment() && ($buildingId = (int) $get('building_id'))) {
                                    $building = Building::find($buildingId);
                                    $pricing = $building ? app(ServicePricing::class)->calculate((int) CompanyContext::currentId(), $item->pricing_mode, (float) $item->base_price, (int) $building->client_id, $building->id, array_filter([(string) $get('unit')]), $item->equipment_kind) : null;
                                    $quantity = $pricing && ! $pricing['error'] ? max(1, $pricing['quantity']) : 1;
                                }
                                $price = (float) ($item->base_price ?? 0);

                                $items = (array) ($get('items') ?? []);
                                // Si la primera fila está vacía, se completa esa en lugar de sumar otra.
                                $emptyKey = collect($items)->search(fn ($row) => blank($row['concept'] ?? null) && blank($row['unit_price'] ?? null));
                                $key = $emptyKey !== false ? $emptyKey : (string) Str::uuid();
                                $items[$key] = [
                                    'concept' => $item->name,
                                    'description' => $item->description ? Str::limit($item->description, 1000, '') : null,
                                    'quantity' => $quantity,
                                    'unit_price' => $price ?: null,
                                    'subtotal_preview' => $money(round($quantity * $price, 2)),
                                ];
                                $set('items', $items);
                            }),
                        Repeater::make('items')
                            ->hiddenLabel()
                            // Ancho mínimo en modo tabla (ver asc-quote-items en el panel): con la barra
                            // lateral, a 1024 px los importes no entraban; ahora la tabla se desplaza.
                            ->extraAttributes(['class' => 'asc-quote-items'])
                            ->relationship()
                            ->orderColumn('position')
                            ->table([
                                // Anchos según lo que se escribe: el concepto y el detalle necesitan más lugar que la cantidad.
                                TableColumn::make('Concepto')->markAsRequired()->width('28%'),
                                TableColumn::make('Detalle')->width('18%'),
                                TableColumn::make('Cantidad')->markAsRequired()->width('10%'),
                                TableColumn::make('Precio unitario')->markAsRequired()->width('18%'),
                                TableColumn::make('Subtotal')->width('18%'),
                            ])
                            ->schema([
                                TextInput::make('concept')->label('Concepto')->required()->maxLength(255)->placeholder('Cambio de contactor'),
                                TextInput::make('description')->label('Detalle')->maxLength(1000)->placeholder('Opcional'),
                                TextInput::make('quantity')->label('Cantidad')->numeric()->required()->minValue(0.01)->maxValue(99999)->default(1)->live(onBlur: true)->afterStateUpdated($refreshSubtotal),
                                TextInput::make('unit_price')->label('Precio unitario')->numeric()->required()->minValue(0)->maxValue(999999999)->prefix('$')->live(onBlur: true)->afterStateUpdated($refreshSubtotal),
                                // Solo para ver: no se envía (lo calcula el servidor).
                                TextInput::make('subtotal_preview')
                                    ->label('Subtotal')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->formatStateUsing(fn (Get $get) => $money(round((float) $get('quantity') * (float) $get('unit_price'), 2)))
                                    ->afterStateHydrated(fn (TextInput $component, Get $get) => $component->state($money(round((float) $get('quantity') * (float) $get('unit_price'), 2)))),
                            ])
                            ->minItems(1)
                            ->defaultItems(1)
                            ->addActionLabel('+ Agregar ítem')
                            ->live()
                            ->validationMessages(['min' => 'Agregá al menos un ítem.']),

                        Text::make(fn (Get $get) => 'Total: '.$money(collect($get('items') ?? [])->sum(fn ($item) => round((float) ($item['quantity'] ?? 0) * (float) ($item['unit_price'] ?? 0), 2))))
                            ->size('lg'),
                    ]),

                Section::make('Detalles')
                    ->columnSpanFull()
                    ->collapsible()
                    ->collapsed(fn (?Quote $record) => blank($record?->description) && blank($record?->conditions) && blank($record?->notes))
                    ->schema([
                        Textarea::make('description')->label('Descripción general')->rows(3)->maxLength(5000),
                        Textarea::make('conditions')->label('Condiciones')->rows(3)->maxLength(5000)
                            ->placeholder('Forma de pago, plazo de entrega, garantía…'),
                        Textarea::make('notes')->label('Observaciones internas')->helperText('No las ve el cliente (ni en el enlace ni en el PDF).')->rows(2)->maxLength(5000),
                    ]),
            ]);
    }
}

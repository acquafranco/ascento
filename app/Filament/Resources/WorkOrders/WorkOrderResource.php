<?php

namespace App\Filament\Resources\WorkOrders;

use App\Filament\Resources\WorkOrders\Pages\CreateWorkOrder;
use App\Filament\Resources\WorkOrders\Pages\EditWorkOrder;
use App\Filament\Resources\WorkOrders\Pages\ListWorkOrders;
use App\Filament\Resources\WorkOrders\Schemas\WorkOrderForm;
use App\Models\WorkOrder;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use App\Support\WorkOrderLabels;

class WorkOrderResource extends Resource
{
    protected static ?string $model = WorkOrder::class;

    protected static string|BackedEnum|null $navigationIcon =
        Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'status';

    protected static ?string $navigationLabel = 'Órdenes de trabajo';

    protected static ?string $modelLabel = 'Orden de trabajo';

    protected static ?string $pluralModelLabel = 'Órdenes de trabajo';

    protected static string|\UnitEnum|null $navigationGroup = 'Operaciones';

    public static function form(Schema $schema): Schema
    {
        return WorkOrderForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([

                Tables\Columns\TextColumn::make('building.name')
                    ->label('Edificio')
                    ->description(fn ($record) => $record->building?->address)
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('unit')
                    ->label('Unidad')
                    ->badge()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('technician.name')
                    ->label('Técnico')
                    ->placeholder('Sin asignar')
                    ->searchable(),

                // 🔥 TIPOS EN ESPAÑOL
                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => WorkOrderLabels::type($state))
                    ->colors([
                        'primary' => 'maintenance',
                        'warning' => 'inspection',
                        'danger' => 'claim',
                        'success' => 'installation',
                        'gray' => 'modernization',
                    ]),

                Tables\Columns\TextColumn::make('priority')
                    ->label('Prioridad')
                    ->badge()
                    ->formatStateUsing(fn ($state) => WorkOrderLabels::priority($state))
                    ->colors([
                        'success' => 'low',
                        'warning' => 'medium',
                        'danger' => 'high',
                        'danger' => 'urgent',
                    ]),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn ($state) => WorkOrderLabels::status($state))
                    ->colors([
                        'warning' => 'pending',
                        'primary' => 'in_progress',
                        'success' => 'completed',
                        'danger' => 'failed',
                    ]),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])

            ->defaultSort('created_at', 'desc')

            ->recordUrl(
                fn ($record) =>
                    static::getUrl('edit', ['record' => $record])
            )

            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkOrders::route('/'),
            'create' => CreateWorkOrder::route('/create'),
            'edit' => EditWorkOrder::route('/{record}/edit'),
        ];
    }
}

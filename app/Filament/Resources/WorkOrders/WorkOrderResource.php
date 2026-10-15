<?php

namespace App\Filament\Resources\WorkOrders;

use App\Enums\PermissionArea;
use App\Filament\Concerns\GuardedByPermission;
use App\Filament\Resources\WorkOrders\Pages\CreateWorkOrder;
use App\Filament\Resources\WorkOrders\Pages\EditWorkOrder;
use App\Filament\Resources\WorkOrders\Pages\ListWorkOrders;
use App\Filament\Resources\WorkOrders\Schemas\WorkOrderForm;
use App\Models\WorkOrder;
use App\Support\WorkOrderLabels;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class WorkOrderResource extends Resource
{
    use GuardedByPermission;

    /** Área de permisos de administrativos (Etapa 6). */
    public static function permissionArea(): ?PermissionArea
    {
        return PermissionArea::WorkOrders;
    }

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
            // Eager loading de lo que usan las columnas/acciones (evita N+1).
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['building', 'users', 'onCallShift.user', 'onCallShift.schedule']))
            ->columns([

                Tables\Columns\TextColumn::make('building.name')
                    ->label('Edificio')
                    ->formatStateUsing(fn ($state, $record) => "{$record->building?->name} {$record->building?->address}"

                    )
                    ->searchable(query: function ($query, $search) {
                        $query->whereHas('building', function ($query) use ($search) {
                            $query
                                ->where('name', 'like', "%{$search}%")
                                ->orWhere('address', 'like', "%{$search}%");
                        });
                    })
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('unit')
                    ->label('Unidad')
                    ->badge()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('users')
                    ->label('Técnicos')
                    ->getStateUsing(function ($record) {

                        return $record->users
                            ->pluck('name')
                            ->unique()
                            ->implode(', ');

                    })
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
                // Trabajo originado durante una guardia (lo indica quien carga la orden).
                Tables\Columns\TextColumn::make('on_call')
                    ->label('Guardia')
                    ->state(fn ($record) => $record->onCallShift ? $record->onCallShift->schedule?->name.' · '.$record->onCallShift->user?->name : null)
                    ->placeholder('—')
                    ->toggleable(),
            ])

            ->filters([
                Tables\Filters\TernaryFilter::make('on_call_shift_id')
                    ->label('Trabajos de guardia')
                    ->nullable()
                    ->trueLabel('Solo de guardia')
                    ->falseLabel('Sin guardia'),
            ])

            ->defaultSort('created_at', 'desc')

            ->recordUrl(
                fn ($record) => static::getUrl('edit', ['record' => $record])
            )

            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\MaterialsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkOrders::route('/'),
            'create' => CreateWorkOrder::route('/create'),
            'edit' => EditWorkOrder::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $user = auth()->user();

        if ($user->isSuperAdmin()) {

            $companyId = session('selected_company_id');

            if ($companyId) {
                return $query->where('company_id', $companyId);
            }

            return $query->whereRaw('1 = 0');
        }

        return $query->where(
            'company_id',
            $user->company_id
        );
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function canAccess(): bool
    {
        return static::canViewAny();
    }

    /**
     * Solo órdenes sin historial (pendientes/fallidas y sin remito).
     */
    public static function canDelete(Model $record): bool
    {
        return $record->canBeDeleted();
    }
}

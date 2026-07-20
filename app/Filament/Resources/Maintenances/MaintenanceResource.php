<?php

namespace App\Filament\Resources\Maintenances;

use App\Filament\Resources\Maintenances\Pages\CreateMaintenance;
use App\Filament\Resources\Maintenances\Pages\EditMaintenance;
use App\Filament\Resources\Maintenances\Pages\ListMaintenances;
use App\Filament\Resources\Maintenances\Pages\ViewMaintenance;
use App\Filament\Resources\Maintenances\Schemas\MaintenanceForm;
use App\Filament\Resources\Maintenances\Schemas\MaintenanceInfolist;
use App\Filament\Resources\Maintenances\Tables\MaintenancesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use App\Models\DeliveryNote;
use Illuminate\Database\Eloquent\Builder;


class MaintenanceResource extends Resource
{
protected static ?string $model = DeliveryNote::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|\UnitEnum|null $navigationGroup = 'Operaciones';

    protected static ?string $navigationLabel = 'Mantenimientos';

    protected static ?string $modelLabel = 'Mantenimiento';

    protected static ?string $pluralModelLabel = 'Mantenimientos';




    public static function form(Schema $schema): Schema
    {
        return MaintenanceForm::configure($schema);
    }

   public static function getEloquentQuery(): Builder

{

    return parent::getEloquentQuery()

        ->where(function (Builder $query) {

            // Mantenimientos mensuales

            $query->where('assignment_type', 'maintenance')

            // Órdenes de trabajo de mantenimiento

            ->orWhere(function (Builder $query) {

                $query->where('assignment_type', 'work_order')

                    ->whereHas('workOrder', function (Builder $query) {

                        $query->where('type', 'maintenance');

                    });

            });

        });

}

    public static function infolist(Schema $schema): Schema
    {
        return MaintenanceInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MaintenancesTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMaintenances::route('/'),
            'create' => CreateMaintenance::route('/create'),
            'view' => ViewMaintenance::route('/{record}'),
            'edit' => EditMaintenance::route('/{record}/edit'),
        ];
    }
}

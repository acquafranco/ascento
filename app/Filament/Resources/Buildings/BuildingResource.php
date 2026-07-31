<?php

namespace App\Filament\Resources\Buildings;

use App\Filament\Resources\Buildings\Pages\CreateBuilding;
use App\Filament\Resources\Buildings\Pages\EditBuilding;
use App\Filament\Resources\Buildings\Pages\ListBuildings;
use App\Filament\Resources\Buildings\Schemas\BuildingForm;
use App\Filament\Resources\Buildings\Tables\BuildingsTable;
use App\Models\Building;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;

class BuildingResource extends Resource
{
    protected static ?string $model = Building::class;

    protected static string|BackedEnum|null $navigationIcon =
        Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Edificios';
    protected static ?string $modelLabel = 'Edificio';
    protected static ?string $pluralModelLabel = 'Edificios';

    protected static string|\UnitEnum|null $navigationGroup = 'Gestión';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return BuildingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BuildingsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBuildings::route('/'),
            'create' => CreateBuilding::route('/create'),
            'edit' => EditBuilding::route('/{record}/edit'),
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
        return auth()->check();
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }
}

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

class BuildingResource extends Resource
{
    protected static ?string $model = Building::class;

    protected static string|BackedEnum|null $navigationIcon =
        Heroicon::OutlinedRectangleStack;

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
}

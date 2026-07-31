<?php

namespace App\Filament\Resources\DeliveryNotes;

use App\Filament\Resources\DeliveryNotes\Pages\EditDeliveryNote;
use App\Filament\Resources\DeliveryNotes\Pages\ListDeliveryNotes;
use App\Filament\Resources\DeliveryNotes\Schemas\DeliveryNoteForm;
use App\Filament\Resources\DeliveryNotes\Tables\DeliveryNotesTable;
use App\Filament\Resources\DeliveryNotes\Pages\ViewDeliveryNote;
use App\Models\DeliveryNote;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use UnitEnum;
use Illuminate\Database\Eloquent\Builder;


class DeliveryNoteResource extends Resource
{
    protected static ?string $model = DeliveryNote::class;

    protected static string|BackedEnum|null $navigationIcon =

        Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Gestión';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Remitos';

    protected static ?string $recordTitleAttribute = 'number';

    public static function getNavigationLabel(): string
    {
        return 'Remitos';
    }



        public static function getModelLabel(): string
    {
        return 'Remito';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Remitos';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return DeliveryNoteForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DeliveryNotesTable::configure($table);
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
            'index' => ListDeliveryNotes::route('/'),
            'view' => ViewDeliveryNote::route('/{record}'),
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

    public static function canEdit($record): bool
    {
        return false;
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


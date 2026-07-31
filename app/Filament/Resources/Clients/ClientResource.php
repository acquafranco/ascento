<?php

namespace App\Filament\Resources\Clients;

use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\Schemas\ClientForm;
use App\Filament\Resources\Clients\Tables\ClientsTable;
use App\Models\Client;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;


class ClientResource extends Resource
{
    protected static ?string $model = Client::class;

    protected static string|BackedEnum|null $navigationIcon =
        Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Clientes';

    protected static ?string $modelLabel = 'Cliente';

    protected static ?string $pluralModelLabel = 'Clientes';

    // ESTA LINEA ES LA QUE TENÉS MAL
    protected static string|\UnitEnum|null $navigationGroup = 'Gestión';

    public static function form(Schema $schema): Schema
    {
        return ClientForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ClientsTable::configure($table);
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
            'index' => ListClients::route('/'),
            'create' => CreateClient::route('/create'),
            'edit' => EditClient::route('/{record}/edit'),
        ];
    }

  public static function getEloquentQuery(): Builder
{
    $query = parent::getEloquentQuery();

    $user = auth()->user();

    if ($user->isSuperAdmin()) {

        $companyId = session('selected_company_id');

        if (!$companyId) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('company_id', $companyId);
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

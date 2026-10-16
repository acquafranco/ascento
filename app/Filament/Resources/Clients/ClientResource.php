<?php

namespace App\Filament\Resources\Clients;

use App\Enums\PermissionArea;
use App\Filament\Concerns\GuardedByPermission;
use App\Filament\RelationManagers\AccountStatementRelationManager;
use App\Filament\RelationManagers\InteractionsRelationManager;
use App\Filament\RelationManagers\PortalUsersRelationManager;
use App\Filament\RelationManagers\ServicesRelationManager;
use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\Clients\Schemas\ClientForm;
use App\Filament\Resources\Clients\Tables\ClientsTable;
use App\Models\Client;
use BackedEnum;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ClientResource extends Resource
{
    use GuardedByPermission;

    /** Área de permisos de administrativos (Etapa 6). */
    public static function permissionArea(): ?PermissionArea
    {
        return PermissionArea::Clients;
    }

    protected static ?string $model = Client::class;

    protected static string|BackedEnum|null $navigationIcon =
        Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Clientes';

    protected static ?string $modelLabel = 'Cliente';

    protected static ?string $pluralModelLabel = 'Clientes';

    protected static string|\UnitEnum|null $navigationGroup = 'Gestión';

    public static function form(Schema $schema): Schema
    {
        return ClientForm::configure($schema);
    }

    /** Ficha CRM (Etapa 6, PR 4): ver ClientDossier. */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            ViewEntry::make('dossier')->hiddenLabel()->columnSpanFull()->view('filament.clients.dossier'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return ClientsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ServicesRelationManager::class,
            AccountStatementRelationManager::class,
            PortalUsersRelationManager::class,
            InteractionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClients::route('/'),
            'create' => CreateClient::route('/create'),
            'import' => \App\Filament\Resources\Clients\Pages\ImportClients::route('/importar'),
            'view' => ViewClient::route('/{record}'),
            'edit' => EditClient::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $user = auth()->user();

        if ($user->isSuperAdmin()) {

            $companyId = session('selected_company_id');

            if (! $companyId) {
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
        return static::canAccess();
    }

    public static function canAccess(): bool
    {
        return static::canViewAny();
    }
}

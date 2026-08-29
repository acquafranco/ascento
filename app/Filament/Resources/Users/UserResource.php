<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Filament\RelationManagers\DeliveryNotesRelationManager;
use Filament\Tables;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon =
        Heroicon::OutlinedUser;

    protected static ?string $navigationLabel = 'Técnicos';

    protected static ?string $modelLabel = 'Técnicos';

    protected static ?string $pluralModelLabel = 'Técnicos';

    protected static string|\UnitEnum|null $navigationGroup =
        'Administración';

    public static function form(Schema $form): Schema
    {
        return $form
            ->schema([
                TextInput::make('name')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255),

                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),

               TextInput::make('phone')
                ->label('WhatsApp')
                ->tel()
                ->required()
                ->dehydrateStateUsing(function ($state) {
                    return preg_replace('/\D/', '', $state);
                }),

            TextInput::make('password')
                ->label('Contraseña')
                ->password()
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn ($state) => filled($state))
                ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                ->minLength(8),
            ]);
    }

    public static function table(Table $table): Table
    {
        return \App\Filament\Resources\Users\Tables\UsersTable::configure($table)
            ->recordUrl(
                fn ($record) =>
                    static::getUrl('view', ['record' => $record])
            );
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' =>
                Pages\ListUsers::route('/'),

            'create' =>
                Pages\CreateUser::route('/create'),

            'view' =>
                Pages\ViewUser::route('/{record}'),

            'edit' =>
                Pages\EditUser::route('/{record}/edit'),
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

    return $query->where('company_id', $user->company_id);
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

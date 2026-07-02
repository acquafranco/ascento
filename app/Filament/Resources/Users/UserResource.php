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

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon =
        Heroicon::OutlinedUser;

    protected static ?string $navigationLabel = 'Usuarios';

    protected static ?string $modelLabel = 'Usuario';

    protected static ?string $pluralModelLabel = 'Usuarios';

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

                TextInput::make('password')
                    ->label('Contraseña')
                    ->password()
                    ->required()
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
}

<?php

namespace App\Filament\Resources\Users;

use App\Support\PhoneNumber;

use App\Filament\Resources\Users\Pages;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
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

    protected static ?string $modelLabel = 'Técnico';

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
                    ->label('Número de teléfono')
                    ->tel()
                    ->required()
                    ->maxLength(30)
                    ->placeholder('11 2345-6789')
                    ->helperText('Celular con código de área. Se aceptan formatos como 11 2345-6789, 0351 15 123-4567 o +54 9 11 2345-6789.')
                    ->formatStateUsing(fn (?string $state) => PhoneNumber::format($state))
                    ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                        if (! PhoneNumber::isValid($value)) {
                            $fail('Ingresá un celular argentino válido, con código de área (por ejemplo 11 2345-6789).');
                        }
                    })
                    ->dehydrateStateUsing(fn (?string $state) => PhoneNumber::normalize($state)),

            // Misma política que el resto de Ascento (PasswordPolicy). En la
            // edición es opcional: vacío = no se cambia.
            TextInput::make('password')
                ->label(fn (string $operation) => $operation === 'create' ? 'Contraseña' : 'Contraseña nueva')
                ->password()
                ->revealable()
                ->autocomplete('new-password')
                ->required(fn (string $operation) => $operation === 'create')
                ->rule(fn () => \Illuminate\Validation\Rules\Password::defaults())
                ->rule(fn (\Filament\Schemas\Components\Utilities\Get $get) => new \App\Rules\NotCommonPassword([$get('name'), $get('email')]))
                ->confirmed()
                ->helperText(fn (string $operation) => \App\Support\Auth\PasswordPolicy::HINT.($operation === 'edit' ? ' Dejala vacía para no cambiarla.' : ''))
                ->dehydrated(fn ($state) => filled($state))
                ->dehydrateStateUsing(fn ($state) => Hash::make($state)),

            TextInput::make('password_confirmation')
                ->label('Repetir contraseña')
                ->password()
                ->revealable()
                ->autocomplete('new-password')
                ->required(fn (string $operation, \Filament\Schemas\Components\Utilities\Get $get) => $operation === 'create' || filled($get('password')))
                ->dehydrated(false),
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
    // Los usuarios del portal del cliente se administran desde el cliente,
    // no son técnicos.
    $query = parent::getEloquentQuery()->where('role', '!=', \App\Models\User::ROLE_CLIENT);

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

<?php

namespace App\Filament\Resources\Users;

use App\Enums\PermissionArea;
use App\Filament\Concerns\GuardedByPermission;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use App\Rules\NotCommonPassword;
use App\Support\Access\Access;
use App\Support\Auth\PasswordPolicy;
use App\Support\PhoneNumber;
use BackedEnum;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class UserResource extends Resource
{
    use GuardedByPermission;

    /** Área de permisos de administrativos (Etapa 6). */
    public static function permissionArea(): ?PermissionArea
    {
        return PermissionArea::Technicians;
    }

    /** Usuarios: el administrativo con «Técnicos» solo consulta; crear, editar y desactivar es del dueño. */
    public static function managedOnlyByOwner(): bool
    {
        return true;
    }

    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon =
        Heroicon::OutlinedUser;

    protected static ?string $navigationLabel = 'Técnicos';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Técnico';

    protected static ?string $pluralModelLabel = 'Técnicos';

    protected static string|\UnitEnum|null $navigationGroup = 'Administración';

    public static function form(Schema $form): Schema
    {
        return $form
            ->schema([
                ...static::technicianFields(),

                // Quien edita SU propio usuario confirma con su contraseña actual para
                // cambiar el correo o la contraseña (con una sesión robada alcanzaba
                // para tomar la cuenta). El correo nuevo queda pendiente (Etapa 11).
                TextInput::make('current_password')
                    ->label('Contraseña actual')
                    ->password()
                    ->revealable()
                    ->autocomplete('current-password')
                    ->helperText('Para cambiar tu correo o tu contraseña. El correo nuevo se confirma con un enlace que te llega a esa dirección.')
                    ->visible(fn (string $operation, ?User $record) => $operation === 'edit' && $record?->is(auth()->user()))
                    ->required(fn (Get $get, ?User $record) => $record !== null
                        && (mb_strtolower(trim((string) $get('email'))) !== mb_strtolower((string) $record->email) || filled($get('password'))))
                    ->rule('current_password')
                    ->dehydrated(false),
            ]);
    }

    /**
     * Datos y acceso de un técnico: los mismos campos (y reglas) en el alta del
     * panel y en la configuración inicial guiada (CompanySetupWizard).
     *
     * @return array<int, Component|Field>
     */
    public static function technicianFields(): array
    {
        return [
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
                ->rule(fn () => Password::defaults())
                ->rule(fn (Get $get) => new NotCommonPassword([$get('name'), $get('email')]))
                ->confirmed()
                ->helperText(fn (string $operation) => PasswordPolicy::HINT.($operation === 'edit' ? ' Dejala vacía para no cambiarla.' : ''))
                ->dehydrated(fn ($state) => filled($state))
                ->dehydrateStateUsing(fn ($state) => Hash::make($state)),

            TextInput::make('password_confirmation')
                ->label('Repetir contraseña')
                ->password()
                ->revealable()
                ->autocomplete('new-password')
                ->required(fn (string $operation, Get $get) => $operation === 'create' || filled($get('password')))
                ->dehydrated(false),
        ];
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table)
            ->recordUrl(
                fn ($record) => static::getUrl('view', ['record' => $record])
            );
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),

            'create' => Pages\CreateUser::route('/create'),

            'view' => Pages\ViewUser::route('/{record}'),

            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        // Los usuarios del portal del cliente se administran desde el cliente,
        // no son técnicos.
        $query = parent::getEloquentQuery()->where('role', '!=', User::ROLE_CLIENT);

        // Los administrativos se gestionan en «Administrativos» (límite del plan y registro), no acá.
        $query->where('role', '!=', User::ROLE_STAFF);

        // Un administrativo con «Técnicos» solo ve técnicos (ni al dueño ni a otros administrativos).
        if (Access::isStaff(auth()->user())) {
            $query->where('role', 'technician');
        }

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
        return static::canAccess();
    }

    public static function canAccess(): bool
    {
        return static::canViewAny();
    }
}

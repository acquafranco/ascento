<?php

namespace App\Filament\Resources\StaffUsers;

use App\Enums\PermissionArea;
use App\Enums\PlanLimit;
use App\Filament\Resources\StaffUsers\Pages\CreateStaffUser;
use App\Filament\Resources\StaffUsers\Pages\EditStaffUser;
use App\Filament\Resources\StaffUsers\Pages\ListStaffUsers;
use App\Models\Company;
use App\Models\User;
use App\Services\Staff\StaffManager;
use App\Support\Access\Access;
use App\Support\Auth\PasswordPolicy;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Administrativos de la empresa (Etapa 6, PR 2): solo el dueño. Alta,
 * datos, permisos por área (sin acceso / ver / gestionar), desactivar y
 * reactivar, con el estado de cada uno y el motivo si no puede entrar.
 * Todo pasa por StaffManager (límite del plan, empresa, registro).
 */
class StaffUserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $navigationLabel = 'Administrativos';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'administrativo';

    protected static ?string $pluralModelLabel = 'administrativos';

    protected static string|\UnitEnum|null $navigationGroup = 'Administración';

    protected static ?string $slug = 'administrativos';

    /** Solo el dueño (nunca un administrativo ni el SuperAdmin). */
    public static function canAccess(): bool
    {
        return Access::isOwner(auth()->user());
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function canCreate(): bool
    {
        return static::canAccess();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canAccess() && $record->role === User::ROLE_STAFF && (int) $record->company_id === (int) auth()->user()->company_id;
    }

    public static function canDelete(Model $record): bool
    {
        return false; // se desactiva (acción propia), nunca se borra
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes()
            ->where('company_id', auth()->user()?->company_id ?? 0)
            ->where('role', User::ROLE_STAFF)
            ->where('is_super_admin', false);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return static::getEloquentQuery();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Datos')->columns(['default' => 1, 'md' => 3])->columnSpanFull()->schema([
                TextInput::make('name')->label('Nombre')->required()->maxLength(120),
                TextInput::make('email')->label('Correo')->email()->required()->maxLength(190)
                    ->rule(fn (?User $record) => Rule::unique('users', 'email')->ignore($record?->id)),
                TextInput::make('phone')->label('Teléfono')->tel()->maxLength(40),
                TextInput::make('password')->label('Contraseña inicial')->password()->revealable()
                    ->rule(PasswordPolicy::defaults())->required()
                    ->helperText('Se la pasás a la persona; después la cambia desde su perfil.')
                    ->visibleOn('create'),
            ]),
            Section::make('Permisos')
                ->columnSpanFull()
                ->description('Empieza sin permisos. «Ver» deja consultar; «Gestionar» además crear, editar y ejecutar acciones. Nunca accede a la suscripción, «Mi empresa», usuarios ni a la exportación completa.')
                ->schema([
                    Grid::make(['default' => 1, 'md' => 2, 'xl' => 3])->schema(collect(self::offeredAreas())->map(fn (PermissionArea $area) => ToggleButtons::make('levels.'.$area->value)
                        ->label($area->label())
                        ->inline()
                        ->options(['none' => 'Sin acceso', PermissionArea::VIEW => 'Ver'] + ($area->hasManage() ? [PermissionArea::MANAGE => 'Gestionar'] : []))
                        ->colors(['none' => 'gray', PermissionArea::VIEW => 'info', PermissionArea::MANAGE => 'success'])
                        ->default('none')
                        ->required()
                    )->all()),
                ]),
        ]);
    }

    /** Áreas que se ofrecen en el formulario (todas desde el PR 3). */
    public static function offeredAreas(): array
    {
        return PermissionArea::cases();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withTrashed())
            ->defaultSort('id')
            ->columns([
                TextColumn::make('name')->label('Nombre')->description(fn (User $record) => $record->email)->searchable(['name', 'email']),
                TextColumn::make('status')->label('Estado')->badge()
                    ->state(fn (User $record) => self::status($record)['label'])
                    ->color(fn (User $record) => self::status($record)['color'])
                    ->description(fn (User $record) => self::status($record)['reason'])
                    ->wrap(),
                TextColumn::make('permissions_summary')->label('Permisos')
                    ->state(fn (User $record) => self::summary($record))->wrap(),
                TextColumn::make('created_at')->label('Alta')->date('d/m/Y'),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (User $record) => ! $record->trashed()),
                Action::make('deactivate')->label('Desactivar')->icon('heroicon-o-no-symbol')->color('danger')
                    ->visible(fn (User $record) => ! $record->trashed())
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $record) => 'Desactivar a '.$record->name)
                    ->modalDescription('Pierde el acceso al panel enseguida. No se borra nada: su historial y sus permisos se conservan y lo podés reactivar.')
                    ->action(function (User $record) {
                        app(StaffManager::class)->deactivate(auth()->user(), $record);
                        Notification::make()->title('Administrativo desactivado')->success()->send();
                    }),
                Action::make('reactivate')->label('Reactivar')->icon('heroicon-o-arrow-path')->color('success')
                    ->visible(fn (User $record) => $record->trashed())
                    ->requiresConfirmation()
                    ->action(function (User $record) {
                        app(StaffManager::class)->reactivate(auth()->user(), $record);
                        Notification::make()->title('Administrativo reactivado')->success()->send();
                    }),
            ]);
    }

    /** Estado visible para el dueño, con el motivo si no puede entrar. */
    public static function status(User $record): array
    {
        $reason = Access::staffBlockReason($record);
        $max = Company::find($record->company_id)?->plan()->limit(PlanLimit::AdminUsers);

        return match (true) {
            $reason === Access::BLOCK_DEACTIVATED => ['label' => 'Desactivado', 'color' => 'gray', 'reason' => 'No entra al panel. Se conserva todo.'],
            $reason === Access::BLOCK_COMPANY => ['label' => 'Sin acceso', 'color' => 'danger', 'reason' => 'La empresa está deshabilitada.'],
            $reason === Access::BLOCK_PLAN => ['label' => 'Suspendido por el plan', 'color' => 'warning', 'reason' => $max === 0 ? 'El plan actual no incluye administrativos.' : "El plan actual permite {$max}: cambiá de plan o desactivá a otro."],
            Access::permissionsOf($record) === [] => ['label' => 'Activo, sin permisos', 'color' => 'info', 'reason' => 'Entra al panel pero no ve nada hasta que le des permisos.'],
            default => ['label' => 'Activo', 'color' => 'success', 'reason' => null],
        };
    }

    public static function summary(User $record): string
    {
        $levels = StaffManager::levelsFromKeys(Access::permissionsOf($record));
        $parts = collect(PermissionArea::cases())
            ->filter(fn (PermissionArea $area) => $levels[$area->value] !== 'none')
            ->map(fn (PermissionArea $area) => $area->label().($levels[$area->value] === PermissionArea::MANAGE ? ' (gestionar)' : ' (ver)'));

        return $parts->isEmpty() ? 'Ninguno' : $parts->implode(', ');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStaffUsers::route('/'),
            'create' => CreateStaffUser::route('/create'),
            'edit' => EditStaffUser::route('/{record}/edit'),
        ];
    }
}

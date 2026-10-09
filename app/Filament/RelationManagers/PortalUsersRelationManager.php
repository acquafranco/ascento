<?php

namespace App\Filament\RelationManagers;

use App\Filament\Concerns\OwnerRecordOfCurrentCompany;
use App\Models\Building;
use App\Models\Client;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Usuarios del portal de este cliente y qué edificios ve cada uno. El
 * usuario solo puede quedar autorizado para edificios de ESTE cliente.
 */
class PortalUsersRelationManager extends RelationManager
{
    use OwnerRecordOfCurrentCompany;

    protected static string $relationship = 'portalUsers';

    protected static ?string $title = 'Acceso al portal';

    public function isReadOnly(): bool
    {
        return false;
    }

    /** Edificios de este cliente (lo único que se puede autorizar). */
    private function buildingOptions(): array
    {
        return Building::where('client_id', $this->getOwnerRecord()->id)->orderBy('name')
            ->get(['id', 'name', 'address'])->mapWithKeys(fn ($b) => [$b->id => trim($b->name.' — '.$b->address, ' —')])->all();
    }

    /** Solo ids de edificios de este cliente (lo que venga del navegador se filtra). */
    private function allowedBuildingIds(array $ids): array
    {
        return array_values(array_intersect(array_map('intval', $ids), array_keys($this->buildingOptions())));
    }

    private function buildingsField(): CheckboxList
    {
        return CheckboxList::make('building_ids')
            ->label('Edificios que puede ver')
            ->options(fn () => $this->buildingOptions())
            ->bulkToggleable()
            ->required()
            ->helperText('Solo ve los remitos, reportes, presupuestos y documentos que compartas de estos edificios.');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('portalBuildings'))
            ->columns([
                TextColumn::make('name')->label('Nombre'),
                TextColumn::make('email')->label('Email'),
                TextColumn::make('portal_buildings_count')->label('Edificios'),
                TextColumn::make('deleted_at')->label('Estado')->badge()
                    ->state(fn (User $record) => $record->trashed() ? 'Desactivado' : 'Activo')
                    ->color(fn (string $state) => $state === 'Activo' ? 'success' : 'gray'),
            ])
            ->filters([TrashedFilter::make()])
            ->emptyStateHeading('Sin usuarios del portal')
            ->emptyStateDescription('Creá un usuario para que el consorcio o la administración vea lo que compartas de sus edificios.')
            ->headerActions([
                Action::make('createPortalUser')
                    ->label('Crear usuario del portal')
                    ->icon('heroicon-o-user-plus')
                    ->schema([
                        TextInput::make('name')->label('Nombre')->required()->maxLength(255),
                        TextInput::make('email')->label('Email')->email()->required()->maxLength(255)->unique(User::class, 'email'),
                        TextInput::make('password')->label('Contraseña inicial')->password()->revealable()->required()->minLength(8)
                            ->helperText('Pasásela al cliente; la puede cambiar con "¿Olvidaste tu contraseña?".'),
                        $this->buildingsField(),
                    ])
                    ->action(function (array $data) {
                        /** @var Client $client */
                        $client = $this->getOwnerRecord();

                        DB::transaction(function () use ($data, $client) {
                            $user = new User(['name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($data['password'])]);
                            // Rol, empresa y cliente los fija el servidor.
                            $user->forceFill(['role' => User::ROLE_CLIENT, 'company_id' => $client->company_id, 'client_id' => $client->id])->save();
                            $user->portalBuildings()->sync($this->allowedBuildingIds($data['building_ids'] ?? []));
                        });

                        Notification::make()->title('Usuario del portal creado')->success()->send();
                    }),
            ])
            ->recordActions([
                Action::make('editBuildings')
                    ->label('Edificios')
                    ->icon('heroicon-o-building-office')
                    ->fillForm(fn (User $record) => ['building_ids' => $record->portalBuildings()->pluck('buildings.id')->all()])
                    ->schema([$this->buildingsField()])
                    ->action(function (User $record, array $data) {
                        $record->portalBuildings()->sync($this->allowedBuildingIds($data['building_ids'] ?? []));
                        Notification::make()->title('Edificios actualizados')->success()->send();
                    })
                    ->visible(fn (User $record) => ! $record->trashed()),
                Action::make('resetPassword')
                    ->label('Contraseña')
                    ->icon('heroicon-o-key')
                    ->schema([TextInput::make('password')->label('Nueva contraseña')->password()->revealable()->required()->minLength(8)])
                    ->action(function (User $record, array $data) {
                        $record->forceFill(['password' => Hash::make($data['password'])])->save();
                        Notification::make()->title('Contraseña actualizada')->success()->send();
                    })
                    ->visible(fn (User $record) => ! $record->trashed()),
                Action::make('deactivate')
                    ->label('Desactivar')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('No va a poder entrar al portal. Se puede reactivar.')
                    ->action(fn (User $record) => $record->delete())
                    ->visible(fn (User $record) => ! $record->trashed()),
                Action::make('reactivate')
                    ->label('Reactivar')
                    ->icon('heroicon-o-arrow-path')
                    ->action(fn (User $record) => $record->restore())
                    ->visible(fn (User $record) => $record->trashed()),
            ]);
    }
}

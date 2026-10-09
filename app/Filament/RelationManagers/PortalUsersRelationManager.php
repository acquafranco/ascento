<?php

namespace App\Filament\RelationManagers;

use App\Enums\PlanFeature;
use App\Filament\Concerns\OwnerRecordOfCurrentCompany;
use App\Models\Building;
use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use App\Services\Portal\PortalInvitations;
use App\Support\Plans\PlanGuard;
use App\Support\Plans\PlanUpsell;
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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;

/**
 * Usuarios del portal de este cliente y qué edificios ve cada uno.
 *
 * - Alta por invitación: el admin carga nombre, email y edificios; Ascento
 *   manda un enlace (un solo uso, 72 h) para que la persona elija su
 *   contraseña. El admin nunca define ni ve contraseñas.
 * - Solo edificios de ESTE cliente (también filtrado en el servidor).
 * - Solo con un plan que incluya el portal (Profesional / Empresa). En
 *   Inicial no se puede invitar, reactivar ni cambiar edificios; desactivar
 *   siempre se puede.
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

    private function portalAllowed(): bool
    {
        $company = Company::find($this->getOwnerRecord()->company_id);

        return $company !== null && PlanGuard::for($company)->allows(PlanFeature::ClientPortal);
    }

    /** Defensa en el servidor (además de ocultar las acciones). */
    private function ensurePortalAllowed(): void
    {
        $company = Company::findOrFail($this->getOwnerRecord()->company_id);
        PlanGuard::for($company)->ensureFeature(PlanFeature::ClientPortal);
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

    private function invite(User $user): void
    {
        if (app(PortalInvitations::class)->send($user)) {
            Notification::make()->title('Invitación enviada')->body("Le mandamos a {$user->email} un enlace para activar su cuenta (vence en 72 horas).")->success()->send();
        } else {
            Notification::make()->title('No se pudo enviar el correo')
                ->body('La cuenta quedó creada. Revisá el email y probá "Reenviar invitación" en unos minutos; si sigue fallando, avisá a soporte.')
                ->warning()->persistent()->send();
        }
    }

    public static function status(User $user): string
    {
        return match (true) {
            $user->trashed() => 'Desactivado',
            $user->portal_activated_at !== null => 'Activo',
            $user->portal_invited_at === null => 'Invitación sin enviar',
            $user->portal_invited_at->lt(now()->subHours(72)) => 'Invitación vencida',
            default => 'Invitación enviada',
        };
    }

    public function table(Table $table): Table
    {
        $allowed = $this->portalAllowed();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('portalBuildings'))
            ->description($allowed ? null : 'El portal para clientes está incluido en los planes Profesional y Empresa. Con tu plan actual los usuarios del portal no pueden ingresar.')
            ->columns([
                TextColumn::make('name')->label('Nombre'),
                TextColumn::make('email')->label('Email'),
                TextColumn::make('portal_buildings_count')->label('Edificios'),
                TextColumn::make('portal_status')->label('Estado')->badge()
                    ->state(fn (User $record) => self::status($record))
                    ->color(fn (string $state) => match ($state) {
                        'Activo' => 'success',
                        'Invitación enviada' => 'info',
                        'Invitación vencida', 'Invitación sin enviar' => 'warning',
                        default => 'gray',
                    }),
            ])
            ->filters([TrashedFilter::make()])
            ->emptyStateHeading('Sin usuarios del portal')
            ->emptyStateDescription($allowed
                ? 'Invitá a una persona del consorcio o la administración para que vea lo que compartas de sus edificios.'
                : 'Disponible en los planes Profesional y Empresa.')
            ->headerActions([
                Action::make('upgradeForPortal')
                    ->label('Ver planes')
                    ->url(fn () => PlanUpsell::url(feature: PlanFeature::ClientPortal))
                    ->visible(! $allowed),
                Action::make('createPortalUser')
                    ->label('Invitar al portal')
                    ->icon('heroicon-o-user-plus')
                    ->visible($allowed)
                    ->modalDescription('Le vamos a mandar un correo para que active su cuenta y elija su contraseña.')
                    ->schema([
                        TextInput::make('name')->label('Nombre de la persona')->required()->maxLength(255),
                        TextInput::make('email')->label('Email')->email()->required()->maxLength(255)->unique(User::class, 'email'),
                        $this->buildingsField(),
                    ])
                    ->action(function (array $data) {
                        $this->ensurePortalAllowed();

                        /** @var Client $client */
                        $client = $this->getOwnerRecord();

                        $user = DB::transaction(function () use ($data, $client) {
                            // Sin contraseña utilizable hasta que active la cuenta.
                            $user = new User(['name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make(Str::random(64))]);
                            // Rol, empresa y cliente los fija el servidor.
                            $user->forceFill(['role' => User::ROLE_CLIENT, 'company_id' => $client->company_id, 'client_id' => $client->id])->save();
                            $user->portalBuildings()->sync($this->allowedBuildingIds($data['building_ids'] ?? []));

                            return $user;
                        });

                        $this->invite($user);
                    }),
            ])
            ->recordActions([
                Action::make('editBuildings')
                    ->label('Edificios')
                    ->icon('heroicon-o-building-office')
                    ->fillForm(fn (User $record) => ['building_ids' => $record->portalBuildings()->pluck('buildings.id')->all()])
                    ->schema([$this->buildingsField()])
                    ->action(function (User $record, array $data) {
                        $this->ensurePortalAllowed();
                        $record->portalBuildings()->sync($this->allowedBuildingIds($data['building_ids'] ?? []));
                        Notification::make()->title('Edificios actualizados')->success()->send();
                    })
                    ->visible(fn (User $record) => $allowed && ! $record->trashed()),
                Action::make('resendInvitation')
                    ->label('Reenviar invitación')
                    ->icon('heroicon-o-envelope')
                    ->requiresConfirmation()
                    ->modalDescription('El enlace anterior deja de funcionar.')
                    ->action(function (User $record) {
                        $this->ensurePortalAllowed();
                        $this->invite($record);
                    })
                    ->visible(fn (User $record) => $allowed && ! $record->trashed() && $record->portal_activated_at === null),
                Action::make('sendPasswordReset')
                    ->label('Enviar cambio de contraseña')
                    ->icon('heroicon-o-key')
                    ->requiresConfirmation()
                    ->modalDescription('Le mandamos un enlace para que elija una contraseña nueva. Vos no la ves.')
                    ->action(function (User $record) {
                        $this->ensurePortalAllowed();

                        try {
                            $status = Password::sendResetLink(['email' => $record->email]);
                        } catch (Throwable $e) {
                            Log::warning('No se pudo enviar el cambio de contraseña del portal', ['user_id' => $record->id, 'exception' => $e::class]);
                            $status = null;
                        }

                        $status === Password::RESET_LINK_SENT
                            ? Notification::make()->title('Enlace enviado')->success()->send()
                            : Notification::make()->title('No se pudo enviar el correo')->body('Probá de nuevo en unos minutos.')->warning()->send();
                    })
                    ->visible(fn (User $record) => $allowed && ! $record->trashed() && $record->portal_activated_at !== null),
                Action::make('deactivate')
                    ->label('Desactivar')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Deja de poder entrar al portal de inmediato (también si tiene una sesión abierta). Se puede reactivar.')
                    ->action(fn (User $record) => $record->delete())
                    ->visible(fn (User $record) => ! $record->trashed()),
                Action::make('reactivate')
                    ->label('Reactivar')
                    ->icon('heroicon-o-arrow-path')
                    ->action(function (User $record) {
                        $this->ensurePortalAllowed();
                        $record->restore();
                    })
                    ->visible(fn (User $record) => $allowed && $record->trashed()),
            ]);
    }
}

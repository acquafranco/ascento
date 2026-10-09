<?php

namespace App\Filament\RelationManagers;

use App\Enums\PlanFeature;
use App\Filament\Concerns\OwnerRecordOfCurrentCompany;
use App\Models\Building;
use App\Models\Client;
use App\Models\Company;
use App\Models\PortalMembership;
use App\Services\Portal\PortalInvitations;
use App\Support\Plans\PlanGuard;
use App\Support\Plans\PlanUpsell;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Throwable;

/**
 * Personas con acceso al portal de este cliente y qué edificios ve cada una.
 *
 * - Cada fila es un acceso (PortalMembership) de este cliente. La misma
 *   persona puede tener acceso en otras empresas: eso no se muestra acá y lo
 *   que se haga acá (edificios, desactivar) no afecta esos otros accesos.
 * - Alta por invitación: el admin carga nombre, email y edificios; Ascento
 *   manda el enlace para elegir contraseña (o el aviso de acceso nuevo si la
 *   persona ya usa el portal). El admin nunca define ni ve contraseñas.
 * - Solo edificios de ESTE cliente (también filtrado en el servidor).
 * - Solo con un plan que incluya el portal. Desactivar siempre se puede.
 */
class PortalUsersRelationManager extends RelationManager
{
    use OwnerRecordOfCurrentCompany;

    protected static string $relationship = 'portalMemberships';

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
        PlanGuard::for(Company::findOrFail($this->getOwnerRecord()->company_id))->ensureFeature(PlanFeature::ClientPortal);
    }

    /** La fila tiene que ser de ESTE cliente (no se confía en lo que manda el navegador). */
    private function ownMembership(PortalMembership $membership): PortalMembership
    {
        abort_unless((int) $membership->client_id === (int) $this->getOwnerRecord()->id, 404);

        return $membership;
    }

    /** Edificios de este cliente (lo único que se puede autorizar). */
    private function buildingOptions(): array
    {
        return Building::where('client_id', $this->getOwnerRecord()->id)->orderBy('name')
            ->get(['id', 'name', 'address'])->mapWithKeys(fn ($b) => [$b->id => trim($b->name.' — '.$b->address, ' —')])->all();
    }

    private function buildingsField(): CheckboxList
    {
        return CheckboxList::make('building_ids')
            ->label('Edificios que puede ver')
            ->options(fn () => $this->buildingOptions())
            ->in(fn () => array_keys($this->buildingOptions()))
            ->bulkToggleable()
            ->required()
            ->helperText('Solo ve los remitos, reportes, presupuestos y documentos que compartas de estos edificios.');
    }

    private function mailResult(bool $mailed, string $email, bool $existing): void
    {
        if (! $mailed) {
            Notification::make()->title('No se pudo enviar el correo')
                ->body('El acceso quedó creado. Revisá el email y probá "Reenviar" en unos minutos; si sigue fallando, avisá a soporte.')
                ->warning()->persistent()->send();

            return;
        }

        Notification::make()->title('Acceso enviado')->success()->body($existing
            ? "{$email} ya usa el portal de Ascento: le avisamos que ahora también puede ver la información de este cliente."
            : "Le mandamos a {$email} un enlace para activar su cuenta (vence en ".PortalInvitations::LINK_HOURS.' horas).')->send();
    }

    public function table(Table $table): Table
    {
        $allowed = $this->portalAllowed();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'))
            ->description($allowed ? null : 'El portal para clientes está incluido en los planes Profesional y Empresa. Con tu plan actual los usuarios del portal no pueden ingresar.')
            ->columns([
                TextColumn::make('user.name')->label('Nombre'),
                TextColumn::make('user.email')->label('Email'),
                TextColumn::make('buildings')->label('Edificios')->state(fn (PortalMembership $record) => count($record->buildingIds())),
                TextColumn::make('portal_status')->label('Estado')->badge()
                    ->state(fn (PortalMembership $record) => PortalInvitations::status($record))
                    ->color(fn (string $state) => match ($state) {
                        'Activo' => 'success',
                        'Invitación enviada' => 'info',
                        'Invitación vencida', 'Invitación sin enviar' => 'warning',
                        default => 'gray',
                    }),
            ])
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
                    ->modalDescription('Le vamos a mandar un correo para que active su cuenta y elija su contraseña. Si ya usa el portal con otra empresa, entra con su cuenta de siempre.')
                    ->schema([
                        TextInput::make('name')->label('Nombre de la persona')->required()->maxLength(255),
                        TextInput::make('email')->label('Email')->email()->required()->maxLength(255)
                            ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                                if ($problem = PortalInvitations::emailProblem($this->getOwnerRecord(), (string) $value)) {
                                    $fail($problem);
                                }
                            }),
                        $this->buildingsField(),
                    ])
                    ->action(function (array $data) {
                        $this->ensurePortalAllowed();

                        /** @var Client $client */
                        $client = $this->getOwnerRecord();
                        $result = app(PortalInvitations::class)->invite($client, $data['name'], $data['email'], $data['building_ids'] ?? [], auth()->user());

                        $this->mailResult($result['mailed'], $data['email'], $result['existing']);
                    }),
            ])
            ->recordActions([
                Action::make('editBuildings')
                    ->label('Edificios')
                    ->icon('heroicon-o-building-office')
                    ->fillForm(fn (PortalMembership $record) => ['building_ids' => $record->buildingIds()])
                    ->schema([$this->buildingsField()])
                    ->action(function (PortalMembership $record, array $data) {
                        $this->ensurePortalAllowed();
                        PortalInvitations::syncBuildings($this->ownMembership($record), $data['building_ids'] ?? []);
                        Notification::make()->title('Edificios actualizados')->success()->send();
                    })
                    ->visible(fn (PortalMembership $record) => $allowed && $record->isActive()),
                Action::make('resendInvitation')
                    ->label('Reenviar invitación')
                    ->icon('heroicon-o-envelope')
                    ->requiresConfirmation()
                    ->modalDescription('El enlace anterior deja de funcionar.')
                    ->action(function (PortalMembership $record) {
                        $this->ensurePortalAllowed();
                        $this->mailResult(app(PortalInvitations::class)->send($this->ownMembership($record)), (string) $record->user?->email, false);
                    })
                    ->visible(fn (PortalMembership $record) => $allowed && $record->isActive() && $record->user?->portal_activated_at === null),
                Action::make('sendPasswordReset')
                    ->label('Enviar cambio de contraseña')
                    ->icon('heroicon-o-key')
                    ->requiresConfirmation()
                    ->modalDescription('Le mandamos un enlace para que elija una contraseña nueva. Vos no la ves.')
                    ->action(function (PortalMembership $record) {
                        $this->ensurePortalAllowed();

                        try {
                            $status = Password::sendResetLink(['email' => $this->ownMembership($record)->user->email]);
                        } catch (Throwable $e) {
                            Log::warning('No se pudo enviar el cambio de contraseña del portal', ['user_id' => $record->user_id, 'exception' => $e::class]);
                            $status = null;
                        }

                        $status === Password::RESET_LINK_SENT
                            ? Notification::make()->title('Enlace enviado')->success()->send()
                            : Notification::make()->title('No se pudo enviar el correo')->body('Probá de nuevo en unos minutos.')->warning()->send();
                    })
                    ->visible(fn (PortalMembership $record) => $allowed && $record->isActive() && $record->user?->portal_activated_at !== null),
                Action::make('deactivate')
                    ->label('Desactivar')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Deja de ver la información de este cliente de inmediato (también si tiene una sesión abierta). Se puede reactivar.')
                    ->action(function (PortalMembership $record) {
                        $this->ownMembership($record)->forceFill(['deactivated_at' => now()])->save();
                        Notification::make()->title('Acceso desactivado')->success()->send();
                    })
                    ->visible(fn (PortalMembership $record) => $record->isActive()),
                Action::make('reactivate')
                    ->label('Reactivar')
                    ->icon('heroicon-o-arrow-path')
                    ->action(function (PortalMembership $record) {
                        $this->ensurePortalAllowed();
                        $this->ownMembership($record)->forceFill(['deactivated_at' => null])->save();
                        $record->user?->trashed() && $record->user->restore();
                        Notification::make()->title('Acceso reactivado')->success()->send();
                    })
                    ->visible(fn (PortalMembership $record) => $allowed && ! $record->isActive()),
            ]);
    }
}

<?php

namespace App\Filament\Resources\Clients\Tables;

use App\Enums\PlanLimit;
use App\Models\Client;
use App\Services\Clients\ClientEraser;
use App\Support\Access\Access;
use App\Support\Plans\PlanRestoreGuard;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ClientsTable
{
    public const DEACTIVATE_DESCRIPTION = 'Deja de aparecer en los listados. Sus edificios, remitos y presupuestos se conservan. Las personas con acceso a su portal no pueden ingresar mientras esté desactivado. Se puede reactivar.';

    public const RESTORE_DESCRIPTION = 'Vuelve a aparecer en los listados y las personas con acceso a su portal pueden volver a ingresar.';

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount([
                'portalMemberships as portal_total',
                'portalMemberships as portal_open' => fn ($q) => $q->whereNull('deactivated_at'),
                'portalMemberships as portal_live' => fn ($q) => $q->whereNull('deactivated_at')
                    ->whereHas('user', fn ($u) => $u->whereNull('deleted_at')->whereNotNull('portal_activated_at')),
            ]))
            ->columns([

                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable(),

                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => Client::TYPES[$state] ?? ucfirst((string) $state)),

                TextColumn::make('contact_person')
                    ->label('Contacto')
                    ->searchable(),

                TextColumn::make('phone')
                    ->label('Teléfono')
                    ->searchable(),

                TextColumn::make('email')
                    ->label('Email')
                    ->searchable(),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->state(fn (Client $record) => $record->trashed() ? 'Desactivado' : 'Activo')
                    ->color(fn (string $state) => $state === 'Activo' ? 'success' : 'gray'),

                TextColumn::make('portal')
                    ->label('Portal')
                    ->badge()
                    ->state(fn (Client $record) => static::portalStatus($record))
                    ->color(fn (string $state) => match ($state) {
                        'Activo' => 'success',
                        'Invitado' => 'info',
                        'Suspendido' => 'danger',
                        default => 'gray',
                    })
                    ->tooltip(fn (Client $record) => static::portalTooltip($record)),

                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('Actualizado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make()->label('Desactivados'),
                TernaryFilter::make('portal')
                    ->label('Portal')
                    ->trueLabel('Con portal activo')
                    ->falseLabel('Sin portal activo')
                    ->queries(
                        true: fn (Builder $q) => $q->whereHas('portalMemberships', fn ($m) => $m->whereNull('deactivated_at')
                            ->whereHas('user', fn ($u) => $u->whereNull('deleted_at')->whereNotNull('portal_activated_at'))),
                        false: fn (Builder $q) => $q->whereDoesntHave('portalMemberships', fn ($m) => $m->whereNull('deactivated_at')
                            ->whereHas('user', fn ($u) => $u->whereNull('deleted_at')->whereNotNull('portal_activated_at'))),
                    ),
            ])
            ->recordActions([
                RestoreAction::make()->before(PlanRestoreGuard::before(PlanLimit::Clients))->label('Reactivar')
                    ->modalHeading('Reactivar cliente')->modalDescription(self::RESTORE_DESCRIPTION),
                ViewAction::make()->label('Ficha'),
                EditAction::make()->label('Editar'),
                DeleteAction::make()
                    ->label('Desactivar')
                    ->modalHeading('Desactivar cliente')
                    ->modalDescription(self::DEACTIVATE_DESCRIPTION)
                    ->modalSubmitActionLabel('Desactivar')
                    ->successNotificationTitle('Desactivado'),
                static::eraseAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label('Desactivar seleccionados')
                        ->modalHeading('Desactivar seleccionados')
                        ->modalDescription(self::DEACTIVATE_DESCRIPTION)
                        ->modalSubmitActionLabel('Desactivar'),
                    RestoreBulkAction::make()->before(PlanRestoreGuard::beforeBulk(PlanLimit::Clients))->label('Reactivar seleccionados')
                        ->modalDescription(self::RESTORE_DESCRIPTION),
                ]),
            ]);
    }

    /**
     * Estado del portal del cliente (columna del listado):
     * Activo (alguien ya entra), Invitado (nadie activó todavía), Desactivado
     * (todas sus personas desactivadas), Suspendido (cliente desactivado:
     * nadie puede entrar) o Sin portal.
     */
    public static function portalStatus(Client $record): string
    {
        $total = (int) ($record->portal_total ?? 0);

        return match (true) {
            $total === 0 => 'Sin portal',
            $record->trashed() => 'Suspendido',
            (int) $record->portal_live > 0 => 'Activo',
            (int) $record->portal_open > 0 => 'Invitado',
            default => 'Desactivado',
        };
    }

    private static function portalTooltip(Client $record): ?string
    {
        $people = fn (int $n) => $n === 1 ? '1 persona' : "{$n} personas";

        return match (static::portalStatus($record)) {
            'Activo' => $people((int) $record->portal_live).' con acceso activo',
            'Invitado' => $people((int) $record->portal_open).' invitada(s), sin activar todavía',
            'Desactivado' => 'Todos sus accesos al portal están desactivados',
            'Suspendido' => 'Cliente desactivado: nadie puede ingresar a su portal hasta reactivarlo',
            default => null,
        };
    }

    /**
     * Eliminar para siempre (ver ClientEraser): solo el dueño (o el
     * SuperAdmin), y solo si no tiene historial.
     */
    public static function eraseAction(): Action
    {
        return Action::make('erase')
            ->label('Eliminar')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Eliminar cliente')
            ->modalDescription(fn (Client $record) => static::eraseDescription($record))
            ->modalSubmitActionLabel('Eliminar definitivamente')
            ->visible(fn () => ! Access::isStaff(auth()->user()))
            ->action(function (Client $record, Action $action) {
                abort_if(Access::isStaff(auth()->user()), 403);

                try {
                    $removed = app(ClientEraser::class)->erase($record);
                } catch (ValidationException $e) {
                    Notification::make()->title('No se puede eliminar')->body(collect($e->errors())->flatten()->first())->danger()->persistent()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->title('Cliente eliminado')->success()
                    ->body($removed > 0 ? ($removed === 1 ? 'También se eliminó 1 acceso al portal.' : "También se eliminaron {$removed} accesos al portal.") : null)
                    ->send();
            });
    }

    private static function eraseDescription(Client $record): string
    {
        if ($blockers = ClientEraser::blockers($record)) {
            return ClientEraser::blockedMessage($blockers);
        }

        $people = ClientEraser::portalPeople($record);

        return 'Se borra para siempre y no se puede deshacer.'
            .($people > 0 ? ' También se elimina el acceso al portal de '.($people === 1 ? '1 persona' : "{$people} personas").' (si alguna también entra por otro cliente, conserva ese acceso).' : '')
            .' Si solo querés que deje de aparecer, usá «Desactivar».';
    }
}

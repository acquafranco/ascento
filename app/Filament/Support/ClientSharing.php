<?php

namespace App\Filament\Support;

use App\Enums\PlanFeature;
use App\Services\Notifications\ClientShareNotifier;
use App\Support\Plans\PlanUpsell;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\ToggleColumn;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * "Compartir con el cliente": la misma columna y las mismas acciones en
 * reportes, remitos, mantenimientos, inspecciones, presupuestos y documentos.
 *
 * Solo con un plan que incluya el portal (Profesional / Empresa): sin él no
 * se muestra, y si igual llega el pedido, el modelo lo rechaza
 * (SharesWithClient). Compartir avisa a los usuarios del portal autorizados.
 */
class ClientSharing
{
    public static function enabled(): bool
    {
        return (bool) PlanUpsell::currentCompany()?->plan()->allows(PlanFeature::ClientPortal);
    }

    /** Comparte o deja de compartir, y avisa al cliente lo que es nuevo. */
    public static function apply(iterable $records, bool $shared): void
    {
        $new = [];

        foreach ($records as $record) {
            if ($record->shareWithClient($shared)) {
                $new[] = $record;
            }
        }

        if ($new !== []) {
            app(ClientShareNotifier::class)->shared($new);
        }
    }

    public static function column(): ToggleColumn
    {
        return ToggleColumn::make('shared_with_client')
            ->label('En portal')
            ->tooltip('Si está activo, el cliente lo ve en su portal (solo en los edificios que tiene habilitados).')
            ->updateStateUsing(function (Model $record, bool $state) {
                self::apply([$record], $state);

                return $state;
            })
            ->visible(fn () => self::enabled())
            ->toggleable();
    }

    /** @return array<BulkAction> */
    public static function bulkActions(): array
    {
        return [
            BulkAction::make('shareWithClient')
                ->label('Compartir con el cliente')
                ->icon('heroicon-o-eye')
                ->requiresConfirmation()
                ->modalDescription('El cliente va a ver estos registros en su portal (solo los de los edificios que tiene habilitados).')
                ->visible(fn () => self::enabled())
                ->action(function (Collection $records) {
                    self::apply($records, true);
                    Notification::make()->title('Compartido con el cliente')->success()->send();
                })
                ->deselectRecordsAfterCompletion(),
            BulkAction::make('unshareWithClient')
                ->label('Dejar de compartir')
                ->icon('heroicon-o-eye-slash')
                // Dejar de compartir siempre se puede (también tras bajar de plan).
                ->action(function (Collection $records) {
                    self::apply($records, false);
                    Notification::make()->title('Ya no se comparte con el cliente')->success()->send();
                })
                ->deselectRecordsAfterCompletion(),
        ];
    }
}

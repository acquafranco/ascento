<?php

namespace App\Filament\Support;

use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\ToggleColumn;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * "Compartir con el cliente": la misma columna y las mismas acciones en
 * reportes, remitos, mantenimientos, inspecciones, presupuestos y documentos.
 */
class ClientSharing
{
    public static function column(): ToggleColumn
    {
        return ToggleColumn::make('shared_with_client')
            ->label('En portal')
            ->tooltip('Si está activo, el cliente lo ve en su portal (solo en los edificios que tiene habilitados).')
            ->updateStateUsing(function (Model $record, bool $state) {
                $record->shareWithClient($state);

                return $state;
            })
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
                ->action(function (Collection $records) {
                    $records->each->shareWithClient(true);
                    Notification::make()->title('Compartido con el cliente')->success()->send();
                })
                ->deselectRecordsAfterCompletion(),
            BulkAction::make('unshareWithClient')
                ->label('Dejar de compartir')
                ->icon('heroicon-o-eye-slash')
                ->action(function (Collection $records) {
                    $records->each->shareWithClient(false);
                    Notification::make()->title('Ya no se comparte con el cliente')->success()->send();
                })
                ->deselectRecordsAfterCompletion(),
        ];
    }
}

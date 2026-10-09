<?php

namespace App\Services\Notifications;

use App\Models\Building;
use App\Models\Company;
use App\Models\PortalMembership;
use App\Models\User;
use App\Notifications\App\SharedWithClientNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Avisa a los usuarios del portal cuando se les comparte algo.
 *
 * - Un aviso interno por registro (no se repite si se deja de compartir y se
 *   vuelve a compartir).
 * - Un solo correo por persona y por acción, con lo nuevo (compartir 10
 *   documentos juntos no manda 10 correos).
 * - Solo a quien tiene ese edificio autorizado en ese momento (Notifier).
 */
class ClientShareNotifier
{
    public function __construct(private Notifier $notifier) {}

    /** @param iterable<Model> $records registros recién compartidos */
    public function shared(iterable $records): void
    {
        /** @var array<int, array{user: User, rows: list<string>, items: list<string>}> $digest */
        $digest = [];

        foreach ($records as $record) {
            $company = Company::find($record->company_id);
            $notification = $company ? SharedWithClientNotification::for($record, $company->name) : null;

            if (! $notification) {
                continue;
            }

            foreach ($this->recipients($notification->buildingId(), $company->id) as $user) {
                if ($row = $this->notifier->sendTo($user, $notification, $company->id, mail: false)) {
                    $digest[$user->id]['user'] = $user;
                    $digest[$user->id]['company'] = $company->name;
                    $digest[$user->id]['rows'][] = $row->id;
                    $digest[$user->id]['items'][] = $notification->buildingName.': '.$notification->what;
                }
            }
        }

        foreach ($digest as $entry) {
            $this->notifier->mail($entry['user'], $entry['rows'], $this->digestMail($entry['company'], $entry['items']));
        }
    }

    /** Usuarios del portal del cliente del edificio que lo tienen autorizado. */
    private function recipients(int $buildingId, int $companyId)
    {
        $clientId = Building::withoutGlobalScopes()->where('company_id', $companyId)->whereKey($buildingId)->value('client_id');

        // Personas con acceso activo a ESE cliente de ESA empresa (pueden ser de
        // cuentas creadas por otra empresa) y el edificio autorizado.
        return User::withoutGlobalScopes()->where('role', User::ROLE_CLIENT)->whereNull('deleted_at')
            ->whereIn('id', PortalMembership::where('company_id', $companyId)->where('client_id', $clientId)->active()->select('user_id'))
            ->whereIn('id', fn ($q) => $q->select('user_id')->from('client_portal_buildings')->where('building_id', $buildingId))
            ->get();
    }

    private function digestMail(string $companyName, array $items): MailMessage
    {
        $message = (new MailMessage)
            ->subject($companyName.' compartió información de tus edificios - Ascento')
            ->greeting('Hola')
            ->line($companyName.' compartió en el portal:');

        foreach (array_slice($items, 0, 10) as $item) {
            $message->line('• '.$item);
        }

        if (count($items) > 10) {
            $message->line('y '.(count($items) - 10).' más.');
        }

        return $message
            ->action('Ver en el portal', route('portal.home'))
            ->line('Para entrar usá tu email y tu contraseña del portal.')
            ->salutation('Ascento');
    }
}

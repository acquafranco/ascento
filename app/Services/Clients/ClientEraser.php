<?php

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\PortalMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Eliminar un cliente para siempre (no es «desactivar»).
 *
 * - Solo si no tiene historial: edificios (también desactivados), servicios,
 *   cuenta corriente, presupuestos, reclamos ni pedidos de ayuda. Ese
 *   historial es de la empresa; para sacarlo de los listados se desactiva.
 * - Se lleva el acceso al portal de este cliente. Una persona que solo
 *   entraba por este cliente se queda sin cuenta del portal; si también tiene
 *   acceso por otro cliente (de esta u otra empresa), conserva su cuenta y
 *   esos otros accesos sin cambios.
 * - Las notas del historial de contactos (CRM) se borran con el cliente.
 */
class ClientEraser
{
    /** @return list<string> qué impide eliminarlo (vacío = se puede) */
    public static function blockers(Client $client): array
    {
        $count = fn (string $table) => DB::table($table)->where('client_id', $client->id)->count();

        return collect([
            'edificio' => [$count('buildings'), 'edificios'],
            'servicio contratado' => [$count('maintenance_services'), 'servicios contratados'],
            'movimiento de cuenta corriente' => [$count('receivables'), 'movimientos de cuenta corriente'],
            'presupuesto' => [$count('quotes'), 'presupuestos'],
            'reclamo' => [$count('client_claims'), 'reclamos'],
            'pedido de ayuda' => [$count('emergency_requests'), 'pedidos de ayuda'],
        ])->filter(fn ($row) => $row[0] > 0)
            ->map(fn ($row, $singular) => $row[0].' '.($row[0] === 1 ? $singular : $row[1]))
            ->values()->all();
    }

    public static function blockedMessage(array $blockers): string
    {
        return 'No se puede eliminar porque tiene '.self::humanList($blockers).'. Ese historial se conserva: desactivalo para que deje de aparecer y nadie pueda entrar a su portal.';
    }

    /** Cuántas personas tienen (o tuvieron) acceso al portal de este cliente. */
    public static function portalPeople(Client $client): int
    {
        return PortalMembership::where('client_id', $client->id)->count();
    }

    /**
     * @return int accesos al portal eliminados
     *
     * @throws ValidationException si tiene historial
     */
    public function erase(Client $client): int
    {
        return DB::transaction(function () use ($client) {
            // Bloqueo: nadie le agrega un edificio o un acceso mientras se elimina.
            $client = Client::withoutGlobalScopes()->withTrashed()->lockForUpdate()->findOrFail($client->id);

            if ($blockers = self::blockers($client)) {
                throw ValidationException::withMessages(['client' => self::blockedMessage($blockers)]);
            }

            $memberships = PortalMembership::where('client_id', $client->id)->lockForUpdate()->get();

            foreach ($memberships as $membership) {
                $user = User::withTrashed()->find($membership->user_id);
                $membership->delete();

                if (! $user || ! $user->isClientUser()) {
                    continue;
                }

                $other = PortalMembership::where('user_id', $user->id)
                    ->orderByRaw('case when company_id = ? then 0 else 1 end', [$client->company_id])
                    ->orderByRaw('case when deactivated_at is null then 0 else 1 end')->orderBy('id')->first();

                if ($other === null) {
                    // Solo entraba por este cliente: sin cuenta del portal. Queda
                    // desactivada (no borrada): si lo vuelven a invitar, se reactiva.
                    $user->forceFill(['client_id' => null])->save();
                    $user->trashed() || $user->delete();
                } elseif ((int) $user->client_id === (int) $client->id) {
                    // Su cliente «de origen» era este: pasa a otro de sus accesos de
                    // esta empresa. Si los demás son de otra empresa, la cuenta no se
                    // muda (eso solo lo hace el SuperAdmin): sigue entrando por esos
                    // accesos, que son los que deciden qué ve (PortalAccess).
                    $user->forceFill(['client_id' => (int) $other->company_id === (int) $client->company_id ? $other->client_id : null])->save();
                }
            }

            DB::table('client_interactions')->where('client_id', $client->id)->delete();
            $client->forceDelete();

            return $memberships->count();
        });
    }

    private static function humanList(array $items): string
    {
        return count($items) > 1
            ? implode(', ', array_slice($items, 0, -1)).' y '.end($items)
            : (string) ($items[0] ?? '');
    }
}

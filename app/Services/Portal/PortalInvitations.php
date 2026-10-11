<?php

namespace App\Services\Portal;

use App\Models\Building;
use App\Models\Client;
use App\Models\Company;
use App\Models\PortalMembership;
use App\Models\User;
use App\Notifications\App\PortalAccountActivatedNotification;
use App\Notifications\PortalAccessGrantedNotification;
use App\Notifications\PortalInvitationNotification;
use App\Services\Notifications\Notifier;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Accesos e invitaciones al portal del cliente.
 *
 * Una persona = una cuenta (el email es único en Ascento). Cada empresa que
 * le da acceso crea una membresía (PortalMembership) para uno de sus clientes:
 *
 * - Email nuevo: se crea la cuenta sin contraseña utilizable y se manda un
 *   enlace (token de un solo uso, 72 h, broker "portal_invitations") para que
 *   elija su contraseña. Nunca se manda una contraseña por correo.
 * - Email de una persona que ya usa el portal (de otra empresa o de otro
 *   cliente): se suma el acceso y se le avisa que ingrese con su cuenta. No se
 *   le revela nada de las otras empresas a quien invita.
 * - Email de una cuenta del personal (admin / técnico): no se puede usar para
 *   el portal (mensaje genérico).
 */
class PortalInvitations
{
    public const BROKER = 'portal_invitations';

    public const LINK_HOURS = 72;

    public static function broker(): PasswordBroker
    {
        return Password::broker(self::BROKER);
    }

    /**
     * Da acceso a una persona para los edificios indicados de ESTE cliente y
     * le avisa por correo.
     *
     * @param  array<int>  $buildingIds  (se filtran a los edificios del cliente)
     * @return array{membership: PortalMembership, mailed: bool, existing: bool}
     *
     * @throws ValidationException
     */
    /** Motivo por el que este email no se puede invitar a este cliente (null = se puede). */
    public static function emailProblem(Client $client, string $email): ?string
    {
        $existing = User::withTrashed()->whereRaw('lower(email) = ?', [Str::lower(trim($email))])->first();

        return match (true) {
            $existing && ! $existing->isClientUser() => 'Este email no se puede usar para el portal. Usá otra dirección.',
            $existing && PortalMembership::where('user_id', $existing->id)->where('client_id', $client->id)->exists() => 'Esta persona ya tiene acceso al portal de este cliente (si está desactivada, reactivala).',
            default => null,
        };
    }

    public function invite(Client $client, string $name, string $email, array $buildingIds, ?User $actor = null): array
    {
        $email = Str::lower(trim($email));

        if ($problem = static::emailProblem($client, $email)) {
            throw ValidationException::withMessages(['email' => $problem]);
        }

        $existing = User::withTrashed()->whereRaw('lower(email) = ?', [$email])->first();

        [$membership, $isNew] = DB::transaction(function () use ($client, $name, $email, $buildingIds, $actor, $existing) {
            $user = $existing;

            if (! $user) {
                // Sin contraseña utilizable hasta que la elija con el enlace.
                $user = new User(['name' => $name, 'email' => $email, 'password' => Hash::make(Str::random(64))]);
                $user->forceFill(['role' => User::ROLE_CLIENT, 'company_id' => $client->company_id, 'client_id' => $client->id])->save();
            } elseif ($user->trashed()) {
                $user->restore();
            }

            // (La cuenta nueva ya trae su primer acceso; se completa.)
            $membership = PortalMembership::firstOrNew(['user_id' => $user->id, 'client_id' => $client->id]);
            $membership->forceFill(['company_id' => $client->company_id, 'created_by' => $actor?->id, 'deactivated_at' => null])->save();

            static::syncBuildings($membership, $buildingIds);

            return [$membership, $existing === null];
        });

        return ['membership' => $membership, 'mailed' => $this->send($membership), 'existing' => ! $isNew];
    }

    /** Edificios autorizados de ESTE cliente (no toca los de otros clientes o empresas). */
    public static function syncBuildings(PortalMembership $membership, array $buildingIds): void
    {
        $own = Building::withoutGlobalScopes()->where('company_id', $membership->company_id)->where('client_id', $membership->client_id)
            ->whereNull('deleted_at')->pluck('id')->map(fn ($id) => (int) $id);
        $wanted = $own->intersect(array_map('intval', $buildingIds))->values();

        DB::table('client_portal_buildings')->where('user_id', $membership->user_id)
            ->whereIn('building_id', $own->diff($wanted)->all())->delete();

        foreach ($wanted->diff($membership->buildingIds()) as $buildingId) {
            DB::table('client_portal_buildings')->insertOrIgnore([
                'user_id' => $membership->user_id, 'building_id' => $buildingId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /**
     * Manda (o reenvía) el correo de esta membresía: invitación con enlace si
     * la persona todavía no activó su cuenta; aviso de acceso nuevo si ya la
     * usa. Un enlace nuevo invalida el anterior.
     *
     * @return bool true si el proveedor de correo aceptó el envío
     */
    public function send(PortalMembership $membership): bool
    {
        $user = User::withTrashed()->find($membership->user_id);

        if (! $user || ! $user->isClientUser() || $user->trashed() || ! $membership->isActive()) {
            return false;
        }

        $companyName = (string) Company::whereKey($membership->company_id)->value('name');
        $activated = $user->portal_activated_at !== null;

        try {
            $user->notifyNow($activated
                ? new PortalAccessGrantedNotification($companyName)
                : new PortalInvitationNotification(self::broker()->createToken($user), $companyName));
        } catch (Throwable $e) {
            Log::warning('No se pudo enviar el correo del portal', ['user_id' => $user->id, 'membership_id' => $membership->id, 'exception' => $e::class]);

            return false;
        }

        $membership->forceFill(['invited_at' => now(), 'activated_at' => $activated ? ($membership->activated_at ?? now()) : null])->save();

        if (! $user->portal_invited_at) {
            $user->forceFill(['portal_invited_at' => now()])->save();
        }

        return true;
    }

    /** ¿El enlace sigue siendo válido para esta cuenta del portal? */
    public static function validFor(?User $user, string $token): bool
    {
        return $user !== null
            && $user->isClientUser()
            && ! $user->trashed()
            && self::broker()->tokenExists($user, $token);
    }

    /**
     * Primera vez que la persona define su contraseña (invitación o
     * recuperación): la cuenta queda activada, sus accesos invitados también,
     * y se avisa a los admins de cada empresa. Escucha el evento PasswordReset.
     */
    public static function markActivated(User $user): void
    {
        if (! $user->isClientUser()) {
            return;
        }

        if ($user->portal_activated_at === null) {
            $user->forceFill(['portal_activated_at' => now()])->save();
        }

        PortalMembership::where('user_id', $user->id)->active()->whereNull('activated_at')->whereNotNull('invited_at')->get()
            ->each(function (PortalMembership $membership) use ($user) {
                $membership->forceFill(['activated_at' => now()])->save();

                $admins = User::where('company_id', $membership->company_id)->where('role', 'admin')->where('is_super_admin', false)->get();
                app(Notifier::class)->send($admins, new PortalAccountActivatedNotification($user, $membership->client_id), (int) $membership->company_id);
            });
    }

    /** Estado legible de un acceso (tabla del admin). */
    public static function status(PortalMembership $membership): string
    {
        $user = $membership->user;

        return match (true) {
            ! $membership->isActive() || ! $user || $user->trashed() => 'Desactivado',
            $membership->activated_at !== null && $user->portal_activated_at !== null => 'Activo',
            $membership->invited_at === null => 'Invitación sin enviar',
            $user->portal_activated_at === null && $membership->invited_at->lt(now()->subHours(self::LINK_HOURS)) => 'Invitación vencida',
            default => 'Invitación enviada',
        };
    }
}

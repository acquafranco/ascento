<?php

namespace App\Services\Portal;

use App\Models\Company;
use App\Models\User;
use App\Notifications\App\PortalAccountActivatedNotification;
use App\Notifications\PortalInvitationNotification;
use App\Services\Notifications\Notifier;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Throwable;

/**
 * Invitaciones al portal del cliente.
 *
 * El admin crea la cuenta sin contraseña utilizable y Ascento manda un
 * enlace para que el cliente elija la suya. El enlace usa el mecanismo de
 * Laravel para restablecer contraseñas: token aleatorio, guardado con hash,
 * de un solo uso y que vence (72 h, broker "portal_invitations"). Nunca se
 * manda una contraseña por correo.
 */
class PortalInvitations
{
    public const BROKER = 'portal_invitations';

    public static function broker(): PasswordBroker
    {
        return Password::broker(self::BROKER);
    }

    /**
     * Manda (o reenvía) la invitación. Un token nuevo invalida el anterior.
     *
     * @return bool true si el proveedor de correo aceptó el envío
     */
    public function send(User $user): bool
    {
        if (! $user->isClientUser() || $user->trashed()) {
            return false;
        }

        $token = self::broker()->createToken($user);
        $company = Company::find($user->company_id);

        try {
            $user->notifyNow(new PortalInvitationNotification($token, (string) $company?->name));
        } catch (Throwable $e) {
            Log::warning('No se pudo enviar la invitación al portal', ['user_id' => $user->id, 'exception' => $e::class]);

            return false;
        }

        $user->forceFill(['portal_invited_at' => now()])->save();

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
     * Primera vez que un usuario del portal define su contraseña (por la
     * invitación o por "olvidé mi contraseña"): queda activado y se avisa a
     * los admins de su empresa. Escucha el evento PasswordReset.
     */
    public static function markActivated(User $user): void
    {
        if (! $user->isClientUser() || $user->portal_activated_at !== null) {
            return;
        }

        $user->forceFill(['portal_activated_at' => now()])->save();

        $admins = User::where('company_id', $user->company_id)->where('role', 'admin')->where('is_super_admin', false)->get();
        app(Notifier::class)->send($admins, new PortalAccountActivatedNotification($user), (int) $user->company_id);
    }
}

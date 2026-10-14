<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Auth\TwoFactorAuthentication;
use Illuminate\Console\Command;

/**
 * Último recurso (Etapa 11): un SuperAdmin perdió el teléfono y los códigos
 * de recuperación y no hay otro SuperAdmin que lo restablezca. Requiere
 * acceso SSH al servidor. Quita el 2FA de esa cuenta y cierra todas sus
 * sesiones; al volver a entrar se le pide configurarlo de nuevo.
 *
 *   php artisan security:reset-2fa persona@ejemplo.com
 */
class ResetTwoFactor extends Command
{
    protected $signature = 'security:reset-2fa {email : Correo exacto de la cuenta de SuperAdmin} {--force : No pedir confirmación (para scripts)}';

    protected $description = 'Restablece el segundo factor de una cuenta de SuperAdmin y cierra sus sesiones';

    public function handle(TwoFactorAuthentication $twoFactor): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('El correo no tiene un formato válido.');

            return self::INVALID;
        }

        $matches = User::query()->whereRaw('LOWER(email) = ?', [$email])->get();
        if ($matches->count() !== 1) {
            $this->error('No hay exactamente una cuenta activa con ese correo. No se cambió nada.');

            return self::FAILURE;
        }

        $user = $matches->first();
        if (! $user->isSuperAdmin()) {
            $this->error('Esa cuenta no es de SuperAdmin. Este comando es solo para SuperAdmin. No se cambió nada.');

            return self::FAILURE;
        }

        // Sin descifrar nada: funciona aunque el secreto no se pueda leer (otra APP_KEY).
        $had = $user->hasConfirmedTwoFactor() || filled($user->getAttributes()['two_factor_secret'] ?? null);
        if (! $this->option('force') && ! $this->confirm("¿Quitar el segundo factor de {$user->name} (id {$user->id}) y cerrar todas sus sesiones?")) {
            $this->line('Cancelado. No se cambió nada.');

            return self::FAILURE;
        }

        $twoFactor->reset($user, null, 'console');

        $this->info($had
            ? 'Listo: se quitó el segundo factor y se cerraron sus sesiones. Al entrar, se le va a pedir configurarlo de nuevo.'
            : 'La cuenta no tenía segundo factor activo; igual se cerraron sus sesiones. Al entrar, se le va a pedir configurarlo.');

        return self::SUCCESS;
    }
}

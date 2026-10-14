<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Notifications\ConfirmEmailChangeNotification;
use App\Notifications\EmailChangeRequestedNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Cambio del correo propio con confirmación (Etapa 11).
 *
 * - El correo nuevo queda en pending_email. `email` no cambia: se sigue
 *   ingresando y recuperando la contraseña con el anterior.
 * - Se manda un enlace al correo nuevo (vence a los EXPIRES_MINUTES, un solo
 *   uso; en la base queda solo el hash del token) y un aviso al actual.
 * - Un pedido nuevo reemplaza al anterior (el enlace viejo deja de servir).
 * - Al confirmar se vuelve a comprobar, con la fila bloqueada, que nadie
 *   más use ese correo. Un pendiente no reserva el correo ni impide nada a
 *   su verdadero dueño.
 *
 * Lo usa quien cambia SU correo (perfil del técnico, el dueño en el panel
 * editándose a sí mismo). Lo que un dueño cambia en la cuenta de un técnico
 * de su empresa sigue como antes (ver User::updating).
 */
class PendingEmailChange
{
    public const EXPIRES_MINUTES = 60;

    public const MAX_REQUESTS = 3;

    public const WINDOW_MINUTES = 10;

    public const CONFIRMED = 'confirmed';

    public const INVALID = 'invalid';

    public const TAKEN = 'taken';

    /** Pide el cambio. Mismo correo que el actual: cancela lo pendiente. */
    public function request(User $user, string $email): void
    {
        $email = mb_strtolower(trim($email));

        if ($email === mb_strtolower((string) $user->email)) {
            $this->cancel($user);

            return;
        }

        if (static::emailTaken($email, $user->id)) {
            throw ValidationException::withMessages(['email' => 'Ese correo ya lo usa otra cuenta.']);
        }

        $key = 'email-change|'.$user->id;
        if (RateLimiter::tooManyAttempts($key, self::MAX_REQUESTS)) {
            throw ValidationException::withMessages(['email' => 'Pediste varios cambios de correo seguidos. Probá de nuevo en '.ceil(RateLimiter::availableIn($key) / 60).' minutos.']);
        }
        RateLimiter::hit($key, self::WINDOW_MINUTES * 60);

        $token = Str::random(64);
        $user->forceFill([
            'pending_email' => $email,
            'pending_email_token' => hash('sha256', $token),
            'pending_email_expires_at' => now()->addMinutes(self::EXPIRES_MINUTES),
        ])->saveQuietly();

        $url = route('email-change.confirm', ['user' => $user->id, 'token' => $token]);

        // Sin el enlace no hay forma de confirmar: si no sale, el pedido se
        // deshace y la persona lo ve (antes quedaba un pendiente imposible de
        // confirmar con un mensaje de «te mandamos un enlace»).
        try {
            Notification::route('mail', $email)->notify(new ConfirmEmailChangeNotification($url, $user->name));
        } catch (Throwable $e) {
            $this->cancel($user);
            Log::warning('No se pudo mandar el enlace de confirmación del correo nuevo', ['user_id' => $user->id, 'exception' => $e::class]);

            throw ValidationException::withMessages(['email' => 'No pudimos mandar el correo de confirmación a esa dirección. Tu correo no cambió: probá de nuevo en unos minutos.']);
        }

        // El aviso al correo actual es informativo: si falla, el pedido sigue.
        try {
            $user->notify(new EmailChangeRequestedNotification($email));
        } catch (Throwable $e) {
            Log::warning('No se pudo avisar al correo actual del pedido de cambio', ['user_id' => $user->id, 'exception' => $e::class]);
        }
    }

    public function cancel(User $user): void
    {
        if ($user->pending_email !== null || $user->pending_email_token !== null) {
            $user->forceFill(['pending_email' => null, 'pending_email_token' => null, 'pending_email_expires_at' => null])->saveQuietly();
        }
    }

    /** Confirma desde el enlace. Devuelve CONFIRMED, INVALID o TAKEN. */
    public function confirm(int $userId, string $token): string
    {
        return DB::transaction(function () use ($userId, $token) {
            $user = User::query()->lockForUpdate()->find($userId);

            if (! $user || ! $user->pending_email || ! $user->pending_email_token
                || ! hash_equals($user->pending_email_token, hash('sha256', $token))) {
                return self::INVALID;
            }

            if (! $user->pending_email_expires_at || $user->pending_email_expires_at->isPast()) {
                $this->cancel($user);

                return self::INVALID;
            }

            $new = $user->pending_email;
            $old = $user->email;

            if (static::emailTaken($new, $user->id)) {
                $this->cancel($user);

                return self::TAKEN;
            }

            try {
                // email y email_verified_at juntos: el enlace llegó a esa dirección (User::updating
                // no lo vuelve a marcar sin confirmar ni manda otro enlace).
                $user->forceFill([
                    'email' => $new,
                    'email_verified_at' => now(),
                    'pending_email' => null,
                    'pending_email_token' => null,
                    'pending_email_expires_at' => null,
                ])->save();
            } catch (UniqueConstraintViolationException) {
                return self::TAKEN;
            }

            // Enlaces de recuperación pedidos para el correo anterior: ya no aplican.
            DB::table('password_reset_tokens')->where('email', $old)->delete();

            return self::CONFIRMED;
        });
    }

    public static function emailTaken(string $email, int $exceptUserId): bool
    {
        return User::withTrashed()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])
            ->whereKeyNot($exceptUserId)
            ->exists();
    }
}

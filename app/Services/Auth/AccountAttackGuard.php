<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Notifications\LoginAttemptsNotification;
use App\Support\Auth\RegistrationChallenge;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Etapa 11 (R6): intentos repetidos contra UNA cuenta, aunque vengan de
 * muchas IP. Nunca bloquea la cuenta (si no, cualquiera que sepa un correo
 * podría dejar afuera a su dueño):
 *
 * - Cuenta los fallos por correo, exista o no la cuenta (las respuestas son
 *   iguales: no sirve para saber qué correos están registrados).
 * - Desde ACCOUNT_THRESHOLD fallos en la ventana, para ese correo se pide
 *   además el desafío humano que ya usa el registro. La persona real lo
 *   resuelve y entra; un programa no puede seguir probando a ciegas.
 * - Avisa al dueño de la cuenta (si existe) como mucho una vez cada
 *   ALERT_HOURS, y al SuperAdmin si hay un pico general, una vez por hora.
 * - Un ingreso correcto pone el contador en cero.
 *
 * Se suma a los límites que ya existían: 5 fallos por correo + IP y 30 por IP.
 */
class AccountAttackGuard
{
    public const ACCOUNT_THRESHOLD = 10;

    public const ACCOUNT_WINDOW_SECONDS = 900;

    public const ALERT_HOURS = 6;

    public const SPIKE_THRESHOLD = 200;

    public const SESSION_FLAG = 'login_challenge_required';

    /** Datos del desafío para la vista de ingreso (panel y portal), o null si no hace falta. */
    public static function challengeForView(Request $request): ?array
    {
        // Hasta que vence la ventana o hay un ingreso correcto (recargar la página no lo saca).
        return (int) $request->session()->get(self::SESSION_FLAG, 0) > now()->getTimestamp() || $request->session()->get('errors')?->has('challenge')
            ? RegistrationChallenge::prepare($request)
            : null;
    }

    public static function key(string $email): string
    {
        return 'login-account|'.hash('sha256', mb_strtolower(trim($email)));
    }

    public function underAttack(string $email): bool
    {
        return RateLimiter::attempts(static::key($email)) >= self::ACCOUNT_THRESHOLD;
    }

    /** Mensaje de error si hace falta el desafío y no se resolvió; null si puede seguir. */
    public function challengeError(Request $request, string $email): ?string
    {
        if (! $this->underAttack($email)) {
            return null;
        }

        $this->requireChallenge($request);

        // Un desafío por intento: en el registro lo resuelto vale 15 minutos, acá
        // eso dejaría probar contraseñas sin límite con la misma sesión (que se
        // puede usar desde muchas IP).
        RegistrationChallenge::forgetPassed($request);
        $error = RegistrationChallenge::check($request);
        RegistrationChallenge::forgetPassed($request);

        return $error;
    }

    public function recordFailure(Request $request, string $email): void
    {
        RateLimiter::hit(static::key($email), self::ACCOUNT_WINDOW_SECONDS);

        if ($this->underAttack($email)) {
            $this->requireChallenge($request);
            $this->alertOwner($email);
        }

        $this->countForSpike();
    }

    public function recordSuccess(Request $request, string $email): void
    {
        RateLimiter::clear(static::key($email));
        $request->session()->forget(self::SESSION_FLAG);
    }

    private function requireChallenge(Request $request): void
    {
        $request->session()->put(self::SESSION_FLAG, now()->addSeconds(self::ACCOUNT_WINDOW_SECONDS)->getTimestamp());
    }

    private function alertOwner(string $email): void
    {
        $user = User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])->first();
        if (! $user || ! Cache::add('login-account-alert|'.$user->id, true, self::ALERT_HOURS * 3600)) {
            return;
        }

        Log::notice('Seguridad: login.repeated_failures', ['user_id' => $user->id]);
        $this->send($user, LoginAttemptsNotification::ACCOUNT);
    }

    private function countForSpike(): void
    {
        $key = 'login-failures-hour|'.now()->format('YmdH');
        Cache::add($key, 0, 3600);
        $count = (int) Cache::increment($key);

        if ($count >= self::SPIKE_THRESHOLD && Cache::add('login-spike-alert|'.now()->format('YmdH'), true, 3600)) {
            Log::warning('Seguridad: login.failure_spike', ['failures_this_hour' => $count]);
            User::query()->where('is_super_admin', true)->get()
                ->each(fn (User $admin) => $this->send($admin, LoginAttemptsNotification::SPIKE, ['count' => $count]));
        }
    }

    private function send(User $user, string $type, array $data = []): void
    {
        try {
            $user->notify(new LoginAttemptsNotification($type, $data));
        } catch (Throwable $e) {
            Log::warning('No se pudo mandar el aviso de intentos de ingreso', ['user_id' => $user->id, 'exception' => $e::class]);
        }
    }
}

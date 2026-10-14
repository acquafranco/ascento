<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Notifications\TwoFactorSecurityNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use PragmaRX\Google2FAQRCode\Google2FA;
use SensitiveParameter;
use Throwable;

/**
 * Segundo factor (TOTP, 6 dígitos) del SuperAdmin (Etapa 11).
 *
 * - El secreto se guarda cifrado (cast «encrypted») y solo se muestra
 *   mientras se configura; recién con un código válido queda activo.
 * - 8 códigos de recuperación de un solo uso, guardados con hash dentro de
 *   un campo cifrado; se muestran una sola vez.
 * - Un código TOTP no sirve dos veces (último paso aceptado).
 * - La sesión queda marcada con una huella atada a esta configuración: si
 *   el 2FA se desactiva o se vuelve a configurar, las sesiones viejas dejan
 *   de valer aunque el servidor no pueda borrarlas.
 * - Nunca se registran secretos ni códigos: el log lleva solo ids y la acción.
 */
class TwoFactorAuthentication
{
    public const RECOVERY_CODES = 8;

    public const SETUP_MINUTES = 15;

    /** Fallos en un mismo desafío: hay que volver a poner la contraseña. */
    public const MAX_CHALLENGE_FAILURES = 5;

    /** Fallos por cuenta en la ventana: hay que esperar (nunca bloqueo permanente). */
    public const MAX_ACCOUNT_FAILURES = 10;

    public const ACCOUNT_WINDOW_SECONDS = 900;

    public const SESSION_PASSED = 'two_factor.passed';

    private const SESSION_SETUP = 'two_factor.setup_started_at';

    public const SESSION_FAILURES = 'two_factor.failures';

    public static function enforced(): bool
    {
        return (bool) config('app.superadmin_two_factor', true);
    }

    public function engine(): Google2FA
    {
        return new Google2FA;
    }

    /* ------------------------------------------------------------ ALTA */

    /** Genera un secreto nuevo (pendiente de confirmar). */
    public function beginSetup(User $user): void
    {
        abort_if($user->hasConfirmedTwoFactor(), 409);

        $user->forceFill([
            'two_factor_secret' => $this->engine()->generateSecretKey(32),
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_timestep' => null,
        ])->saveQuietly();

        session()->put(self::SESSION_SETUP, now()->getTimestamp());
    }

    /** Hay un secreto pendiente y no venció. */
    public function setupInProgress(User $user): bool
    {
        $started = (int) session(self::SESSION_SETUP, 0);

        return ! $user->hasConfirmedTwoFactor()
            && filled($user->getAttributes()['two_factor_secret'] ?? null)
            && $started > 0 && $started > now()->subMinutes(self::SETUP_MINUTES)->getTimestamp();
    }

    public function qrCode(User $user): string
    {
        return $this->engine()->getQRCodeInline('Ascento', $user->email, (string) $user->two_factor_secret);
    }

    /**
     * Confirma con un código de la app. Devuelve los códigos de recuperación
     * en claro (solo esta vez) o null si el código no sirve.
     *
     * @return list<string>|null
     */
    public function confirmSetup(User $user, #[SensitiveParameter] string $code): ?array
    {
        if (! $this->setupInProgress($user)) {
            return null;
        }

        $timestep = $this->checkTotp($user, $code);
        if ($timestep === null) {
            return null;
        }

        $codes = $this->newRecoveryCodes();
        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_last_timestep' => $timestep,
            'two_factor_recovery_codes' => array_map(fn (string $c) => Hash::make($c), $codes),
        ])->saveQuietly();

        session()->forget(self::SESSION_SETUP);
        $this->revokeOtherSessions($user);
        $this->markPassed($user);
        $this->audit('2fa.activated', $user);
        $this->notify($user, TwoFactorSecurityNotification::ACTIVATED);

        return $codes;
    }

    /* ------------------------------------------------------- DESAFÍO */

    /**
     * Verifica un código TOTP o de recuperación. Devuelve 'totp',
     * 'recovery' o null. No dice cuál falló.
     */
    public function verify(User $user, #[SensitiveParameter] string $input): ?string
    {
        if (! $user->hasConfirmedTwoFactor()) {
            return null;
        }

        $input = trim($input);

        if (preg_match('/^\d{6}$/', str_replace(' ', '', $input))) {
            $timestep = $this->checkTotp($user, str_replace(' ', '', $input));
            if ($timestep !== null) {
                $user->forceFill(['two_factor_last_timestep' => $timestep])->saveQuietly();

                return 'totp';
            }

            return null;
        }

        $normalized = strtoupper(str_replace([' ', '-'], '', $input));
        if (! preg_match('/^[A-Z0-9]{10}$/', $normalized)) {
            return null;
        }

        // Con la fila bloqueada: el mismo código no se puede usar dos veces en paralelo.
        return DB::transaction(function () use ($user, $normalized) {
            $locked = User::query()->lockForUpdate()->find($user->id);
            $hashes = (array) ($locked?->two_factor_recovery_codes ?? []);

            foreach ($hashes as $i => $hash) {
                if (Hash::check($normalized, (string) $hash)) {
                    unset($hashes[$i]);
                    $locked->forceFill(['two_factor_recovery_codes' => array_values($hashes)])->saveQuietly();
                    $user->setRawAttributes($locked->getAttributes(), true);
                    $this->audit('2fa.recovery_code_used', $user, ['remaining' => count($hashes)]);
                    $this->notify($user, TwoFactorSecurityNotification::RECOVERY_USED, ['remaining' => count($hashes)]);

                    return 'recovery';
                }
            }

            return null;
        });
    }

    private function checkTotp(User $user, #[SensitiveParameter] string $code): ?int
    {
        if (! preg_match('/^\d{6}$/', $code)) {
            return null;
        }

        try {
            if (blank($user->two_factor_secret)) {
                return null;
            }
            // Paso de 30 s según el reloj de la aplicación (el mismo que el del servidor;
            // permite probarlo). Ventana: ±1 paso, para desfases chicos del teléfono.
            $timestep = $this->engine()->verifyKeyNewer((string) $user->two_factor_secret, $code, (int) ($user->two_factor_last_timestep ?? 0), null, self::currentTimestep());
        } catch (Throwable) {
            return null; // secreto ilegible (p. ej. APP_KEY distinta): ver el comando de recuperación
        }

        return is_int($timestep) ? $timestep : null;
    }

    public static function currentTimestep(): int
    {
        return intdiv(now()->getTimestamp(), 30);
    }

    /** Fallo en el desafío: cuenta por sesión y por cuenta; avisa una vez por hora. */
    public function recordFailure(User $user): array
    {
        $failures = (int) session(self::SESSION_FAILURES, 0) + 1;
        session()->put(self::SESSION_FAILURES, $failures);

        $key = 'two-factor-failures|'.$user->id;
        RateLimiter::hit($key, self::ACCOUNT_WINDOW_SECONDS);

        if (RateLimiter::attempts($key) >= self::MAX_CHALLENGE_FAILURES
            && Cache::add('two-factor-alert|'.$user->id, true, 3600)) {
            $this->audit('2fa.repeated_failures', $user);
            $this->notify($user, TwoFactorSecurityNotification::FAILED_CODES);
        }

        return [
            'challenge_exhausted' => $failures >= self::MAX_CHALLENGE_FAILURES,
            'account_wait' => RateLimiter::tooManyAttempts($key, self::MAX_ACCOUNT_FAILURES) ? RateLimiter::availableIn($key) : 0,
        ];
    }

    public function accountWait(User $user): int
    {
        $key = 'two-factor-failures|'.$user->id;

        return RateLimiter::tooManyAttempts($key, self::MAX_ACCOUNT_FAILURES) ? RateLimiter::availableIn($key) : 0;
    }

    /* ------------------------------------------------------- SESIÓN */

    public function markPassed(User $user): void
    {
        session()->regenerate(true); // id nuevo; el anterior se destruye
        session()->put(self::SESSION_PASSED, $this->fingerprint($user));
        session()->forget(self::SESSION_FAILURES);
    }

    public function passed(User $user): bool
    {
        $marker = session(self::SESSION_PASSED);

        return is_string($marker) && $user->hasConfirmedTwoFactor() && hash_equals($this->fingerprint($user), $marker);
    }

    /** Atada al usuario y a ESTA configuración del 2FA. */
    public function fingerprint(User $user): string
    {
        return hash_hmac('sha256', $user->id.'|'.$user->two_factor_confirmed_at?->getTimestamp(), (string) config('app.key'));
    }

    /* --------------------------------------------- GESTIÓN Y RESCATE */

    /** @return list<string> */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->newRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => array_map(fn (string $c) => Hash::make($c), $codes)])->saveQuietly();
        $this->audit('2fa.recovery_codes_regenerated', $user);
        $this->notify($user, TwoFactorSecurityNotification::CODES_REGENERATED);

        return $codes;
    }

    /**
     * Quita el 2FA de la cuenta y cierra todas sus sesiones. Con el 2FA
     * obligatorio, al volver a entrar se le pide configurarlo otra vez.
     * $actor: quien lo hace (la propia cuenta, otro SuperAdmin) o null (consola).
     */
    public function reset(User $target, ?User $actor, string $via): void
    {
        $target->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_timestep' => null,
        ])->saveQuietly();

        $this->revokeAllSessions($target);
        $this->audit('2fa.reset', $target, ['via' => $via, 'actor_id' => $actor?->id]);
        $this->notify($target, TwoFactorSecurityNotification::RESET, ['via' => $via]);
    }

    /** Cierra las sesiones de la cuenta (y «Recordarme»), salvo la actual. */
    public function revokeOtherSessions(User $user): void
    {
        $this->revokeAllSessions($user, session()->getId());
    }

    public function revokeAllSessions(User $user, ?string $except = null): void
    {
        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();

        if (config('session.driver') === 'database') {
            DB::table((string) config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->when($except, fn ($q) => $q->where('id', '!=', $except))
                ->delete();
        }
        // Con otros drivers las sesiones no se pueden listar: igual quedan sin
        // valor porque su huella (fingerprint) ya no coincide con la configuración.
    }

    /** @return list<string> */
    private function newRecoveryCodes(): array
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        return array_map(function () use ($alphabet) {
            $code = '';
            for ($i = 0; $i < 10; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            return $code;
        }, range(1, self::RECOVERY_CODES));
    }

    public static function format(string $code): string
    {
        return substr($code, 0, 5).'-'.substr($code, 5);
    }

    private function audit(string $event, User $user, array $context = []): void
    {
        Log::notice('Seguridad: '.$event, ['user_id' => $user->id, 'ip' => request()?->ip()] + $context);
    }

    private function notify(User $user, string $event, array $data = []): void
    {
        try {
            $user->notify(new TwoFactorSecurityNotification($event, $data));
        } catch (Throwable $e) {
            Log::warning('No se pudo mandar el aviso de seguridad', ['user_id' => $user->id, 'exception' => $e::class]);
        }
    }
}

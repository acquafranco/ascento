<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\TwoFactorAuthentication;
use App\Support\HomeRedirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Segundo factor del SuperAdmin (Etapa 11): configurar, verificar en cada
 * sesión y gestionar (códigos nuevos, volver a configurar, restablecer el de
 * otro SuperAdmin). Toda acción sensible pide la contraseña y un código.
 */
class TwoFactorController extends Controller
{
    public function __construct(private TwoFactorAuthentication $twoFactor) {}

    private function superAdmin(Request $request): User
    {
        $user = $request->user();
        abort_unless($user && $user->requiresTwoFactor(), 404);

        return $user;
    }

    /* ----------------------------------------------------------- ALTA */

    public function setup(Request $request): View|RedirectResponse
    {
        $user = $this->superAdmin($request);
        if ($user->hasConfirmedTwoFactor()) {
            return redirect()->route($this->twoFactor->passed($user) ? 'two-factor.manage' : 'two-factor.challenge');
        }

        $inProgress = $this->twoFactor->setupInProgress($user);

        return view('auth.two-factor.setup', [
            'inProgress' => $inProgress,
            'qr' => $inProgress ? $this->twoFactor->qrCode($user) : null,
            'secret' => $inProgress ? trim(chunk_split((string) $user->two_factor_secret, 4, ' ')) : null,
        ]);
    }

    public function start(Request $request): RedirectResponse
    {
        $user = $this->superAdmin($request);
        abort_if($user->hasConfirmedTwoFactor(), 409);
        $this->checkPassword($request, $user);

        $this->twoFactor->beginSetup($user);

        return redirect()->route('two-factor.setup');
    }

    public function confirm(Request $request): View|RedirectResponse
    {
        $user = $this->superAdmin($request);
        abort_if($user->hasConfirmedTwoFactor(), 409);
        $request->validate(['code' => ['required', 'string', 'max:20']]);

        if (! $this->twoFactor->setupInProgress($user)) {
            return redirect()->route('two-factor.setup')->withErrors(['code' => 'La configuración venció. Empezá de nuevo.']);
        }

        $this->throttle($request, 'two-factor-setup|'.$user->id, 'code');
        $codes = $this->twoFactor->confirmSetup($user, (string) $request->input('code'));
        if ($codes === null) {
            throw ValidationException::withMessages(['code' => 'El código no es válido. Revisá la hora del teléfono y probá con el código nuevo.']);
        }

        return view('auth.two-factor.recovery-codes', ['codes' => array_map([TwoFactorAuthentication::class, 'format'], $codes), 'first' => true]);
    }

    /* -------------------------------------------------------- DESAFÍO */

    public function challenge(Request $request): View|RedirectResponse
    {
        $user = $this->superAdmin($request);
        if (! $user->hasConfirmedTwoFactor()) {
            return redirect()->route('two-factor.setup');
        }
        if ($this->twoFactor->passed($user)) {
            return redirect()->to(HomeRedirect::url());
        }

        return view('auth.two-factor.challenge', ['wait' => $this->twoFactor->accountWait($user)]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $user = $this->superAdmin($request);
        if (! $user->hasConfirmedTwoFactor()) {
            return redirect()->route('two-factor.setup');
        }
        $request->validate(['code' => ['required', 'string', 'max:20']]);

        if (($wait = $this->twoFactor->accountWait($user)) > 0) {
            throw ValidationException::withMessages(['code' => 'Demasiados códigos incorrectos. Esperá '.ceil($wait / 60).' minutos.']);
        }

        if ($this->twoFactor->verify($user, (string) $request->input('code')) !== null) {
            $this->twoFactor->markPassed($user);

            return redirect()->intended(HomeRedirect::url());
        }

        $result = $this->twoFactor->recordFailure($user);
        if ($result['challenge_exhausted']) {
            // Nunca un bloqueo permanente: vuelve a poner la contraseña.
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'Demasiados códigos incorrectos. Volvé a ingresar.']);
        }

        throw ValidationException::withMessages(['code' => 'El código no es válido.']);
    }

    /* -------------------------------------------------------- GESTIÓN */

    private function passedSuperAdmin(Request $request): User
    {
        $user = $this->superAdmin($request);
        abort_unless($user->hasConfirmedTwoFactor() && $this->twoFactor->passed($user), 403);

        return $user;
    }

    public function manage(Request $request): View
    {
        $user = $this->passedSuperAdmin($request);

        return view('auth.two-factor.manage', [
            'remaining' => count((array) ($user->two_factor_recovery_codes ?? [])),
            'confirmedAt' => $user->two_factor_confirmed_at,
        ]);
    }

    public function regenerate(Request $request): View
    {
        $user = $this->passedSuperAdmin($request);
        $this->checkPasswordAndCode($request, $user);

        $codes = $this->twoFactor->regenerateRecoveryCodes($user);

        return view('auth.two-factor.recovery-codes', ['codes' => array_map([TwoFactorAuthentication::class, 'format'], $codes), 'first' => false]);
    }

    /** Volver a configurar (teléfono nuevo): quita el 2FA y pide configurarlo de nuevo. */
    public function reconfigure(Request $request): RedirectResponse
    {
        $user = $this->passedSuperAdmin($request);
        $this->checkPasswordAndCode($request, $user);

        $this->twoFactor->reset($user, $user, 'self');

        // reset() cierra todas las sesiones de la cuenta; esta sigue para configurar enseguida.
        return redirect()->route('two-factor.setup')->with('status', 'Configurá el segundo factor con el teléfono nuevo.');
    }

    /** Otro SuperAdmin perdió su teléfono y sus códigos: se le restablece el 2FA. */
    public function resetOther(Request $request): RedirectResponse
    {
        $actor = $this->passedSuperAdmin($request);
        $request->validate(['email' => ['required', 'email', 'max:255']]);
        $this->checkPasswordAndCode($request, $actor);

        $target = User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $request->input('email')))])->first();
        if (! $target || ! $target->isSuperAdmin() || $target->is($actor)) {
            throw ValidationException::withMessages(['email' => 'Tiene que ser otra cuenta de SuperAdmin.']);
        }

        $this->twoFactor->reset($target, $actor, 'superadmin');

        return redirect()->route('two-factor.manage')->with('status', 'Listo: se restableció el segundo factor de esa cuenta y se cerraron sus sesiones.');
    }

    /* ------------------------------------------------------ CONTROLES */

    private function checkPassword(Request $request, User $user): void
    {
        $request->validate(['password' => ['required', 'string']]);
        $this->throttle($request, 'two-factor-password|'.$user->id, 'password');

        if (! Hash::check((string) $request->input('password'), (string) $user->password)) {
            throw ValidationException::withMessages(['password' => 'La contraseña no es correcta.']);
        }
    }

    private function checkPasswordAndCode(Request $request, User $user): void
    {
        $this->checkPassword($request, $user);
        $request->validate(['code' => ['required', 'string', 'max:20']]);

        if ($this->twoFactor->verify($user, (string) $request->input('code')) === null) {
            $this->twoFactor->recordFailure($user);

            throw ValidationException::withMessages(['code' => 'El código no es válido.']);
        }
    }

    private function throttle(Request $request, string $key, string $field): void
    {
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([$field => 'Demasiados intentos. Esperá '.ceil(RateLimiter::availableIn($key) / 60).' minutos.']);
        }
        RateLimiter::hit($key, 600);
    }
}

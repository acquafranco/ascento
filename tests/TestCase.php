<?php

namespace Tests;

use App\Models\User;
use App\Services\Auth\TwoFactorAuthentication;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Auth;

abstract class TestCase extends BaseTestCase
{
    /**
     * Las pruebas que actúan como SuperAdmin lo hacen como si ya hubiera
     * pasado el segundo factor (Etapa 11). Las pruebas del 2FA lo apagan
     * para probar el control de verdad.
     */
    protected bool $superAdminPassesTwoFactor = true;

    /**
     * Como un ingreso real (SessionGuard::login): además de fijar el usuario,
     * guarda en la sesión la huella de su contraseña. Sin esto,
     * AuthenticateSession (Etapa 11) cerraría la sesión al cambiar de usuario
     * dentro de una misma prueba, cosa que en la app solo pasa con un login.
     */
    public function actingAs(UserContract $user, $guard = null)
    {
        parent::actingAs($user, $guard);

        $name = $guard ?? Auth::getDefaultDriver();
        $sessionGuard = Auth::guard($name);
        if ($user->getAuthPassword() && method_exists($sessionGuard, 'hashPasswordForCookie')) {
            $this->withSession(['password_hash_'.$name => $sessionGuard->hashPasswordForCookie($user->getAuthPassword())]);
        }

        if ($this->superAdminPassesTwoFactor && $user instanceof User && $user->isSuperAdmin()) {
            $twoFactor = app(TwoFactorAuthentication::class);
            if (! $user->hasConfirmedTwoFactor()) {
                $user->forceFill(['two_factor_secret' => $twoFactor->engine()->generateSecretKey(32), 'two_factor_confirmed_at' => now()])->saveQuietly();
            }
            $this->withSession([TwoFactorAuthentication::SESSION_PASSED => $twoFactor->fingerprint($user)]);
        }

        return $this;
    }
}

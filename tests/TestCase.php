<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Auth;

abstract class TestCase extends BaseTestCase
{
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

        return $this;
    }
}

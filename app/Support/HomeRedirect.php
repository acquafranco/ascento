<?php

namespace App\Support;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * A dónde mandar a un usuario autenticado que pide entrar o aterriza en
 * una pantalla que no es la suya. Nunca devuelve un 403: o va a la pantalla
 * de su rol, o se cierra la sesión y vuelve al login.
 */
class HomeRedirect
{
    public const NO_ACCESS_MESSAGE = 'Tu cuenta no tiene una empresa activa en Ascento. Consultá con el administrador de tu empresa.';

    /**
     * URL de inicio del usuario actual. Si su cuenta no tiene ningún destino
     * válido, cierra la sesión y devuelve la URL del login.
     */
    public static function url(): string
    {
        $user = Auth::user();

        if ($user && ($home = $user->homeUrl()) !== null) {
            return $home;
        }

        static::logout();

        if ($user) {
            session()->flash('status', static::NO_ACCESS_MESSAGE);
        }

        return route('login');
    }

    public static function to(): RedirectResponse
    {
        return redirect()->to(static::url());
    }

    public static function logout(): void
    {
        Auth::guard('web')->logout();

        if (request()->hasSession()) {
            request()->session()->invalidate();
            request()->session()->regenerateToken();
        }
    }
}

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

    /** Portal: la empresa desactivó a la persona o al cliente (solo se ve con la contraseña correcta). */
    public const PORTAL_DISABLED_MESSAGE = 'Tu acceso al portal fue desactivado por la empresa de mantenimiento, por eso no podés ingresar. Si creés que es un error, comunicate con ellos.';

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

        if ($user?->isClientUser()) {
            session()->flash('status', static::PORTAL_DISABLED_MESSAGE);

            return route('portal.login');
        }

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

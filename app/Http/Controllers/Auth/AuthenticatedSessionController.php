<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Auth\AccountAttackGuard;
use App\Support\HomeRedirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        // R6: si el último intento pidió el desafío humano, se muestra.
        return view('auth.login', ['challenge' => AccountAttackGuard::challengeForView(request())]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        // Venía de abrir el enlace de confirmación del correo sin sesión:
        // vuelve a ese enlace (el resto de los destinos los decide el rol).
        $intended = (string) $request->session()->get('url.intended', '');
        if (str_starts_with((string) parse_url($intended, PHP_URL_PATH), '/verify-email/') && $request->user()->homeUrl() !== null) {
            return redirect()->intended();
        }

        // Sin destino válido (ej. empresa eliminada) no se lo deja entrar.
        return HomeRedirect::to();
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $wasPortalUser = (bool) $request->user()?->isClientUser();

        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        // El cliente vuelve al ingreso del portal, no a la web de Ascento.
        return $wasPortalUser ? redirect()->route('portal.login') : redirect('/');
    }
}

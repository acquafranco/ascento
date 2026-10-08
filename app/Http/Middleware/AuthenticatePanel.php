<?php

namespace App\Http\Middleware;

use App\Support\HomeRedirect;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Autenticación del panel /admin.
 *
 * Igual que la de Filament, pero quien NO puede usar el panel (un técnico,
 * o una cuenta sin empresa) no ve un 403: va a la pantalla de su rol, o
 * se cierra su sesión y vuelve al login. Sin sesión → login.
 */
class AuthenticatePanel extends Authenticate
{
    protected function authenticate($request, array $guards): void
    {
        $guard = Filament::auth();

        if (! $guard->check()) {
            $this->unauthenticated($request, $guards);

            return;
        }

        $this->auth->shouldUse(Filament::getAuthGuard());

        $user = $guard->user();

        if ($user instanceof FilamentUser && $user->canAccessPanel(Filament::getCurrentOrDefaultPanel())) {
            return;
        }

        // Las requests de Livewire no pueden seguir un redirect HTTP común.
        if ($request->hasHeader('X-Livewire')) {
            abort(403);
        }

        throw new HttpResponseException(HomeRedirect::to());
    }

    protected function redirectTo($request): ?string
    {
        return Filament::getLoginUrl() ?? route('login');
    }
}

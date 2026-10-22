<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Rules\UniqueUserEmail;
use App\Support\Auth\UserEmail;
use App\Support\HomeRedirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Send a new email verification notification.
     */
    public function store(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return HomeRedirect::to();
        }

        // Además del límite por minuto de la ruta: 3 enlaces cada 10 minutos por cuenta.
        $key = 'verification-send:'.$request->user()->id;

        if (RateLimiter::tooManyAttempts($key, 3)) {
            return back()->with('status', 'verification-throttled');
        }

        RateLimiter::hit($key, 600);
        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'verification-link-sent');
    }

    /**
     * «¿Escribiste mal tu correo?»: una cuenta SIN confirmar corrige su correo y
     * recibe el enlace en la dirección nueva. Mismas reglas que el resto (un
     * correo = una cuenta) y el mismo límite de envíos que «Reenviar».
     */
    public function changeEmail(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user->hasVerifiedEmail()) {
            return HomeRedirect::to();
        }

        $request->merge(['email' => UserEmail::normalize((string) $request->input('email'))]);
        $request->validate(['email' => ['required', 'string', 'email', 'max:255', new UniqueUserEmail($user->id)]]);

        $key = 'verification-send:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return back()->with('status', 'verification-throttled');
        }
        RateLimiter::hit($key, 600);

        $previous = $user->email;
        UserEmail::guard(fn () => $user->forceFill(['email' => $request->input('email')])->save());
        // Al registrarse, el contacto de la empresa es el mismo correo: si era ese, se corrige también.
        if ($user->company && $user->company->email === $previous) {
            $user->company->forceFill(['email' => $user->email])->save();
        }
        $user->sendEmailVerificationNotification();

        return back()->with('status', 'verification-email-changed');
    }
}

<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        // We will send the password reset link to this user. Once we have attempted
        // to send the link, we will examine the response then see the message we
        // need to show to the user. Finally, we'll send out a proper response.
        // Si el correo falla, se registra (sin el email) y la respuesta es la
        // misma: ni un error 500 ni pistas sobre qué cuentas existen.
        try {
            Password::sendResetLink(
                $request->only('email')
            );
        } catch (\Throwable $e) {
            Log::warning('No se pudo enviar el correo para restablecer la contraseña', ['exception' => $e::class]);
        }

        // Misma respuesta exista o no el email: no revelar qué cuentas
        // están registradas.
        return back()->with(
            'status',
            'Si el email corresponde a una cuenta, te enviamos un enlace para restablecer la contraseña.'
        );
    }
}

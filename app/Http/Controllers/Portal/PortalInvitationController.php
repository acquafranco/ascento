<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Portal\PortalInvitations;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;

/**
 * Activación de una cuenta del portal desde el enlace de la invitación. El
 * cliente elige su contraseña; después entra por el login del portal.
 */
class PortalInvitationController extends Controller
{
    public function show(Request $request, string $token)
    {
        $user = User::where('email', (string) $request->query('email'))->first();

        // Mismo mensaje para enlace vencido, usado o de otra cuenta.
        if (! PortalInvitations::validFor($user, $token)) {
            return response()->view('portal.invitation-invalid', [], 410);
        }

        return view('portal.invitation', ['token' => $token, 'email' => $user->email, 'name' => $user->name]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = User::where('email', $request->input('email'))->first();

        if (! PortalInvitations::validFor($user, $request->input('token'))) {
            return response()->view('portal.invitation-invalid', [], 410);
        }

        $status = PortalInvitations::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user) use ($request) {
                $user->forceFill([
                    'password' => Hash::make($request->input('password')),
                    'remember_token' => Str::random(60),
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->view('portal.invitation-invalid', [], 410);
        }

        return redirect()->route('portal.login')->with('status', 'Tu cuenta quedó activada. Ya podés ingresar con tu email y tu contraseña.');
    }
}

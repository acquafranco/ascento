<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\PendingEmailChange;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Enlace del correo nuevo (Etapa 11). Se puede abrir sin sesión (el token
 * prueba que la persona recibe correo en esa dirección), pero no desde la
 * sesión de OTRA cuenta.
 */
class ConfirmEmailChangeController extends Controller
{
    public function __invoke(Request $request, int $user, string $token, PendingEmailChange $changes): Response
    {
        abort_if($request->user() && $request->user()->id !== $user, 403);

        $result = strlen($token) === 64 ? $changes->confirm($user, $token) : PendingEmailChange::INVALID;

        return response()->view('auth.email-change-result', ['result' => $result], match ($result) {
            PendingEmailChange::CONFIRMED => 200,
            PendingEmailChange::TAKEN => 409,
            default => 410,
        });
    }
}

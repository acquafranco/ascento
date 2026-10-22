<?php

namespace App\Http\Middleware;

use App\Support\HomeRedirect;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Área de proveedores (/proveedores): solo usuarios de un proveedor, con el
 * correo confirmado. Cualquier otra cuenta vuelve a su propia pantalla; un
 * proveedor nunca entra al panel, a la app del técnico ni al portal (esas
 * puertas ya lo rechazan: no tiene empresa).
 */
class EnsureSupplierUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->isSupplierUser() || ! $user->supplier) {
            return HomeRedirect::to();
        }

        if (! $user->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        // Las pantallas del área muestran el estado de la cuenta.
        view()->share('supplier', $user->supplier);

        return $next($request);
    }
}

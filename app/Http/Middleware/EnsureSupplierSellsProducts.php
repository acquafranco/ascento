<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Catálogo, plan y destacados del área de proveedores: solo para cuentas que
 * venden productos. Una cuenta que solo ofrece servicios va a su perfil, donde
 * puede habilitar la venta de productos cargando su identificación fiscal.
 */
class EnsureSupplierSellsProducts
{
    public function handle(Request $request, Closure $next): Response
    {
        $supplier = $request->user()?->supplier;
        if ($supplier && ! $supplier->sellsProducts()) {
            return redirect()->route('suppliers.profile.edit')->with('status', 'Tu cuenta ofrece solo servicios. Para vender productos, cargá tu identificación fiscal abajo.');
        }

        return $next($request);
    }
}

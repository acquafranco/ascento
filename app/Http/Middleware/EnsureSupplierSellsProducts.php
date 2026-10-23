<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Catálogo, plan y destacados del área de proveedores: solo para cuentas que
 * venden productos (todas, salvo una anterior registrada «solo servicios», función
 * retirada): esa va a su perfil, donde habilita la venta cargando su
 * identificación fiscal.
 */
class EnsureSupplierSellsProducts
{
    public function handle(Request $request, Closure $next): Response
    {
        $supplier = $request->user()?->supplier;
        if ($supplier && ! $supplier->sellsProducts()) {
            return redirect()->route('suppliers.profile.edit')->with('status', 'Tu cuenta todavía no tiene habilitada la venta de productos. Para vender repuestos, cargá tu identificación fiscal abajo.');
        }

        return $next($request);
    }
}

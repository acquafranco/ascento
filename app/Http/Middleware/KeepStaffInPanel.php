<?php

namespace App\Http\Middleware;

use App\Support\Access\Access;
use App\Support\Access\StaffRoutes;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un administrativo trabaja en el panel, no en la app del técnico: solo usa
 * las rutas de StaffRoutes. El resto, si es una página, lo lleva al panel;
 * si es una acción (cargar fotos, firmar, crear reportes…), 403. Para
 * técnicos, dueño y SuperAdmin no cambia nada.
 */
class KeepStaffInPanel
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! Access::isStaff($user) || StaffRoutes::allows($user, $request->route()?->getName())) {
            return $next($request);
        }

        if ($request->isMethod('GET') && ! $request->expectsJson()) {
            return redirect('/admin');
        }

        abort(403);
    }
}

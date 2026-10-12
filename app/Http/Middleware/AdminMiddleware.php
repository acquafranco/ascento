<?php

namespace App\Http\Middleware;

use App\Support\Access\StaffRoutes;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    public function handle(
        Request $request,
        Closure $next
    ): Response {

        $user = auth()->user();

        // Dueño y SuperAdmin, como siempre; un administrativo, solo en las
        // rutas de lectura habilitadas para su área (StaffRoutes).
        if (! $user || (! $user->isAdmin() && ! $user->isSuperAdmin()
            && ! StaffRoutes::allows($user, $request->route()?->getName()))) {
            abort(404);
        }

        return $next($request);
    }
}

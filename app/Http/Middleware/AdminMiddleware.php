<?php

namespace App\Http\Middleware;

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

        if (! $user || (! $user->isAdmin() && ! $user->isSuperAdmin())) {
            abort(404);
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class SetCompanyRouteDefaults
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($company = $request->route('company')) {
            URL::defaults([
                'company' => $company,
            ]);
        }

        return $next($request);
    }
}

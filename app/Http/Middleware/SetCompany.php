<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Company;

class SetCompany
{
    public function handle(Request $request, Closure $next)
    {
        $company = $request->route('company');

        if (!$company) {
            abort(404);
        }


        if (!$company instanceof Company) {

            $company = Company::where('slug', $company)
                ->firstOrFail();

        }


        if (!auth()->check()) {
            return redirect()->route('login');
        }


        // URL de otra empresa (link viejo, sesión de otra cuenta en el mismo
        // navegador): se lo lleva a SU pantalla. Nunca se muestra nada de la
        // otra empresa. En POST/PUT/etc. se rechaza sin redirigir.
        if (auth()->user()->company_id !== $company->id) {
            if ($request->isMethod('GET') && ! $request->expectsJson()) {
                return \App\Support\HomeRedirect::to();
            }

            abort(403);
        }


        // La app de la empresa es para su personal (admins y técnicos). Un
        // usuario del portal del cliente va a su portal, nunca acá.
        if (auth()->user()->isClientUser()) {
            if ($request->isMethod('GET') && ! $request->expectsJson()) {
                return redirect()->route('portal.home');
            }

            abort(403);
        }

        app()->instance('company', $company);


        return $next($request);
    }
}

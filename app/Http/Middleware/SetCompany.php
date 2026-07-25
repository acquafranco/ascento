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


        if (auth()->user()->company_id !== $company->id) {
            abort(403);
        }


        app()->instance('company', $company);


        return $next($request);
    }
}

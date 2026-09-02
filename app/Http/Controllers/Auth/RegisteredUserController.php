<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Company;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Illuminate\Support\Str;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }


    public function store(Request $request): RedirectResponse
    {

        $request->validate([

            /*
            |--------------------------------------------------------------------------
            | Empresa
            |--------------------------------------------------------------------------
            */

            'company_name' => [
                'required',
                'string',
                'max:255'
            ],

            'business_name' => [
                'nullable',
                'string',
                'max:255'
            ],

            'cuit' => [
                'nullable',
                'string',
                'max:20'
            ],


            /*
            |--------------------------------------------------------------------------
            | Usuario administrador
            |--------------------------------------------------------------------------
            */

            'name' => [
                'required',
                'string',
                'max:255'
            ],

            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                'unique:' . User::class,
            ],

            'password' => [
                'required',
                'confirmed',
                Rules\Password::defaults(),
            ],

        ]);


        /*
        |--------------------------------------------------------------------------
        | Crear empresa
        |--------------------------------------------------------------------------
        */

        $company = Company::create([

            'name' => $request->company_name,

            'business_name' => $request->business_name,

            'cuit' => $request->cuit,


            // Estos datos se completan después
            // desde "Mi empresa"

            'email' => null,

            'phone' => null,

            'address' => null,


            'slug' => Str::slug($request->company_name)
                . '-' .
                Str::random(5),


            // Trial de 30 días sin pedir tarjeta: mientras esta fecha
            // no venza, EnsureActiveSubscription deja pasar aunque no
            // haya ninguna suscripción cargada todavía.

            'trial_ends_at' => now()->addDays(30),

        ]);



        /*
        |--------------------------------------------------------------------------
        | Crear usuario administrador
        |--------------------------------------------------------------------------
        */

        $user = User::create([

            'company_id' => $company->id,

            'name' => $request->name,

            'email' => $request->email,

            'password' => Hash::make($request->password),

            'role' => 'admin',

            'job_type' => null,

        ]);



        event(new Registered($user));


        Auth::login($user);


        return redirect()->route('dashboard', [

            'company' => $company->slug

        ]);

    }
}

<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Rules\Cuit;
use App\Support\Provinces;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * CUIT ya registrado: qué hacer, sin revelar nada de la empresa que lo
     * tiene (ni nombre, ni email, ni quién es el administrador).
     */
    public static function cuitTakenMessage(): string
    {
        return 'Ya hay una cuenta de Ascento para ese CUIT. Si sos parte de esa empresa, pedile a su administrador que te cree un usuario, o recuperá tu contraseña desde "¿Olvidaste tu contraseña?". Si creés que es un error, escribinos a '.config('app.support_email').'.';
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
                'max:255',
            ],

            // Lo mínimo para crear bien la empresa. El resto (razón social,
            // domicilio, condición fiscal, datos bancarios…) se completa después
            // desde "Mi empresa".
            'cuit' => [
                'required',
                'string',
                'max:20',
                new Cuit,
                // Una empresa = una cuenta (y una sola prueba gratis).
                function (string $attribute, $value, \Closure $fail) {
                    if (Company::withTrashed()->where('cuit', Cuit::format($value))->exists()) {
                        $fail(static::cuitTakenMessage());
                    }
                },
            ],

            'phone' => ['required', 'string', 'min:6', 'max:30', 'regex:/^[0-9+()\-\s]+$/'],

            'province' => ['required', 'string', Rule::in(Provinces::LIST)],

            'locality' => ['required', 'string', 'max:255'],

            'business_name' => ['nullable', 'string', 'max:255'],

            'terms' => ['accepted'],

            /*
            |--------------------------------------------------------------------------
            | Usuario administrador
            |--------------------------------------------------------------------------
            */

            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                'unique:'.User::class,
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

        // Empresa y administrador juntos: si algo falla (p. ej. dos registros
        // al mismo tiempo con el mismo CUIT o email), no queda una empresa
        // huérfana ocupando el CUIT.
        try {
            [$company, $user] = DB::transaction(function () use ($request) {
                $company = Company::create([

                    'name' => $request->company_name,

                    'business_name' => $request->business_name,

                    'cuit' => Cuit::format($request->cuit),

                    // Contacto inicial = el del responsable. El resto se completa
                    // después desde "Mi empresa".
                    'email' => $request->email,

                    'phone' => trim((string) $request->phone),

                    'province' => $request->province,

                    'city' => trim((string) $request->locality),

                    'address' => null,

                    'slug' => Str::slug($request->company_name)
                        .'-'.
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

                $user = new User([

                    'company_id' => $company->id,

                    'name' => $request->name,

                    'email' => $request->email,

                    'password' => Hash::make($request->password),

                    'role' => 'admin',

                    'job_type' => null,

                ]);
                $user->forceFill(['terms_accepted_at' => now()])->save();

                return [$company, $user];

            });
        } catch (UniqueConstraintViolationException $e) {
            // El INSERT de la empresa también menciona la columna email: se mira
            // cuit primero (la tabla de usuarios no tiene cuit).
            $field = str_contains($e->getMessage(), 'cuit') ? 'cuit' : 'email';

            return back()
                ->withInput($request->except('password', 'password_confirmation'))
                ->withErrors([$field => $field === 'cuit' ? static::cuitTakenMessage() : 'Ese email ya tiene una cuenta. Ingresá o recuperá tu contraseña.']);
        }

        event(new Registered($user));

        Auth::login($user);

        return redirect()->route('dashboard', [

            'company' => $company->slug,

        ]);

    }
}

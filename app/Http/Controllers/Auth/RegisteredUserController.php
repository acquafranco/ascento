<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Rules\TaxId;
use App\Rules\UniqueUserEmail;
use App\Services\Onboarding\CompanySetup;
use App\Support\Auth\PasswordPolicy;
use App\Support\Auth\RegistrationChallenge;
use App\Support\Auth\UserEmail;
use App\Support\Countries;
use App\Support\Provinces;
use App\Support\RegistrationCountries;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(Request $request): View
    {
        // Plan elegido en la calculadora (solo uno que se venda hoy): se recuerda para «Mi suscripción».
        $plan = SubscriptionPlan::offered()->firstWhere('slug', (string) $request->query('plan'));

        return view('auth.register', ['challenge' => RegistrationChallenge::prepare($request), 'intendedPlan' => $plan]);
    }

    /**
     * CUIT ya registrado: qué hacer, sin revelar nada de la empresa que lo
     * tiene (ni nombre, ni email, ni quién es el administrador).
     */
    public static function cuitTakenMessage(): string
    {
        return 'Ya hay una cuenta de Ascento para esa identificación fiscal. Si sos parte de esa empresa, pedile a su administrador que te cree un usuario, o recuperá tu contraseña desde "¿Olvidaste tu contraseña?". Si creés que es un error, escribinos a '.config('app.support_email').'.';
    }

    public function store(Request $request): RedirectResponse
    {
        // Campo trampa y tiempo mínimo (ver RegistrationChallenge).
        RegistrationChallenge::ensureHuman($request);

        // Mismo correo con otras mayúsculas o espacios = la misma cuenta.
        $request->merge(['email' => UserEmail::normalize((string) $request->input('email'))]);
        // País de la empresa: solo los habilitados para registrarse (RegistrationCountries; por
        // defecto, Argentina). Sin elegir (formulario sin selector), Argentina; cualquier otro
        // código enviado a mano se rechaza al validar.
        if (blank($request->input('country'))) {
            $request->merge(['country' => RegistrationCountries::HOME]);
        }
        $country = Countries::normalize((string) $request->input('country'));

        $request->validate([

            // Desafío del ascensor (o su pregunta alternativa), validado acá.
            'challenge' => [RegistrationChallenge::rule($request)],

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
            'country' => ['required', Rule::in(RegistrationCountries::enabled())],

            // Identificación fiscal según el país: CUIT en Argentina; RUT, RFC, NIT, RUC… en el resto.
            'cuit' => [
                'required',
                'string',
                'max:25',
                new TaxId($country),
                // Una empresa = una cuenta (y una sola prueba gratis).
                function (string $attribute, $value, \Closure $fail) use ($country) {
                    if (Company::withTrashed()->where('cuit', TaxId::format($country, $value))->exists()) {
                        $fail(static::cuitTakenMessage());
                    }
                },
            ],

            'phone' => ['required', 'string', 'min:6', 'max:30', 'regex:/^[0-9+()\-\s]+$/'],

            // Argentina: una de sus provincias. Otros países: estado, región, departamento… (texto).
            'province' => Countries::isArgentina($country)
                ? ['required', 'string', Rule::in(Provinces::LIST)]
                : ['required', 'string', 'max:255'],

            'locality' => ['required', 'string', 'max:255'],

            'business_name' => ['nullable', 'string', 'max:255'],

            // Plan elegido en la calculadora (no cambia la prueba gratis).
            'intended_plan' => ['nullable', 'string', Rule::in(SubscriptionPlan::offered()->pluck('slug')->all())],

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
                'email',
                'max:255',
                new UniqueUserEmail,
            ],

            'password' => PasswordPolicy::rules([$request->input('name'), $request->input('email'), $request->input('company_name')]),

        ], ['country.in' => RegistrationCountries::rejectedMessage()]);

        /*
        |--------------------------------------------------------------------------
        | Crear empresa
        |--------------------------------------------------------------------------
        */

        // Empresa y administrador juntos: si algo falla (p. ej. dos registros
        // al mismo tiempo con el mismo CUIT o email), no queda una empresa
        // huérfana ocupando el CUIT.
        try {
            [$company, $user] = DB::transaction(function () use ($request, $country) {
                $company = Company::create([

                    'name' => $request->company_name,

                    'business_name' => $request->business_name,

                    'cuit' => TaxId::format($country, $request->cuit),

                    'country' => $country,

                    // Contacto inicial = el del responsable. El resto se completa
                    // después desde "Mi empresa".
                    'email' => $request->email,

                    'phone' => trim((string) $request->phone),

                    'province' => trim((string) $request->province),

                    'city' => trim((string) $request->locality),

                    'address' => null,

                    'slug' => Str::slug($request->company_name)
                        .'-'.
                        Str::random(5),

                    // Trial de 30 días sin pedir tarjeta: mientras esta fecha
                    // no venza, EnsureActiveSubscription deja pasar aunque no
                    // haya ninguna suscripción cargada todavía.

                    'trial_ends_at' => now()->addDays(SubscriptionPlan::TRIAL_DAYS),

                ]);

                // Empresa nueva: se le abre la configuración inicial guiada (CompanySetup).
                $company->forceFill(['setup_status' => CompanySetup::PENDING, 'intended_plan' => $request->input('intended_plan') ?: null])->save();

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
                ->withErrors([$field => $field === 'cuit' ? static::cuitTakenMessage() : UserEmail::TAKEN_MESSAGE]);
        }

        RegistrationChallenge::complete($request); // un solo uso

        event(new Registered($user));

        Auth::login($user);

        return redirect()->route('dashboard', [

            'company' => $company->slug,

        ]);

    }
}

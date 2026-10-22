<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Models\Marketplace\Supplier;
use App\Models\User;
use App\Rules\TaxId;
use App\Rules\UniqueUserEmail;
use App\Services\Marketplace\MarketplaceNotifier;
use App\Support\Auth\PasswordPolicy;
use App\Support\Auth\RegistrationChallenge;
use App\Support\Auth\UserEmail;
use App\Support\Countries;
use App\Support\Marketplace\Catalog;
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

/**
 * Registro de un proveedor de repuestos: cuenta comercial + su primer usuario
 * (role = supplier, sin empresa). Queda «pendiente» hasta que el SuperAdmin
 * lo apruebe; mientras tanto puede completar su perfil y armar su catálogo,
 * que nadie ve. No toca el registro de las empresas de mantenimiento.
 */
class SupplierRegistrationController extends Controller
{
    public const TAX_ID_TAKEN = 'Ya hay un proveedor registrado con esa identificación fiscal. Si sos parte de esa empresa, ingresá con su cuenta o escribinos.';

    public function create(Request $request): View
    {
        return view('suppliers.register', ['challenge' => RegistrationChallenge::prepare($request)]);
    }

    public function store(Request $request): RedirectResponse
    {
        RegistrationChallenge::ensureHuman($request);

        $request->merge(['email' => UserEmail::normalize((string) $request->input('email'))]);
        if (blank($request->input('country'))) {
            $request->merge(['country' => Countries::DEFAULT]);
        }
        $country = Countries::normalize((string) $request->input('country'));

        $data = $request->validate([
            'challenge' => [RegistrationChallenge::rule($request)],
            'supplier_name' => ['required', 'string', 'max:160'],
            'legal_name' => ['nullable', 'string', 'max:190'],
            'country' => ['required', Rule::in(array_keys(Countries::LATAM))],
            'tax_id' => ['required', 'string', 'max:25', new TaxId($country),
                function (string $attribute, $value, \Closure $fail) use ($country) {
                    if (Supplier::where('country', $country)->where('tax_id', TaxId::format($country, $value))->exists()) {
                        $fail(self::TAX_ID_TAKEN);
                    }
                }],
            'phone' => ['required', 'string', 'min:6', 'max:30', 'regex:/^[0-9+()\-\s]+$/'],
            'coverage' => ['required', 'string', 'max:255'],
            'categories' => ['required', 'array', 'min:1', 'max:'.count(Catalog::CATEGORIES)],
            'categories.*' => ['string', Rule::in(array_keys(Catalog::CATEGORIES))],
            'brands' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:2000'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', new UniqueUserEmail],
            'password' => PasswordPolicy::rules([$request->input('name'), $request->input('email'), $request->input('supplier_name')]),
            'terms' => ['accepted'],
        ], [
            'categories.required' => 'Elegí al menos un rubro.',
            'coverage.required' => 'Contanos a qué zonas vendés o enviás.',
        ]);

        try {
            [$supplier, $user] = DB::transaction(function () use ($data, $country) {
                $supplier = new Supplier([
                    'name' => trim($data['supplier_name']),
                    'legal_name' => filled($data['legal_name'] ?? null) ? trim($data['legal_name']) : null,
                    'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
                    'contact_email' => $data['email'],
                    'contact_phone' => trim($data['phone']),
                    'coverage' => trim($data['coverage']),
                    'categories' => array_values(array_unique($data['categories'])),
                    'brands' => self::brands($data['brands'] ?? null),
                ]);
                $supplier->forceFill([
                    'slug' => Str::slug($data['supplier_name']).'-'.Str::lower(Str::random(5)),
                    'country' => $country,
                    // Moneda de sus precios según el país registrado (validado con su identificación fiscal).
                    'currency' => Supplier::currencyFor($country),
                    'tax_id' => TaxId::format($country, $data['tax_id']),
                    'status' => Supplier::PENDING,
                ])->save();

                $user = new User(['name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($data['password'])]);
                $user->forceFill(['role' => User::ROLE_SUPPLIER, 'supplier_id' => $supplier->id, 'company_id' => null, 'terms_accepted_at' => now()])->save();

                return [$supplier, $user];
            });
        } catch (UniqueConstraintViolationException $e) {
            $field = str_contains($e->getMessage(), 'tax_id') ? 'tax_id' : 'email';

            return back()->withInput($request->except('password', 'password_confirmation'))
                ->withErrors([$field => $field === 'tax_id' ? self::TAX_ID_TAKEN : UserEmail::TAKEN_MESSAGE]);
        }

        RegistrationChallenge::complete($request);
        event(new Registered($user)); // correo para confirmar la cuenta
        Auth::login($user);
        $request->session()->regenerate();

        $notify = app(MarketplaceNotifier::class);
        $notify->team('Proveedor nuevo para revisar', $supplier->name.' ('.Countries::name($supplier->country).') se registró como proveedor de repuestos.', '/admin/marketplace/proveedores/'.$supplier->id, 'supplier-new:'.$supplier->id, mail: true);
        // Constancia en su bandeja (el correo de confirmación de la cuenta ya sale aparte).
        $notify->supplier($supplier, 'Recibimos tu solicitud', 'Estamos revisando tu cuenta de proveedor. Te avisamos por correo cuando la aprobemos; mientras tanto podés completar tu perfil y armar tu catálogo.', '/proveedores', 'registered', mail: false, push: false);

        return redirect()->route('suppliers.home');
    }

    /** «Schindler, Otis, Orona» → lista corta, sin repetidos. */
    public static function brands(?string $text): array
    {
        return collect(preg_split('/[,;\n]+/', (string) $text))
            ->map(fn ($b) => Str::limit(trim(preg_replace('/\s+/', ' ', $b)), 40, ''))
            ->filter()->unique(fn ($b) => mb_strtolower($b))->take(30)->values()->all();
    }
}

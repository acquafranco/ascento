<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Models\Marketplace\MarketplaceRequest;
use App\Models\Marketplace\ServiceContactRequest;
use App\Models\Marketplace\Supplier;
use App\Models\Marketplace\SupplierProduct;
use App\Rules\TaxId;
use App\Services\Marketplace\MarketplaceNotifier;
use App\Services\Marketplace\PublicationLimits;
use App\Support\Marketplace\Catalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Inicio y perfil del proveedor (área /proveedores). */
class SupplierAreaController extends Controller
{
    public function home(Request $request): View
    {
        $user = $request->user();
        $supplier = $user->supplier;

        $notifications = $user->notifications()->latest()->limit(5)->get();

        return view('suppliers.home', [
            'supplier' => $supplier,
            'usage' => PublicationLimits::usage($supplier),
            'products' => SupplierProduct::where('supplier_id', $supplier->id)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'hidden' => SupplierProduct::where('supplier_id', $supplier->id)->where('hidden_by_admin', true)->count(),
            'open' => MarketplaceRequest::forSupplier($supplier->id)->whereIn('status', [MarketplaceRequest::SENT])->count(),
            'accepted' => MarketplaceRequest::forSupplier($supplier->id)->where('status', MarketplaceRequest::ACCEPTED)->count(),
            'completed' => $supplier->completedOperations(),
            'notifications' => $notifications,
            'serviceProfile' => $profile = $supplier->serviceProfile,
            'openConsultations' => $profile ? ServiceContactRequest::forProfile($profile->id)->where('status', ServiceContactRequest::OPEN)->count() : 0,
        ]);
    }

    public function edit(Request $request): View
    {
        return view('suppliers.profile', ['supplier' => $request->user()->supplier]);
    }

    public function update(Request $request): RedirectResponse
    {
        $supplier = $request->user()->supplier;
        self::ensureCanEdit($supplier);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'legal_name' => ['nullable', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:2000'],
            'contact_email' => ['required', 'email', 'max:255'],
            'contact_phone' => ['required', 'string', 'min:6', 'max:30', 'regex:/^[0-9+()\-\s]+$/'],
            'website' => ['nullable', 'url:https,http', 'max:255'],
            'coverage' => ['required', 'string', 'max:255'],
            'categories' => [$supplier->sellsProducts() ? 'required' : 'nullable', 'array', 'min:1'],
            'categories.*' => ['string', Rule::in(array_keys(Catalog::CATEGORIES))],
            'brands' => ['nullable', 'string', 'max:500'],
        ], ['categories.required' => 'Elegí al menos un rubro.']);

        $data['categories'] = array_values(array_unique((array) ($data['categories'] ?? [])));
        $data['brands'] = SupplierRegistrationController::brands($data['brands'] ?? null);
        $data['contact_email'] = mb_strtolower(trim($data['contact_email']));
        $supplier->fill($data)->save();

        return redirect()->route('suppliers.profile.edit')->with('status', 'Perfil guardado.');
    }

    /**
     * Una cuenta que solo ofrecía servicios empieza a vender productos: carga su
     * identificación fiscal (la misma regla que el registro) y se avisa al equipo.
     */
    public function enableProducts(Request $request): RedirectResponse
    {
        $supplier = $request->user()->supplier;
        self::ensureCanEdit($supplier);
        if ($supplier->sellsProducts()) {
            return redirect()->route('suppliers.products.index');
        }
        $country = $supplier->country;
        $data = $request->validate([
            'tax_id' => [$supplier->tax_id ? 'nullable' : 'required', 'string', 'max:25', new TaxId($country),
                function (string $attribute, $value, \Closure $fail) use ($country, $supplier) {
                    if (filled($value) && Supplier::where('country', $country)->where('tax_id', TaxId::format($country, $value))->whereKeyNot($supplier->id)->exists()) {
                        $fail(SupplierRegistrationController::TAX_ID_TAKEN);
                    }
                }],
        ]);
        $supplier->forceFill([
            'tax_id' => $supplier->tax_id ?? TaxId::format($country, $data['tax_id']),
            'sells_products' => true,
        ])->save();
        app(MarketplaceNotifier::class)->team('Proveedor que empieza a vender productos', $supplier->name.' cargó su identificación fiscal ('.$supplier->tax_id.') para vender productos. Revisala.', '/admin/marketplace/proveedores/'.$supplier->id, 'supplier-sells:'.$supplier->id, mail: true);

        return redirect()->route('suppliers.products.index')->with('status', 'Listo: ya podés cargar productos.');
    }

    /** Rechazado, suspendido o cerrado: puede mirar, no cambiar. */
    public static function ensureCanEdit(Supplier $supplier): void
    {
        abort_if($supplier->closed_at !== null || in_array($supplier->status, [Supplier::REJECTED, Supplier::SUSPENDED], true), 403, 'Tu cuenta de proveedor no está habilitada para hacer cambios.');
    }

    /**
     * Cargar o publicar productos: con la cuenta sin rechazar, suspender ni
     * cerrar (también mientras se revisa, para tener el catálogo listo). El
     * límite del plan se controla al publicar (PublicationLimits).
     */
    public static function ensureCanPublish(Supplier $supplier): void
    {
        self::ensureCanEdit($supplier);
    }
}

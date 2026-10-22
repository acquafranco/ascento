<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Models\Marketplace\MarketplaceRequest;
use App\Models\Marketplace\Supplier;
use App\Models\Marketplace\SupplierProduct;
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

        $notifications = $user->notifications()->latest()->limit(8)->get();
        $user->unreadNotifications()->whereIn('id', $notifications->pluck('id'))->update(['read_at' => now()]);

        return view('suppliers.home', [
            'supplier' => $supplier,
            'products' => SupplierProduct::where('supplier_id', $supplier->id)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'hidden' => SupplierProduct::where('supplier_id', $supplier->id)->where('hidden_by_admin', true)->count(),
            'open' => MarketplaceRequest::forSupplier($supplier->id)->whereIn('status', [MarketplaceRequest::SENT])->count(),
            'accepted' => MarketplaceRequest::forSupplier($supplier->id)->where('status', MarketplaceRequest::ACCEPTED)->count(),
            'completed' => $supplier->completedOperations(),
            'notifications' => $notifications,
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
            'categories' => ['required', 'array', 'min:1'],
            'categories.*' => ['string', Rule::in(array_keys(Catalog::CATEGORIES))],
            'brands' => ['nullable', 'string', 'max:500'],
        ], ['categories.required' => 'Elegí al menos un rubro.']);

        $data['categories'] = array_values(array_unique($data['categories']));
        $data['brands'] = SupplierRegistrationController::brands($data['brands'] ?? null);
        $data['contact_email'] = mb_strtolower(trim($data['contact_email']));
        $supplier->fill($data)->save();

        return redirect()->route('suppliers.profile.edit')->with('status', 'Perfil guardado.');
    }

    /** Rechazado o suspendido: puede mirar, no cambiar. */
    public static function ensureCanEdit(Supplier $supplier): void
    {
        abort_if(in_array($supplier->status, [Supplier::REJECTED, Supplier::SUSPENDED], true), 403, 'Tu cuenta de proveedor no está habilitada para hacer cambios.');
    }
}

<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Models\Marketplace\FeaturedPlacement;
use App\Models\Marketplace\MarketplaceSettings;
use App\Models\Marketplace\SupplierProduct;
use App\Services\Marketplace\Billing\SupplierBilling;
use App\Services\Marketplace\FeaturedPlacements;
use App\Services\MercadoPagoApiException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * «Destacados» del proveedor: historial, contratar visibilidad para un
 * producto propio (7 o 30 días, precio de la configuración) y volver del pago
 * (se consulta la pasarela; el retorno del navegador no activa nada).
 */
class SupplierFeaturedController extends Controller
{
    public function index(Request $request): View
    {
        $supplier = $request->user()->supplier;

        if ($request->query('mp') === 'return' && ($gateway = SupplierBilling::for($supplier))?->isConfigured()) {
            FeaturedPlacement::where('supplier_id', $supplier->id)->where('status', FeaturedPlacement::PENDING_PAYMENT)->get()
                ->each(function (FeaturedPlacement $p) use ($gateway) {
                    try {
                        $gateway->syncFeatured($p);
                    } catch (Throwable $e) {
                        Log::info('No se pudo sincronizar un destacado al volver del pago', ['featured_placement_id' => $p->id, 'exception' => $e::class]);
                    }
                });
        }

        $settings = MarketplaceSettings::current();

        return view('suppliers.featured', [
            'supplier' => $supplier,
            'offers' => SupplierBilling::canCharge($supplier) ? $settings->featuredOffers() : [],
            'currency' => $settings->plan_currency ?: 'ARS',
            'products' => SupplierProduct::visible()->where('supplier_id', $supplier->id)->orderBy('name')->get(['id', 'name', 'supplier_id', 'status', 'hidden_by_admin']),
            'placements' => FeaturedPlacement::where('supplier_id', $supplier->id)->latest('id')->limit(100)->get(),
            'billable' => SupplierBilling::supportsCountry($supplier->country),
        ]);
    }

    public function purchase(Request $request, FeaturedPlacements $placements): RedirectResponse
    {
        $data = $request->validate([
            'producto' => ['required', 'integer'],
            'dias' => ['required', 'integer', Rule::in(MarketplaceSettings::FEATURED_DAYS)],
        ]);
        SupplierAreaController::ensureCanPublish($request->user()->supplier);
        $product = SupplierProduct::where('supplier_id', $request->user()->supplier_id)->findOrFail((int) $data['producto']);

        try {
            $url = $placements->purchase($request->user(), $product, (int) $data['dias'], route('suppliers.featured', ['mp' => 'return']));
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('No se pudo iniciar el pago de un destacado', ['supplier_id' => $request->user()->supplier_id, 'exception' => $e::class]);

            return back()->withErrors(['destacado' => $e instanceof MercadoPagoApiException ? $e->hint() : 'No pudimos iniciar el pago. Intentá de nuevo en unos minutos.']);
        }

        return redirect()->away($url);
    }
}

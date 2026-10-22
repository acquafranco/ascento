<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Models\Marketplace\MarketplaceSettings;
use App\Services\Marketplace\Billing\SupplierBilling;
use App\Services\Marketplace\Billing\SupplierSubscriptions;
use App\Services\Marketplace\PublicationLimits;
use App\Services\MercadoPagoApiException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * «Plan» del proveedor: uso del límite de publicaciones, plan gratuito y
 * Premium, historial de cobros, contratar y cancelar Premium. Al volver de Mercado Pago NO
 * se activa nada por el retorno: se vuelve a consultar la API (y el webhook
 * confirma igual).
 */
class SupplierBillingController extends Controller
{
    public function show(Request $request, SupplierSubscriptions $subscriptions): View
    {
        $supplier = $request->user()->supplier->load('subscription');

        // Retorno del checkout: se consulta a la pasarela (nunca se confía en la URL).
        if ($request->query('mp') === 'return' && $supplier->subscription && ($gateway = SupplierBilling::for($supplier))?->isConfigured()) {
            try {
                $gateway->syncSubscription($supplier->subscription);
                $supplier->load('subscription');
            } catch (Throwable $e) {
                Log::info('No se pudo sincronizar la suscripción del proveedor al volver del checkout', ['supplier_id' => $supplier->id, 'exception' => $e::class]);
            }
        }

        $settings = MarketplaceSettings::current();

        return view('suppliers.billing', [
            'supplier' => $supplier,
            'settings' => $settings,
            'usage' => PublicationLimits::usage($supplier, $settings),
            'subscription' => $supplier->subscription,
            'blockReason' => SupplierSubscriptions::blockReason($supplier, $settings),
            'payments' => $supplier->payments()->with('placement')->limit(50)->get(),
            'billable' => $settings->chargesIn($supplier->country),
            'featuredOffers' => $settings->chargesIn($supplier->country) && SupplierBilling::canCharge($supplier) ? $settings->featuredOffers() : [],
        ]);
    }

    public function subscribe(Request $request, SupplierSubscriptions $subscriptions): RedirectResponse
    {
        try {
            $url = $subscriptions->start($request->user()->supplier, $request->user(), route('suppliers.billing', ['mp' => 'return']));
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('No se pudo iniciar la suscripción de un proveedor', ['supplier_id' => $request->user()->supplier_id, 'exception' => $e::class]);

            return back()->withErrors(['suscripcion' => $e instanceof MercadoPagoApiException ? $e->hint() : 'No pudimos iniciar el pago. Intentá de nuevo en unos minutos.']);
        }

        return redirect()->away($url);
    }

    public function cancel(Request $request, SupplierSubscriptions $subscriptions): RedirectResponse
    {
        try {
            $subscriptions->cancel($request->user()->supplier, $request->user());
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('No se pudo cancelar la suscripción de un proveedor', ['supplier_id' => $request->user()->supplier_id, 'exception' => $e::class]);

            return back()->withErrors(['suscripcion' => 'No pudimos cancelarla. Intentá de nuevo en unos minutos o escribinos.']);
        }

        return back()->with('status', 'Premium cancelado: no se te va a volver a cobrar. Sigue hasta el fin del período pago y después volvés al plan gratuito.');
    }
}

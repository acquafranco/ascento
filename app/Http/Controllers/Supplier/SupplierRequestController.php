<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Models\Marketplace\MarketplaceRequest;
use App\Services\Marketplace\MarketplaceRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Pedidos de cotización que recibe el proveedor. Ve solo los suyos y, del
 * comprador, solo lo que este compartió para coordinar (nombre de la
 * empresa y el contacto del pedido): nada de sus clientes, edificios ni técnicos.
 */
class SupplierRequestController extends Controller
{
    public function __construct(private MarketplaceRequests $requests) {}

    private function find(Request $request, string $reference): MarketplaceRequest
    {
        return MarketplaceRequest::forSupplier((int) $request->user()->supplier_id)->where('reference', $reference)->firstOrFail();
    }

    public function index(Request $request): View
    {
        $status = $request->query('estado');
        $rows = MarketplaceRequest::forSupplier((int) $request->user()->supplier_id)
            ->when(isset(MarketplaceRequest::STATUSES[$status]), fn ($q) => $q->where('status', $status))
            ->with('company:id,name')->withCount('items')->latest()->paginate(25)->withQueryString();

        return view('suppliers.requests.index', ['rows' => $rows, 'status' => $status]);
    }

    public function show(Request $request, string $reference): View
    {
        return view('suppliers.requests.show', ['row' => $this->find($request, $reference)->load(['items', 'events', 'company:id,name'])]);
    }

    public function quote(Request $request, string $reference): RedirectResponse
    {
        $data = $request->validate([
            'precios' => ['required', 'array'],
            'validez' => ['nullable', 'integer', 'min:1', 'max:90'],
            'nota' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->requests->quote($request->user(), $this->find($request, $reference), $data['precios'], isset($data['validez']) ? (int) $data['validez'] : null, $data['nota'] ?? null);

        return back()->with('status', 'Cotización enviada. Te avisamos cuando el comprador responda.');
    }

    public function decline(Request $request, string $reference): RedirectResponse
    {
        $data = $request->validate(['nota' => ['nullable', 'string', 'max:2000']]);
        $this->requests->decline($request->user(), $this->find($request, $reference), $data['nota'] ?? null);

        return back()->with('status', 'Le avisamos al comprador que no podés cotizarlo.');
    }
}

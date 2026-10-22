<?php

namespace App\Services\Marketplace;

use App\Models\Marketplace\MarketplaceRequest;
use App\Models\Marketplace\MarketplaceRequestEvent;
use App\Models\Marketplace\Supplier;
use App\Models\Marketplace\SupplierProduct;
use App\Models\User;
use App\Notifications\Marketplace\BuyerRequestUpdate;
use App\Notifications\Marketplace\SupplierRequestUpdate;
use App\Services\Notifications\Notifier;
use App\Support\Marketplace\BuyerAccess;
use App\Support\Marketplace\Catalog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Pedidos de cotización del marketplace: crear, cotizar, aceptar, completar.
 *
 * - Todo se valida acá, en el servidor: quién es, de qué empresa o
 *   proveedor, qué estado permite qué (MarketplaceRequest::TRANSITIONS).
 * - Precios y totales salen de la base o del proveedor; nunca del comprador.
 * - Un mismo envío (misma clave) no crea dos pedidos.
 * - Ascento no cobra: nada se marca como pagado.
 */
class MarketplaceRequests
{
    public function __construct(private Notifier $notifier) {}

    /**
     * @param  array<int, int>  $quantities  producto => cantidad (del carrito)
     * @param  array{contact_name: string, contact_email: string, contact_phone?: ?string, delivery_area?: ?string, notes?: ?string}  $contact
     */
    public function create(User $buyer, int $supplierId, array $quantities, array $contact, string $key): MarketplaceRequest
    {
        abort_unless(BuyerAccess::canOrder($buyer), 403);
        $companyId = (int) $buyer->company_id;
        $idempotency = hash('sha256', 'mkt|'.$companyId.'|'.$key);

        if ($existing = MarketplaceRequest::where('idempotency_key', $idempotency)->first()) {
            return $existing; // reenvío del mismo formulario
        }

        $supplier = Supplier::approved()->find($supplierId);
        $products = $supplier
            ? SupplierProduct::visible()->where('supplier_id', $supplier->id)->whereIn('id', array_keys($quantities))->get()
            : collect();

        if ($products->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Los productos de este pedido ya no están disponibles.']);
        }
        if ($products->count() > Catalog::MAX_ITEMS) {
            throw ValidationException::withMessages(['items' => 'Un pedido puede tener hasta '.Catalog::MAX_ITEMS.' productos.']);
        }

        try {
            $request = DB::transaction(function () use ($buyer, $companyId, $supplier, $products, $quantities, $contact, $idempotency) {
                $request = new MarketplaceRequest;
                $request->forceFill([
                    'reference' => $this->reference(),
                    'company_id' => $companyId,
                    'supplier_id' => $supplier->id,
                    'created_by' => $buyer->id,
                    'contact_name' => trim($contact['contact_name']),
                    'contact_email' => mb_strtolower(trim($contact['contact_email'])),
                    'contact_phone' => filled($contact['contact_phone'] ?? null) ? trim($contact['contact_phone']) : null,
                    'delivery_area' => filled($contact['delivery_area'] ?? null) ? trim($contact['delivery_area']) : null,
                    'notes' => filled($contact['notes'] ?? null) ? trim($contact['notes']) : null,
                    'status' => MarketplaceRequest::SENT,
                    'currency' => $products->first()->currency,
                    'idempotency_key' => $idempotency,
                ])->save();

                foreach ($products as $product) {
                    $request->items()->create([
                        'supplier_product_id' => $product->id,
                        'name' => $product->name,
                        'brand' => $product->brand,
                        'part_number' => $product->part_number,
                        'quantity' => max(1, min(Catalog::MAX_QUANTITY, (int) $quantities[$product->id])),
                        'listed_price' => $product->hasPrice() ? $product->price : null,
                    ]);
                }

                $this->event($request, $buyer, 'buyer', null, MarketplaceRequest::SENT, null);

                return $request;
            });
        } catch (UniqueConstraintViolationException) {
            return MarketplaceRequest::where('idempotency_key', $idempotency)->firstOrFail();
        }

        $this->notifySupplier($request, 'Pedido de cotización nuevo', ($request->company?->name ?? 'Una empresa').' te pidió una cotización ('.($n = $request->items()->count()).' '.($n === 1 ? 'producto' : 'productos').').', 'new');

        return $request;
    }

    /**
     * El proveedor cotiza: precio unitario por ítem (o deja uno sin cotizar si no lo tiene).
     *
     * @param  array<int, mixed>  $unitPrices  ítem => precio
     */
    public function quote(User $supplierUser, MarketplaceRequest $request, array $unitPrices, ?int $validDays, ?string $note): MarketplaceRequest
    {
        $this->assertSupplier($supplierUser, $request, MarketplaceRequest::QUOTED);

        $items = $request->items()->get();
        $total = 0.0;
        $priced = 0;
        $errors = [];
        foreach ($items as $item) {
            $raw = $unitPrices[$item->id] ?? null;
            if ($raw === null || $raw === '') {
                $item->quoted_unit_price = null;

                continue;
            }
            if (! is_numeric($raw) || (float) $raw < 0 || (float) $raw > 999999999) {
                $errors['precios.'.$item->id] = 'Revisá el precio de «'.$item->name.'».';

                continue;
            }
            $item->quoted_unit_price = round((float) $raw, 2);
            $total += $item->quoted_unit_price * $item->quantity;
            $priced++;
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        if ($priced === 0) {
            throw ValidationException::withMessages(['precios' => 'Cotizá al menos un producto, o avisá que no podés cotizar.']);
        }
        if ($validDays !== null && ($validDays < 1 || $validDays > 90)) {
            throw ValidationException::withMessages(['validez' => 'La validez va de 1 a 90 días.']);
        }

        $from = $request->status;
        DB::transaction(function () use ($items, $request, $total, $validDays, $note, $supplierUser, $from) {
            $items->each->save();
            $request->forceFill([
                'status' => MarketplaceRequest::QUOTED,
                'quoted_total' => round($total, 2),
                'quote_valid_days' => $validDays,
                'supplier_note' => filled($note) ? Str::limit(trim($note), 2000, '') : null,
                'quoted_at' => now(),
            ])->save();
            $this->event($request, $supplierUser, 'supplier', $from, MarketplaceRequest::QUOTED, $note);
        });

        $this->notifyBuyer($request, $from === MarketplaceRequest::QUOTED ? 'Cotización actualizada' : 'Te cotizaron un pedido', $request->supplier->name.' cotizó el pedido '.$request->reference.'.', 'quoted-'.$request->quoted_at->timestamp);

        return $request;
    }

    public function decline(User $supplierUser, MarketplaceRequest $request, ?string $note): MarketplaceRequest
    {
        $this->assertSupplier($supplierUser, $request, MarketplaceRequest::DECLINED);
        $this->move($request, $supplierUser, 'supplier', MarketplaceRequest::DECLINED, $note);
        $this->notifyBuyer($request, 'Un proveedor no puede cotizar', $request->supplier->name.' no puede cotizar el pedido '.$request->reference.'.', 'declined');

        return $request;
    }

    /** Aceptar, rechazar, cancelar o confirmar la recepción (comprador). */
    public function buyerMove(User $buyer, MarketplaceRequest $request, string $to, ?string $note = null): MarketplaceRequest
    {
        abort_unless(BuyerAccess::canOrder($buyer) && (int) $request->company_id === (int) $buyer->company_id, 404);

        if (! $request->canMove('buyer', $to)) {
            throw ValidationException::withMessages(['estado' => 'Este pedido ya no se puede cambiar así. Actualizá la pantalla.']);
        }
        if ($to === MarketplaceRequest::ACCEPTED && $request->quoteExpired()) {
            throw ValidationException::withMessages(['estado' => 'La cotización venció. Pedile al proveedor que la actualice.']);
        }
        if ($to === MarketplaceRequest::ACCEPTED && ! $request->supplier?->isApproved()) {
            throw ValidationException::withMessages(['estado' => 'Este proveedor no está disponible en este momento.']);
        }

        $this->move($request, $buyer, 'buyer', $to, $note);

        $texts = [
            MarketplaceRequest::ACCEPTED => ['Aceptaron tu cotización', 'aceptó la cotización del pedido '.$request->reference.'. Coordiná el pago y la entrega con el comprador.'],
            MarketplaceRequest::REJECTED => ['Rechazaron tu cotización', 'rechazó la cotización del pedido '.$request->reference.'.'],
            MarketplaceRequest::CANCELLED => ['Cancelaron un pedido', 'canceló el pedido '.$request->reference.'.'],
            MarketplaceRequest::COMPLETED => ['Pedido completado', 'confirmó que recibió el pedido '.$request->reference.'.'],
        ];
        [$title, $body] = $texts[$to];
        $this->notifySupplier($request, $title, ($request->company?->name ?? 'La empresa').' '.$body, $to);

        return $request;
    }

    private function assertSupplier(User $user, MarketplaceRequest $request, string $to): void
    {
        abort_unless($user->isSupplierUser() && $user->supplier_id !== null && (int) $request->supplier_id === (int) $user->supplier_id, 404);

        if (! $user->supplier?->isApproved()) {
            throw ValidationException::withMessages(['estado' => 'Tu cuenta no está habilitada para cotizar.']);
        }
        if (! $request->canMove('supplier', $to)) {
            throw ValidationException::withMessages(['estado' => 'Este pedido ya no se puede cambiar así. Actualizá la pantalla.']);
        }
    }

    private function move(MarketplaceRequest $request, User $user, string $actor, string $to, ?string $note): void
    {
        DB::transaction(function () use ($request, $user, $actor, $to, $note) {
            // Bloqueo: dos clics (o dos personas) a la vez no cambian el estado dos veces.
            $fresh = MarketplaceRequest::whereKey($request->id)->lockForUpdate()->first();
            if (! $fresh->canMove($actor, $to)) {
                throw ValidationException::withMessages(['estado' => 'Este pedido ya cambió. Actualizá la pantalla.']);
            }
            $from = $fresh->status;
            $request->forceFill(array_filter([
                'status' => $to,
                'accepted_at' => $to === MarketplaceRequest::ACCEPTED ? now() : null,
                'completed_at' => $to === MarketplaceRequest::COMPLETED ? now() : null,
            ]))->save();
            $this->event($request, $user, $actor, $from, $to, $note);
        });
    }

    private function event(MarketplaceRequest $request, ?User $user, string $actor, ?string $from, string $to, ?string $note): void
    {
        MarketplaceRequestEvent::create([
            'marketplace_request_id' => $request->id,
            'user_id' => $user?->id,
            'actor' => $actor,
            'from' => $from,
            'to' => $to,
            'note' => filled($note) ? Str::limit(trim((string) $note), 2000, '') : null,
            'created_at' => now(),
        ]);
    }

    private function reference(): string
    {
        do {
            $ref = 'R-'.strtoupper(Str::random(8));
        } while (MarketplaceRequest::where('reference', $ref)->exists());

        return $ref;
    }

    /** Aviso a los usuarios del proveedor: en su área al instante; el correo, después de responder. */
    private function notifySupplier(MarketplaceRequest $request, string $title, string $body, string $event): void
    {
        $notification = new SupplierRequestUpdate($request->reference, $title, $body, $event);
        User::where('supplier_id', $request->supplier_id)->where('role', User::ROLE_SUPPLIER)->get()->each(function (User $user) use ($notification, $request) {
            try {
                $user->notifyNow($notification, ['database']);
                $send = fn () => Notification::sendNow($user, $notification, ['mail']);
                app()->runningInConsole() ? $send() : dispatch(function () use ($send, $request) {
                    try {
                        $send();
                    } catch (Throwable $e) {
                        Log::warning('No se pudo enviar el correo de un pedido de repuestos', ['marketplace_request_id' => $request->id, 'exception' => $e::class]);
                    }
                })->afterResponse();
            } catch (Throwable $e) {
                Log::warning('No se pudo avisar al proveedor', ['marketplace_request_id' => $request->id, 'exception' => $e::class]);
            }
        });
    }

    /** Aviso a quien hizo el pedido (campanita del panel), si sigue pudiendo verlo. */
    private function notifyBuyer(MarketplaceRequest $request, string $title, string $body, string $event): void
    {
        $buyer = $request->creator;
        if (! $buyer || ! BuyerAccess::canBrowse($buyer)) {
            return;
        }
        try {
            $this->notifier->sendTo($buyer, new BuyerRequestUpdate($request->id, $request->reference, $title, $body, $event), (int) $request->company_id, mail: false);
        } catch (Throwable $e) {
            Log::warning('No se pudo avisar al comprador', ['marketplace_request_id' => $request->id, 'exception' => $e::class]);
        }
    }
}

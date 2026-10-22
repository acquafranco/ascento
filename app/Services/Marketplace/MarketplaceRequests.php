<?php

namespace App\Services\Marketplace;

use App\Models\Marketplace\MarketplaceRequest;
use App\Models\Marketplace\MarketplaceRequestEvent;
use App\Models\Marketplace\Supplier;
use App\Models\Marketplace\SupplierProduct;
use App\Models\User;
use App\Services\Activity\PlatformAudit;
use App\Support\Marketplace\BuyerAccess;
use App\Support\Marketplace\Catalog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Pedidos de cotización del marketplace: crear, cotizar, aceptar, completar,
 * comentar, reportar una incidencia y la intervención del SuperAdmin.
 *
 * - Todo se valida acá, en el servidor: quién es, de qué empresa o
 *   proveedor, qué estado permite qué (MarketplaceRequest::TRANSITIONS).
 * - Precios y totales salen de la base o del proveedor; nunca del comprador.
 * - Un mismo envío (misma clave) no crea dos pedidos.
 * - Ascento no cobra: nada se marca como pagado.
 * - Proveedor no habilitado por moderación (suspendido, cerrado): no recibe
 *   pedidos nuevos ni cotiza, y su cotización no se puede aceptar. El plan
 *   (gratuito o Premium) no cambia nada de esto: solo el límite de publicaciones.
 *   Lo ya aceptado sigue: el comprador lo completa o lo cancela, y las dos
 *   partes pueden seguir comentando para coordinar. Nada se cancela solo.
 */
class MarketplaceRequests
{
    public function __construct(private MarketplaceNotifier $notify) {}

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

        $supplier = Supplier::operating()->find($supplierId);
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
                    'buyer_name' => (string) $buyer->company?->name,
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

        $n = $request->items()->count();
        $this->notify->supplier($request->supplier_id, 'Pedido de cotización nuevo', $request->buyer_name.' te pidió una cotización ('.$n.' '.($n === 1 ? 'producto' : 'productos').', pedido '.$request->reference.').', $this->supplierPath($request), 'r'.$request->id.':new');
        // Constancia en la campanita de quien lo envió (sin push ni correo: lo acaba de hacer).
        $this->notify->buyer($request, 'Pedido de cotización enviado', 'Le enviaste el pedido '.$request->reference.' a '.$request->supplier->name.'. Te avisamos cuando lo cotice.', 'sent', mail: false, push: false);

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
        if (! $supplierUser->supplier->isOperating()) {
            throw ValidationException::withMessages(['estado' => 'Tu cuenta no está habilitada para cotizar.']);
        }

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

        $from = DB::transaction(function () use ($items, $request, $total, $validDays, $note, $supplierUser) {
            // Bloqueo: si el comprador aceptó o canceló mientras tanto, la cotización no se pisa.
            $fresh = MarketplaceRequest::whereKey($request->id)->lockForUpdate()->first();
            if (! $fresh->canMove('supplier', MarketplaceRequest::QUOTED)) {
                throw ValidationException::withMessages(['estado' => 'Este pedido ya cambió. Actualizá la pantalla.']);
            }
            $from = $fresh->status;
            $items->each->save();
            $request->forceFill([
                'status' => MarketplaceRequest::QUOTED,
                'quoted_total' => round($total, 2),
                'quote_valid_days' => $validDays,
                'supplier_note' => filled($note) ? Str::limit(trim($note), 2000, '') : null,
                'quoted_at' => now(),
                // Una cotización nueva tiene sus propios avisos de vencimiento.
                'expiring_notified_at' => null,
                'expired_notified_at' => null,
            ])->save();
            $this->event($request, $supplierUser, 'supplier', $from, MarketplaceRequest::QUOTED, $note);

            return $from;
        });

        $expires = $request->quoteExpiresAt();
        $this->notify->buyer($request,
            $from === MarketplaceRequest::QUOTED ? 'Cotización actualizada' : 'Te cotizaron un pedido',
            $request->supplier->name.' cotizó el pedido '.$request->reference.': '.$request->money((float) $request->quoted_total).($expires ? ', válida hasta el '.$expires->format('d/m/Y') : '').'.',
            'quoted-'.$request->quoted_at->timestamp, mail: true);

        return $request;
    }

    public function decline(User $supplierUser, MarketplaceRequest $request, ?string $note): MarketplaceRequest
    {
        $this->assertSupplier($supplierUser, $request, MarketplaceRequest::DECLINED);
        $this->move($request, $supplierUser, 'supplier', MarketplaceRequest::DECLINED, $note);
        $this->notify->buyer($request, 'Un proveedor no puede cotizar', $request->supplier->name.' no puede cotizar el pedido '.$request->reference.'.', 'declined', mail: true);

        return $request;
    }

    /**
     * Aceptar, rechazar, cancelar o confirmar la recepción (comprador).
     *
     * @param  ?int  $seenQuote  al aceptar: la versión de la cotización que vio el comprador
     *                           (quoteVersion()). Si el proveedor la cambió después, no se acepta.
     */
    public function buyerMove(User $buyer, MarketplaceRequest $request, string $to, ?string $note = null, ?int $seenQuote = null): MarketplaceRequest
    {
        abort_unless(BuyerAccess::canOrder($buyer) && (int) $request->company_id === (int) $buyer->company_id, 404);

        if (! $request->canMove('buyer', $to)) {
            throw ValidationException::withMessages(['estado' => 'Este pedido ya no se puede cambiar así. Actualizá la pantalla.']);
        }
        if ($to === MarketplaceRequest::ACCEPTED && $request->quoteExpired()) {
            throw ValidationException::withMessages(['estado' => 'La cotización venció. Pedile al proveedor que la actualice.']);
        }
        if ($to === MarketplaceRequest::ACCEPTED && ! $request->supplier?->isOperating()) {
            throw ValidationException::withMessages(['estado' => 'Este proveedor no está disponible en este momento.']);
        }

        $this->move($request, $buyer, 'buyer', $to, $note, $to === MarketplaceRequest::ACCEPTED
            ? function (MarketplaceRequest $fresh) use ($seenQuote) {
                if ($seenQuote === null || $fresh->quoteVersion() !== $seenQuote) {
                    throw ValidationException::withMessages(['estado' => 'El proveedor cambió la cotización. Revisala antes de aceptarla.']);
                }
                if ($fresh->quoteExpired()) {
                    throw ValidationException::withMessages(['estado' => 'La cotización venció. Pedile al proveedor que la actualice.']);
                }
            }
            : null);

        $texts = [
            MarketplaceRequest::ACCEPTED => ['Aceptaron tu cotización', 'aceptó la cotización del pedido '.$request->reference.'. Coordiná el pago y la entrega directamente con el comprador.'],
            MarketplaceRequest::REJECTED => ['Rechazaron tu cotización', 'rechazó la cotización del pedido '.$request->reference.'.'],
            MarketplaceRequest::CANCELLED => ['Cancelaron un pedido', 'canceló el pedido '.$request->reference.'.'],
            MarketplaceRequest::COMPLETED => ['Pedido completado', 'confirmó que recibió el pedido '.$request->reference.'.'],
        ];
        [$title, $body] = $texts[$to];
        $this->notify->supplier($request->supplier_id, $title, $request->buyer_name.' '.$body.($note ? ' Comentario: '.Str::limit(trim($note), 300) : ''), $this->supplierPath($request), 'r'.$request->id.':'.$to);

        // Otra persona de la empresa (no quien lo hizo) se entera del cambio.
        $buyerTexts = [
            MarketplaceRequest::ACCEPTED => 'Se aceptó la cotización del pedido '.$request->reference.'.',
            MarketplaceRequest::REJECTED => 'Se rechazó la cotización del pedido '.$request->reference.'.',
            MarketplaceRequest::CANCELLED => 'Se canceló el pedido '.$request->reference.'.',
            MarketplaceRequest::COMPLETED => 'Se confirmó la recepción del pedido '.$request->reference.'.',
        ];
        $this->notify->buyer($request, 'Pedido '.$request->reference.' actualizado', $buyerTexts[$to].' Lo hizo '.$buyer->name.'.', $to, actor: $buyer);

        return $request;
    }

    /**
     * Comentario entre las partes (queda en el historial del pedido y avisa a
     * la otra parte). No reemplaza el contacto directo: las dos partes ven el
     * teléfono y el correo de la otra.
     */
    public function comment(User $user, MarketplaceRequest $request, string $text): MarketplaceRequest
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 2000) {
            throw ValidationException::withMessages(['comentario' => 'Escribí un comentario (hasta 2000 caracteres).']);
        }
        $actor = $this->party($user, $request);
        if (! in_array($request->status, [...MarketplaceRequest::OPEN, MarketplaceRequest::COMPLETED], true)) {
            throw ValidationException::withMessages(['comentario' => 'Este pedido está cerrado. Para seguir, usá los datos de contacto.']);
        }

        $event = $this->note($request, $user, $actor, MarketplaceRequestEvent::NOTE, $text);
        if ($actor === 'buyer') {
            $this->notify->supplier($request->supplier_id, 'Comentario en el pedido '.$request->reference, $request->buyer_name.': '.Str::limit($text, 300), $this->supplierPath($request), 'note:'.$event->id);
        } else {
            $this->notify->buyer($request, 'Comentario en el pedido '.$request->reference, $request->supplier->name.': '.Str::limit($text, 300), 'note:'.$event->id, mail: true);
        }

        return $request;
    }

    /** Una de las partes pide ayuda a Ascento con este pedido (una incidencia abierta a la vez). */
    public function reportIncident(User $user, MarketplaceRequest $request, string $text): MarketplaceRequest
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 2000) {
            throw ValidationException::withMessages(['incidencia' => 'Contanos qué pasó (hasta 2000 caracteres).']);
        }
        $actor = $this->party($user, $request);

        $opened = DB::transaction(function () use ($request, $user, $actor, $text) {
            $fresh = MarketplaceRequest::whereKey($request->id)->lockForUpdate()->first();
            if ($fresh->hasOpenIncident()) {
                return false;
            }
            $request->forceFill(['incident_at' => now(), 'incident_resolved_at' => null])->save();
            $this->note($request, $user, $actor, MarketplaceRequestEvent::INCIDENT, $text);

            return true;
        });
        if (! $opened) {
            throw ValidationException::withMessages(['incidencia' => 'Ya hay una incidencia abierta en este pedido: Ascento la está revisando.']);
        }

        $this->notify->team('Incidencia en un pedido de repuestos', 'Pedido '.$request->reference.' ('.($actor === 'buyer' ? 'lo reportó el comprador' : 'lo reportó el proveedor').'): '.Str::limit($text, 200), '/admin/marketplace/pedidos/'.$request->id, 'incident:'.$request->id.':'.$request->incident_at->timestamp, mail: true);

        return $request;
    }

    /** SuperAdmin: cierra la incidencia (con nota visible para las dos partes). */
    public function resolveIncident(User $admin, MarketplaceRequest $request, string $note): MarketplaceRequest
    {
        abort_unless($admin->isSuperAdmin(), 403);
        if (! $request->hasOpenIncident()) {
            throw ValidationException::withMessages(['nota' => 'Este pedido no tiene una incidencia abierta.']);
        }
        $request->forceFill(['incident_resolved_at' => now()])->save();
        $this->note($request, $admin, 'admin', MarketplaceRequestEvent::RESOLVED, trim($note));
        PlatformAudit::record('marketplace.request.incident_resolved', $request, ['note' => $note], 'Pedido '.$request->reference);
        $this->notifyBoth($request, 'Ascento respondió la incidencia del pedido '.$request->reference, trim($note), 'resolved:'.$request->incident_resolved_at->timestamp);

        return $request;
    }

    /**
     * SuperAdmin: anula un pedido abierto por una incidencia administrativa.
     * No cambia importes ni borra nada: queda un evento «Cancelado» de Ascento
     * con el motivo, visible para las dos partes, y el registro de auditoría.
     */
    public function adminCancel(User $admin, MarketplaceRequest $request, string $reason): MarketplaceRequest
    {
        abort_unless($admin->isSuperAdmin(), 403);
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['motivo' => 'Escribí el motivo: lo ven las dos partes.']);
        }

        DB::transaction(function () use ($request, $admin, $reason) {
            $fresh = MarketplaceRequest::whereKey($request->id)->lockForUpdate()->first();
            if (! $fresh->isOpen()) {
                throw ValidationException::withMessages(['motivo' => 'Solo se puede anular un pedido abierto.']);
            }
            $from = $fresh->status;
            $request->forceFill(['status' => MarketplaceRequest::CANCELLED])->save();
            $this->event($request, $admin, 'admin', $from, MarketplaceRequest::CANCELLED, $reason);
        });
        PlatformAudit::record('marketplace.request.cancelled_by_admin', $request, ['reason' => $reason], 'Pedido '.$request->reference);
        $this->notifyBoth($request, 'Ascento anuló el pedido '.$request->reference, 'Motivo: '.$reason, 'admin-cancel');

        return $request;
    }

    private function notifyBoth(MarketplaceRequest $request, string $title, string $body, string $key): void
    {
        $this->notify->supplier($request->supplier_id, $title, $body, $this->supplierPath($request), 'r'.$request->id.':'.$key);
        $this->notify->buyer($request, $title, $body, $key, mail: true);
    }

    /** 'buyer' o 'supplier' si el usuario es parte del pedido; si no, 404. */
    private function party(User $user, MarketplaceRequest $request): string
    {
        if (! $user->isSupplierUser() && BuyerAccess::canOrder($user) && $request->company_id !== null && (int) $request->company_id === (int) $user->company_id) {
            return 'buyer';
        }
        if ($user->isSupplierUser() && $user->supplier_id !== null && (int) $user->supplier_id === (int) $request->supplier_id
            && in_array($user->supplier?->status, [Supplier::APPROVED, Supplier::SUSPENDED], true) && $user->supplier?->closed_at === null) {
            return 'supplier';
        }
        abort(404);
    }

    private function assertSupplier(User $user, MarketplaceRequest $request, string $to): void
    {
        abort_unless($user->isSupplierUser() && $user->supplier_id !== null && (int) $request->supplier_id === (int) $user->supplier_id, 404);

        if (! $user->supplier?->isApproved()) {
            throw ValidationException::withMessages(['estado' => 'Tu cuenta no está habilitada para responder pedidos.']);
        }
        if (! $request->canMove('supplier', $to)) {
            throw ValidationException::withMessages(['estado' => 'Este pedido ya no se puede cambiar así. Actualizá la pantalla.']);
        }
    }

    /** @param  ?callable(MarketplaceRequest): void  $check  validación extra con el pedido bloqueado */
    private function move(MarketplaceRequest $request, User $user, string $actor, string $to, ?string $note, ?callable $check = null): void
    {
        DB::transaction(function () use ($request, $user, $actor, $to, $note, $check) {
            // Bloqueo: dos clics (o dos personas) a la vez no cambian el estado dos veces.
            $fresh = MarketplaceRequest::whereKey($request->id)->lockForUpdate()->first();
            if (! $fresh->canMove($actor, $to)) {
                throw ValidationException::withMessages(['estado' => 'Este pedido ya cambió. Actualizá la pantalla.']);
            }
            if ($check) {
                $check($fresh);
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

    private function event(MarketplaceRequest $request, ?User $user, string $actor, ?string $from, string $to, ?string $note): MarketplaceRequestEvent
    {
        return MarketplaceRequestEvent::create([
            'marketplace_request_id' => $request->id,
            'user_id' => $user?->id,
            'actor' => $actor,
            'kind' => MarketplaceRequestEvent::STATUS,
            'from' => $from,
            'to' => $to,
            'note' => filled($note) ? Str::limit(trim((string) $note), 2000, '') : null,
            'created_at' => now(),
        ]);
    }

    /** Comentario, incidencia o resolución: no cambia el estado del pedido. */
    private function note(MarketplaceRequest $request, User $user, string $actor, string $kind, string $text): MarketplaceRequestEvent
    {
        return MarketplaceRequestEvent::create([
            'marketplace_request_id' => $request->id,
            'user_id' => $user->id,
            'actor' => $actor,
            'kind' => $kind,
            'from' => $request->status,
            'to' => $request->status,
            'note' => Str::limit($text, 2000, ''),
            'created_at' => now(),
        ]);
    }

    private function supplierPath(MarketplaceRequest $request): string
    {
        return '/proveedores/pedidos/'.$request->reference;
    }

    private function reference(): string
    {
        do {
            $ref = 'R-'.strtoupper(Str::random(8));
        } while (MarketplaceRequest::where('reference', $ref)->exists());

        return $ref;
    }
}

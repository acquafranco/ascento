<?php

namespace App\Services\Marketplace;

use App\Models\Marketplace\FeaturedPlacement;
use App\Models\Marketplace\MarketplaceSettings;
use App\Models\Marketplace\SupplierPayment;
use App\Models\Marketplace\SupplierProduct;
use App\Models\User;
use App\Services\Activity\PlatformAudit;
use App\Services\Marketplace\Billing\SupplierBilling;
use App\Services\Marketplace\Billing\SupplierBillingSync;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Publicaciones destacadas: compra (pendiente → activa SOLO con el pago
 * aprobado), asignación del SuperAdmin, vencimiento y cancelación.
 *
 * - El precio sale de la configuración (nunca del navegador).
 * - Un producto no elegible (no visible, oculto por moderación, proveedor no
 *   habilitado) no se puede destacar; si se paga y ya no es elegible, queda
 *   «requiere revisión» y no se activa.
 * - Renovar = comprar otra vez: el período nuevo empieza cuando termina el vigente.
 * - supplier_products.featured_until es solo una copia del fin del destacado
 *   vigente (para ordenar el catálogo); la verdad está en featured_placements.
 */
class FeaturedPlacements
{
    public function __construct(private MarketplaceNotifier $notify) {}

    /** URL de pago para destacar un producto propio. */
    public function purchase(User $user, SupplierProduct $product, int $days, string $backUrl): string
    {
        $supplier = $user->supplier;
        abort_unless($user->isSupplierUser() && $supplier && (int) $product->supplier_id === (int) $supplier->id, 404);

        $price = MarketplaceSettings::current()->featuredPrice($days);
        $gateway = SupplierBilling::for($supplier);
        $why = match (true) {
            $price === null => 'Los destacados no están disponibles por ahora.',
            ! $gateway?->isConfigured() => 'Los pagos no están disponibles en tu país todavía.',
            ! $product->isVisible() => 'Solo se puede destacar un producto publicado y visible en el catálogo.',
            default => null,
        };
        if ($why) {
            throw ValidationException::withMessages(['destacado' => $why]);
        }

        // Un checkout pendiente reciente del mismo producto y duración se reutiliza (dos clics = un solo cobro).
        $pending = FeaturedPlacement::where('supplier_product_id', $product->id)->where('status', FeaturedPlacement::PENDING_PAYMENT)
            ->where('created_at', '>', now()->subHours(FeaturedPlacement::CHECKOUT_HOURS - 1))->latest('id')->first();
        if ($pending && $pending->days === $days && $pending->checkout_url && abs((float) $pending->amount - $price) < 0.01) {
            return $pending->checkout_url;
        }
        if ($pending) {
            throw ValidationException::withMessages(['destacado' => 'Ya hay un pago pendiente para destacar este producto. Esperá a que se acredite o a que venza (24 h).']);
        }

        $placement = DB::transaction(function () use ($user, $supplier, $product, $days, $price) {
            $placement = FeaturedPlacement::create([
                'supplier_id' => $supplier->id,
                'supplier_product_id' => $product->id,
                'product_name' => $product->name,
                'source' => 'purchase',
                'days' => $days,
                'amount' => $price,
                'currency' => MarketplaceSettings::current()->plan_currency ?: 'ARS',
                'status' => FeaturedPlacement::PENDING_PAYMENT,
                'created_by' => $user->id,
            ]);
            $placement->forceFill(['external_reference' => SupplierBillingSync::featuredReference($placement->id)])->save();

            return $placement;
        });

        try {
            return $gateway->startFeaturedCheckout($placement, $user, $backUrl);
        } catch (\Throwable $e) {
            $placement->forceFill(['status' => FeaturedPlacement::CANCELLED, 'status_reason' => 'No se pudo iniciar el pago.'])->save();
            throw $e;
        }
    }

    /** Pago aprobado (lo llama SupplierBillingSync, con la API ya consultada). */
    public function activatePaid(FeaturedPlacement $placement, SupplierPayment $payment): void
    {
        $result = DB::transaction(function () use ($placement) {
            $row = FeaturedPlacement::lockForUpdate()->find($placement->id);
            if (! in_array($row->status, [FeaturedPlacement::PENDING_PAYMENT, FeaturedPlacement::CANCELLED], true)) {
                return null; // ya activado (aviso repetido)
            }
            $product = $row->product()->with('supplier')->first();
            if (! $product || ! $product->isVisible()) {
                $row->forceFill(['status' => FeaturedPlacement::NEEDS_REVIEW, 'status_reason' => 'Se pagó cuando el producto ya no se podía destacar.'])->save();

                return 'review';
            }
            $start = $this->nextStart($product, $row->id);
            $row->forceFill(['status' => FeaturedPlacement::ACTIVE, 'starts_at' => $start, 'ends_at' => $start->copy()->addDays($row->days), 'checkout_url' => null])->save();
            $this->recompute($product);

            return 'active';
        });

        $placement->refresh();
        if ($result === 'active') {
            $this->notify->supplier($placement->supplier_id, 'Destacado activo', '«'.$placement->product_name.'» está destacado del '.$placement->starts_at->format('d/m/Y').' al '.$placement->ends_at->format('d/m/Y').'.', '/proveedores/destacados', 'feat:'.$placement->id.':active');
        } elseif ($result === 'review') {
            $this->notify->supplier($placement->supplier_id, 'Recibimos el pago del destacado', 'El producto «'.$placement->product_name.'» ya no se puede destacar (no está visible). Ascento va a revisar el caso y te va a escribir.', '/proveedores/destacados', 'feat:'.$placement->id.':review');
            $this->notify->team('Destacado pagado que requiere revisión', 'El destacado #'.$placement->id.' («'.$placement->product_name.'») se pagó pero el producto ya no era elegible. Definí la devolución o la reasignación.', '/admin/marketplace/destacados', 'feat-review:'.$placement->id, mail: true);
        }
    }

    /** Devolución o contracargo de un destacado pagado: deja de mostrarse. */
    public function paymentReversed(FeaturedPlacement $placement, SupplierPayment $payment): void
    {
        if ($placement->status === FeaturedPlacement::ACTIVE) {
            $this->end($placement, FeaturedPlacement::CANCELLED, 'Pago devuelto o desconocido.');
        }
        $this->notify->team('Pago de destacado devuelto', 'El pago del destacado #'.$placement->id.' («'.$placement->product_name.'») fue devuelto o desconocido. El destacado se canceló.', '/admin/marketplace/destacados', 'feat-refund:'.$payment->id, mail: true);
    }

    /** SuperAdmin: destaca sin cobro hasta una fecha (queda en el historial y en la auditoría). */
    public function grant(User $admin, SupplierProduct $product, Carbon $until): FeaturedPlacement
    {
        abort_unless($admin->isSuperAdmin(), 403);
        if ($until->isPast() || $until->gt(now()->addYear()->endOfDay())) {
            throw ValidationException::withMessages(['until' => 'Elegí una fecha dentro del próximo año.']);
        }
        $placement = DB::transaction(function () use ($admin, $product, $until) {
            $placement = FeaturedPlacement::create([
                'supplier_id' => $product->supplier_id,
                'supplier_product_id' => $product->id,
                'product_name' => $product->name,
                'source' => 'admin',
                'days' => max(1, (int) ceil(now()->diffInDays($until))),
                'status' => FeaturedPlacement::ACTIVE,
                'starts_at' => now(),
                'ends_at' => $until,
                'created_by' => $admin->id,
            ]);
            $this->recompute($product);

            return $placement;
        });
        PlatformAudit::record('marketplace.product.featured', $product, ['until' => $until->toDateString(), 'placement' => $placement->id]);

        return $placement;
    }

    /** SuperAdmin: cancela un destacado (comprado o asignado) con motivo. */
    public function cancel(User $admin, FeaturedPlacement $placement, string $reason): void
    {
        abort_unless($admin->isSuperAdmin(), 403);
        if (! in_array($placement->status, [FeaturedPlacement::ACTIVE, FeaturedPlacement::PENDING_PAYMENT, FeaturedPlacement::NEEDS_REVIEW], true)) {
            throw ValidationException::withMessages(['reason' => 'Este destacado ya terminó.']);
        }
        $this->end($placement, FeaturedPlacement::CANCELLED, trim($reason));
        PlatformAudit::record('marketplace.featured.cancelled', $placement, ['reason' => $reason], 'Destacado #'.$placement->id.' · '.$placement->product_name);
        if ($placement->source === 'purchase') {
            $this->notify->supplier($placement->supplier_id, 'Destacado cancelado', 'Ascento canceló el destacado de «'.$placement->product_name.'». Motivo: '.trim($reason), '/proveedores/destacados', 'feat:'.$placement->id.':cancelled');
        }
    }

    /** Moderación: ocultar o retirar un producto termina sus destacados activos. */
    public function endForProduct(SupplierProduct $product, string $reason): void
    {
        FeaturedPlacement::where('supplier_product_id', $product->id)->where('status', FeaturedPlacement::ACTIVE)->get()
            ->each(fn (FeaturedPlacement $p) => $this->end($p, FeaturedPlacement::CANCELLED, $reason));
    }

    /** Vencimientos (tarea programada). Idempotente: cada destacado vence y se avisa una sola vez. */
    public function expireDue(): int
    {
        $n = 0;
        FeaturedPlacement::where('status', FeaturedPlacement::ACTIVE)->where('ends_at', '<=', now())->get()->each(function (FeaturedPlacement $p) use (&$n) {
            if ($this->end($p, FeaturedPlacement::EXPIRED, null)) {
                $n++;
                if ($p->source === 'purchase') {
                    $this->notify->supplier($p->supplier_id, 'Destacado vencido', 'Terminó el destacado de «'.$p->product_name.'». Podés renovarlo desde «Destacados».', '/proveedores/destacados', 'feat:'.$p->id.':expired', mail: false);
                }
            }
        });
        // Aviso previo (24 h antes), una vez.
        FeaturedPlacement::where('status', FeaturedPlacement::ACTIVE)->where('source', 'purchase')->whereNull('ending_notified_at')
            ->whereBetween('ends_at', [now(), now()->addDay()])->get()->each(function (FeaturedPlacement $p) {
                if (FeaturedPlacement::whereKey($p->id)->whereNull('ending_notified_at')->update(['ending_notified_at' => now()])) {
                    $this->notify->supplier($p->supplier_id, 'Tu destacado vence mañana', 'El destacado de «'.$p->product_name.'» termina el '.$p->ends_at->format('d/m/Y H:i').'. Si querés seguir destacándolo, renovalo desde «Destacados».', '/proveedores/destacados', 'feat:'.$p->id.':ending');
                }
            });
        // Checkouts abandonados (la preferencia de pago ya venció).
        FeaturedPlacement::where('status', FeaturedPlacement::PENDING_PAYMENT)->where('created_at', '<', now()->subHours(FeaturedPlacement::CHECKOUT_HOURS + 48))
            ->update(['status' => FeaturedPlacement::CANCELLED, 'status_reason' => 'No se completó el pago.', 'checkout_url' => null]);

        return $n;
    }

    private function end(FeaturedPlacement $placement, string $status, ?string $reason): bool
    {
        return DB::transaction(function () use ($placement, $status, $reason) {
            $changed = FeaturedPlacement::whereKey($placement->id)->whereIn('status', [FeaturedPlacement::ACTIVE, FeaturedPlacement::PENDING_PAYMENT, FeaturedPlacement::NEEDS_REVIEW])
                ->update(['status' => $status, 'status_reason' => $reason, 'checkout_url' => null, 'updated_at' => now()]);
            if ($changed && $placement->product) {
                $this->recompute($placement->product);
            }

            return (bool) $changed;
        });
    }

    /** El período nuevo empieza al terminar el destacado vigente del mismo producto (renovar = comprar otra vez). */
    private function nextStart(SupplierProduct $product, int $exceptId): Carbon
    {
        $last = FeaturedPlacement::where('supplier_product_id', $product->id)->where('status', FeaturedPlacement::ACTIVE)
            ->whereKeyNot($exceptId)->where('ends_at', '>', now())->max('ends_at');

        return $last ? Carbon::parse($last) : now();
    }

    /** Copia del fin del destacado vigente en el producto (para ordenar el catálogo). */
    public function recompute(SupplierProduct $product): void
    {
        $until = FeaturedPlacement::where('supplier_product_id', $product->id)->where('status', FeaturedPlacement::ACTIVE)
            ->where('starts_at', '<=', now())->where('ends_at', '>', now())->max('ends_at');
        $product->forceFill(['featured_until' => $until ? Carbon::parse($until) : null])->saveQuietly();
    }
}

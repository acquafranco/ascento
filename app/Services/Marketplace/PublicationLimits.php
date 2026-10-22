<?php

namespace App\Services\Marketplace;

use App\Models\Marketplace\FeaturedPlacement;
use App\Models\Marketplace\MarketplaceSettings;
use App\Models\Marketplace\Supplier;
use App\Models\Marketplace\SupplierProduct;
use App\Support\Marketplace\SupplierStanding;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Límite de publicaciones simultáneas por plan (gratuito / Premium).
 *
 * Cuenta: productos en estado «publicado» que NO estén ocultos por
 * moderación. No cuentan: borradores, pausados, retirados, ocultos por
 * moderación ni «fuera del límite». Un producto es una publicación sin
 * importar su stock.
 *
 * Toda operación que puede sumar publicaciones toma un bloqueo sobre la fila
 * del proveedor (SELECT … FOR UPDATE) y cuenta dentro de esa transacción: dos
 * pedidos simultáneos no pueden pasar el límite.
 *
 * Nada se borra: lo que no entra queda en «fuera del límite» (over_limit),
 * distinto de «oculto por moderación», y vuelve a publicarse cuando hay lugar.
 */
class PublicationLimits
{
    public function __construct(private MarketplaceNotifier $notify) {}

    public static function counted(int $supplierId): Builder
    {
        return SupplierProduct::where('supplier_id', $supplierId)->where('status', SupplierProduct::PUBLISHED)->where('hidden_by_admin', false);
    }

    /** @return array{limit: int, published: int, remaining: int, drafts: int, over_limit: int, paused: int, premium: bool} */
    public static function usage(Supplier $supplier, ?MarketplaceSettings $settings = null): array
    {
        $limit = SupplierStanding::publicationLimit($supplier, $settings);
        $byStatus = SupplierProduct::where('supplier_id', $supplier->id)->where('hidden_by_admin', false)
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $published = (int) ($byStatus[SupplierProduct::PUBLISHED] ?? 0);

        return [
            'limit' => $limit,
            'published' => $published,
            'remaining' => max(0, $limit - $published),
            'drafts' => (int) ($byStatus[SupplierProduct::DRAFT] ?? 0),
            'over_limit' => (int) ($byStatus[SupplierProduct::OVER_LIMIT] ?? 0),
            'paused' => (int) ($byStatus[SupplierProduct::PAUSED] ?? 0),
            'premium' => SupplierStanding::isPremium($supplier),
        ];
    }

    /** Publica un producto si hay lugar en el plan (con bloqueo). */
    public function publish(SupplierProduct $product): SupplierProduct
    {
        return DB::transaction(function () use ($product) {
            $supplier = Supplier::whereKey($product->supplier_id)->lockForUpdate()->first();
            $row = SupplierProduct::whereKey($product->id)->lockForUpdate()->first();
            if ($row->status === SupplierProduct::PUBLISHED) {
                return $row;
            }
            if ($row->hidden_by_admin) {
                throw ValidationException::withMessages(['estado' => 'Esta publicación está oculta por moderación: no se puede publicar hasta que Ascento la restaure.']);
            }
            $limit = SupplierStanding::publicationLimit($supplier);
            if (self::counted($supplier->id)->count() >= $limit) {
                throw ValidationException::withMessages(['estado' => self::fullMessage($supplier, $limit)]);
            }
            $row->forceFill(['status' => SupplierProduct::PUBLISHED, 'published_at' => $row->published_at ?? now(), 'limit_hidden_at' => null])->save();

            return $row;
        });
    }

    public static function fullMessage(Supplier $supplier, int $limit): string
    {
        $premium = MarketplaceSettings::current();

        return "Llegaste al límite de {$limit} productos publicados de tu plan. Tu producto queda guardado como borrador: pausá o retirá otro para hacerle lugar"
            .(! SupplierStanding::isPremium($supplier) && $premium->sellsPremium() && $premium->chargesIn($supplier->country)
                ? ', o pasate a Premium (hasta '.number_format($premium->premiumLimit(), 0, ',', '.').' productos).'
                : '.');
    }

    /**
     * Si hay más publicaciones que el límite del plan (p. ej. terminó Premium),
     * deja fuera del límite las que sobran: primero se conservan las que el
     * proveedor marcó «conservar en el plan gratuito», después las que tienen
     * un destacado vigente, y el resto según la regla configurada (más
     * recientes o más antiguas; a igualdad, el id). Devuelve cuántas movió.
     */
    public function enforce(Supplier $supplier, bool $notify = true): int
    {
        $moved = DB::transaction(function () use ($supplier) {
            $locked = Supplier::whereKey($supplier->id)->lockForUpdate()->first();
            $limit = SupplierStanding::publicationLimit($locked);
            $published = self::counted($locked->id)->get(['id', 'keep_on_free', 'published_at']);
            if ($published->count() <= $limit) {
                return 0;
            }
            $featured = FeaturedPlacement::running()->whereIn('supplier_product_id', $published->pluck('id'))->pluck('supplier_product_id')->all();
            $recent = MarketplaceSettings::current()->overLimitRule() === 'recent';
            $keep = $published->sort(function ($a, $b) use ($featured, $recent) {
                $byKeep = (int) $b->keep_on_free <=> (int) $a->keep_on_free;
                $byFeatured = (int) in_array($b->id, $featured, true) <=> (int) in_array($a->id, $featured, true);
                $byDate = $recent ? ($b->published_at?->timestamp ?? 0) <=> ($a->published_at?->timestamp ?? 0) : ($a->published_at?->timestamp ?? 0) <=> ($b->published_at?->timestamp ?? 0);

                return $byKeep ?: ($byFeatured ?: ($byDate ?: ($recent ? $b->id <=> $a->id : $a->id <=> $b->id)));
            })->take($limit)->pluck('id');

            return SupplierProduct::where('supplier_id', $locked->id)->where('status', SupplierProduct::PUBLISHED)->where('hidden_by_admin', false)
                ->whereNotIn('id', $keep)->update(['status' => SupplierProduct::OVER_LIMIT, 'limit_hidden_at' => now(), 'updated_at' => now()]);
        });

        if ($moved > 0) {
            // Un destacado de un producto que ya no se ve no sigue activo.
            SupplierProduct::where('supplier_id', $supplier->id)->where('status', SupplierProduct::OVER_LIMIT)->get()
                ->each(fn (SupplierProduct $p) => app(FeaturedPlacements::class)->endForProduct($p, 'El producto quedó fuera del límite de publicaciones del plan.'));
            if ($notify) {
                $this->notify->supplier($supplier, 'Algunos productos quedaron fuera del límite',
                    "Tu plan permite {$this->limitFor($supplier)} productos publicados: {$moved} quedaron «fuera del límite». No se borró nada; elegí cuáles publicar desde «Mi catálogo» o pasate a Premium.",
                    '/proveedores/catalogo?estado=over_limit', 'over-limit:'.now()->format('YmdHi'));
            }
        }

        return $moved;
    }

    /**
     * Al restaurar una publicación oculta por moderación: si ya no entra en el
     * límite, queda ella «fuera del límite» (nunca desplaza a otra publicada).
     */
    public function fitRestored(SupplierProduct $product): void
    {
        DB::transaction(function () use ($product) {
            $supplier = Supplier::whereKey($product->supplier_id)->lockForUpdate()->first();
            $others = self::counted($supplier->id)->whereKeyNot($product->id)->count();
            if ($product->status === SupplierProduct::PUBLISHED && $others >= SupplierStanding::publicationLimit($supplier)) {
                $product->forceFill(['status' => SupplierProduct::OVER_LIMIT, 'limit_hidden_at' => now()])->save();
            }
        });
    }

    /** Vuelve a publicar lo que quedó fuera del límite, mientras haya lugar (Premium reactivado). */
    public function restoreOverLimit(Supplier $supplier): int
    {
        return DB::transaction(function () use ($supplier) {
            $locked = Supplier::whereKey($supplier->id)->lockForUpdate()->first();
            $room = SupplierStanding::publicationLimit($locked) - self::counted($locked->id)->count();
            if ($room <= 0) {
                return 0;
            }
            $ids = SupplierProduct::where('supplier_id', $locked->id)->where('status', SupplierProduct::OVER_LIMIT)->where('hidden_by_admin', false)
                ->orderByDesc('keep_on_free')->orderByDesc('published_at')->orderByDesc('id')->limit($room)->pluck('id');

            return SupplierProduct::whereIn('id', $ids)->update(['status' => SupplierProduct::PUBLISHED, 'limit_hidden_at' => null, 'updated_at' => now()]);
        });
    }

    /** «Conservar en el plan gratuito»: como mucho tantas marcas como el límite gratuito. */
    public function setKeep(SupplierProduct $product, bool $keep): void
    {
        DB::transaction(function () use ($product, $keep) {
            Supplier::whereKey($product->supplier_id)->lockForUpdate()->first();
            $free = MarketplaceSettings::current()->freeLimit();
            if ($keep && SupplierProduct::where('supplier_id', $product->supplier_id)->where('keep_on_free', true)->whereKeyNot($product->id)->count() >= $free) {
                throw ValidationException::withMessages(['estado' => "Podés marcar hasta {$free} productos para conservar en el plan gratuito."]);
            }
            $product->forceFill(['keep_on_free' => $keep])->save();
        });
    }

    private function limitFor(Supplier $supplier): int
    {
        return SupplierStanding::publicationLimit($supplier);
    }
}

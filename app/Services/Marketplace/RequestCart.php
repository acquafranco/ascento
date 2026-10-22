<?php

namespace App\Services\Marketplace;

use App\Models\Marketplace\SupplierProduct;
use App\Support\Marketplace\Catalog;
use Illuminate\Support\Collection;

/**
 * Pedido en armado (carrito) de una empresa, en la sesión de quien lo arma:
 * solo ids de producto y cantidades. Precios y proveedor se leen siempre de
 * la base al mostrar y al enviar; nada de eso viene del navegador.
 */
final class RequestCart
{
    public function __construct(private int $companyId) {}

    private function key(): string
    {
        return 'marketplace.cart.'.$this->companyId;
    }

    /** @return array<int, int> producto => cantidad */
    public function all(): array
    {
        return array_filter((array) session($this->key(), []), fn ($q, $id) => is_int($id) && is_int($q) && $q > 0, ARRAY_FILTER_USE_BOTH);
    }

    public function count(): int
    {
        return count($this->all());
    }

    public function add(int $productId, int $quantity = 1): void
    {
        $items = $this->all();
        if (! isset($items[$productId]) && count($items) >= Catalog::MAX_ITEMS) {
            return;
        }
        $items[$productId] = min(Catalog::MAX_QUANTITY, max(1, ($items[$productId] ?? 0) + $quantity));
        session([$this->key() => $items]);
    }

    public function set(int $productId, int $quantity): void
    {
        $items = $this->all();
        if ($quantity < 1) {
            unset($items[$productId]);
        } elseif (isset($items[$productId])) {
            $items[$productId] = min(Catalog::MAX_QUANTITY, $quantity);
        }
        session([$this->key() => $items]);
    }

    /** @param list<int> $productIds */
    public function remove(array $productIds): void
    {
        session([$this->key() => array_diff_key($this->all(), array_flip($productIds))]);
    }

    /**
     * Lo que se puede pedir hoy, agrupado por proveedor (un pedido por proveedor).
     * Lo que dejó de estar visible se descarta.
     *
     * @return Collection<int, Collection<int, array{product: SupplierProduct, quantity: int}>>
     */
    public function groups(): Collection
    {
        $items = $this->all();
        if ($items === []) {
            return collect();
        }

        $products = SupplierProduct::visible()->with('supplier')->whereIn('id', array_keys($items))->get();
        $gone = array_diff(array_keys($items), $products->pluck('id')->all());
        if ($gone !== []) {
            $this->remove($gone);
        }

        return $products->map(fn (SupplierProduct $p) => ['product' => $p, 'quantity' => $items[$p->id]])
            ->groupBy(fn ($row) => $row['product']->supplier_id);
    }
}

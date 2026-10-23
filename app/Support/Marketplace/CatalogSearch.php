<?php

namespace App\Support\Marketplace;

use Illuminate\Database\Eloquent\Builder;

/**
 * Búsqueda y filtros del catálogo de repuestos, compartidos por el catálogo
 * del panel (PartsCatalog) y el catálogo público (/marketplace/repuestos).
 * Se aplica SIEMPRE sobre SupplierProduct::visible(): filtrar nunca agrega
 * productos que un comprador no podría ver. Los valores que no están en las
 * listas fijas (Catalog) se ignoran.
 */
final class CatalogSearch
{
    /** Palabras de la búsqueda: hasta 6, del texto recortado a 120 caracteres. */
    public static function words(?string $q): array
    {
        $q = trim((string) $q);

        return $q === '' ? [] : array_slice(preg_split('/\s+/', trim(mb_substr($q, 0, 120))), 0, 6);
    }

    /**
     * @param  list<string>  $words
     * @param  array{rubro?: ?string, marca?: ?string, disponibilidad?: ?string, precio?: ?string, proveedor?: ?int}  $filters
     */
    public static function apply(Builder $query, array $words, array $filters): Builder
    {
        $like = fn (string $v) => '%'.addcslashes($v, '\\%_').'%';
        $rubro = (string) ($filters['rubro'] ?? '');
        $marca = (string) ($filters['marca'] ?? '');
        $disponibilidad = (string) ($filters['disponibilidad'] ?? '');
        $precio = (string) ($filters['precio'] ?? '');
        $proveedor = $filters['proveedor'] ?? null;

        return $query
            ->when($words !== [], function (Builder $q) use ($like, $words) {
                foreach ($words as $word) {
                    $q->where(fn (Builder $w) => $w->where('supplier_products.name', 'like', $like($word))->orWhere('supplier_products.brand', 'like', $like($word))
                        ->orWhere('supplier_products.model', 'like', $like($word))->orWhere('supplier_products.part_number', 'like', $like($word))
                        ->orWhere('supplier_products.compatibility', 'like', $like($word))->orWhere('supplier_products.description', 'like', $like($word)));
                }
            })
            ->when(isset(Catalog::CATEGORIES[$rubro]), fn (Builder $q) => $q->where('supplier_products.category', $rubro))
            ->when($marca !== '', fn (Builder $q) => $q->where('supplier_products.brand', mb_substr($marca, 0, 120)))
            ->when(isset(Catalog::AVAILABILITY[$disponibilidad]), fn (Builder $q) => $q->where('supplier_products.availability', $disponibilidad))
            ->when(isset(Catalog::PRICE_MODES[$precio]), fn (Builder $q) => $q->where('supplier_products.price_mode', $precio))
            ->when($proveedor, fn (Builder $q) => $q->where('supplier_products.supplier_id', (int) $proveedor));
    }
}

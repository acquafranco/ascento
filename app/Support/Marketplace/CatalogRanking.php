<?php

namespace App\Support\Marketplace;

use App\Models\Marketplace\FeaturedPlacement;
use Illuminate\Database\Eloquent\Builder;

/**
 * Orden del catálogo de repuestos. Pagar no gana sobre la relevancia:
 *
 *   1. Relevancia: cuántas palabras buscadas aparecen en nombre, modelo,
 *      código o marca (el filtro ya exige que TODAS aparezcan en algún campo).
 *   2. Compatibilidad declarada: cuántas aparecen en la compatibilidad que
 *      declaró el proveedor (sin búsqueda: si declaró alguna).
 *   3. Disponibilidad declarada: en stock, a pedido, sin disponibilidad.
 *   4. Visibilidad contratada: un destacado vigente va antes SOLO entre
 *      productos iguales en 1, 2 y 3.
 *   5. Rotación entre equivalentes: estable durante el día para cada empresa
 *      (el «ver más» no repite ni saltea) y distinta al día siguiente.
 *
 * Todo sale del servidor: el navegador solo manda el texto y los filtros, no
 * puede pedir otro orden ni otra semilla. Los destacados se miran en el
 * historial (vigentes ahora), no en una marca que pudiera quedar vieja.
 */
final class CatalogRanking
{
    /** @param  list<string>  $words  palabras de la búsqueda (ya limitadas) */
    public static function apply(Builder $query, array $words, int $companyId): Builder
    {
        $like = fn (string $w) => '%'.addcslashes($w, '\\%_').'%';
        $strong = [];
        $compat = [];
        $strongBindings = [];
        $compatBindings = [];
        foreach ($words as $word) {
            $strong[] = '(CASE WHEN supplier_products.name LIKE ? OR supplier_products.model LIKE ? OR supplier_products.part_number LIKE ? OR supplier_products.brand LIKE ? THEN 1 ELSE 0 END)';
            array_push($strongBindings, $like($word), $like($word), $like($word), $like($word));
            $compat[] = '(CASE WHEN supplier_products.compatibility LIKE ? THEN 1 ELSE 0 END)';
            $compatBindings[] = $like($word);
        }

        $compatibility = $compat === [] ? '(CASE WHEN supplier_products.compatibility IS NOT NULL AND supplier_products.compatibility <> \'\' THEN 1 ELSE 0 END)' : '('.implode(' + ', $compat).')';
        $featured = '(CASE WHEN EXISTS (SELECT 1 FROM featured_placements fp WHERE fp.supplier_product_id = supplier_products.id AND fp.status = ? AND fp.starts_at <= ? AND fp.ends_at > ?) THEN 1 ELSE 0 END)';
        $now = now();
        $seed = crc32(now()->toDateString().'|'.$companyId) % 10007;

        $query->select('supplier_products.*')->selectRaw($featured.' as featured_now', [FeaturedPlacement::ACTIVE, $now, $now]);
        if ($strong !== []) {
            $query->orderByRaw('('.implode(' + ', $strong).') DESC', $strongBindings);
        }

        return $query
            ->orderByRaw($compatibility.' DESC', $compatBindings)
            ->orderByRaw("CASE supplier_products.availability WHEN 'in_stock' THEN 0 WHEN 'to_order' THEN 1 ELSE 2 END")
            ->orderByRaw($featured.' DESC', [FeaturedPlacement::ACTIVE, $now, $now])
            ->orderByRaw('((supplier_products.id * 7919 + ?) % 10007)', [$seed])
            ->orderBy('supplier_products.id');
    }
}

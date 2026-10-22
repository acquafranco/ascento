<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Destacados asignados por el SuperAdmin ANTES del historial de destacados
 * (PR #117: solo se guardaba supplier_products.featured_until). Desde
 * 2027_01_18 el catálogo lee el historial (featured_placements), así que esos
 * destacados vigentes dejarían de verse como tales. Esta migración los pasa al
 * historial como «asignado por Ascento», sin cobro y con la misma fecha de fin.
 *
 * - Solo los vigentes (featured_until futuro) y sin un destacado activo ya registrado.
 * - No cambia productos, precios ni pagos. Se puede correr más de una vez.
 * - down() quita solo las filas que creó (marcadas en status_reason).
 */
return new class extends Migration
{
    private const MARK = 'Destacado asignado antes del historial (migrado).';

    public function up(): void
    {
        $now = now();
        DB::table('supplier_products')->whereNotNull('featured_until')->where('featured_until', '>', $now)
            ->whereNotExists(fn ($q) => $q->from('featured_placements')->whereColumn('featured_placements.supplier_product_id', 'supplier_products.id')->where('featured_placements.status', 'active'))
            ->orderBy('id')->get(['id', 'supplier_id', 'name', 'featured_until', 'updated_at'])
            ->each(function ($p) use ($now) {
                $start = $p->updated_at && $p->updated_at < $p->featured_until ? $p->updated_at : $now;
                DB::table('featured_placements')->insert([
                    'supplier_id' => $p->supplier_id,
                    'supplier_product_id' => $p->id,
                    'product_name' => mb_substr((string) $p->name, 0, 160),
                    'source' => 'admin',
                    'days' => max(1, (int) ceil(Carbon::parse($start)->diffInDays(Carbon::parse($p->featured_until)))),
                    'status' => 'active',
                    'starts_at' => $start,
                    'ends_at' => $p->featured_until,
                    'status_reason' => self::MARK,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        DB::table('featured_placements')->where('source', 'admin')->where('status_reason', self::MARK)->delete();
    }
};

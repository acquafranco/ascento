<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Nuevos topes de edificios: Profesional 70 → 120 y Empresa 300 → 400
 * (Inicial sigue en 20). Solo cambia `max_buildings` de esos planes: precios,
 * funciones, prueba gratis y los demás límites quedan igual. Las empresas
 * suscriptas leen el límite del plan en cada pedido, así que reciben el nuevo
 * tope sin tocar su suscripción (importe, vencimiento ni estado).
 *
 * Solo se actualiza si el plan todavía tiene el valor anterior: un tope
 * cambiado a mano no se pisa. La reversión vuelve al valor anterior solo si
 * sigue el nuevo.
 */
return new class extends Migration
{
    private const CHANGES = [
        'profesional' => [70, 120],
        'empresa' => [300, 400],
    ];

    public function up(): void
    {
        foreach (self::CHANGES as $slug => [$old, $new]) {
            DB::table('subscription_plans')->where('slug', $slug)->where('max_buildings', $old)->update(['max_buildings' => $new, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (self::CHANGES as $slug => [$old, $new]) {
            DB::table('subscription_plans')->where('slug', $slug)->where('max_buildings', $new)->update(['max_buildings' => $old, 'updated_at' => now()]);
        }
    }
};

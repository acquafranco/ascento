<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las suscripciones que venían del plan anterior de $149.000 (marcadas con
 * legacy_plan) pasan de Empresa a Profesional ($119.000).
 *
 * - Solo se tocan esas filas; el resto de los datos de la empresa no cambia.
 * - El importe se actualiza a $119.000 únicamente donde Mercado Pago NO está
 *   cobrando (pendientes, canceladas, manuales, etc.). Si alguna estuviera
 *   cobrando, conserva su importe: cambiarlo exige avisarle a Mercado Pago
 *   (desde "Cambiar de plan").
 */
return new class extends Migration
{
    private const PRICE = 119000;

    public function up(): void
    {
        $legacy = fn () => DB::table('subscriptions')
            ->whereNotNull('legacy_plan')
            ->where('plan', 'empresa');

        $legacy()
            ->where(fn ($q) => $q->where('provider', '!=', 'mercadopago')
                ->orWhereNotIn('status', ['authorized', 'past_due']))
            ->update(['amount' => self::PRICE, 'updated_at' => now()]);

        $legacy()->update(['plan' => 'profesional', 'updated_at' => now()]);
    }

    public function down(): void
    {
        $legacy = fn () => DB::table('subscriptions')
            ->whereNotNull('legacy_plan')
            ->where('plan', 'profesional');

        $legacy()
            ->where('legacy_plan', 'professional')
            ->where('amount', self::PRICE)
            ->update(['amount' => 149000]);

        $legacy()->update(['plan' => 'empresa']);
    }
};

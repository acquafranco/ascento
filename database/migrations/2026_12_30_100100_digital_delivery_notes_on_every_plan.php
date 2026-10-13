<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Regla comercial: los remitos digitales (PDF, enlace, WhatsApp o correo
 * para el cliente) están en TODOS los planes. Hasta ahora Inicial solo
 * firmaba el remito; desde acá también lo comparte. Solo suma la clave: no
 * cambia precios, límites ni otras funciones. Al revertir, se quita solo de
 * los planes que no la tenían (Profesional, Empresa y el histórico
 * «professional» ya la tenían).
 */
return new class extends Migration
{
    private const FEATURE = 'digital_delivery_notes';

    private const HAD_IT = ['profesional', 'empresa', 'professional'];

    public function up(): void
    {
        DB::table('subscription_plans')->get(['id', 'feature_keys'])->each(function ($plan) {
            $keys = json_decode((string) $plan->feature_keys, true) ?: [];

            DB::table('subscription_plans')->where('id', $plan->id)->update([
                'feature_keys' => json_encode(array_values(array_unique([...$keys, self::FEATURE]))),
            ]);
        });
    }

    public function down(): void
    {
        DB::table('subscription_plans')->whereNotIn('slug', self::HAD_IT)->get(['id', 'feature_keys'])->each(function ($plan) {
            $keys = json_decode((string) $plan->feature_keys, true) ?: [];

            DB::table('subscription_plans')->where('id', $plan->id)->update([
                'feature_keys' => json_encode(array_values(array_diff($keys, [self::FEATURE]))),
            ]);
        });
    }
};

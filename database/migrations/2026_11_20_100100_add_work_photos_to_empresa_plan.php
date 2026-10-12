<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las funciones fotográficas de la Etapa 5 (fotos de antes y después de
 * mantenimientos y órdenes, reporte de inspección con fotos y su consulta
 * en el panel) son exclusivas del plan Empresa. Solo SUMA la clave a ese
 * plan: no cambia precios, límites ni lo que cada plan ya tenía, y no
 * borra fotos, reportes ni los interruptores de «Mi empresa» (con otro
 * plan quedan guardados; vuelven a estar disponibles al pasar a Empresa).
 */
return new class extends Migration
{
    private const FEATURE = 'work_photos';

    public function up(): void
    {
        DB::table('subscription_plans')->where('slug', 'empresa')->get(['id', 'feature_keys'])->each(function ($plan) {
            $keys = json_decode((string) $plan->feature_keys, true) ?: [];

            DB::table('subscription_plans')->where('id', $plan->id)->update([
                'feature_keys' => json_encode(array_values(array_unique([...$keys, self::FEATURE]))),
            ]);
        });
    }

    public function down(): void
    {
        DB::table('subscription_plans')->get(['id', 'feature_keys'])->each(function ($plan) {
            $keys = json_decode((string) $plan->feature_keys, true) ?: [];

            DB::table('subscription_plans')->where('id', $plan->id)->update([
                'feature_keys' => json_encode(array_values(array_diff($keys, [self::FEATURE]))),
            ]);
        });
    }
};

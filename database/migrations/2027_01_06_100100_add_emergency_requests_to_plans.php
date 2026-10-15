<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Solicitud de ayuda por atrapamiento desde el QR: en Profesional, Empresa y
 * el histórico «professional» (no en Inicial). Solo SUMA la clave: no cambia
 * precios, límites ni lo que cada plan ya tenía.
 */
return new class extends Migration
{
    private const KEY = 'emergency_requests';

    private const SLUGS = ['profesional', 'empresa', 'professional'];

    public function up(): void
    {
        DB::table('subscription_plans')->whereIn('slug', self::SLUGS)->get(['id', 'feature_keys'])->each(function ($plan) {
            $keys = json_decode((string) $plan->feature_keys, true) ?: [];

            if (! in_array(self::KEY, $keys, true)) {
                DB::table('subscription_plans')->where('id', $plan->id)->update(['feature_keys' => json_encode([...$keys, self::KEY])]);
            }
        });
    }

    public function down(): void
    {
        DB::table('subscription_plans')->get(['id', 'feature_keys'])->each(function ($plan) {
            $keys = json_decode((string) $plan->feature_keys, true) ?: [];

            DB::table('subscription_plans')->where('id', $plan->id)->update(['feature_keys' => json_encode(array_values(array_diff($keys, [self::KEY])))]);
        });
    }
};

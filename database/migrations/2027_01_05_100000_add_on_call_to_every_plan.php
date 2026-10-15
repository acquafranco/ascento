<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Guardias técnicas: incluidas en todos los planes, sin costo adicional.
 * Solo SUMA la clave a cada plan (también a los históricos): no cambia
 * precios, límites ni lo que cada plan ya tenía.
 */
return new class extends Migration
{
    private const KEY = 'on_call';

    public function up(): void
    {
        DB::table('subscription_plans')->get(['id', 'feature_keys'])->each(function ($plan) {
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

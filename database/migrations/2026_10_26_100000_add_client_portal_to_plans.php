<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El portal para clientes pasa a ser de Profesional y Empresa (y del plan
 * histórico "professional"). Solo SUMA la clave: no cambia precios, límites
 * ni lo que cada plan ya tenía. Inicial no la recibe: sus empresas no pueden
 * crear accesos al portal y los usuarios de portal existentes no entran
 * (EnsurePortalUser) hasta que la empresa tenga un plan que lo incluya.
 */
return new class extends Migration
{
    private const SLUGS = ['profesional', 'empresa', 'professional'];

    public function up(): void
    {
        DB::table('subscription_plans')->whereIn('slug', self::SLUGS)->get(['id', 'feature_keys'])->each(function ($plan) {
            $keys = json_decode((string) $plan->feature_keys, true) ?: [];

            DB::table('subscription_plans')->where('id', $plan->id)->update([
                'feature_keys' => json_encode(array_values(array_unique([...$keys, 'client_portal']))),
            ]);
        });
    }

    public function down(): void
    {
        DB::table('subscription_plans')->get(['id', 'feature_keys'])->each(function ($plan) {
            $keys = json_decode((string) $plan->feature_keys, true) ?: [];

            DB::table('subscription_plans')->where('id', $plan->id)->update([
                'feature_keys' => json_encode(array_values(array_diff($keys, ['client_portal']))),
            ]);
        });
    }
};

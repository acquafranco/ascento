<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Etapa 9.2: las funciones de las Etapas 6 a 8 pasan a declararse en los
 * planes, para controlarlas desde el plan (y que el SuperAdmin pueda
 * administrarlas). Solo SUMA claves: no cambia precios, límites ni lo que
 * cada plan ya tenía.
 *
 * - En todos los planes (como hasta ahora): tareas, QR público, pruebas de
 *   paracaídas y reparaciones.
 * - Profesional, Empresa y el histórico «professional»: historial de
 *   contactos con clientes (Inicial deja de verlo; sus datos se conservan).
 */
return new class extends Migration
{
    private const EVERY_PLAN = ['tasks', 'public_qr', 'parachute_tests', 'repairs'];

    private const CONTACTS = 'client_contacts';

    private const CONTACT_SLUGS = ['profesional', 'empresa', 'professional'];

    public function up(): void
    {
        DB::table('subscription_plans')->get(['id', 'slug', 'feature_keys'])->each(function ($plan) {
            $keys = json_decode((string) $plan->feature_keys, true) ?: [];
            $add = in_array($plan->slug, self::CONTACT_SLUGS, true) ? [...self::EVERY_PLAN, self::CONTACTS] : self::EVERY_PLAN;

            DB::table('subscription_plans')->where('id', $plan->id)->update([
                'feature_keys' => json_encode(array_values(array_unique([...$keys, ...$add]))),
            ]);
        });
    }

    public function down(): void
    {
        DB::table('subscription_plans')->get(['id', 'feature_keys'])->each(function ($plan) {
            $keys = json_decode((string) $plan->feature_keys, true) ?: [];

            DB::table('subscription_plans')->where('id', $plan->id)->update([
                'feature_keys' => json_encode(array_values(array_diff($keys, [...self::EVERY_PLAN, self::CONTACTS]))),
            ]);
        });
    }
};

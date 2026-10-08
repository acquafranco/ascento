<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tres planes con límites reales (Inicial, Profesional, Empresa).
 *
 * NO destructiva:
 * - El plan anterior ("professional", $149.000) queda en la tabla, inactivo,
 *   para el historial.
 * - Las suscripciones que tenían ese plan (u otro que ya no existe) pasan a
 *   "empresa" — el plan más completo, así nadie pierde funciones — y
 *   CONSERVAN su importe: Mercado Pago les sigue cobrando lo mismo. El slug
 *   anterior queda guardado en subscriptions.legacy_plan.
 */
return new class extends Migration
{
    public const PLANS = [
        'inicial' => [
            'name' => 'Ascento Inicial',
            'description' => 'Para empresas chicas que quieren ordenar su operación.',
            'price' => 69000,
            'max_buildings' => 20,
            'max_clients' => 50,
            'max_technicians' => 3,
            'max_reports_per_month' => 15,
            'is_recommended' => false,
            'sort_order' => 1,
            'feature_keys' => ['buildings', 'clients', 'technicians', 'maintenances', 'inspections', 'work_orders', 'reports', 'history', 'map'],
        ],
        'profesional' => [
            'name' => 'Ascento Profesional',
            'description' => 'Todo lo que necesita una empresa de mantenimiento en crecimiento.',
            'price' => 119000,
            'max_buildings' => 70,
            'max_clients' => 150,
            'max_technicians' => 10,
            'max_reports_per_month' => null,
            'is_recommended' => true,
            'sort_order' => 2,
            'feature_keys' => ['buildings', 'clients', 'technicians', 'maintenances', 'inspections', 'work_orders', 'reports', 'history', 'map', 'quotes', 'digital_delivery_notes'],
        ],
        'empresa' => [
            'name' => 'Ascento Empresa',
            'description' => 'Para empresas con una operación grande.',
            'price' => 169000,
            'max_buildings' => 300,
            'max_clients' => 420,
            'max_technicians' => 25,
            'max_reports_per_month' => null,
            'is_recommended' => false,
            'sort_order' => 3,
            'feature_keys' => ['buildings', 'clients', 'technicians', 'maintenances', 'inspections', 'work_orders', 'reports', 'history', 'map', 'quotes', 'digital_delivery_notes'],
        ],
    ];

    /** Plan al que pasan las suscripciones con un plan que ya no existe. */
    public const LEGACY_TARGET = 'empresa';

    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->string('description')->nullable()->after('slug');
            $table->unsignedInteger('max_buildings')->nullable();
            $table->unsignedInteger('max_clients')->nullable();
            $table->unsignedInteger('max_technicians')->nullable();
            $table->unsignedInteger('max_reports_per_month')->nullable();
            $table->json('feature_keys')->nullable();
            $table->boolean('is_recommended')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('legacy_plan')->nullable()->after('plan');
            // Al cambiar de plan, un cobro en curso puede venir con el importe
            // anterior: se acepta durante un ciclo (ver MercadoPagoSubscriptionSync).
            $table->decimal('previous_amount', 12, 2)->nullable()->after('amount');
            $table->timestamp('amount_changed_at')->nullable()->after('previous_amount');
        });

        $now = now();

        foreach (self::PLANS as $slug => $plan) {
            $values = [
                ...$plan,
                'currency' => 'ARS',
                'is_active' => true,
                'features' => json_encode([]),
                'feature_keys' => json_encode($plan['feature_keys']),
                'updated_at' => $now,
            ];

            if (DB::table('subscription_plans')->where('slug', $slug)->exists()) {
                DB::table('subscription_plans')->where('slug', $slug)->update($values);
            } else {
                DB::table('subscription_plans')->insert([...$values, 'slug' => $slug, 'created_at' => $now]);
            }
        }

        // El resto de los planes (incluido "professional" de $149.000) queda
        // inactivo, sin borrarse.
        DB::table('subscription_plans')
            ->whereNotIn('slug', array_keys(self::PLANS))
            ->update(['is_active' => false, 'updated_at' => $now]);

        // Suscripciones con un plan que ya no existe → Empresa, mismo importe.
        DB::table('subscriptions')
            ->where(fn ($q) => $q->whereNull('plan')->orWhereNotIn('plan', array_keys(self::PLANS)))
            ->update([
                'legacy_plan' => DB::raw('plan'),
                'plan' => self::LEGACY_TARGET,
                'updated_at' => $now,
            ]);
    }

    public function down(): void
    {
        // Devuelve a cada suscripción su plan anterior.
        DB::table('subscriptions')
            ->whereNotNull('legacy_plan')
            ->update(['plan' => DB::raw('legacy_plan')]);

        DB::table('subscription_plans')->where('slug', 'professional')->update(['is_active' => true]);

        // Los planes nuevos solo se borran si ninguna suscripción los usa.
        foreach (array_keys(self::PLANS) as $slug) {
            if (! DB::table('subscriptions')->where('plan', $slug)->exists()) {
                DB::table('subscription_plans')->where('slug', $slug)->delete();
            } else {
                DB::table('subscription_plans')->where('slug', $slug)->update(['is_active' => false]);
            }
        }

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['legacy_plan', 'previous_amount', 'amount_changed_at']);
        });

        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn([
                'description', 'max_buildings', 'max_clients', 'max_technicians',
                'max_reports_per_month', 'feature_keys', 'is_recommended', 'sort_order',
            ]);
        });
    }
};

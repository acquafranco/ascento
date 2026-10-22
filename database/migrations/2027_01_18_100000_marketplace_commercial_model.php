<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marketplace de repuestos: modelo comercial de los proveedores.
 *
 * - marketplace_settings: una fila que edita el SuperAdmin (duración de la
 *   prueba, precio de la suscripción, precios de destacados, cobros
 *   habilitados, modalidad gratuita fuera de Argentina). Sin precios cargados:
 *   nada se cobra ni se anuncia.
 * - suppliers: prueba (empieza UNA vez, al aprobarse), moneda (según el país
 *   registrado), notas administrativas y el último estado comercial avisado.
 * - supplier_subscriptions / supplier_payments: suscripción de proveedores y
 *   cada cobro confirmado por Mercado Pago (aparte de las de empresas).
 * - featured_placements: historial de publicaciones destacadas (compradas o
 *   asignadas por el SuperAdmin).
 * - marketplace_requests: el historial deja de borrarse en cascada al eliminar
 *   la empresa compradora o el proveedor (es también historial de la otra parte);
 *   avisos de vencimiento de cotizaciones sin duplicar.
 *
 * Datos existentes: no se borra ni se cambia nada. Los proveedores ya
 * aprobados empiezan su prueba al aplicar esta migración (no se pierde ningún día).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('trial_days')->default(30);
            // Cobros a proveedores argentinos: hasta habilitarlos (con precio), nadie queda bloqueado por no pagar.
            $table->boolean('billing_enabled')->default(false);
            $table->decimal('plan_price', 12, 2)->nullable();
            $table->char('plan_currency', 3)->default('ARS');
            // Proveedores de otros países: sin cobro posible en esta etapa.
            $table->boolean('international_free')->default(true);
            $table->boolean('featured_enabled')->default(false);
            $table->decimal('featured_price_7', 12, 2)->nullable();
            $table->decimal('featured_price_30', 12, 2)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->char('currency', 3)->default('ARS')->after('country');
            $table->timestamp('trial_started_at')->nullable()->after('approved_at');
            $table->timestamp('trial_ends_at')->nullable()->after('trial_started_at');
            $table->text('admin_notes')->nullable();
            // Último estado comercial avisado (para avisar cada cambio una sola vez).
            $table->string('commercial_state', 24)->nullable();
            $table->timestamp('commercial_state_at')->nullable();
            $table->timestamp('closed_at')->nullable();
        });

        Schema::create('supplier_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->unique()->constrained()->restrictOnDelete();
            $table->string('provider', 30)->default('mercadopago');
            $table->string('provider_subscription_id')->nullable()->unique();
            $table->string('external_reference', 80);
            $table->string('payer_email')->nullable();
            $table->string('checkout_url', 512)->nullable();
            $table->string('status', 20)->default('pending'); // pending | authorized | past_due | paused | canceled
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3);
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('next_payment_at')->nullable();
            $table->string('last_payment_status', 30)->nullable();
            $table->timestamp('last_payment_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'current_period_end']);
        });

        Schema::create('featured_placements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name', 160); // copia: el historial se entiende aunque el producto ya no exista
            $table->string('source', 12); // purchase | admin
            $table->unsignedSmallInteger('days');
            $table->decimal('amount', 12, 2)->nullable();
            $table->char('currency', 3)->nullable();
            // pending_payment | active | expired | cancelled | needs_review (pagado pero el producto ya no era elegible)
            $table->string('status', 16);
            $table->string('external_reference', 80)->nullable()->unique();
            $table->string('checkout_url', 512)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('status_reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ending_notified_at')->nullable();
            $table->timestamp('ended_notified_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'ends_at']);
            $table->index(['supplier_product_id', 'status']);
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('kind', 12); // subscription | featured
            $table->foreignId('supplier_subscription_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('featured_placement_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('provider', 30)->default('mercadopago');
            // Cuota (authorized_payment) o pago único de Mercado Pago: único, un aviso repetido no suma dos veces.
            $table->string('provider_payment_id')->unique();
            $table->string('mp_payment_id')->nullable();
            $table->string('status', 20); // approved | pending | rejected | amount_mismatch | refunded
            $table->string('status_detail')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamps();
            $table->index(['status', 'paid_at']);
        });

        Schema::table('marketplace_requests', function (Blueprint $table) {
            $table->timestamp('expiring_notified_at')->nullable();
            $table->timestamp('expired_notified_at')->nullable();
            // Nombre de la empresa compradora al pedir: el proveedor conserva su historial aunque la empresa se elimine.
            $table->string('buyer_name', 160)->nullable();
            // Incidencia que una de las partes reportó al equipo de Ascento (la resuelve el SuperAdmin).
            $table->timestamp('incident_at')->nullable();
            $table->timestamp('incident_resolved_at')->nullable();
        });

        Schema::table('marketplace_request_events', function (Blueprint $table) {
            $table->string('kind', 8)->default('status'); // status | note | incident | resolved
        });

        // El historial de pedidos es de las dos partes: borrar una no lo borra para la otra.
        Schema::table('marketplace_requests', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropForeign(['supplier_id']);
        });
        Schema::table('marketplace_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->change();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->foreign('supplier_id')->references('id')->on('suppliers')->restrictOnDelete();
        });

        DB::table('marketplace_requests')->whereNull('buyer_name')->orderBy('id')->each(function ($row) {
            DB::table('marketplace_requests')->where('id', $row->id)->update(['buyer_name' => DB::table('companies')->where('id', $row->company_id)->value('name')]);
        });

        // Moneda de cada proveedor existente según su país registrado (los precios ya cargados no cambian de número).
        foreach ((array) config('marketplace.currencies', []) as $country => $currency) {
            DB::table('suppliers')->where('country', $country)->update(['currency' => $currency]);
            DB::table('supplier_products')->whereIn('supplier_id', DB::table('suppliers')->where('country', $country)->select('id'))->update(['currency' => $currency]);
        }

        // Proveedores ya aprobados: la prueba empieza hoy (una sola vez).
        DB::table('suppliers')->where('status', 'approved')->whereNull('trial_ends_at')->update([
            'trial_started_at' => now(),
            'trial_ends_at' => now()->addDays(30),
        ]);
    }

    public function down(): void
    {
        Schema::table('marketplace_requests', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropForeign(['supplier_id']);
        });
        Schema::table('marketplace_requests', function (Blueprint $table) {
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('supplier_id')->references('id')->on('suppliers')->cascadeOnDelete();
            $table->dropColumn(['expiring_notified_at', 'expired_notified_at', 'buyer_name', 'incident_at', 'incident_resolved_at']);
        });
        Schema::table('marketplace_request_events', fn (Blueprint $table) => $table->dropColumn('kind'));
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('featured_placements');
        Schema::dropIfExists('supplier_subscriptions');
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn(['currency', 'trial_started_at', 'trial_ends_at', 'admin_notes', 'commercial_state', 'commercial_state_at', 'closed_at']);
        });
        Schema::dropIfExists('marketplace_settings');
    }
};

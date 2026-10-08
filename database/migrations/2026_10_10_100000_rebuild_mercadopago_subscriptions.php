<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suscripción mensual real con Mercado Pago.
 *
 * - subscriptions: datos que hacen falta para decidir el acceso según lo
 *   que REALMENTE cobró Mercado Pago (período pago, último cobro, etc.).
 * - subscription_payments: cada cuota (authorized payment) y su resultado.
 * - webhook_events: auditoría de cada notificación recibida.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('payer_email')->nullable()->after('external_reference');
            $table->string('checkout_url', 512)->nullable()->after('payer_email');
            $table->timestamp('authorized_at')->nullable()->after('trial_ends_at');
            $table->timestamp('next_payment_at')->nullable()->after('current_period_end');
            $table->string('last_payment_status', 30)->nullable()->after('next_payment_at');
            $table->timestamp('last_payment_at')->nullable()->after('last_payment_status');
            $table->timestamp('last_synced_at')->nullable()->after('cancel_at_period_end');
        });

        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('provider')->default('mercadopago');

            // Id de la cuota en Mercado Pago (authorized_payment): único, así
            // un webhook repetido nunca suma dos veces el mismo mes.
            $table->string('provider_payment_id')->unique();
            $table->string('provider_preapproval_id')->index();
            $table->string('mp_payment_id')->nullable();

            $table->string('status', 30);          // approved | rejected | pending | amount_mismatch
            $table->string('status_detail')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();

            $table->timestamps();
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30);
            $table->string('topic', 60)->nullable();
            $table->string('resource_id')->nullable()->index();
            $table->string('request_id')->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->string('result', 60)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('subscription_payments');

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn([
                'payer_email',
                'checkout_url',
                'authorized_at',
                'next_payment_at',
                'last_payment_status',
                'last_payment_at',
                'last_synced_at',
            ]);
        });
    }
};

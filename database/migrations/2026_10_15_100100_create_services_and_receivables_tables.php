<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Servicios de mantenimiento (contratos) y cobranzas internas (cuenta
 * corriente). Sin facturación ni contabilidad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('building_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('description');
            $table->decimal('amount', 12, 2);
            $table->string('frequency', 20)->default('monthly');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status', 20)->default('active'); // active | paused | finished
            $table->unsignedTinyInteger('payment_due_day')->default(10);
            // Desde qué período se generan cobros (ver ServiceBillingService).
            $table->date('billing_from');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
        });

        Schema::create('receivables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('building_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('maintenance_service_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('source', 20); // service | quote | manual
            $table->string('concept');
            $table->date('period_start')->nullable();
            $table->decimal('amount', 12, 2);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->date('due_date');
            $table->string('status', 20)->default('pending'); // pending | partial | paid | void
            $table->text('notes')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Un período de un servicio = una sola obligación.
            $table->unique(['maintenance_service_id', 'period_start']);
            $table->index(['company_id', 'status', 'due_date']);
        });

        Schema::create('receivable_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('receivable_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('paid_at');
            $table->string('method', 20); // cash | transfer | check | other
            $table->string('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receivable_payments');
        Schema::dropIfExists('receivables');
        Schema::dropIfExists('maintenance_services');
    }
};

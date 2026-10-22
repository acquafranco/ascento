<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calculadora comercial (Welcome):
 * - implementation_rates: tarifas del servicio de implementación inicial, una
 *   sola fila que edita el SuperAdmin. Todo vacío al empezar: mientras no se
 *   publiquen, el presupuesto queda «pendiente de confirmación».
 * - sales_leads: pedidos de implementación o de propuesta personalizada que
 *   deja un visitante (con los datos que eligió compartir).
 * - companies.intended_plan: el plan que eligió en la calculadora antes de
 *   registrarse (para preseleccionarlo en «Mi suscripción»; no cambia la prueba).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('implementation_rates', function (Blueprint $table) {
            $table->id();
            $table->char('currency', 3)->default('ARS');
            $table->decimal('base_fee', 12, 2)->nullable();
            $table->unsignedInteger('included_clients')->default(0);
            $table->unsignedInteger('included_buildings')->default(0);
            $table->unsignedInteger('included_elevators')->default(0);
            $table->decimal('per_client', 12, 2)->nullable();
            $table->decimal('per_building', 12, 2)->nullable();
            $table->decimal('per_elevator', 12, 2)->nullable();
            // Factor sobre la parte variable según el estado de los datos (1 = sin recargo).
            $table->decimal('factor_tidy', 4, 2)->default(1);
            $table->decimal('factor_mixed', 4, 2)->default(1.25);
            $table->decimal('factor_paper', 4, 2)->default(1.5);
            $table->decimal('training_fee', 12, 2)->nullable();
            $table->decimal('launch_support_fee', 12, 2)->nullable();
            // Por encima de este total, propuesta personalizada en vez de precio.
            $table->decimal('max_quote', 12, 2)->nullable();
            // Mientras sea false, la calculadora no muestra importes: «pendiente de confirmación».
            $table->boolean('published')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('sales_leads', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16); // implementation | custom
            $table->string('status', 16)->default('new'); // new | contacted | closed
            $table->json('inputs');      // edificios, ascensores, clientes, técnicos, datos, opcionales
            $table->string('plan_slug', 40)->nullable();
            $table->json('quote')->nullable(); // el presupuesto que vio la persona (o «pendiente»)
            $table->string('name', 120);
            $table->string('email', 255);
            $table->string('phone', 40)->nullable();
            $table->string('company_name', 160)->nullable();
            $table->text('message')->nullable();
            $table->string('idempotency_key', 64)->unique();
            $table->string('ip_hash', 64)->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->string('intended_plan', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('intended_plan');
        });
        Schema::dropIfExists('sales_leads');
        Schema::dropIfExists('implementation_rates');
    }
};

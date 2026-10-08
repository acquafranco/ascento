<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock: catálogo de materiales, historial de movimientos (inmutable) y
 * materiales usados en órdenes de trabajo. Ver docs/stock-servicios-cobranzas.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 60)->nullable();
            $table->text('description')->nullable();
            $table->string('unit', 20)->default('unidad');
            $table->decimal('cost', 12, 2)->default(0);
            // Se mantiene solo desde StockService (con lock). Puede quedar negativo.
            $table->decimal('current_stock', 12, 2)->default(0);
            $table->decimal('min_stock', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
        });

        Schema::create('work_order_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_item_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 2);
            $table->decimal('unit_cost', 12, 2)->default(0);
            // Movimiento de salida que generó (null = todavía no descontado).
            $table->unsignedBigInteger('stock_movement_id')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained()->restrictOnDelete();
            $table->string('type', 20); // in | out | adjustment
            // Variación con signo (+ entra, - sale) y saldo después del movimiento.
            $table->decimal('quantity', 12, 2);
            $table->decimal('balance_after', 12, 2);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('work_order_id')->nullable()->constrained()->nullOnDelete();
            // UNIQUE: un renglón de orden nunca genera dos descuentos.
            $table->foreignId('work_order_material_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['company_id', 'occurred_at']);
        });

        Schema::table('work_order_materials', function (Blueprint $table) {
            $table->foreign('stock_movement_id')->references('id')->on('stock_movements')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_order_materials', function (Blueprint $table) {
            $table->dropForeign(['stock_movement_id']);
        });

        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('work_order_materials');
        Schema::dropIfExists('stock_items');
    }
};

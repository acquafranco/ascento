<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de servicios reutilizables de cada empresa y, en cada contratación
 * (maintenance_services), de qué servicio del catálogo viene y cómo se
 * calculó su importe. Solo agrega: las contrataciones existentes quedan como
 * estaban (sin catálogo = «precio acordado», el comportamiento de siempre).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('service_catalog_items')) {
            Schema::create('service_catalog_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('category', 30);
                $table->string('pricing_mode', 30);
                // Precio de referencia: se copia al contratar; cambiarlo no toca contratos existentes.
                $table->decimal('base_price', 12, 2)->nullable();
                // Frecuencia de COBRO sugerida (no la de visitas, que es del edificio).
                $table->string('billing_frequency', 20)->default('monthly');
                // Equipos que cuenta (por equipo / por parada): todos, solo ascensores o solo montacargas.
                $table->string('equipment_kind', 20)->nullable();
                $table->json('includes')->nullable();
                $table->text('notes')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['company_id', 'is_active']);
            });
        }

        Schema::table('maintenance_services', function (Blueprint $table) {
            if (! Schema::hasColumn('maintenance_services', 'catalog_item_id')) {
                $table->foreignId('catalog_item_id')->nullable()->after('building_id')->constrained('service_catalog_items')->nullOnDelete();
            }
            if (! Schema::hasColumn('maintenance_services', 'category')) {
                // null = contratos anteriores al catálogo: se tratan como mantenimiento (como siempre).
                $table->string('category', 30)->nullable()->after('catalog_item_id');
            }
            if (! Schema::hasColumn('maintenance_services', 'pricing_mode')) {
                $table->string('pricing_mode', 30)->nullable()->after('category');
            }
            if (! Schema::hasColumn('maintenance_services', 'unit_price')) {
                $table->decimal('unit_price', 12, 2)->nullable()->after('amount');
            }
            if (! Schema::hasColumn('maintenance_services', 'quantity')) {
                $table->unsignedInteger('quantity')->nullable()->after('unit_price');
            }
            if (! Schema::hasColumn('maintenance_services', 'pricing_detail')) {
                // Cómo se calculó el importe al contratar o editar (líneas y explicación).
                $table->json('pricing_detail')->nullable()->after('quantity');
            }
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_services', function (Blueprint $table) {
            if (Schema::hasColumn('maintenance_services', 'catalog_item_id')) {
                $table->dropConstrainedForeignId('catalog_item_id');
            }
            foreach (['category', 'pricing_mode', 'unit_price', 'quantity', 'pricing_detail'] as $column) {
                if (Schema::hasColumn('maintenance_services', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('service_catalog_items');
    }
};

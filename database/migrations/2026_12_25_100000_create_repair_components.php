<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 8.4: componentes enviados a reparación (variadores, motores, placas,
 * controles y otros) y sus documentos o fotos. La empresa propietaria es la
 * del registro (company_id); el cliente sale del edificio, no se copia.
 * Ascensor y orden de trabajo, opcionales: sin clave foránea al ascensor
 * (como tasks y parachute_tests), lo valida el servicio.
 *
 * building_public_pages.show_repairs: bloque opcional del QR, apagado.
 * Solo agrega; se puede revertir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('repair_components')) {
            Schema::create('repair_components', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('building_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('elevator_id')->nullable();
                $table->foreignId('work_order_id')->nullable()->constrained()->nullOnDelete();
                $table->string('component_type', 20);
                $table->string('description', 160)->nullable();
                $table->string('brand', 120)->nullable();
                $table->string('model', 120)->nullable();
                $table->string('serial_number', 120)->nullable();
                $table->text('failure');
                $table->string('status', 30);
                $table->date('sent_at')->nullable();
                $table->string('provider', 160)->nullable();
                $table->foreignId('follow_up_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->decimal('quote_amount', 14, 2)->nullable();
                $table->decimal('final_cost', 14, 2)->nullable();
                $table->date('expected_return_at')->nullable();
                $table->date('returned_at')->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['company_id', 'status']);
                $table->index(['company_id', 'building_id']);
                $table->index(['company_id', 'elevator_id']);
            });
        }

        if (! Schema::hasTable('repair_component_files')) {
            Schema::create('repair_component_files', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('repair_component_id')->constrained()->cascadeOnDelete();
                $table->string('path');
                $table->string('original_name')->nullable();
                $table->string('mime', 100);
                $table->unsignedInteger('size')->default(0);
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        Schema::table('building_public_pages', function (Blueprint $table) {
            if (! Schema::hasColumn('building_public_pages', 'show_repairs')) {
                $table->boolean('show_repairs')->default(false);
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('building_public_pages', 'show_repairs')) {
            Schema::table('building_public_pages', fn (Blueprint $table) => $table->dropColumn('show_repairs'));
        }

        Schema::dropIfExists('repair_component_files');
        Schema::dropIfExists('repair_components');
    }
};

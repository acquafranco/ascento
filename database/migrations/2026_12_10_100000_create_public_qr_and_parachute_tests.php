<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 7: QR público de edificios y pruebas de paracaídas.
 *
 * - building_public_pages: el QR de un edificio (uno por edificio). Token
 *   aleatorio (nunca el id), activo o no, y qué se publica (todo apagado
 *   salvo lo básico). Desactivar o regenerar deja sin efecto el token
 *   anterior.
 * - elevators.public_status: estado informativo que la empresa decide
 *   publicar (en servicio, fuera de servicio, en mantenimiento), con fecha.
 *   No es una certificación.
 * - clients.parachute_interval_months: periodicidad de la prueba de
 *   paracaídas según la política del cliente (null: sin definir).
 * - parachute_schedules: por ascensor, su periodicidad (si no, la del
 *   cliente), con cuántos días antes avisar, inspector y responsable.
 * - parachute_tests: cada prueba realizada (fecha, resultado, quién la hizo,
 *   inspector o profesional, observaciones). parachute_test_files: sus
 *   documentos o fotos en el disco privado.
 *
 * Sin claves foráneas a elevators (como tasks.elevator_id): los servicios
 * validan que el ascensor sea de la empresa. Solo agrega; se puede revertir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('building_public_pages')) {
            Schema::create('building_public_pages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('building_id')->unique()->constrained()->cascadeOnDelete();
                $table->string('token', 64)->unique();
                $table->boolean('is_active')->default(true);
                $table->boolean('show_elevators')->default(true);
                $table->boolean('show_status')->default(false);
                $table->boolean('show_last_maintenance')->default(true);
                $table->boolean('show_last_inspection')->default(true);
                $table->boolean('show_safety_tests')->default(false);
                $table->boolean('show_pending_work')->default(false);
                $table->boolean('show_contact')->default(false);
                $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('generated_at')->nullable();
                $table->timestamp('deactivated_at')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('elevators', function (Blueprint $table) {
            if (! Schema::hasColumn('elevators', 'public_status')) {
                $table->string('public_status', 20)->nullable();
                $table->timestamp('public_status_updated_at')->nullable();
            }
        });

        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'parachute_interval_months')) {
                $table->unsignedSmallInteger('parachute_interval_months')->nullable();
            }
        });

        if (! Schema::hasTable('parachute_schedules')) {
            Schema::create('parachute_schedules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('elevator_id')->unique();
                $table->unsignedSmallInteger('interval_months')->nullable();
                $table->unsignedSmallInteger('notice_days')->default(30);
                $table->foreignId('inspector_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('responsible_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('parachute_tests')) {
            Schema::create('parachute_tests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('elevator_id');
                $table->foreignId('building_id')->constrained()->cascadeOnDelete();
                $table->date('performed_on');
                $table->string('result', 20);
                $table->foreignId('performed_by_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('performed_by_name', 120)->nullable();
                $table->string('inspector_name', 120)->nullable();
                $table->string('inspector_registration', 60)->nullable();
                $table->text('observations')->nullable();
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['company_id', 'elevator_id', 'performed_on']);
            });
        }

        if (! Schema::hasTable('parachute_test_files')) {
            Schema::create('parachute_test_files', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('parachute_test_id')->constrained()->cascadeOnDelete();
                $table->string('path');
                $table->string('original_name')->nullable();
                $table->string('mime', 100);
                $table->unsignedInteger('size')->default(0);
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('parachute_test_files');
        Schema::dropIfExists('parachute_tests');
        Schema::dropIfExists('parachute_schedules');

        if (Schema::hasColumn('clients', 'parachute_interval_months')) {
            Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('parachute_interval_months'));
        }

        if (Schema::hasColumn('elevators', 'public_status')) {
            Schema::table('elevators', fn (Blueprint $table) => $table->dropColumn(['public_status', 'public_status_updated_at']));
        }

        Schema::dropIfExists('building_public_pages');
    }
};

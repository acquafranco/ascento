<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guardias técnicas (cobertura fuera del horario habitual).
 *
 * - on_call_schedules: la guardia configurada (días y horario cubiertos, cuántos
 *   técnicos a la vez, manual o rotativa).
 * - on_call_members: participantes de una rotación, en orden (con exclusión temporal).
 * - on_call_shifts: asignaciones efectivas (técnico + período). Nunca se borran:
 *   se reemplazan o se cancelan, así el historial queda tal como ocurrió.
 * - on_call_changes: registro inmutable de cada alta, reemplazo, cancelación y
 *   cambio de rotación (quién, cuándo, qué período y por qué).
 * - work_orders.on_call_shift_id: la orden se originó durante esa guardia (lo
 *   indica quien la carga; nunca se deduce solo por la hora).
 *
 * Solo agrega tablas y una columna opcional: no modifica datos existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('on_call_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            // Días (ISO 1 = lunes … 7 = domingo) en que EMPIEZA cada ventana de cobertura.
            $table->json('days');
            $table->time('starts_time');
            // Si es menor o igual al inicio, la ventana termina al día siguiente (cruza la medianoche).
            $table->time('ends_time');
            // Técnicos a la vez en cada momento cubierto (1 = uno solo; más de uno, explícito).
            $table->unsignedTinyInteger('technicians_needed')->default(1);
            $table->string('mode', 20)->default('manual'); // manual | rotation
            $table->unsignedSmallInteger('rotation_days')->nullable(); // duración de cada turno de la rotación
            $table->date('rotation_starts_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'is_active']);
        });

        Schema::create('on_call_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('on_call_schedule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->unsignedSmallInteger('position');
            // Exclusión temporal (vacaciones, licencia): no se le asignan turnos en ese período.
            $table->date('excluded_from')->nullable();
            $table->date('excluded_until')->nullable();
            $table->timestamps();

            $table->unique(['on_call_schedule_id', 'user_id']);
        });

        Schema::create('on_call_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('on_call_schedule_id')->constrained();
            $table->foreignId('user_id')->constrained('users');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable(); // null = permanente (hasta que se finalice)
            $table->string('status', 20)->default('active'); // active | replaced | cancelled
            $table->string('origin', 20)->default('manual'); // manual | rotation | replacement
            // Turno del que proviene (reemplazo o partición de un turno anterior).
            $table->foreignId('parent_shift_id')->nullable()->constrained('on_call_shifts')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('close_reason', 500)->nullable();
            $table->timestamp('reminded_at')->nullable(); // aviso de «próxima guardia» (una vez)
            $table->timestamps();

            $table->index(['company_id', 'status', 'starts_at']);
            $table->index(['on_call_schedule_id', 'status', 'starts_at']);
            $table->index(['user_id', 'status', 'starts_at']);
        });

        Schema::create('on_call_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('on_call_schedule_id')->constrained();
            $table->foreignId('on_call_shift_id')->nullable()->constrained('on_call_shifts')->nullOnDelete();
            // assigned | replaced | cancelled | ended | rotation_updated | schedule_updated | schedule_finished
            $table->string('action', 30);
            $table->foreignId('from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('period_start')->nullable();
            $table->dateTime('period_end')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('summary', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(); // quién hizo el cambio
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'created_at']);
            $table->index(['on_call_schedule_id', 'created_at']);
        });

        Schema::table('work_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('work_orders', 'on_call_shift_id')) {
                $table->foreignId('on_call_shift_id')->nullable()->after('building_id')->constrained('on_call_shifts')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            if (Schema::hasColumn('work_orders', 'on_call_shift_id')) {
                $table->dropConstrainedForeignId('on_call_shift_id');
            }
        });

        Schema::dropIfExists('on_call_changes');
        Schema::dropIfExists('on_call_shifts');
        Schema::dropIfExists('on_call_members');
        Schema::dropIfExists('on_call_schedules');
    }
};

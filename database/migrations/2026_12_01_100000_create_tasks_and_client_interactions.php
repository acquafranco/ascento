<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 6, PR 3: tareas y recordatorios, e historial de contactos.
 *
 * - tasks: tarea administrativa de una empresa, con responsable, vencimiento,
 *   prioridad, estado, quién la completó y un recordatorio opcional (se avisa
 *   una sola vez: reminded_at). Vínculo opcional a cliente, edificio,
 *   ascensor, presupuesto u orden (nullOnDelete: borrar esos registros no
 *   borra la tarea).
 * - client_interactions: contacto con un cliente (fecha, tipo, resultado y
 *   observaciones, quién lo registró con copia de su nombre). Complementa
 *   presupuestos y órdenes: no los duplica, solo los referencia.
 *
 * Solo tablas nuevas. Se puede volver a correr.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tasks')) {
            Schema::create('tasks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('title', 160);
                $table->text('description')->nullable();
                $table->string('priority', 10)->default('medium');
                $table->string('status', 15)->default('pending');
                $table->timestamp('due_at')->nullable();
                $table->timestamp('remind_at')->nullable();
                $table->timestamp('reminded_at')->nullable();
                $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('completed_at')->nullable();
                $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('building_id')->nullable()->constrained()->nullOnDelete();
                // Sin clave foránea, como visit_photos.elevator_id: TaskService valida
                // que el ascensor sea de la empresa (y así revertir la migración de
                // ascensores no queda bloqueada por las tareas).
                $table->unsignedBigInteger('elevator_id')->nullable()->index();
                $table->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('work_order_id')->nullable()->constrained()->nullOnDelete();
                $table->timestamps();

                $table->index(['company_id', 'status', 'due_at']);
                $table->index(['assigned_to', 'status']);
                $table->index(['remind_at', 'reminded_at']);
            });
        }

        if (! Schema::hasTable('client_interactions')) {
            Schema::create('client_interactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('client_id')->constrained()->cascadeOnDelete();
                $table->foreignId('building_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('work_order_id')->nullable()->constrained()->nullOnDelete();
                $table->string('type', 15);
                $table->string('result', 25);
                $table->timestamp('occurred_at');
                $table->text('notes')->nullable();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('user_name', 120)->nullable();
                $table->timestamps();

                $table->index(['company_id', 'client_id', 'occurred_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('client_interactions');
        Schema::dropIfExists('tasks');
    }
};

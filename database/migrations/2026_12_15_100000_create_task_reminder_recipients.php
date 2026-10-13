<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Destinatarios elegidos para el recordatorio de una tarea (mejora de la
 * Etapa 6). Sin filas: como hasta ahora, el responsable o quien la creó. Una
 * persona borrada o la tarea borrada se llevan sus filas. Solo agrega.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('task_reminder_recipients')) {
            Schema::create('task_reminder_recipients', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('task_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['task_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('task_reminder_recipients');
    }
};

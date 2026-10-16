<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuración inicial guiada, por EMPRESA (no por usuario).
 *
 * - setup_status: null = no aplica (todas las empresas que ya existían: nunca
 *   se les abre sola); «pending» la pone el registro; «completed» al terminar;
 *   «skipped» si el dueño la omite (se retoma desde Ayuda).
 * - setup_acks: pasos que no dependen de un registro (bienvenida, datos de la
 *   empresa guardados, «así lo ve el técnico»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('setup_status', 16)->nullable();
            $table->json('setup_acks')->nullable();
            $table->timestamp('setup_completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['setup_status', 'setup_acks', 'setup_completed_at']);
        });
    }
};

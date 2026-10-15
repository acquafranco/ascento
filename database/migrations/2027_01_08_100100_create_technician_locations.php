<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ubicación de los técnicos: UNA fila por técnico con su consentimiento y su
 * última posición (sin historial de recorridos). Las coordenadas se borran
 * al desactivar el seguimiento, al cerrar sesión y después de unas horas sin
 * novedades (config/location.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technician_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            // Activación dentro de Ascento (la decide solo el técnico).
            $table->boolean('tracking_enabled')->default(false);
            $table->timestamp('enabled_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            // Permiso del navegador, tal como lo informa el celular: granted / prompt / denied / unsupported.
            $table->string('permission', 16)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('accuracy')->nullable();
            // Cuándo la recibió el servidor (la hora que se muestra).
            $table->timestamp('located_at')->nullable();
            // Último problema conocido (timeout / unavailable / insecure / denied) y cuándo.
            $table->string('last_error', 24)->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'tracking_enabled']);
            $table->index('located_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technician_locations');
    }
};

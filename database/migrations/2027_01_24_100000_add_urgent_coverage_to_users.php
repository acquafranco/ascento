<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Autorización por técnico para registrar URGENCIAS (intervenciones) en
 * cualquier edificio de su empresa, además de sus edificios asignados y de
 * cuando está de guardia. Solo sirve para eso: no cambia mantenimientos,
 * inspecciones, clientes, edificios ni órdenes ajenas.
 *
 * - urgent_coverage_granted_at / _by: vigente si tiene fecha; quién y cuándo
 *   la otorgó. Revocar las vacía. El historial completo (otorgar y revocar,
 *   quién y cuándo) queda en la actividad de la empresa (activity_logs).
 *
 * Solo agrega columnas nullables: ninguna cuenta queda autorizada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('urgent_coverage_granted_at')->nullable();
            $table->foreignId('urgent_coverage_granted_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('urgent_coverage_granted_by');
            $table->dropColumn('urgent_coverage_granted_at');
        });
    }
};

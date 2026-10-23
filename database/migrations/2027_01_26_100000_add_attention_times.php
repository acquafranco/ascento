<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tiempos de atención de una orden (urgencia, intervención o trabajo de la
 * oficina): AVISO, LLEGADA y SALIDA, cada uno con quién lo registró.
 *
 * - call_received_at: cuándo se RECIBIÓ el aviso. No es created_at (cuándo se
 *   cargó el registro) ni started_at («Tomar trabajo»). Lo carga quien lo
 *   sabe (la oficina, o el técnico al abrir su intervención); en una orden
 *   creada desde un reclamo del portal o del QR es la hora en que llegó ese
 *   reclamo. call_source dice de dónde salió el dato; call_recorded_by, quién
 *   lo cargó (null si lo tomó el sistema del reclamo).
 * - arrived_at / arrived_by: «Llegué al edificio», hora del servidor, una vez.
 * - left_at / left_by: «Terminé y me retiro», hora del servidor, una vez. No
 *   es la firma del remito (finished_at), que puede ser antes o después.
 *
 * Trabajos de las intervenciones (reports): en qué estado quedó el equipo
 * (mismos estados que el QR público) y qué quedó pendiente.
 *
 * Solo agrega columnas nullables: ninguna orden, reporte ni remito cambia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->timestamp('call_received_at')->nullable();
            $table->string('call_source', 20)->nullable();
            $table->foreignId('call_recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('arrived_at')->nullable();
            $table->foreignId('arrived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('left_at')->nullable();
            $table->foreignId('left_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('reports', function (Blueprint $table) {
            $table->string('elevator_state', 30)->nullable();
            $table->text('pending_work')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn(['elevator_state', 'pending_work']);
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('left_by');
            $table->dropColumn('left_at');
            $table->dropConstrainedForeignId('arrived_by');
            $table->dropColumn('arrived_at');
            $table->dropConstrainedForeignId('call_recorded_by');
            $table->dropColumn(['call_source', 'call_received_at']);
        });
    }
};

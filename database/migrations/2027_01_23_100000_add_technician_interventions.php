<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Intervenciones iniciadas por el técnico (urgencias y trabajos no
 * programados), sin esperar a que la oficina cree la orden.
 *
 * - work_orders.origin: null = creada por la oficina (todas las existentes);
 *   'technician' = la abrió el técnico desde Reportes. created_by: quién la
 *   creó (null en las existentes: no se inventa).
 * - reports.work_order_id: cada trabajo de una visita es un reporte de tipo
 *   'intervention' vinculado a la orden de esa visita (una orden, un remito,
 *   varios trabajos diferenciados). channel: por dónde llegó el reclamo
 *   (whatsapp, teléfono, encargado, detectado en la visita, otro).
 *   work_done / result: lo que se hizo y cómo quedó, cargado al firmar el
 *   remito. idempotency_key: un reenvío del mismo formulario no duplica.
 *
 * Solo agrega columnas nullables: ningún dato existente cambia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->string('origin', 20)->nullable()->after('type');
            $table->foreignId('created_by')->nullable()->after('origin')->constrained('users')->nullOnDelete();
            // Sin company_id adelante: MySQL lo tomaría para la clave foránea y no se podría deshacer.
            $table->index(['origin', 'status']);
        });

        Schema::table('reports', function (Blueprint $table) {
            $table->foreignId('work_order_id')->nullable()->constrained('work_orders')->nullOnDelete();
            $table->string('channel', 20)->nullable();
            $table->text('work_done')->nullable();
            $table->string('result', 20)->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->unique(['idempotency_key', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key', 'user_id']);
            $table->dropConstrainedForeignId('work_order_id');
            $table->dropColumn(['channel', 'work_done', 'result', 'idempotency_key']);
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropIndex(['origin', 'status']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn('origin');
        });
    }
};

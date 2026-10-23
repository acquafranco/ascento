<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tipo de orden «Reparación» (repair): un trabajo hecho que puede llevar
 * repuestos y facturarse aparte (fuera de Ascento). Distinto de «Reclamo»
 * (un aviso que necesita atención) y del módulo «Reparaciones» (componentes
 * enviados a taller, tabla repair_components, que no se toca).
 *
 * Solo AGREGA el valor al final del enum: los valores y el orden existentes
 * no cambian, así que ninguna orden se modifica (en MySQL 8 agregar al final
 * es un cambio de metadatos). down() solo lo quita si ninguna orden lo usa.
 */
return new class extends Migration
{
    private const BEFORE = ['inspection', 'maintenance', 'claim', 'installation', 'modernization'];

    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->enum('type', [...self::BEFORE, 'repair'])->change();
        });
    }

    public function down(): void
    {
        if (DB::table('work_orders')->where('type', 'repair')->exists()) {
            throw new RuntimeException('Hay órdenes de tipo «Reparación»: no se puede quitar el tipo sin perder ese dato. Cambiales el tipo antes de revertir.');
        }

        Schema::table('work_orders', function (Blueprint $table) {
            $table->enum('type', self::BEFORE)->change();
        });
    }
};

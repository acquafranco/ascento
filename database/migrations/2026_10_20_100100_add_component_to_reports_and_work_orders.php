<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Componente afectado (opcional) en reportes y órdenes de trabajo: el dato
 * mínimo que faltaba para el análisis de fallas por componente. Lo histórico
 * queda sin clasificar (no se infiere).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->string('component')->nullable()->after('elevator_number');
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->string('component')->nullable()->after('unit');
        });
    }

    public function down(): void
    {
        Schema::table('reports', fn (Blueprint $table) => $table->dropColumn('component'));
        Schema::table('work_orders', fn (Blueprint $table) => $table->dropColumn('component'));
    }
};

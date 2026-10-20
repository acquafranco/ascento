<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guardias rotativas: «¿hasta cuándo se generan los turnos?». Opcional: sin
 * fecha, la rotación se sigue generando unas semanas por delante, como hasta
 * ahora. Solo agrega una columna que admite vacío: no cambia datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('on_call_schedules', function (Blueprint $table) {
            if (! Schema::hasColumn('on_call_schedules', 'rotation_ends_on')) {
                $table->date('rotation_ends_on')->nullable()->after('rotation_starts_on');
            }
        });
    }

    public function down(): void
    {
        Schema::table('on_call_schedules', function (Blueprint $table) {
            if (Schema::hasColumn('on_call_schedules', 'rotation_ends_on')) {
                $table->dropColumn('rotation_ends_on');
            }
        });
    }
};

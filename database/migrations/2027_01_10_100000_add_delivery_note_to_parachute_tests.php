<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prueba de paracaídas registrada en un remito (la firma el técnico al
 * terminar la inspección): queda vinculada a ESE remito, para saber de dónde
 * salió y mostrarla en el remito. Las pruebas cargadas desde el panel quedan
 * sin remito (null). Una sola prueba por ascensor y por remito.
 *
 * Solo agrega una columna nullable y un índice único: no modifica ni borra
 * datos existentes. Se puede volver a correr.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('parachute_tests', 'delivery_note_id')) {
            Schema::table('parachute_tests', function (Blueprint $table) {
                $table->foreignId('delivery_note_id')->nullable()->after('building_id')->constrained('delivery_notes')->nullOnDelete();
                $table->unique(['delivery_note_id', 'elevator_id'], 'parachute_tests_note_elevator_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('parachute_tests', 'delivery_note_id')) {
            // En MySQL el índice único sostiene la clave foránea: primero la clave.
            Schema::table('parachute_tests', fn (Blueprint $table) => $table->dropForeign(['delivery_note_id']));
            Schema::table('parachute_tests', fn (Blueprint $table) => $table->dropUnique('parachute_tests_note_elevator_unique'));
            Schema::table('parachute_tests', fn (Blueprint $table) => $table->dropColumn('delivery_note_id'));
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fotos gestionadas desde Visitas (Etapa 5, ampliación).
 *
 * - companies.photos_*: la documentación fotográfica se habilita por empresa
 *   y por tipo de trabajo. Apagada por defecto (también para las empresas
 *   existentes): desactivarla solo impide cargas nuevas, no toca fotos.
 * - building_visits.in_progress: la visita existe ANTES del remito para poder
 *   cargarle fotos ("visita en curso"). No cuenta como realizada hasta que se
 *   firma el remito, que completa esa misma visita (no crea otra). Las
 *   visitas existentes quedan en false: nada cambia para ellas.
 * - visit_photos.work_order_id: fotos de una orden de trabajo antes de su
 *   remito (la visita de la orden se crea al firmar). Sin clave foránea, como
 *   elevator_id: la app valida empresa y edificio, y las órdenes no se borran.
 * - Índices únicos de reintento por visita y por orden (client_key).
 *
 * Solo columnas e índices nuevos: las fotos ya cargadas conservan su visita,
 * su remito y su lote. Se puede volver a correr si se corta.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['photos_maintenance', 'photos_inspection', 'photos_work_order'] as $column) {
            if (! Schema::hasColumn('companies', $column)) {
                Schema::table('companies', fn (Blueprint $table) => $table->boolean($column)->default(false));
            }
        }

        if (! Schema::hasColumn('building_visits', 'in_progress')) {
            Schema::table('building_visits', fn (Blueprint $table) => $table->boolean('in_progress')->default(false));
        }

        if (! Schema::hasColumn('visit_photos', 'work_order_id')) {
            Schema::table('visit_photos', function (Blueprint $table) {
                $table->unsignedBigInteger('work_order_id')->nullable()->index();
            });
        }

        if (! Schema::hasIndex('visit_photos', ['building_visit_id', 'client_key'], 'unique')) {
            Schema::table('visit_photos', fn (Blueprint $table) => $table->unique(['building_visit_id', 'client_key']));
        }

        if (! Schema::hasIndex('visit_photos', ['work_order_id', 'client_key'], 'unique')) {
            Schema::table('visit_photos', fn (Blueprint $table) => $table->unique(['work_order_id', 'client_key']));
        }
    }

    public function down(): void
    {
        // Revertir no borra fotos ni visitas: solo quita las columnas nuevas.
        if (Schema::hasIndex('visit_photos', ['work_order_id', 'client_key'], 'unique')) {
            Schema::table('visit_photos', fn (Blueprint $table) => $table->dropUnique(['work_order_id', 'client_key']));
        }

        if (Schema::hasColumn('visit_photos', 'work_order_id')) {
            Schema::table('visit_photos', function (Blueprint $table) {
                $table->dropIndex(['work_order_id']);
                $table->dropColumn('work_order_id');
            });
        }

        // MySQL usa este índice para la clave foránea de la visita: antes de
        // quitarlo, uno simple (si no hay otro que empiece por la columna).
        if (Schema::hasIndex('visit_photos', ['building_visit_id', 'client_key'], 'unique')) {
            if (! Schema::hasIndex('visit_photos', ['building_visit_id'])) {
                Schema::table('visit_photos', fn (Blueprint $table) => $table->index('building_visit_id'));
            }
            Schema::table('visit_photos', fn (Blueprint $table) => $table->dropUnique(['building_visit_id', 'client_key']));
        }

        if (Schema::hasColumn('building_visits', 'in_progress')) {
            Schema::table('building_visits', fn (Blueprint $table) => $table->dropColumn('in_progress'));
        }

        foreach (['photos_maintenance', 'photos_inspection', 'photos_work_order'] as $column) {
            if (Schema::hasColumn('companies', $column)) {
                Schema::table('companies', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};

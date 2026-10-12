<?php

use App\Support\PhotoZones;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Zonas de fotos del sistema y reporte de inspección.
 *
 * - photo_sections.code: la zona del sistema (PhotoZones) de cada sección.
 *   Las secciones que ya existían con el nombre de una zona («Pozo»,
 *   «Techo», «Sala de máquinas»…) quedan como esa zona, así sus fotos se
 *   ven agrupadas con las nuevas. Las secciones propias que no coinciden
 *   quedan sin código: no se ofrecen más, pero sus fotos se conservan y se
 *   siguen viendo con su nombre. No se borra ni se renombra nada.
 * - reports.kind / reports.building_visit_id: el reporte de una inspección
 *   (observaciones y fotos del inspector) reutiliza los reportes, sin ser
 *   un reporte de falla. Los reportes existentes quedan como «incident».
 *   Un reporte por visita (único). Sin clave foránea, como work_order_id en
 *   las fotos: la app valida empresa y visita.
 *
 * Solo columnas e índices nuevos. Se puede volver a correr si se corta.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('photo_sections', 'code')) {
            Schema::table('photo_sections', fn (Blueprint $table) => $table->string('code', 30)->nullable());
        }

        if (! Schema::hasIndex('photo_sections', ['company_id', 'code'], 'unique')) {
            Schema::table('photo_sections', fn (Blueprint $table) => $table->unique(['company_id', 'code']));
        }

        // Secciones existentes → zona del sistema (la primera de cada empresa y zona).
        DB::table('photo_sections')->whereNull('code')->orderBy('id')->get(['id', 'company_id', 'name'])
            ->each(function ($section) {
                $code = PhotoZones::codeForLegacyName((string) $section->name);

                if ($code && ! DB::table('photo_sections')->where('company_id', $section->company_id)->where('code', $code)->exists()) {
                    DB::table('photo_sections')->where('id', $section->id)->update(['code' => $code]);
                }
            });

        if (! Schema::hasColumn('reports', 'kind')) {
            Schema::table('reports', fn (Blueprint $table) => $table->string('kind', 20)->default('incident'));
        }

        if (! Schema::hasColumn('reports', 'building_visit_id')) {
            Schema::table('reports', fn (Blueprint $table) => $table->unsignedBigInteger('building_visit_id')->nullable());
        }

        if (! Schema::hasIndex('reports', ['building_visit_id'], 'unique')) {
            Schema::table('reports', fn (Blueprint $table) => $table->unique('building_visit_id'));
        }

        if (! Schema::hasIndex('reports', ['company_id', 'kind'])) {
            Schema::table('reports', fn (Blueprint $table) => $table->index(['company_id', 'kind']));
        }
    }

    public function down(): void
    {
        // Revertir no borra reportes ni fotos: solo quita las columnas nuevas.
        if (Schema::hasIndex('reports', ['company_id', 'kind'])) {
            // MySQL puede estar usando este índice para la clave foránea de la
            // empresa: antes de quitarlo, uno simple.
            if (! Schema::hasIndex('reports', ['company_id'])) {
                Schema::table('reports', fn (Blueprint $table) => $table->index('company_id'));
            }
            Schema::table('reports', fn (Blueprint $table) => $table->dropIndex(['company_id', 'kind']));
        }

        if (Schema::hasIndex('reports', ['building_visit_id'], 'unique')) {
            Schema::table('reports', fn (Blueprint $table) => $table->dropUnique(['building_visit_id']));
        }

        foreach (['building_visit_id', 'kind'] as $column) {
            if (Schema::hasColumn('reports', $column)) {
                Schema::table('reports', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }

        if (Schema::hasIndex('photo_sections', ['company_id', 'code'], 'unique')) {
            Schema::table('photo_sections', fn (Blueprint $table) => $table->dropUnique(['company_id', 'code']));
        }

        if (Schema::hasColumn('photo_sections', 'code')) {
            Schema::table('photo_sections', fn (Blueprint $table) => $table->dropColumn('code'));
        }
    }
};

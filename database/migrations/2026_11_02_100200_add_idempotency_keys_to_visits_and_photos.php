<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reintentos seguros (Etapa 5, revisión).
 *
 * - building_visits.submission_token: identificador del formulario del
 *   remito. Si el primer envío se guardó pero la respuesta no llegó al
 *   celular y el técnico (o el navegador) lo reenvía, el segundo envío no
 *   crea otra visita: en una frecuencia quincenal se habría registrado la
 *   "2ª visita" con el mismo trabajo. Único en la base.
 * - visit_photos.client_key: identificador de cada foto que genera el
 *   celular. Si la subida se completó pero la respuesta se perdió,
 *   «Reintentar» devuelve la misma foto en vez de duplicarla. Único por lote.
 *
 * Solo columnas nuevas y vacías: no toca datos existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('building_visits', 'submission_token')) {
            Schema::table('building_visits', function (Blueprint $table) {
                $table->string('submission_token', 36)->nullable()->unique();
            });
        }

        if (! Schema::hasColumn('visit_photos', 'client_key')) {
            Schema::table('visit_photos', function (Blueprint $table) {
                $table->string('client_key', 64)->nullable();
                $table->unique(['batch', 'client_key']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('visit_photos', 'client_key')) {
            Schema::table('visit_photos', function (Blueprint $table) {
                $table->dropUnique(['batch', 'client_key']);
                $table->dropColumn('client_key');
            });
        }

        if (Schema::hasColumn('building_visits', 'submission_token')) {
            Schema::table('building_visits', function (Blueprint $table) {
                $table->dropUnique(['submission_token']);
                $table->dropColumn('submission_token');
            });
        }
    }
};

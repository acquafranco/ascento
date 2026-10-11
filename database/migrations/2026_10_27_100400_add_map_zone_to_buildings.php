<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zona (sector) del edificio, definida por el admin ("Zona Norte",
 * "Ruta 1"…). Sirve para filtrar el mapa y organizar la cartera. No se
 * infiere de las coordenadas. No destructiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buildings', function (Blueprint $table) {
            $table->string('map_zone', 60)->nullable();
            $table->index(['company_id', 'map_zone']);
        });
    }

    public function down(): void
    {
        Schema::table('buildings', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'map_zone']);
            $table->dropColumn('map_zone');
        });
    }
};

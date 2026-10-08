<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coordenadas de cada edificio para el mapa. Se geocodifican una sola vez
 * (Geoapify) y se reutilizan; si cambia la dirección, el modelo las borra
 * y vuelve a "pending" (ver Building::booted()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buildings', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // pending | geocoded | manual | needs_review | error
            $table->string('geocoding_status', 20)->default('pending');
            $table->decimal('geocoding_confidence', 4, 3)->nullable();

            // Dirección exacta que se geocodificó: si deja de coincidir con
            // la actual, las coordenadas están desactualizadas.
            $table->string('geocoded_address')->nullable();
            $table->timestamp('geocoded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('buildings', function (Blueprint $table) {
            $table->dropColumn([
                'latitude',
                'longitude',
                'geocoding_status',
                'geocoding_confidence',
                'geocoded_address',
                'geocoded_at',
            ]);
        });
    }
};

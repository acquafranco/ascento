<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Direcciones de Latinoamérica: país de la empresa (por defecto Argentina, como
 * hasta ahora) y país del edificio (vacío = el de su empresa). No cambia
 * ningún dato existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->char('country', 2)->default('AR');
        });

        Schema::table('buildings', function (Blueprint $table) {
            $table->char('country', 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('buildings', function (Blueprint $table) {
            $table->dropColumn('country');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('country');
        });
    }
};

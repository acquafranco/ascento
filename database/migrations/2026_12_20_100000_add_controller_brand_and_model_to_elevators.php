<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 8.1: marca y modelo del control del ascensor. Opcionales y vacíos
 * en los equipos existentes (no se inventan valores). El texto libre
 * «controller» se conserva como el tipo o la descripción de la maniobra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('elevators', function (Blueprint $table) {
            if (! Schema::hasColumn('elevators', 'controller_brand')) {
                $table->string('controller_brand')->nullable()->after('controller');
                $table->string('controller_model')->nullable()->after('controller_brand');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('elevators', 'controller_brand')) {
            Schema::table('elevators', fn (Blueprint $table) => $table->dropColumn(['controller_brand', 'controller_model']));
        }
    }
};

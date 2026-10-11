<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paso de la guía de bienvenida guardado en el servidor (antes en el
 * localStorage del navegador: se perdía al abrir un enlace en otro navegador,
 * por ejemplo el de confirmación de correo, y la guía volvía a empezar).
 * Aditiva: una columna nullable, no toca datos existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('onboarding_step')->nullable()->after('onboarding_skipped_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('onboarding_step');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 11: segundo factor (TOTP) del SuperAdmin. Solo agrega columnas
 * opcionales: ningún usuario cambia al aplicarla. El secreto y los códigos
 * de recuperación se guardan cifrados (casts del modelo) y los códigos,
 * además, con hash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'two_factor_secret')) {
                $table->text('two_factor_secret')->nullable();
            }
            if (! Schema::hasColumn('users', 'two_factor_recovery_codes')) {
                $table->text('two_factor_recovery_codes')->nullable();
            }
            if (! Schema::hasColumn('users', 'two_factor_confirmed_at')) {
                $table->timestamp('two_factor_confirmed_at')->nullable();
            }
            if (! Schema::hasColumn('users', 'two_factor_last_timestep')) {
                // Último paso de 30 s aceptado: un código no se puede usar dos veces.
                $table->unsignedBigInteger('two_factor_last_timestep')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['two_factor_last_timestep', 'two_factor_confirmed_at', 'two_factor_recovery_codes', 'two_factor_secret'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 11: cambio de correo con confirmación. El correo nuevo queda en
 * pending_email hasta que se confirma desde esa dirección; mientras tanto,
 * `email` (ingreso y recuperación) no cambia. Solo agrega columnas
 * opcionales: no toca ningún correo existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'pending_email')) {
                $table->string('pending_email')->nullable()->after('email');
            }
            if (! Schema::hasColumn('users', 'pending_email_token')) {
                // Hash SHA-256 del token del enlace (el token en sí solo viaja en el correo).
                $table->string('pending_email_token', 64)->nullable()->after('pending_email');
            }
            if (! Schema::hasColumn('users', 'pending_email_expires_at')) {
                $table->timestamp('pending_email_expires_at')->nullable()->after('pending_email_token');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['pending_email_expires_at', 'pending_email_token', 'pending_email'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

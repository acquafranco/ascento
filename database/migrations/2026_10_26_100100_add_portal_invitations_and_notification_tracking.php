<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - Invitaciones al portal: cuándo se envió la última (solo si el correo se
 *   entregó al proveedor) y cuándo el cliente activó su cuenta.
 * - Notificaciones internas: empresa (auditoría), clave de un evento para no
 *   duplicarlo (única por destinatario) y estado del correo, que se marca
 *   enviado solo si el proveedor lo aceptó.
 *
 * No destructiva: columnas nullable sobre tablas existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('portal_invited_at')->nullable();
            $table->timestamp('portal_activated_at')->nullable();
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('dedupe_key', 191)->nullable();
            $table->timestamp('mailed_at')->nullable();
            $table->timestamp('mail_failed_at')->nullable();

            $table->unique(['notifiable_type', 'notifiable_id', 'dedupe_key'], 'notifications_dedupe_unique');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropUnique('notifications_dedupe_unique');
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn(['dedupe_key', 'mailed_at', 'mail_failed_at']);
        });

        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['portal_invited_at', 'portal_activated_at']));
    }
};

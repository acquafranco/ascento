<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 6, PR 1: infraestructura de permisos y registro de actividad.
 *
 * - users.permissions: áreas habilitadas a un usuario administrativo
 *   (role = staff). Null para todos los usuarios existentes: nada cambia.
 * - subscription_plans.max_admin_users: administrativos adicionales por
 *   plan (Inicial 0, Profesional 1, Empresa 5; el histórico «professional»
 *   como Profesional). Se aplica desde el PR 2.
 * - activity_logs: acciones sensibles (quién, qué, sobre qué, cuándo). Copia
 *   el nombre y el rol del autor: se conserva aunque el usuario se borre.
 *
 * Solo columnas, una tabla y valores nuevos. Se puede volver a correr.
 */
return new class extends Migration
{
    private const ADMIN_USERS = ['inicial' => 0, 'profesional' => 1, 'professional' => 1, 'empresa' => 5];

    public function up(): void
    {
        if (! Schema::hasColumn('users', 'permissions')) {
            Schema::table('users', fn (Blueprint $table) => $table->json('permissions')->nullable());
        }

        if (! Schema::hasColumn('subscription_plans', 'max_admin_users')) {
            Schema::table('subscription_plans', fn (Blueprint $table) => $table->unsignedSmallInteger('max_admin_users')->nullable());
        }

        foreach (self::ADMIN_USERS as $slug => $max) {
            DB::table('subscription_plans')->where('slug', $slug)->whereNull('max_admin_users')->update(['max_admin_users' => $max]);
        }

        if (! Schema::hasTable('activity_logs')) {
            Schema::create('activity_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('actor_name', 120)->nullable();
                $table->string('actor_role', 20)->nullable();
                $table->string('action', 60);
                $table->string('subject_type', 60)->nullable();
                $table->unsignedBigInteger('subject_id')->nullable();
                $table->string('subject_label', 190)->nullable();
                $table->json('changes')->nullable();
                $table->string('ip', 45)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['company_id', 'created_at']);
                $table->index(['subject_type', 'subject_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');

        if (Schema::hasColumn('subscription_plans', 'max_admin_users')) {
            Schema::table('subscription_plans', fn (Blueprint $table) => $table->dropColumn('max_admin_users'));
        }

        if (Schema::hasColumn('users', 'permissions')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('permissions'));
        }
    }
};

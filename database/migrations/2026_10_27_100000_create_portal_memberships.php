<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Accesos al portal por empresa ("membresías").
 *
 * Una persona = una cuenta (users.email es único en todo Ascento). Puede
 * consultar edificios de varias empresas de mantenimiento si cada una le dio
 * acceso explícito: una membresía por (persona, cliente). Los edificios
 * autorizados siguen en client_portal_buildings y solo valen con una
 * membresía activa de ese cliente y esa empresa.
 *
 * Desactivar es por empresa (deactivated_at): ya no se borra la cuenta
 * global, que podría tener acceso en otra empresa.
 *
 * No destructiva: tabla nueva + copia de los usuarios del portal existentes
 * (su empresa y cliente actuales). No modifica ni borra cuentas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'client_id']);
            $table->index(['company_id', 'client_id']);
            $table->index(['user_id', 'deactivated_at']);
        });

        // Usuarios del portal que ya existen → su membresía actual.
        DB::table('users')->where('role', 'client')->whereNotNull('company_id')->whereNotNull('client_id')->orderBy('id')
            ->each(function ($user) {
                DB::table('portal_memberships')->insertOrIgnore([
                    'user_id' => $user->id,
                    'company_id' => $user->company_id,
                    'client_id' => $user->client_id,
                    'invited_at' => $user->portal_invited_at,
                    'activated_at' => $user->portal_activated_at,
                    'deactivated_at' => $user->deleted_at,
                    'created_at' => $user->created_at ?? now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_memberships');
    }
};

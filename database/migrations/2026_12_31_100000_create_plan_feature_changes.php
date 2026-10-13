<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 9.2: historial de cambios del catálogo de planes. Una fila por
 * función que cambia en un plan: quién (y su nombre, por si el usuario se
 * borra), cuándo, plan, función, estado anterior y nuevo, y el motivo que
 * escribió el SuperAdmin. Solo se agregan filas. Se puede revertir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('plan_feature_changes')) {
            Schema::create('plan_feature_changes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('user_name', 120)->nullable();
                $table->foreignId('subscription_plan_id')->nullable()->constrained()->nullOnDelete();
                $table->string('plan_slug', 60);
                $table->string('feature', 60);
                $table->boolean('enabled_before');
                $table->boolean('enabled_after');
                $table->string('context', 255)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['plan_slug', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_feature_changes');
    }
};

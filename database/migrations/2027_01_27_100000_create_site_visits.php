<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visita técnica: la presencia física de UN técnico en UN edificio, desde
 * «Llegué al edificio» hasta «Finalizar visita y retirarme». Agrupa los
 * trabajos (órdenes) y los remitos de ese tramo; cada uno conserva su estado.
 *
 * - site_visits.arrived_at / left_at: hora del servidor, una vez cada una.
 *   left_at null = sin salida registrada (nunca se inventa).
 * - closed_at / closed_by / close_note: la visita se cerró. Con left_at, es la
 *   salida normal; sin left_at, un cierre excepcional «sin salida» con motivo.
 * - exit_reminded_at: el recordatorio de salida ya salió (una vez).
 * - work_orders.site_visit_id: en qué visita se hizo la orden;
 *   arrival_reminded_at: recordatorio de llegada enviado (una vez).
 * - building_visits.site_visit_id: en qué visita se firmó ese remito;
 *   arrival_exception: motivo, si se firmó sin llegada registrada.
 *
 * Solo agrega una tabla y columnas nullables: nada existente cambia. Los
 * índices no empiezan por una columna con clave foránea (MySQL los necesita
 * para la FK y no deja borrarlos al revertir).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('building_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('arrived_at');
            $table->timestamp('left_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('close_note', 500)->nullable();
            $table->timestamp('exit_reminded_at')->nullable();
            $table->timestamps();

            $table->index(['closed_at', 'user_id']);
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->foreignId('site_visit_id')->nullable()->constrained('site_visits')->nullOnDelete();
            $table->timestamp('arrival_reminded_at')->nullable();
        });

        Schema::table('building_visits', function (Blueprint $table) {
            $table->foreignId('site_visit_id')->nullable()->constrained('site_visits')->nullOnDelete();
            $table->string('arrival_exception', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('building_visits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('site_visit_id');
            $table->dropColumn('arrival_exception');
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('site_visit_id');
            $table->dropColumn('arrival_reminded_at');
        });

        Schema::dropIfExists('site_visits');
    }
};

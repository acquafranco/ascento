<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Presupuestos:
 * - Número correlativo por empresa (los existentes se numeran por fecha de
 *   alta, sin tocar ningún otro dato).
 * - Cuándo y a quién se envió por correo.
 * - Historial de acciones (creado, enviado, aprobado, rechazado, anulado,
 *   duplicado, enlace renovado).
 *
 * No destructiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->unsignedInteger('number')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('sent_to')->nullable();
            $table->unique(['company_id', 'number']);
        });

        DB::table('quotes')->select('company_id')->distinct()->pluck('company_id')->each(function ($companyId) {
            $n = 0;
            DB::table('quotes')->where('company_id', $companyId)->orderBy('created_at')->orderBy('id')->pluck('id')
                ->each(function ($id) use (&$n) {
                    DB::table('quotes')->where('id', $id)->update(['number' => ++$n]);
                });
        });

        Schema::create('quote_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 30);
            $table->string('detail')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['quote_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_events');
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'number']);
            $table->dropColumn(['number', 'sent_at', 'sent_to']);
        });
    }
};

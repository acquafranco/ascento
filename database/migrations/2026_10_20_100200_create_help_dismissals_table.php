<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué ayudas contextuales ya vio (o descartó) cada usuario: una fila por
 * ayuda, así se sabe cuáles faltan y se puede reiniciar una sola.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 64);
            $table->timestamp('dismissed_at');

            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_dismissals');
    }
};

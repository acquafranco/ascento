<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Backups globales (restaurables) y quién los descargó. Tablas nuevas. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);                       // scheduled | manual
            $table->string('status', 20)->default('requested'); // requested | running | completed | failed
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('checksum', 64)->nullable();        // sha256 del ZIP
            $table->boolean('encrypted')->default(false);
            $table->json('summary')->nullable();               // tablas/filas, archivos, faltantes
            $table->string('error')->nullable();               // motivo legible, sin secretos
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('file_deleted_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('backup_downloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backup_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->timestamp('downloaded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_downloads');
        Schema::dropIfExists('backups');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exportaciones de datos de negocio de cada empresa (ZIP con Excel +
 * adjuntos) y quién las descargó. El historial se conserva aunque el archivo
 * expire y se borre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('requested'); // requested | generating | completed | failed
            $table->string('file_name')->nullable();
            $table->string('path')->nullable();                 // disco privado; null cuando expira
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedInteger('record_count')->nullable();
            $table->json('categories')->nullable();             // {"Clientes": 12, ...}
            $table->json('warnings')->nullable();               // archivos que no se pudieron incluir
            $table->string('error')->nullable();                // motivo legible, sin secretos
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('file_deleted_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('company_export_downloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_export_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('downloaded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_export_downloads');
        Schema::dropIfExists('company_exports');
    }
};

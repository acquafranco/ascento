<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reclamos que los clientes presentan desde el portal. Son registros propios
 * (no son órdenes, mantenimientos ni remitos): una orden se crea solo con una
 * acción explícita de la empresa y queda vinculada (work_order_id).
 *
 * - client_claim_events: historial (alta, estados, asignación, notas internas
 *   y respuestas al cliente). `public` separa lo que ve el cliente.
 * - client_claim_photos: fotos opcionales, en disco privado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('building_id')->constrained()->cascadeOnDelete();
            $table->foreignId('elevator_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('work_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 24);
            $table->text('description');
            $table->timestamp('occurred_at')->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->string('status', 16)->default('new');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status', 'created_at']);
            $table->index(['created_by', 'created_at']);
            $table->index('building_id');
        });

        Schema::create('client_claim_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_claim_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // created / status / assigned / note / reply / work_order
            $table->string('type', 16);
            $table->string('from', 40)->nullable();
            $table->string('to', 40)->nullable();
            $table->text('body')->nullable();
            // Solo lo marcado como público lo ve el cliente (respuestas y cambios de estado).
            $table->boolean('public')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->index(['client_claim_id', 'created_at']);
        });

        Schema::create('client_claim_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_claim_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('mime', 40);
            $table->unsignedInteger('size');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_claim_photos');
        Schema::dropIfExists('client_claim_events');
        Schema::dropIfExists('client_claims');
    }
};

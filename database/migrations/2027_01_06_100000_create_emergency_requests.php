<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitudes de ayuda por posible atrapamiento desde el QR público.
 *
 * - companies.emergency_requests_enabled: interruptor de la empresa (apagado
 *   por defecto: nunca se activa una función de emergencia sin consentimiento).
 * - emergency_requests: cada incidente (edificio, ascensor si se indicó,
 *   empresa y cliente al momento, estado, repeticiones). idempotency_key es
 *   única: un reintento del mismo envío nunca crea dos solicitudes.
 * - emergency_deliveries: a quién se avisó y por qué canales (el aviso
 *   interno; el correo se registra en la notificación: enviado o fallido),
 *   para auditar la entrega y reintentar los correos fallidos.
 *
 * Solo agrega: no modifica datos existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (! Schema::hasColumn('companies', 'emergency_requests_enabled')) {
                $table->boolean('emergency_requests_enabled')->default(false);
            }
        });

        Schema::create('emergency_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('building_id')->constrained();
            $table->foreignId('elevator_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 12)->unique();          // código corto para hablar de la solicitud
            $table->string('idempotency_key', 64)->unique();    // hash del comprobante firmado del envío
            $table->string('status', 20)->default('open');      // open | attended | false_alarm | closed
            $table->unsignedInteger('repeat_count')->default(0);
            $table->timestamp('last_repeat_at')->nullable();
            $table->unsignedSmallInteger('notify_rounds')->default(0);
            $table->timestamp('last_notified_at')->nullable();
            $table->unsignedSmallInteger('recipients_count')->default(0);
            $table->string('ip_hash', 64)->nullable();           // HMAC de la IP: análisis de abuso sin guardar la IP
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('close_note', 500)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status', 'created_at']);
            $table->index(['building_id', 'created_at']);
        });

        Schema::create('emergency_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('emergency_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->string('audience', 20);                      // company | technician | consortium
            $table->unsignedSmallInteger('round')->default(1);
            $table->uuid('notification_id')->nullable();         // aviso interno creado (null: no se pudo)
            $table->boolean('push_attempted')->default(false);
            $table->boolean('mail_expected')->default(false);
            $table->unsignedTinyInteger('mail_attempts')->default(0);
            $table->string('failure', 120)->nullable();          // motivo técnico sin datos personales
            $table->timestamps();

            $table->unique(['emergency_request_id', 'user_id', 'round']);
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emergency_deliveries');
        Schema::dropIfExists('emergency_requests');

        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'emergency_requests_enabled')) {
                $table->dropColumn('emergency_requests_enabled');
            }
        });
    }
};

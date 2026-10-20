<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ascento Conecta: reportes de problemas desde el QR (sobre los reclamos que
 * ya existían), pasaporte del ascensor (bloques nuevos del QR y documentos
 * publicados) y registro de los avisos de cada reporte.
 *
 * Solo agrega columnas y una tabla, todas con valores por defecto: no cambia
 * ningún dato existente. Los pedidos de ayuda (emergency_requests) se
 * conservan tal cual como historial.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Recibir reportes desde el QR: encendido por defecto (la empresa lo puede apagar).
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('qr_reports_enabled')->default(true);
        });

        Schema::table('client_claims', function (Blueprint $table) {
            // portal (persona del portal) | qr (persona sin cuenta, desde el QR del edificio)
            $table->string('origin', 10)->default('portal');
            // Un reporte del QR lo ve la administración del edificio solo si la empresa lo comparte.
            $table->boolean('shared_with_client')->default(false);
            // Su estado aparece en el pasaporte público solo si la empresa lo publica.
            $table->boolean('public_on_qr')->default(false);
            // Reportes del QR: comprobante del formulario (un reintento no duplica) y la IP solo como HMAC.
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->string('ip_hash', 64)->nullable();

            $table->index(['building_id', 'origin', 'created_at']);
        });

        // Avisos de cada reporte: a quién se intentó avisar y qué pasó (no confirma lectura).
        Schema::create('client_claim_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_claim_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('audience', 16); // company | technician | client
            $table->uuid('notification_id')->nullable();
            $table->string('failure', 80)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['client_claim_id', 'created_at']);
        });

        // Pasaporte del ascensor: bloques nuevos, apagados hasta que la empresa los elija.
        Schema::table('building_public_pages', function (Blueprint $table) {
            $table->boolean('show_service_history')->default(false);
            $table->boolean('show_next_visit')->default(false);
            $table->boolean('show_documents')->default(false);
            $table->boolean('show_incidents')->default(false);
        });

        // Documentación técnica publicada en el QR: solo con una acción explícita de la empresa.
        Schema::table('elevator_documents', function (Blueprint $table) {
            $table->boolean('public_on_qr')->default(false);
            $table->timestamp('published_on_qr_at')->nullable();
            $table->foreignId('published_on_qr_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('elevator_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('published_on_qr_by');
            $table->dropColumn(['public_on_qr', 'published_on_qr_at']);
        });

        Schema::table('building_public_pages', function (Blueprint $table) {
            $table->dropColumn(['show_service_history', 'show_next_visit', 'show_documents', 'show_incidents']);
        });

        Schema::dropIfExists('client_claim_deliveries');

        Schema::table('client_claims', function (Blueprint $table) {
            $table->dropIndex(['building_id', 'origin', 'created_at']);
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['origin', 'shared_with_client', 'public_on_qr', 'idempotency_key', 'ip_hash']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('qr_reports_enabled');
        });
    }
};

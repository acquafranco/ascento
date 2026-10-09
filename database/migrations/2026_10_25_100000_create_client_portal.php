<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Portal del cliente (consorcio / administración).
 *
 * - Usuarios del portal = usuarios comunes con rol "client" y el cliente al
 *   que pertenecen (users.client_id). No se duplica la entidad cliente: un
 *   cliente puede tener varios usuarios.
 * - Qué edificios ve cada usuario: autorización explícita por edificio
 *   (client_portal_buildings). Un cliente con varios edificios puede dar
 *   acceso a todos o solo a algunos.
 * - Qué documentos ve: marca explícita "compartido con el cliente", privada
 *   por defecto, en reportes (con sus fotos), remitos, presupuestos y
 *   documentos del legajo. Nada de lo histórico queda compartido.
 *
 * No destructiva: solo columnas nullable / con default y una tabla nueva.
 */
return new class extends Migration
{
    private const SHARED = ['reports', 'delivery_notes', 'quotes', 'elevator_documents'];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('company_id')->constrained('clients')->nullOnDelete();
        });

        Schema::create('client_portal_buildings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('building_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'building_id']);
        });

        foreach (self::SHARED as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->boolean('shared_with_client')->default(false);
                $table->timestamp('shared_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::SHARED as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['shared_with_client', 'shared_at']));
        }

        Schema::dropIfExists('client_portal_buildings');

        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('client_id'));
    }
};

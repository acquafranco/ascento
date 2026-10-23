<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuración general de la plataforma (una fila, la edita el SuperAdmin en
 * «Configuración de la plataforma») y catálogo público del marketplace.
 *
 * - platform_settings.international_registration_enabled: apagado = los
 *   registros nuevos de empresas y proveedores son solo de Argentina.
 *   Encendido = además, los países de registration_countries (vacío al
 *   empezar: encender el switch no agrega ningún país por sí solo).
 * - mp_account_*: resultado de la última verificación de la cuenta de
 *   Mercado Pago (cuenta de prueba o real, país) y una huella NO reversible
 *   del token verificado; si el token cambia, la verificación deja de valer.
 *   Nunca se guarda el token.
 * - marketplace_settings.public_catalog_enabled: el catálogo de repuestos se
 *   puede explorar sin cuenta (solo productos visibles).
 *
 * No toca ninguna cuenta existente, plan, precio ni suscripción.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('international_registration_enabled')->default(false);
            $table->json('registration_countries')->nullable();
            $table->timestamp('mp_account_checked_at')->nullable();
            $table->boolean('mp_account_is_test')->nullable();
            $table->string('mp_account_site', 8)->nullable();
            $table->string('mp_token_fingerprint', 64)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('marketplace_settings', function (Blueprint $table) {
            $table->boolean('public_catalog_enabled')->default(true)->after('over_limit_rule');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_settings', function (Blueprint $table) {
            $table->dropColumn('public_catalog_enabled');
        });
        Schema::dropIfExists('platform_settings');
    }
};

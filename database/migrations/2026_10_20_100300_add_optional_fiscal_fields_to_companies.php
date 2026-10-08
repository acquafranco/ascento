<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datos de empresa opcionales, para completar después desde "Mi empresa" y
 * dejar preparada una futura integración fiscal. NO hay facturación: solo
 * datos. Los que ya existían (razón social, CUIT, condición fiscal,
 * domicilio, ciudad, provincia, logo) no se tocan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('postal_code', 10)->nullable()->after('province');
            $table->string('activity')->nullable()->after('tax_condition');
            $table->string('gross_income_number', 30)->nullable()->after('activity'); // Ingresos Brutos
            $table->date('activity_started_at')->nullable()->after('gross_income_number');
            $table->string('bank_name')->nullable()->after('activity_started_at');
            $table->string('bank_cbu', 22)->nullable()->after('bank_name');
            $table->string('bank_alias', 40)->nullable()->after('bank_cbu');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['postal_code', 'activity', 'gross_income_number', 'activity_started_at', 'bank_name', 'bank_cbu', 'bank_alias']);
        });
    }
};

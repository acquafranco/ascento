<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Empresas de Latinoamérica: la cuenta bancaria (CBU en Argentina; CLABE,
 * IBAN u otras en el resto) puede tener hasta 34 caracteres. Solo agranda la
 * columna: no cambia ningún dato.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('bank_cbu', 34)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('bank_cbu', 22)->nullable()->change();
        });
    }
};

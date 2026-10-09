<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un CUIT = una empresa, también en la base (dos registros simultáneos no
 * pueden pasar los dos la validación).
 *
 * 1. Normaliza los CUIT existentes: "" → NULL; los válidos con 11 dígitos,
 *    al formato 30-71234567-1 (el que usa la app). Los que no tienen 11
 *    dígitos se dejan como están.
 * 2. Si quedan CUIT repetidos, NO toca nada más y frena con un mensaje: hay
 *    que resolverlos a mano (no se borra ni se cambia ningún dato).
 * 3. Índice único (los NULL pueden repetirse).
 *
 * La normalización está escrita acá (no usa clases de la app) para que la
 * migración no cambie si la app cambia.
 */
return new class extends Migration
{
    public function up(): void
    {
        $companies = DB::table('companies')->get(['id', 'cuit']);

        $normalized = $companies->mapWithKeys(function ($company) {
            $raw = trim((string) $company->cuit);
            $digits = preg_replace('/\D/', '', $raw);

            $value = match (true) {
                $raw === '' => null,
                strlen($digits) === 11 => substr($digits, 0, 2).'-'.substr($digits, 2, 8).'-'.substr($digits, 10),
                default => $raw,
            };

            return [$company->id => $value];
        });

        $duplicates = $normalized->filter()->countBy()->filter(fn ($count) => $count > 1);

        if ($duplicates->isNotEmpty()) {
            $detail = $duplicates->keys()->map(fn ($cuit) => $cuit.' (empresas '.$normalized->filter(fn ($v) => $v === $cuit)->keys()->implode(', ').')')->implode('; ');

            throw new RuntimeException("Hay CUIT repetidos entre empresas: {$detail}. Resolvelos antes de migrar; no se modificó ningún dato.");
        }

        foreach ($normalized as $id => $value) {
            DB::table('companies')->where('id', $id)->update(['cuit' => $value]);
        }

        Schema::table('companies', function (Blueprint $table) {
            $table->unique('cuit');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique(['cuit']);
        });
    }
};

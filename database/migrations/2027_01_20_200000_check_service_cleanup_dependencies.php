<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Control previo de la limpieza de «Profesionales y servicios»
 * (2027_01_21_100000_drop_service_profiles_tables). Solo LEE.
 *
 * Va con fecha anterior a la limpieza a propósito: si las dos están
 * pendientes, Laravel corre esta primero y, si encuentra algo que haría
 * fallar la limpieza a mitad de camino (MySQL no deshace un DROP), detiene
 * el deploy ANTES de borrar cualquier cosa:
 *
 * - una tabla que no es de la función retirada con una clave foránea hacia
 *   alguna de sus 7 tablas (la limpieza no podría borrarlas);
 * - una cuenta registrada «solo servicios» (suppliers.sells_products = 0).
 *
 * Si la limpieza ya se aplicó (las tablas no existen), no hace nada.
 */
return new class extends Migration
{
    public const TABLES = ['service_categories', 'service_profiles', 'service_profile_category', 'service_profile_terms',
        'service_contact_requests', 'service_contact_messages', 'service_profile_reports'];

    public function up(): void
    {
        $present = array_values(array_filter(self::TABLES, fn ($t) => Schema::hasTable($t)));
        if ($present === []) {
            return;
        }

        $problems = [];
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if (in_array($table, self::TABLES, true)) {
                continue;
            }
            foreach (Schema::getForeignKeys($table) as $fk) {
                $target = (string) str($fk['foreign_table'])->afterLast('.');
                if (in_array($target, self::TABLES, true)) {
                    $problems[] = $table.'.'.implode(',', $fk['columns']).' → '.$target;
                }
            }
        }
        if (Schema::hasColumn('suppliers', 'sells_products') && ($ids = DB::table('suppliers')->where('sells_products', false)->pluck('id')->all()) !== []) {
            $problems[] = 'proveedores «solo servicios» (ids: '.implode(', ', $ids).')';
        }

        if ($problems !== []) {
            throw new RuntimeException('La limpieza de «Profesionales y servicios» no puede correr sin riesgo; no se borró nada. Resolvé antes: '.implode('; ', $problems).'.');
        }
    }

    public function down(): void
    {
        // Solo lee: no hay nada que deshacer.
    }
};

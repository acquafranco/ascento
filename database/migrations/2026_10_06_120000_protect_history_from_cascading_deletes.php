<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Protege el historial (remitos, visitas, reportes, órdenes, presupuestos).
 *
 * 1. Soft deletes en las entidades "padre": borrar pasa a ser desactivar,
 *    la fila sigue existiendo y ninguna FK en cascada se dispara.
 * 2. Las FK de las tablas históricas pasan de CASCADE a RESTRICT: si algo
 *    llegara a intentar un borrado físico del padre, la base lo rechaza en
 *    vez de llevarse el historial puesto.
 *
 * Es aditiva: no borra ni modifica datos existentes.
 */
return new class extends Migration
{
    /** Tablas que pasan a tener soft deletes. */
    private array $softDeletes = [
        'companies',
        'users',
        'clients',
        'buildings',
        'work_orders',
        'quotes',
        'reports',
    ];

    /** [tabla, columna, tabla referenciada] que dejan de ser CASCADE. */
    private array $restricted = [
        ['buildings', 'client_id', 'clients'],
        ['work_orders', 'building_id', 'buildings'],
        ['building_visits', 'building_id', 'buildings'],
        ['building_visits', 'user_id', 'users'],
        ['delivery_notes', 'building_id', 'buildings'],
        ['delivery_notes', 'user_id', 'users'],
        ['quotes', 'building_id', 'buildings'],
        ['reports', 'building_id', 'buildings'],
        ['reports', 'user_id', 'users'],
    ];

    public function up(): void
    {
        foreach ($this->softDeletes as $table) {
            if (! Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, fn (Blueprint $t) => $t->softDeletes());
            }
        }

        foreach ($this->restricted as [$table, $column, $references]) {
            Schema::table($table, function (Blueprint $t) use ($column, $references) {
                $t->dropForeign([$column]);
                $t->foreign($column)->references('id')->on($references)->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->restricted as [$table, $column, $references]) {
            Schema::table($table, function (Blueprint $t) use ($column, $references) {
                $t->dropForeign([$column]);
                $t->foreign($column)->references('id')->on($references)->cascadeOnDelete();
            });
        }

        foreach ($this->softDeletes as $table) {
            if (Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropSoftDeletes());
            }
        }
    }
};

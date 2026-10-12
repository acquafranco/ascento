<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Frecuencia real de visitas (Etapa 5).
 *
 * - building_visit_plans: cada cuánto se visita un edificio para
 *   mantenimiento o inspección. Es independiente de la frecuencia de cobro
 *   (maintenance_services.frequency). Sin fila = mensual, como siempre.
 *   Cada cambio es una fila nueva con su mes de inicio: los meses anteriores
 *   se siguen evaluando con la frecuencia que tenían (no se reinterpreta el
 *   historial).
 *
 * - building_visits.slot / period_key: identidad propia de cada visita
 *   periódica ("maintenance:2026-10:2" = segunda visita del período que
 *   empieza en octubre). El índice único (edificio + period_key) impide
 *   duplicados aunque dos técnicos firmen a la vez. Las visitas de órdenes
 *   de trabajo quedan con period_key NULL (no participan).
 *
 * Datos existentes: todos eran mensuales con una visita por mes, así que
 * cada visita existente es la visita 1 de su mes. Si ya había duplicados del
 * mismo edificio, tipo y mes (antes solo lo impedía el código), una sola
 * recibe la clave (la que tiene remito; entre iguales, la más antigua) y las
 * demás quedan como historial sin clave: no se borra ni modifica nada más.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Cada paso se puede repetir: en MySQL los cambios de estructura no son
        // transaccionales, así que si la migración se corta a mitad de camino,
        // volver a correrla completa lo que faltó sin fallar ni duplicar nada.
        // Si se cortó al crear la tabla (MySQL agrega claves e índices en
        // sentencias aparte), quedó vacía y sin su último índice: se recrea.
        if (Schema::hasTable('building_visit_plans')
            && ! Schema::hasIndex('building_visit_plans', ['company_id', 'building_id'])
            && DB::table('building_visit_plans')->doesntExist()) {
            Schema::drop('building_visit_plans');
        }

        if (! Schema::hasTable('building_visit_plans')) {
            Schema::create('building_visit_plans', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('building_id')->constrained()->cascadeOnDelete();
                $table->string('type', 20); // maintenance | inspection
                $table->unsignedTinyInteger('visits_per_month')->default(1); // 2 = quincenal
                $table->unsignedTinyInteger('every_months')->default(1);      // 1 = mensual, 2..12
                $table->date('starts_on');                                    // primer día del mes desde el que rige
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['building_id', 'type', 'starts_on']);
                $table->index(['company_id', 'building_id']);
            });
        }

        if (! Schema::hasColumn('building_visits', 'slot')) {
            Schema::table('building_visits', function (Blueprint $table) {
                $table->unsignedTinyInteger('slot')->default(1)->after('year');
            });
        }

        if (! Schema::hasColumn('building_visits', 'period_key')) {
            Schema::table('building_visits', function (Blueprint $table) {
                $table->string('period_key', 40)->nullable()->after('slot');
            });
        }

        // Datos: en una transacción (todo o nada) y por tandas de edificios,
        // sin cargar toda la tabla en memoria. Solo escribe period_key.
        $keyed = 0;
        $duplicates = 0;

        DB::transaction(function () use (&$keyed, &$duplicates) {
            DB::table('building_visits')
                ->where('visit_type', 'fixed')
                ->whereIn('assignment_type', ['maintenance', 'inspection'])
                ->select('building_id')->distinct()->orderBy('building_id')
                ->pluck('building_id')
                ->chunk(200)
                ->each(function ($buildingIds) use (&$keyed, &$duplicates) {
                    DB::table('building_visits')
                        ->whereIn('building_id', $buildingIds->all())
                        ->where('visit_type', 'fixed')
                        ->whereIn('assignment_type', ['maintenance', 'inspection'])
                        ->select(['id', 'building_id', 'assignment_type', 'month', 'year', 'period_key'])
                        ->selectSub(DB::table('delivery_notes')->selectRaw('count(*)')->whereColumn('delivery_notes.building_visit_id', 'building_visits.id'), 'notes')
                        ->get()
                        ->groupBy(fn ($v) => $v->building_id.'|'.$v->assignment_type.'|'.$v->year.'|'.$v->month)
                        ->each(function ($group) use (&$keyed, &$duplicates) {
                            $keep = $group->sortBy([['notes', 'desc'], ['id', 'asc']])->first();
                            $key = sprintf('%s:%04d-%02d:1', $keep->assignment_type, $keep->year, $keep->month);

                            if ($keep->period_key !== $key) {
                                DB::table('building_visits')->where('id', $keep->id)->update(['period_key' => $key]);
                            }

                            $keyed++;
                            $duplicates += $group->count() - 1;
                        });
                });
        });

        if ($duplicates > 0) {
            // Solo cantidades: ningún dato de clientes ni usuarios.
            logger()->warning('Visitas duplicadas existentes: quedan como historial sin clave de período', ['keyed' => $keyed, 'duplicates' => $duplicates]);
        }

        if (! Schema::hasIndex('building_visits', 'building_visits_period_unique')) {
            Schema::table('building_visits', function (Blueprint $table) {
                $table->unique(['building_id', 'period_key'], 'building_visits_period_unique');
            });
        }

        // Índice auxiliar que deja un rollback anterior (ver down()): ya no hace falta.
        if (Schema::hasIndex('building_visits', 'building_visits_building_id_period_fallback')) {
            Schema::table('building_visits', function (Blueprint $table) {
                $table->dropIndex('building_visits_building_id_period_fallback');
            });
        }
    }

    public function down(): void
    {
        // OJO: revertir pierde la frecuencia de visitas y el número de visita
        // (quincenales). Volver a migrar deja la 2ª visita de cada mes como
        // historial sin clave. Las visitas y los remitos no se borran.

        // MySQL usa el índice único para la clave foránea del edificio: antes
        // de quitarlo, uno simple sobre building_id (una sola vez).
        if (Schema::hasIndex('building_visits', 'building_visits_period_unique')) {
            if (! Schema::hasIndex('building_visits', 'building_visits_building_id_period_fallback')) {
                Schema::table('building_visits', function (Blueprint $table) {
                    $table->index('building_id', 'building_visits_building_id_period_fallback');
                });
            }

            Schema::table('building_visits', function (Blueprint $table) {
                $table->dropUnique('building_visits_period_unique');
            });
        }

        foreach (['period_key', 'slot'] as $column) {
            if (Schema::hasColumn('building_visits', $column)) {
                Schema::table('building_visits', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }

        Schema::dropIfExists('building_visit_plans');
    }
};

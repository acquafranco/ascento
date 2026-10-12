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

        Schema::table('building_visits', function (Blueprint $table) {
            $table->unsignedTinyInteger('slot')->default(1)->after('year');
            $table->string('period_key', 40)->nullable()->after('slot');
        });

        $keyed = 0;
        $duplicates = 0;

        DB::table('building_visits')
            ->where('visit_type', 'fixed')
            ->whereIn('assignment_type', ['maintenance', 'inspection'])
            ->orderBy('building_id')->orderBy('assignment_type')->orderBy('year')->orderBy('month')
            ->select(['id', 'building_id', 'assignment_type', 'month', 'year'])
            ->selectSub(DB::table('delivery_notes')->selectRaw('count(*)')->whereColumn('delivery_notes.building_visit_id', 'building_visits.id'), 'notes')
            ->get()
            ->groupBy(fn ($v) => $v->building_id.'|'.$v->assignment_type.'|'.$v->year.'|'.$v->month)
            ->each(function ($group) use (&$keyed, &$duplicates) {
                $keep = $group->sortBy([['notes', 'desc'], ['id', 'asc']])->first();

                DB::table('building_visits')->where('id', $keep->id)->update([
                    'period_key' => sprintf('%s:%04d-%02d:1', $keep->assignment_type, $keep->year, $keep->month),
                ]);

                $keyed++;
                $duplicates += $group->count() - 1;
            });

        if ($duplicates > 0) {
            // Solo cantidades: ningún dato de clientes ni usuarios.
            logger()->warning('Visitas duplicadas existentes: quedan como historial sin clave de período', ['keyed' => $keyed, 'duplicates' => $duplicates]);
        }

        Schema::table('building_visits', function (Blueprint $table) {
            $table->unique(['building_id', 'period_key'], 'building_visits_period_unique');
        });
    }

    public function down(): void
    {
        // MySQL usa el índice único para la clave foránea del edificio: antes
        // de quitarlo, uno simple sobre building_id.
        Schema::table('building_visits', function (Blueprint $table) {
            $table->index('building_id', 'building_visits_building_id_period_fallback');
        });

        Schema::table('building_visits', function (Blueprint $table) {
            $table->dropUnique('building_visits_period_unique');
        });

        Schema::table('building_visits', function (Blueprint $table) {
            $table->dropColumn(['slot', 'period_key']);
        });

        Schema::dropIfExists('building_visit_plans');
    }
};

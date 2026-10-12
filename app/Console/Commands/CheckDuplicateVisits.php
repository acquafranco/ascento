<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Solo lectura (únicamente consultas SELECT): visitas periódicas repetidas
 * (mismo edificio, tipo y mes) que se cargaron antes del índice único. Se
 * puede correr antes y después de migrar.
 *
 * La migración deja UNA visita por grupo con identidad (la que tiene remito;
 * entre iguales, la de menor id) y las demás como historial sin contar. Para
 * cada grupo muestra qué visita conserva la identidad, cuáles quedan como
 * historial y cuántos remitos tiene cada una. "Revisar" marca los grupos
 * donde más de una visita tiene remito: esos remitos siguen existiendo y se
 * ven en el historial, pero solo uno cuenta en el progreso del mes.
 *
 * No muestra datos personales: solo ids.
 */
class CheckDuplicateVisits extends Command
{
    protected $signature = 'visits:check-duplicates';

    protected $description = 'Lista visitas periódicas duplicadas del mismo edificio, tipo y mes (solo lectura)';

    public function handle(): int
    {
        $groups = DB::table('building_visits')
            ->where('visit_type', 'fixed')
            ->whereIn('assignment_type', ['maintenance', 'inspection'])
            ->select('company_id', 'building_id', 'assignment_type', 'year', 'month', DB::raw('count(*) as total'))
            ->groupBy('company_id', 'building_id', 'assignment_type', 'year', 'month')
            ->havingRaw('count(*) > 1')
            ->orderBy('company_id')->orderBy('building_id')->orderBy('year')->orderBy('month')
            ->get();

        $this->line('Visitas periódicas (mantenimiento e inspección): '.DB::table('building_visits')->where('visit_type', 'fixed')->whereIn('assignment_type', ['maintenance', 'inspection'])->count());

        if ($groups->isEmpty()) {
            $this->info('No hay visitas periódicas duplicadas: la migración no deja ninguna visita como historial sin clave.');

            return self::SUCCESS;
        }

        $migrated = Schema::hasColumn('building_visits', 'period_key');
        $review = 0;
        $extra = 0;

        $rows = $groups->map(function ($g) use ($migrated, &$review, &$extra) {
            $visits = DB::table('building_visits')
                ->where('building_id', $g->building_id)->where('assignment_type', $g->assignment_type)
                ->where('year', $g->year)->where('month', $g->month)->where('visit_type', 'fixed')
                ->select(['id'])
                ->when($migrated, fn ($q) => $q->addSelect('period_key'))
                ->selectSub(DB::table('delivery_notes')->selectRaw('count(*)')->whereColumn('delivery_notes.building_visit_id', 'building_visits.id'), 'notes')
                ->orderBy('id')
                ->get();

            // Misma regla que la migración: la que tiene remito; entre iguales, la de menor id.
            $keep = $migrated
                ? $visits->first(fn ($v) => $v->period_key !== null)
                : $visits->sortBy([['notes', 'desc'], ['id', 'asc']])->first();
            $withNotes = $visits->where('notes', '>', 0)->count();
            $review += (int) ($withNotes > 1);
            $extra += $visits->count() - 1;

            return [
                $g->company_id,
                $g->building_id,
                $g->assignment_type,
                sprintf('%04d-%02d', $g->year, $g->month),
                $keep ? $keep->id.' ('.$keep->notes.' remito'.((int) $keep->notes === 1 ? '' : 's').')' : '—',
                $visits->reject(fn ($v) => $v->id === $keep?->id)->map(fn ($v) => $v->id.' ('.$v->notes.')')->implode(', '),
                $withNotes > 1 ? 'Revisar' : '',
            ];
        });

        $this->table(['Empresa', 'Edificio', 'Tipo', 'Mes', $migrated ? 'Con identidad' : 'Conservará identidad', 'Quedan como historial (remitos)', ''], $rows->all());

        $this->warn("Grupos duplicados: {$groups->count()}");
        $this->warn("Visitas que quedan como historial sin contar: {$extra}");
        $this->warn("Grupos con más de un remito (revisar): {$review}");
        $this->line('Nada se borra: todas las visitas y sus remitos siguen en el historial. Solo la visita con identidad cuenta en el progreso del mes.');

        return self::SUCCESS;
    }
}

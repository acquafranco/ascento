<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Solo lectura: visitas periódicas repetidas (mismo edificio, tipo y mes)
 * que se cargaron antes del índice único. La migración deja una con
 * identidad (la que tiene remito; si no, la más antigua) y las demás como
 * historial sin contar; este comando las lista para revisarlas. No modifica
 * nada y no muestra datos personales (solo ids).
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
            ->select('company_id', 'building_id', 'assignment_type', 'year', 'month', DB::raw('count(*) as total'), DB::raw('min(id) as first_id'), DB::raw('max(id) as last_id'))
            ->groupBy('company_id', 'building_id', 'assignment_type', 'year', 'month')
            ->having('total', '>', 1)
            ->orderBy('company_id')->orderBy('building_id')
            ->get();

        if ($groups->isEmpty()) {
            $this->info('No hay visitas periódicas duplicadas.');

            return self::SUCCESS;
        }

        $this->table(['Empresa', 'Edificio', 'Tipo', 'Período', 'Cantidad', 'Ids'], $groups->map(fn ($g) => [
            $g->company_id, $g->building_id, $g->assignment_type, sprintf('%04d-%02d', $g->year, $g->month), $g->total, $g->first_id.' … '.$g->last_id,
        ])->all());
        $this->warn("Grupos duplicados: {$groups->count()}. Se conservan como historial; solo una visita por grupo cuenta en el progreso.");

        return self::SUCCESS;
    }
}

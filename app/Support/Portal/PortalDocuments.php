<?php

namespace App\Support\Portal;

use App\Models\Quote;
use App\Models\Report;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Centro de documentos del portal: remitos, reportes, presupuestos y
 * documentos técnicos compartidos, de los edificios autorizados de la empresa
 * activa. Todo se filtra, ordena y pagina en la base (no se cargan cientos de
 * registros para ocultarlos en el navegador).
 *
 * Cada tipo es un SELECT con las mismas columnas; "Todos" es su UNION.
 */
class PortalDocuments
{
    public const TYPES = [
        'delivery_note' => 'Remitos',
        'report' => 'Reportes',
        'quote' => 'Presupuestos',
        'document' => 'Documentación técnica',
    ];

    public const PER_PAGE = 15;

    /**
     * @param  array{type?: ?string, building?: ?int, status?: ?string, from?: ?string, to?: ?string, q?: ?string, sort?: ?string}  $filters
     * @param  list<int>  $buildingIds  edificios autorizados (de la empresa activa)
     */
    public static function paginate(int $companyId, array $buildingIds, array $filters, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        $type = array_key_exists((string) ($filters['type'] ?? ''), self::TYPES) ? $filters['type'] : null;
        $buildings = isset($filters['building']) && in_array((int) $filters['building'], $buildingIds, true) ? [(int) $filters['building']] : $buildingIds;

        $selects = collect(self::TYPES)->keys()
            ->filter(fn ($t) => $type === null || $t === $type)
            ->map(fn ($t) => static::select($t, $companyId, $buildings, $filters))
            ->values();

        $union = $selects->shift();
        foreach ($selects as $select) {
            $union->unionAll($select);
        }

        return DB::query()->fromSub($union, 'documents')
            ->orderBy('happened_at', ($filters['sort'] ?? 'desc') === 'asc' ? 'asc' : 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** Conteo por tipo con los mismos filtros (para las pestañas). */
    public static function counts(int $companyId, array $buildingIds, array $filters): array
    {
        $buildings = isset($filters['building']) && in_array((int) $filters['building'], $buildingIds, true) ? [(int) $filters['building']] : $buildingIds;

        return collect(self::TYPES)->keys()
            ->mapWithKeys(fn ($t) => [$t => DB::query()->fromSub(static::select($t, $companyId, $buildings, $filters), 'd')->count()])
            ->all();
    }

    private static function select(string $type, int $companyId, array $buildingIds, array $filters): Builder
    {
        $query = match ($type) {
            'delivery_note' => DB::table('delivery_notes')->where('delivery_notes.company_id', $companyId)
                ->whereIn('delivery_notes.building_id', $buildingIds ?: [0])->where('delivery_notes.shared_with_client', true)
                ->selectRaw("'delivery_note' as kind, delivery_notes.id, delivery_notes.building_id, delivery_notes.created_at as happened_at, delivery_notes.number as ref, delivery_notes.description as summary, delivery_notes.assignment_type as status, null as amount"),
            'report' => DB::table('reports')->where('reports.company_id', $companyId)->whereNull('reports.deleted_at')
                ->whereIn('reports.building_id', $buildingIds ?: [0])->where('reports.shared_with_client', true)
                ->selectRaw("'report' as kind, reports.id, reports.building_id, reports.created_at as happened_at, reports.elevator_number as ref, reports.description as summary, reports.status as status, null as amount"),
            'quote' => DB::table('quotes')->where('quotes.company_id', $companyId)->whereNull('quotes.deleted_at')
                ->whereIn('quotes.building_id', $buildingIds ?: [0])->where('quotes.shared_with_client', true)
                ->selectRaw("'quote' as kind, quotes.id, quotes.building_id, coalesce(quotes.issued_at, quotes.created_at) as happened_at, null as ref, quotes.title as summary, quotes.status as status, quotes.amount as amount"),
            'document' => DB::table('elevator_documents')->join('elevators', 'elevators.id', '=', 'elevator_documents.elevator_id')
                ->where('elevator_documents.company_id', $companyId)->whereIn('elevators.building_id', $buildingIds ?: [0])
                ->where('elevator_documents.shared_with_client', true)
                ->selectRaw("'document' as kind, elevator_documents.id, elevators.building_id as building_id, elevator_documents.created_at as happened_at, elevators.label as ref, elevator_documents.title as summary, elevator_documents.type as status, null as amount"),
        };

        $date = match ($type) {
            'quote' => 'quotes.created_at',
            'document' => 'elevator_documents.created_at',
            default => $type === 'delivery_note' ? 'delivery_notes.created_at' : 'reports.created_at',
        };

        if ($from = static::date($filters['from'] ?? null)) {
            $query->where($date, '>=', $from->startOfDay());
        }
        if ($to = static::date($filters['to'] ?? null)) {
            $query->where($date, '<=', $to->endOfDay());
        }

        // Estado: solo aplica al tipo que lo tiene (reportes / presupuestos).
        $status = (string) ($filters['status'] ?? '');
        if ($status !== '') {
            match ($type) {
                'report' => array_key_exists($status, Report::STATUS_LABELS) ? $query->where('reports.status', $status) : $query->whereRaw('1 = 0'),
                'quote' => array_key_exists($status, Quote::STATUSES) ? $query->where('quotes.status', $status) : $query->whereRaw('1 = 0'),
                default => $query->whereRaw('1 = 0'),
            };
        }

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $like = '%'.mb_strtolower(str_replace(['%', '_'], ['\%', '\_'], mb_substr($search, 0, 80))).'%';
            [$a, $b] = match ($type) {
                'delivery_note' => ['delivery_notes.number', 'delivery_notes.description'],
                'report' => ['reports.elevator_number', 'reports.description'],
                'quote' => ['quotes.title', 'quotes.description'],
                'document' => ['elevator_documents.title', 'elevators.label'],
            };
            $query->where(fn ($q) => $q->whereRaw("lower({$a}) like ?", [$like])->orWhereRaw("lower({$b}) like ?", [$like]));
        }

        return $query;
    }

    private static function date(?string $value): ?Carbon
    {
        if (! $value || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }
    }
}

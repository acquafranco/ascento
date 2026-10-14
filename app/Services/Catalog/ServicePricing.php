<?php

namespace App\Services\Catalog;

use App\Models\Building;
use App\Models\Elevator;
use App\Models\ServiceCatalogItem;
use Illuminate\Support\Collection;

/**
 * Importe de una contratación según su modalidad y alcance. Transparente:
 * devuelve las líneas (por edificio) y una explicación legible. Lo usan la
 * vista previa del formulario y el guardado (siempre recalculado en el
 * servidor, nunca tomado del navegador). Sin fórmulas comerciales nuevas:
 *
 * - Importe fijo / precio acordado: el importe ingresado.
 * - Por equipo: precio × equipos activos alcanzados (del tipo que indique el servicio).
 * - Por parada: precio × paradas de esos equipos (las cargadas en su ficha; si
 *   falta alguna, no se adivina: se pide completarla o elegir otra modalidad).
 *
 * Alcance: todo el cliente (sus edificios activos), un edificio o algunos
 * equipos de un edificio. Nunca mezcla empresas: todo se filtra por empresa.
 */
final class ServicePricing
{
    /**
     * @param  list<string>|null  $units  etiquetas de equipos del edificio («Ascensor 1»); vacío = todos
     * @return array{quantity: int, unit_price: float, total: float, lines: list<array{building: string, quantity: int, detail: string}>, explanation: string, error: ?string}
     */
    public function calculate(int $companyId, string $mode, float $unitPrice, int $clientId, ?int $buildingId, ?array $units = null, ?string $equipmentKind = null): array
    {
        $mode = array_key_exists($mode, ServiceCatalogItem::PRICING_MODES) ? $mode : ServiceCatalogItem::CUSTOM;
        $unitPrice = max(0, round($unitPrice, 2));

        if (! in_array($mode, [ServiceCatalogItem::PER_EQUIPMENT, ServiceCatalogItem::PER_STOP], true)) {
            $label = ServiceCatalogItem::PRICING_MODES[$mode][1];

            return $this->result(1, $unitPrice, $unitPrice, [], "{$label}: ".self::money($unitPrice));
        }

        $equipment = $this->equipment($companyId, $clientId, $buildingId, $units, $equipmentKind);
        $kindLabel = ServiceCatalogItem::EQUIPMENT_KINDS[$equipmentKind ?: 'all'] ?? ServiceCatalogItem::EQUIPMENT_KINDS['all'];

        if ($equipment->isEmpty()) {
            return $this->result(0, $unitPrice, 0, [], '', 'Este alcance no tiene equipos cargados ('.mb_strtolower($kindLabel).'). Cargalos en el edificio o elegí otra modalidad.');
        }

        if ($mode === ServiceCatalogItem::PER_STOP) {
            $missing = $equipment->filter(fn (Elevator $e) => (int) $e->stops < 1);
            if ($missing->isNotEmpty()) {
                return $this->result(0, $unitPrice, 0, [], '', 'Faltan las paradas en la ficha de: '.$missing->map->displayName()->take(5)->implode(', ').($missing->count() > 5 ? ' y otros' : '').'. Completalas o elegí otra modalidad.');
            }
        }

        $count = fn (Collection $group) => $mode === ServiceCatalogItem::PER_STOP ? (int) $group->sum('stops') : $group->count();
        $word = fn (int $n) => $mode === ServiceCatalogItem::PER_STOP ? ($n === 1 ? 'parada' : 'paradas') : ($n === 1 ? 'equipo' : 'equipos');

        $lines = $equipment->groupBy('building_id')->map(function (Collection $group) use ($count, $word) {
            $n = $count($group);

            return ['building' => (string) $group->first()->building?->name, 'quantity' => $n, 'detail' => "{$n} {$word($n)} (".$group->pluck('label')->implode(', ').')'];
        })->values()->all();

        $quantity = $count($equipment);
        $total = round($quantity * $unitPrice, 2);

        return $this->result($quantity, $unitPrice, $total, $lines, "{$quantity} {$word($quantity)} × ".self::money($unitPrice).' = '.self::money($total));
    }

    /** Equipos activos alcanzados, de la empresa y el cliente (o del edificio), del tipo pedido. */
    public function equipment(int $companyId, int $clientId, ?int $buildingId, ?array $units = null, ?string $equipmentKind = null): Collection
    {
        $buildingIds = $buildingId
            ? Building::withoutGlobalScopes()->where('company_id', $companyId)->where('client_id', $clientId)->whereKey($buildingId)->pluck('id')
            : Building::withoutGlobalScopes()->where('company_id', $companyId)->where('client_id', $clientId)->whereNull('deleted_at')->pluck('id');

        return Elevator::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('building_id', $buildingIds->all() ?: [0])
            ->where('is_active', true)
            ->when(in_array($equipmentKind, ['elevator', 'freight'], true), fn ($q) => $q->where('kind', $equipmentKind))
            ->when($buildingId && array_filter((array) $units), fn ($q) => $q->whereIn('label', array_values(array_filter((array) $units))))
            ->with(['building' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'name')])
            ->orderBy('building_id')->orderBy('kind')->orderBy('id')
            ->get();
    }

    public static function money(float $value): string
    {
        return '$'.number_format($value, fmod($value, 1.0) === 0.0 ? 0 : 2, ',', '.');
    }

    private function result(int $quantity, float $unitPrice, float $total, array $lines, string $explanation, ?string $error = null): array
    {
        return ['quantity' => $quantity, 'unit_price' => $unitPrice, 'total' => $total, 'lines' => $lines, 'explanation' => $explanation, 'error' => $error];
    }
}

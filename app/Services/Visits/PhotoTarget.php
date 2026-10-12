<?php

namespace App\Services\Visits;

use App\Models\BuildingVisit;
use App\Models\DeliveryNote;
use App\Models\VisitPhoto;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;

/**
 * A qué pertenecen unas fotos: una visita periódica (mantenimiento o
 * inspección, en curso o ya con remito) o una orden de trabajo (antes de su
 * remito no tiene visita; al firmarlo, sus fotos pasan también a la visita
 * de la orden). El tipo de trabajo decide qué interruptor de la empresa
 * habilita las cargas y quiénes pueden trabajar con ellas.
 */
final class PhotoTarget
{
    private function __construct(
        public readonly int $companyId,
        public readonly int $buildingId,
        public readonly string $type,
        public readonly ?BuildingVisit $visit,
        public readonly ?WorkOrder $workOrder,
    ) {}

    public static function forVisit(BuildingVisit $visit): self
    {
        if ($visit->visit_type === 'work_order' && $visit->work_order_id) {
            $workOrder = WorkOrder::withoutGlobalScopes()->find($visit->work_order_id);

            if ($workOrder) {
                return new self((int) $visit->company_id, (int) $visit->building_id, 'work_order', $visit, $workOrder);
            }
        }

        abort_unless($visit->visit_type === 'fixed' && in_array($visit->assignment_type, VisitSchedule::TYPES, true), 404);

        return new self((int) $visit->company_id, (int) $visit->building_id, $visit->assignment_type, $visit, null);
    }

    public static function forWorkOrder(WorkOrder $workOrder): self
    {
        $visit = BuildingVisit::withoutGlobalScopes()
            ->where('company_id', $workOrder->company_id)
            ->where('work_order_id', $workOrder->id)->where('visit_type', 'work_order')
            ->orderBy('id')->first();

        return new self((int) $workOrder->company_id, (int) $workOrder->building_id, 'work_order', $visit, $workOrder);
    }

    /** Fotos de este destino (de la visita y, en una orden, también las de antes del remito). */
    public function photos(): Builder
    {
        return VisitPhoto::withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->where(function (Builder $q) {
                if ($this->visit) {
                    $q->orWhere('building_visit_id', $this->visit->id);
                }
                if ($this->workOrder) {
                    $q->orWhere('work_order_id', $this->workOrder->id);
                }
            });
    }

    /** Remito ya firmado de este destino (para vincular las fotos que llegan después). */
    public function deliveryNoteId(): ?int
    {
        if (! $this->visit) {
            return null;
        }

        $id = DeliveryNote::withoutGlobalScopes()->where('building_visit_id', $this->visit->id)->value('id');

        return $id ? (int) $id : null;
    }
}

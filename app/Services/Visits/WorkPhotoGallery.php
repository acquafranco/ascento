<?php

namespace App\Services\Visits;

use App\Models\DeliveryNote;
use App\Models\User;
use App\Models\VisitPhoto;
use App\Models\WorkOrder;
use Illuminate\Support\Collection;

/**
 * «Fotos del trabajo» dentro de cada registro del panel: el remito de un
 * mantenimiento o una inspección (fotos de su visita) y la orden de trabajo
 * (fotos de la orden, antes y después de su remito). No hay una galería
 * global: cada trabajo muestra solo las suyas.
 *
 * Siempre de la empresa del registro y filtradas con VisitPhoto::
 * canBeViewedBy (el admin ve todas las de SU empresa; otra empresa, nada).
 * Las imágenes se sirven por la ruta privada, que vuelve a verificar todo.
 */
class WorkPhotoGallery
{
    /** Fotos de un remito: las de su visita y, si es de una orden, también las de la orden. */
    public function forDeliveryNote(DeliveryNote $note, ?User $user): Collection
    {
        if (! $note->building_visit_id && ! $note->work_order_id) {
            return collect();
        }

        $query = VisitPhoto::withoutGlobalScopes()
            ->where('company_id', $note->company_id)
            ->where(function ($q) use ($note) {
                if ($note->building_visit_id) {
                    $q->orWhere('building_visit_id', $note->building_visit_id);
                }
                if ($note->work_order_id) {
                    $q->orWhere('work_order_id', $note->work_order_id);
                }
            });

        return $this->visible($query, $user);
    }

    /** Fotos de una orden de trabajo (antes y después de su remito). */
    public function forWorkOrder(WorkOrder $workOrder, ?User $user): Collection
    {
        return $this->visible(PhotoTarget::forWorkOrder($workOrder)->photos(), $user);
    }

    /**
     * Antes y Después, cada uno por sección.
     *
     * @return array<string, array{label: string, sections: Collection}>
     */
    public static function group(Collection $photos): array
    {
        $result = [];

        foreach (VisitPhoto::STAGES as $stage => $label) {
            $result[$stage] = [
                'label' => $label,
                'sections' => $photos->where('stage', $stage)->groupBy('section_name'),
            ];
        }

        return $result;
    }

    private function visible($query, ?User $user): Collection
    {
        if (! $user) {
            return collect();
        }

        return $query->with(['uploader:id,name', 'elevator:id,label'])
            ->orderBy('photo_section_id')->orderBy('id')->get()
            ->filter(fn (VisitPhoto $photo) => $photo->canBeViewedBy($user))
            ->values();
    }
}

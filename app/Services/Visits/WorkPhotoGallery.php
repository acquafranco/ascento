<?php

namespace App\Services\Visits;

use App\Models\DeliveryNote;
use App\Models\User;
use App\Models\VisitPhoto;
use App\Models\WorkOrder;
use App\Support\PhotoZones;
use Illuminate\Support\Collection;

/**
 * «Fotos de antes y después» dentro de cada trabajo del panel: el
 * mantenimiento (fotos de su visita), la orden de trabajo (fotos de la
 * orden, antes y después de su remito) y, solo lectura, las fotos de
 * inspecciones del flujo anterior. Nunca en el remito. No hay una galería
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
     * Por zona (en el orden del sistema; después, las secciones viejas con su
     * nombre), cada una con Antes y Después para mostrarlos uno al lado del otro.
     *
     * @return Collection<int, array{key: string, label: string, before: Collection, after: Collection}>
     */
    public static function byZone(Collection $photos): Collection
    {
        $order = array_flip(array_keys(PhotoZones::LABELS));

        return $photos
            ->groupBy(fn (VisitPhoto $photo) => self::zoneOf($photo) ?? 'legacy:'.$photo->section_name)
            ->map(fn (Collection $zonePhotos, string $key) => [
                'key' => $key,
                'label' => PhotoZones::LABELS[$key] ?? $zonePhotos->first()->section_name,
                'before' => $zonePhotos->where('stage', 'before')->values(),
                'after' => $zonePhotos->where('stage', 'after')->values(),
            ])
            ->sortBy(fn (array $zone) => [$order[$zone['key']] ?? 99, $zone['label']])
            ->values();
    }

    /** Código de zona del sistema de una foto (también las cargadas antes de las zonas). */
    public static function zoneOf(VisitPhoto $photo): ?string
    {
        $code = $photo->relationLoaded('section') ? $photo->section?->code : null;

        return $code ?? PhotoZones::codeForLegacyName((string) $photo->section_name);
    }

    private function visible($query, ?User $user): Collection
    {
        if (! $user) {
            return collect();
        }

        return $query->with(['uploader:id,name', 'elevator:id,label', 'section' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'code')])
            ->orderBy('photo_section_id')->orderBy('id')->get()
            ->filter(fn (VisitPhoto $photo) => $photo->canBeViewedBy($user))
            ->values();
    }
}

<?php

namespace App\Notifications\App;

use App\Models\Building;
use App\Models\DeliveryNote;
use App\Models\Elevator;
use App\Models\ElevatorDocument;
use App\Models\Quote;
use App\Models\Report;
use App\Notifications\AppNotification;
use Illuminate\Database\Eloquent\Model;

/**
 * Al cliente (portal): la empresa compartió un remito, reporte, presupuesto
 * o documento de uno de sus edificios. Solo se crea al compartir (acción
 * explícita del admin), una vez por registro, y solo para usuarios con ese
 * edificio autorizado. El correo es un resumen por acción (ClientShareNotifier).
 */
class SharedWithClientNotification extends AppNotification
{
    public function __construct(
        public string $recordType,
        public int $recordId,
        public int $building,
        public string $what,
        public string $buildingName,
        public string $companyName,
        public ?string $target,
    ) {}

    /** Null si el registro no se puede compartir o no tiene edificio. */
    public static function for(Model $record, string $companyName): ?self
    {
        [$what, $buildingId, $target] = match (true) {
            $record instanceof DeliveryNote => ['el remito '.$record->number, $record->building_id, route('portal.delivery-note', $record, false)],
            $record instanceof Report => ['un reporte técnico', $record->building_id, route('portal.report', $record, false)],
            $record instanceof Quote => ['el presupuesto "'.$record->title.'"', $record->building_id, route('portal.quote', $record, false)],
            $record instanceof ElevatorDocument => ['el documento "'.$record->title.'"', Elevator::withoutGlobalScopes()->whereKey($record->elevator_id)->value('building_id'), null],
            default => [null, null, null],
        };

        if (! $what || ! $buildingId) {
            return null;
        }

        $building = Building::withoutGlobalScopes()->find($buildingId);

        return new self($record::class, $record->getKey(), (int) $buildingId, $what, (string) $building?->name, $companyName,
            $target ?? route('portal.building', $buildingId, false));
    }

    public function title(): string
    {
        return 'Nueva información de '.$this->buildingName;
    }

    public function body(): string
    {
        return $this->companyName.' compartió '.$this->what.'.';
    }

    public function path(): ?string
    {
        return $this->target;
    }

    public function buildingId(): ?int
    {
        return $this->building;
    }

    public function dedupeKey(): ?string
    {
        return 'shared:'.class_basename($this->recordType).':'.$this->recordId;
    }

    public function icon(): string
    {
        return 'heroicon-o-document-text';
    }
}

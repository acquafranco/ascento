<?php

namespace App\Models;

use App\Enums\PermissionArea;
use App\Models\Concerns\BelongsToCompany;
use App\Services\Visits\PhotoTarget;
use App\Services\Visits\VisitPhotoService;
use App\Support\Access\Access;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Foto de antes o después de una visita (mantenimiento o inspección).
 *
 * - El archivo vive en el disco privado (visits/{empresa}/…) y solo se sirve
 *   por VisitPhotoController, con sesión y permisos: admin de la empresa,
 *   técnico que hizo la visita o participó, y (mientras se completa el
 *   remito) quien la subió. Nunca el portal ni el enlace público.
 * - Al borrar la foto se borra el archivo: no quedan huérfanos.
 */
class VisitPhoto extends Model
{
    use BelongsToCompany;

    public const STAGES = ['before' => 'Antes', 'after' => 'Después'];

    /** Fotos por sección y estado (antes / después). */
    public const MAX_PER_GROUP = 3;

    /** Fotos por visita. */
    public const MAX_PER_VISIT = 30;

    /** Por archivo subido (igual que las fotos de reportes). */
    public const MAX_KB = 10240;

    /** Total guardado por visita (ya procesado): evita cargas excesivas. */
    public const MAX_TOTAL_BYTES = 60 * 1024 * 1024;

    protected $casts = [
        'building_visit_id' => 'integer',
        'work_order_id' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'size' => 'integer',
        'attached_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::deleted(fn (VisitPhoto $photo) => $photo->deleteFiles());
    }

    public function building()
    {
        return $this->belongsTo(Building::class)->withTrashed();
    }

    public function visit()
    {
        return $this->belongsTo(BuildingVisit::class, 'building_visit_id');
    }

    public function deliveryNote()
    {
        return $this->belongsTo(DeliveryNote::class);
    }

    public function section()
    {
        return $this->belongsTo(PhotoSection::class, 'photo_section_id');
    }

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class)->withoutGlobalScopes();
    }

    public function elevator()
    {
        return $this->belongsTo(Elevator::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by')->withTrashed();
    }

    /** Foto del flujo anterior subida desde el formulario del remito y nunca vinculada. */
    public function isStaged(): bool
    {
        return $this->building_visit_id === null && $this->work_order_id === null;
    }

    /** Path seguro: dentro de la carpeta de la empresa y sin saltos de directorio. */
    public function hasSafePath(?string $path = null): bool
    {
        $path ??= $this->path;

        return is_string($path) && $path !== ''
            && str_starts_with($path, 'visits/'.$this->company_id.'/')
            && ! str_contains($path, '..');
    }

    /**
     * ¿Puede verla? Misma empresa y, además:
     * - el admin de la empresa (y el SuperAdmin en la empresa elegida): todas;
     * - quien la subió;
     * - quien trabaja en esa visita u orden (PhotoTarget / canWorkOn): técnico
     *   asignado al edificio para ese tipo o a la orden, o quien hizo la
     *   visita o participó.
     * Los clientes del portal, nunca. No depende de que las fotos estén
     * habilitadas: desactivarlas solo impide cargas nuevas.
     */
    public function canBeViewedBy(?User $user): bool
    {
        if (! $user || $user->isClientUser()) {
            return false;
        }

        $companyId = $user->isSuperAdmin() ? (int) session('selected_company_id') : (int) $user->company_id;

        if ($companyId !== (int) $this->company_id) {
            return false;
        }

        // Exclusivas del plan Empresa: con otro plan se conservan, sin acceso.
        if (! Company::hasWorkPhotosById((int) $this->company_id)) {
            return false;
        }

        // Dueño y SuperAdmin; un administrativo, con el área del trabajo (Access).
        if (Access::can($user, PermissionArea::forJobType($this->assignment_type)) || (int) $this->uploaded_by === (int) $user->id) {
            return true;
        }

        $target = $this->target();

        return $target !== null && app(VisitPhotoService::class)->canWorkOn($user, $target);
    }

    /** Visita u orden a la que pertenece (null: foto en preparación del flujo anterior). */
    public function target(): ?PhotoTarget
    {
        if ($this->building_visit_id && ($visit = BuildingVisit::withoutGlobalScopes()->find($this->building_visit_id))) {
            return PhotoTarget::forVisit($visit);
        }

        if ($this->work_order_id && ($workOrder = WorkOrder::withoutGlobalScopes()->find($this->work_order_id))) {
            return PhotoTarget::forWorkOrder($workOrder);
        }

        return null;
    }

    public function url(): string
    {
        return route('visit-photos.show', ['visitPhoto' => $this->id]);
    }

    public function downloadUrl(): string
    {
        return $this->url().'?download=1';
    }

    public function thumbUrl(): string
    {
        return $this->url().'?thumb=1';
    }

    public function deleteFiles(): void
    {
        foreach (array_filter([$this->path, $this->thumb_path]) as $path) {
            if ($this->hasSafePath($path)) {
                Storage::disk('local')->delete($path);
            }
        }
    }
}

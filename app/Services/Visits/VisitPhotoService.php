<?php

namespace App\Services\Visits;

use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\Elevator;
use App\Models\PhotoSection;
use App\Models\User;
use App\Models\VisitPhoto;
use App\Models\WorkOrder;
use App\Services\Media\PhotoProcessor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Fotos de antes y después de una visita (mantenimiento o inspección) o de
 * una orden de trabajo. Se cargan desde Visitas (o desde la orden), antes,
 * durante o después del trabajo, de a una: cada foto queda guardada en el
 * momento, vinculada a su visita u orden, así no se pierde nada por un error
 * del remito, un reintento o un corte de señal. No hay que cargarlas para
 * firmar el remito.
 *
 * - Cargas nuevas: solo si la empresa habilitó las fotos para ese tipo de
 *   trabajo y el usuario trabaja en esa visita u orden (todo verificado acá).
 * - Límites (también con subidas simultáneas, bajo bloqueo de la visita u
 *   orden): 3 por sección y estado, 30 por visita y 60 MB por visita.
 * - Reintento con la misma identidad (client_key): devuelve la foto que ya
 *   estaba, sin duplicar.
 */
class VisitPhotoService
{
    public function __construct(private PhotoProcessor $processor) {}

    /*
    |--------------------------------------------------------------------------
    | PERMISOS (siempre en el servidor)
    |--------------------------------------------------------------------------
    */

    /**
     * ¿Trabaja en esta visita u orden? Admin de la empresa; técnico asignado
     * al edificio para ese tipo (mantenimiento / inspección) o a la orden; o
     * quien hizo la visita o participó de ella. Clientes y otras empresas,
     * nunca.
     */
    public function canWorkOn(?User $user, PhotoTarget $target): bool
    {
        if (! $user || $user->isClientUser() || $user->isSuperAdmin()) {
            return false;
        }

        if ((int) $user->company_id !== $target->companyId) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        if ($target->visit && ((int) $target->visit->user_id === (int) $user->id
            || $target->visit->participants()->where('users.id', $user->id)->exists())) {
            return true;
        }

        if ($target->type === 'work_order') {
            return (bool) $target->workOrder?->users()->whereKey($user->id)->exists();
        }

        return Building::withoutGlobalScopes()->whereKey($target->buildingId)->first()
            ?->users()->where('users.id', $user->id)->wherePivot('type', $target->type)->exists() ?? false;
    }

    /** Puede cargar fotos NUEVAS: trabaja en ella y la empresa las habilitó para ese tipo. */
    public function canUpload(?User $user, PhotoTarget $target): bool
    {
        return $this->canWorkOn($user, $target) && (bool) Company::find($target->companyId)?->photosEnabledFor($target->type);
    }

    /**
     * Lo que necesita «Fotos del trabajo» en el celular (la visita o la
     * orden): sus fotos, las secciones activas (más las de fotos históricas
     * aunque estén desactivadas), los equipos del edificio y si puede cargar.
     *
     * @return array{photos: Collection, sections: Collection, elevators: Collection, canUpload: bool}
     */
    public function managerData(User $user, PhotoTarget $target): array
    {
        $photos = (clone $target->photos())->with(['uploader:id,name', 'elevator:id,label'])->orderBy('id')->get();
        $sections = PhotoSection::forCompany($target->companyId);
        $legacy = $photos->whereNotIn('photo_section_id', $sections->pluck('id'))->unique('photo_section_id')
            ->map(fn (VisitPhoto $p) => (new PhotoSection)->forceFill(['id' => $p->photo_section_id, 'name' => $p->section_name, 'is_active' => false]));

        return [
            'photos' => $photos,
            'sections' => $sections->concat($legacy->values()),
            'elevators' => Elevator::withoutGlobalScopes()->where('building_id', $target->buildingId)->where('company_id', $target->companyId)
                ->where('is_active', true)->orderBy('label')->get(['id', 'label']),
            'canUpload' => $this->canUpload($user, $target),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | CARGA, BAJA Y DATOS DE LA SECCIÓN
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array{photo_section_id: int, stage: string, client_key?: ?string, notes?: ?string, elevator_id?: mixed}  $data
     */
    public function upload(User $user, PhotoTarget $target, array $data, UploadedFile $file): VisitPhoto
    {
        abort_unless($this->canWorkOn($user, $target), 404);

        if (! Company::findOrFail($target->companyId)->photosEnabledFor($target->type)) {
            throw ValidationException::withMessages(['photo' => 'La carga de fotos está desactivada para este tipo de trabajo.']);
        }

        $section = PhotoSection::withoutGlobalScopes()
            ->where('company_id', $target->companyId)->active()
            ->findOrFail($data['photo_section_id']);

        $clientKey = filled($data['client_key'] ?? null) ? (string) $data['client_key'] : null;

        if ($clientKey && ($existing = $this->retried($user, $target, $clientKey))) {
            return $existing;
        }

        // Se procesa fuera del bloqueo (puede tardar); si después no entra, se borra.
        $stored = $this->processor->store($file, 'visits/'.$target->companyId, 'photo', 'Foto de visita inválida');
        $discard = fn () => Storage::disk('local')->delete(array_filter([$stored['path'], $stored['thumb_path'] ?? null]));

        try {
            return DB::transaction(function () use ($user, $target, $data, $section, $stored, $clientKey, $discard) {
                // Serializa las cargas de ESTA visita u orden (varios técnicos o
                // varias fotos a la vez): los límites siempre se cumplen.
                $this->lock($target);

                if ($clientKey && ($existing = $this->retried($user, $target, $clientKey))) {
                    $discard();

                    return $existing;
                }

                $photos = $target->photos();

                if ((clone $photos)->where('photo_section_id', $section->id)->where('stage', $data['stage'])->count() >= VisitPhoto::MAX_PER_GROUP) {
                    throw ValidationException::withMessages(['photo' => 'Podés cargar hasta '.VisitPhoto::MAX_PER_GROUP.' fotos de '.$section->name.' ('.mb_strtolower(VisitPhoto::STAGES[$data['stage']]).').']);
                }

                if ((clone $photos)->count() >= VisitPhoto::MAX_PER_VISIT) {
                    throw ValidationException::withMessages(['photo' => 'Podés cargar hasta '.VisitPhoto::MAX_PER_VISIT.' fotos por visita.']);
                }

                if ((int) (clone $photos)->sum('size') + $stored['size'] > VisitPhoto::MAX_TOTAL_BYTES) {
                    throw ValidationException::withMessages(['photo' => 'Las fotos de esta visita superan el tamaño máximo permitido.']);
                }

                $text = trim((string) ($data['notes'] ?? ''));

                $photo = new VisitPhoto;
                $photo->forceFill([
                    'company_id' => $target->companyId,
                    'building_id' => $target->buildingId,
                    'building_visit_id' => $target->visit?->id,
                    'work_order_id' => $target->workOrder?->id,
                    'delivery_note_id' => $target->deliveryNoteId(),
                    'photo_section_id' => $section->id,
                    'section_name' => $section->name,
                    'elevator_id' => $this->validElevator($target, $data['elevator_id'] ?? null),
                    'notes' => $text !== '' ? mb_substr($text, 0, 500) : null,
                    'assignment_type' => $target->type,
                    'stage' => $data['stage'],
                    'batch' => (string) Str::uuid(),
                    'client_key' => $clientKey,
                    'uploaded_by' => $user->id,
                    'attached_at' => now(),
                    ...$stored,
                ])->save();

                return $photo;
            });
        } catch (UniqueConstraintViolationException $e) {
            $discard();

            if ($clientKey && ($existing = $this->retried($user, $target, $clientKey))) {
                return $existing;
            }

            throw $e;
        } catch (\Throwable $e) {
            $discard();

            throw $e;
        }
    }

    /**
     * Quitar una foto: solo quien la subió (las fotos no forman parte del
     * remito firmado), mientras trabaje en esa visita u orden y las fotos de
     * ese tipo estén habilitadas (desactivarlas congela el historial). Bajo
     * bloqueo; borra también sus archivos.
     */
    public function remove(User $user, VisitPhoto $photo): void
    {
        DB::transaction(function () use ($user, $photo) {
            $fresh = VisitPhoto::withoutGlobalScopes()
                ->whereKey($photo->id)->where('company_id', $user->company_id)->where('uploaded_by', $user->id)
                ->lockForUpdate()->first();

            abort_unless($fresh, 404);

            $target = $fresh->target();

            if ($target && ! $this->canUpload($user, $target)) {
                abort_unless($this->canWorkOn($user, $target), 404);

                throw ValidationException::withMessages(['photo' => 'La carga de fotos está desactivada para este tipo de trabajo: las fotos ya cargadas se conservan.']);
            }

            $fresh->delete();
        });
    }

    /**
     * Equipo y observación de una sección (opcionales): se aplican a las
     * fotos de esa sección en esta visita u orden. El equipo solo si es de
     * ese edificio.
     */
    public function updateSection(User $user, PhotoTarget $target, int $sectionId, ?string $notes, mixed $elevatorId): int
    {
        abort_unless($this->canWorkOn($user, $target), 404);

        // Con las fotos desactivadas para el tipo, lo cargado queda como está.
        if (! $this->canUpload($user, $target)) {
            throw ValidationException::withMessages(['photo' => 'La carga de fotos está desactivada para este tipo de trabajo.']);
        }

        $text = trim((string) $notes);

        return (clone $target->photos())->where('photo_section_id', $sectionId)->update([
            'notes' => $text !== '' ? mb_substr($text, 0, 500) : null,
            'elevator_id' => $this->validElevator($target, $elevatorId),
            'updated_at' => now(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | AL FIRMAR EL REMITO
    |--------------------------------------------------------------------------
    */

    /** Las fotos de la visita (y las de su orden, cargadas antes) quedan del remito. */
    public function linkToDeliveryNote(BuildingVisit $visit, DeliveryNote $note, ?WorkOrder $workOrder = null): int
    {
        $linked = 0;

        if ($workOrder) {
            $linked += VisitPhoto::withoutGlobalScopes()
                ->where('company_id', $visit->company_id)->where('work_order_id', $workOrder->id)->whereNull('building_visit_id')
                ->update(['building_visit_id' => $visit->id, 'updated_at' => now()]);
        }

        VisitPhoto::withoutGlobalScopes()
            ->where('company_id', $visit->company_id)->where('building_visit_id', $visit->id)->whereNull('delivery_note_id')
            ->update(['delivery_note_id' => $note->id, 'updated_at' => now()]);

        return $linked;
    }

    /*
    |--------------------------------------------------------------------------
    | LIMPIEZA
    |--------------------------------------------------------------------------
    */

    /**
     * Borra SOLO las fotos que quedaron "en preparación" con el flujo anterior
     * (subidas desde el formulario de un remito que nunca se firmó): sin
     * visita y sin orden. Una foto con visita u orden nunca se toca. Cada una
     * se vuelve a verificar bajo bloqueo y un error en una no frena al resto.
     *
     * @return array{deleted: int, failed: int}
     */
    public function prune(int $hours = 24): array
    {
        $cutoff = now()->subHours($hours);
        $deleted = 0;
        $failed = 0;
        $orphan = fn ($q) => $q->whereNull('building_visit_id')->whereNull('work_order_id')->where('created_at', '<', $cutoff);

        $orphan(VisitPhoto::withoutGlobalScopes())
            ->select('id')
            ->chunkById(200, function ($photos) use ($orphan, &$deleted, &$failed) {
                foreach ($photos as $candidate) {
                    try {
                        $deleted += (int) DB::transaction(function () use ($candidate, $orphan) {
                            $photo = $orphan(VisitPhoto::withoutGlobalScopes()->whereKey($candidate->id))->lockForUpdate()->first();

                            return $photo ? (bool) $photo->delete() : false;
                        });
                    } catch (\Throwable $e) {
                        $failed++;
                        logger()->warning('No se pudo borrar una foto de visita sin vincular', ['visit_photo_id' => $candidate->id, 'message' => $e->getMessage()]);
                    }
                }
            });

        return ['deleted' => $deleted, 'failed' => $failed];
    }

    /*
    |--------------------------------------------------------------------------
    | AUXILIARES
    |--------------------------------------------------------------------------
    */

    /**
     * Bloquea la visita u orden. Si la visita se descartó mientras la foto
     * subía (o la orden se borró), no se guarda nada: sin esto la foto
     * quedaba apuntando a una visita inexistente (error de base o archivo
     * huérfano).
     */
    private function lock(PhotoTarget $target): void
    {
        $gone = ($target->visit && ! BuildingVisit::withoutGlobalScopes()->whereKey($target->visit->id)->lockForUpdate()->exists())
            || ($target->workOrder && ! WorkOrder::withoutGlobalScopes()->whereKey($target->workOrder->id)->whereNull('deleted_at')->lockForUpdate()->exists());

        if ($gone) {
            throw ValidationException::withMessages(['photo' => 'Esta visita u orden ya no existe (se descartó o se borró). Recargá la página.']);
        }
    }

    /** La foto que ya se guardó con esa identidad en este destino. */
    private function retried(User $user, PhotoTarget $target, string $clientKey): ?VisitPhoto
    {
        $photo = (clone $target->photos())->where('client_key', $clientKey)->first();

        if ($photo && (int) $photo->uploaded_by !== (int) $user->id) {
            throw ValidationException::withMessages(['photo' => 'No se pudo subir la foto. Recargá la página.']);
        }

        return $photo;
    }

    private function validElevator(PhotoTarget $target, mixed $elevatorId): ?int
    {
        $id = (int) $elevatorId;

        return $id > 0 && Elevator::withoutGlobalScopes()
            ->whereKey($id)->where('company_id', $target->companyId)->where('building_id', $target->buildingId)->exists()
            ? $id : null;
    }
}

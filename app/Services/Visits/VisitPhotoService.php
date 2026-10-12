<?php

namespace App\Services\Visits;

use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\DeliveryNote;
use App\Models\Elevator;
use App\Models\PhotoSection;
use App\Models\User;
use App\Models\VisitPhoto;
use App\Services\Media\PhotoProcessor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Fotos de antes y después de una visita. Se suben de a una mientras el
 * técnico completa el remito (si una falla, se reintenta esa sola y el resto
 * del remito no se pierde) y quedan "en preparación" dentro del lote del
 * formulario. Al firmar el remito, el lote se vincula a la visita y al
 * remito. Lo que nunca se vincula se borra con visit-photos:prune.
 *
 * Límites (también con subidas simultáneas): 3 por sección y estado, 30 por
 * visita y 60 MB guardados por visita.
 */
class VisitPhotoService
{
    /**
     * Fotos en preparación (sin remito firmado) que puede tener un técnico
     * entre todos sus formularios abiertos: tres visitas completas. Evita
     * llenar el disco con formularios que nunca se firman.
     */
    public const MAX_STAGED_PER_USER = 3 * VisitPhoto::MAX_PER_VISIT;

    public function __construct(private PhotoProcessor $processor) {}

    /**
     * Si llega de nuevo una foto con la misma identidad (client_key) del
     * mismo lote —la subida anterior se guardó pero la respuesta no llegó—,
     * devuelve la que ya estaba: no se duplica ni cuenta dos veces.
     *
     * @param  array{batch: string, assignment_type: string, photo_section_id: int, stage: string, client_key?: ?string}  $data
     */
    public function stage(User $user, Building $building, array $data, UploadedFile $file): VisitPhoto
    {
        $section = PhotoSection::withoutGlobalScopes()
            ->where('company_id', $building->company_id)->active()
            ->findOrFail($data['photo_section_id']);

        $clientKey = filled($data['client_key'] ?? null) ? (string) $data['client_key'] : null;

        if ($clientKey && ($existing = $this->retried($user, $building, $data, $clientKey))) {
            return $existing;
        }

        // Se procesa fuera del bloqueo (puede tardar); si después no entra, se borra.
        $stored = $this->processor->store($file, 'visits/'.$building->company_id, 'photo', 'Foto de visita inválida');
        $discard = fn () => Storage::disk('local')->delete(array_filter([$stored['path'], $stored['thumb_path'] ?? null]));

        try {
            return DB::transaction(function () use ($user, $building, $data, $section, $stored, $clientKey, $discard) {
                // Serializa las subidas del mismo técnico: los límites valen
                // aunque el celular mande varias fotos a la vez.
                User::whereKey($user->id)->lockForUpdate()->first();

                // El mismo reintento llegó dos veces en paralelo.
                if ($clientKey && ($existing = $this->retried($user, $building, $data, $clientKey))) {
                    $discard();

                    return $existing;
                }

                $batch = VisitPhoto::withoutGlobalScopes()->where('batch', $data['batch'])->where('uploaded_by', $user->id);

                if (VisitPhoto::withoutGlobalScopes()->where('uploaded_by', $user->id)->whereNull('building_visit_id')->count() >= self::MAX_STAGED_PER_USER) {
                    throw ValidationException::withMessages(['photo' => 'Tenés muchas fotos subidas en remitos sin firmar. Firmá o cerrá esos remitos y probá de nuevo.']);
                }

                if ((clone $batch)->where(fn ($q) => $q->whereNotNull('building_visit_id')
                    ->orWhere('building_id', '!=', $building->id)
                    ->orWhere('assignment_type', '!=', $data['assignment_type']))->exists()) {
                    throw ValidationException::withMessages(['photo' => 'Este formulario ya no admite fotos. Recargá la página.']);
                }

                if ((clone $batch)->where('photo_section_id', $section->id)->where('stage', $data['stage'])->count() >= VisitPhoto::MAX_PER_GROUP) {
                    throw ValidationException::withMessages(['photo' => 'Podés cargar hasta '.VisitPhoto::MAX_PER_GROUP.' fotos de '.$section->name.' ('.mb_strtolower(VisitPhoto::STAGES[$data['stage']]).').']);
                }

                if ((clone $batch)->count() >= VisitPhoto::MAX_PER_VISIT) {
                    throw ValidationException::withMessages(['photo' => 'Podés cargar hasta '.VisitPhoto::MAX_PER_VISIT.' fotos por visita.']);
                }

                if ((int) (clone $batch)->sum('size') + $stored['size'] > VisitPhoto::MAX_TOTAL_BYTES) {
                    throw ValidationException::withMessages(['photo' => 'Las fotos de esta visita superan el tamaño máximo permitido.']);
                }

                $photo = new VisitPhoto;
                $photo->forceFill([
                    'company_id' => $building->company_id,
                    'building_id' => $building->id,
                    'photo_section_id' => $section->id,
                    'section_name' => $section->name,
                    'assignment_type' => $data['assignment_type'],
                    'stage' => $data['stage'],
                    'batch' => $data['batch'],
                    'client_key' => $clientKey,
                    'uploaded_by' => $user->id,
                    ...$stored,
                ])->save();

                return $photo;
            });
        } catch (UniqueConstraintViolationException $e) {
            $discard();

            if ($clientKey && ($existing = $this->retried($user, $building, $data, $clientKey))) {
                return $existing;
            }

            throw $e;
        } catch (\Throwable $e) {
            $discard();

            throw $e;
        }
    }

    /** La foto que ya se guardó con esa identidad en ese lote (de este técnico, edificio y tipo). */
    private function retried(User $user, Building $building, array $data, string $clientKey): ?VisitPhoto
    {
        $photo = VisitPhoto::withoutGlobalScopes()
            ->where('batch', $data['batch'])->where('client_key', $clientKey)
            ->first();

        if (! $photo) {
            return null;
        }

        if ((int) $photo->uploaded_by !== (int) $user->id || (int) $photo->building_id !== (int) $building->id
            || $photo->assignment_type !== $data['assignment_type']) {
            throw ValidationException::withMessages(['photo' => 'Este formulario ya no admite fotos. Recargá la página.']);
        }

        return $photo;
    }

    /**
     * Quita una foto en preparación de quien la subió. Bajo bloqueo: si el
     * remito se firmó en el mismo instante, la foto ya es historial y no se
     * borra (devuelve false).
     */
    public function discardStaged(VisitPhoto $photo, User $user): bool
    {
        return DB::transaction(function () use ($photo, $user) {
            User::whereKey($user->id)->lockForUpdate()->first();

            $fresh = VisitPhoto::withoutGlobalScopes()
                ->whereKey($photo->id)->where('uploaded_by', $user->id)->whereNull('building_visit_id')
                ->lockForUpdate()->first();

            return $fresh ? (bool) $fresh->delete() : false;
        });
    }

    /**
     * Vincula el lote del formulario a la visita y su remito (dentro de la
     * transacción del remito). Solo fotos de ese técnico, ese edificio y ese
     * tipo de visita, todavía sin vincular, y dentro de los límites. La
     * observación y el ascensor (opcionales) se eligen por sección en el
     * formulario; el ascensor solo si es de ese edificio.
     *
     * @param  array<int|string, mixed>  $notes  sección => observación
     * @param  array<int|string, mixed>  $elevators  sección => id de ascensor
     */
    public function attach(?string $batch, BuildingVisit $visit, DeliveryNote $note, User $user, array $notes = [], array $elevators = []): int
    {
        if (! $batch) {
            return 0;
        }

        // Mismo bloqueo que las subidas y las bajas de este técnico: una foto
        // que termina de subirse mientras se firma entra en el remito o queda
        // rechazada ("ya no admite fotos"), nunca en un estado intermedio.
        User::whereKey($user->id)->lockForUpdate()->first();

        $photos = VisitPhoto::withoutGlobalScopes()
            ->where('batch', $batch)
            ->where('uploaded_by', $user->id)
            ->where('company_id', $visit->company_id)
            ->where('building_id', $visit->building_id)
            ->where('assignment_type', $visit->assignment_type)
            ->whereNull('building_visit_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $perGroup = [];
        $total = 0;
        $bytes = 0;
        $ids = [];

        foreach ($photos as $photo) {
            $group = $photo->photo_section_id.'|'.$photo->stage;
            $perGroup[$group] = ($perGroup[$group] ?? 0) + 1;

            if ($perGroup[$group] > VisitPhoto::MAX_PER_GROUP || $total >= VisitPhoto::MAX_PER_VISIT || $bytes + $photo->size > VisitPhoto::MAX_TOTAL_BYTES) {
                continue; // queda en preparación y se borra sola
            }

            $total++;
            $bytes += $photo->size;
            $ids[] = $photo->id;
        }

        if ($ids === []) {
            return 0;
        }

        $validElevators = Elevator::withoutGlobalScopes()
            ->where('company_id', $visit->company_id)->where('building_id', $visit->building_id)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        foreach ($photos->whereIn('id', $ids) as $photo) {
            $text = trim((string) ($notes[$photo->photo_section_id] ?? ''));
            $elevator = (int) ($elevators[$photo->photo_section_id] ?? 0);

            $photo->forceFill([
                'building_visit_id' => $visit->id,
                'delivery_note_id' => $note->id,
                'attached_at' => now(),
                'notes' => $text !== '' ? mb_substr($text, 0, 500) : null,
                'elevator_id' => in_array($elevator, $validElevators, true) ? $elevator : null,
            ])->saveQuietly();
        }

        return count($ids);
    }

    /**
     * Borra fotos en preparación que nunca se vincularon (con sus archivos).
     * Cada una se vuelve a verificar bajo bloqueo (un remito firmado en ese
     * instante la vincula y ya no se toca) y un error en una no frena al
     * resto: lo que no se pudo borrar queda para la próxima corrida.
     *
     * @return array{deleted: int, failed: int}
     */
    public function prune(int $hours = 24): array
    {
        $cutoff = now()->subHours($hours);
        $deleted = 0;
        $failed = 0;

        VisitPhoto::withoutGlobalScopes()
            ->whereNull('building_visit_id')
            ->where('created_at', '<', $cutoff)
            ->select('id')
            ->chunkById(200, function ($photos) use ($cutoff, &$deleted, &$failed) {
                foreach ($photos as $candidate) {
                    try {
                        $deleted += (int) DB::transaction(function () use ($candidate, $cutoff) {
                            $photo = VisitPhoto::withoutGlobalScopes()
                                ->whereKey($candidate->id)->whereNull('building_visit_id')->where('created_at', '<', $cutoff)
                                ->lockForUpdate()->first();

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
}

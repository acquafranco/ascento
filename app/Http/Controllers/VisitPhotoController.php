<?php

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\Company;
use App\Models\VisitPhoto;
use App\Services\Visits\VisitPhotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fotos de antes y después de mantenimientos e inspecciones.
 *
 * - store / destroy: el técnico las sube (o quita) de a una mientras
 *   completa el remito de un edificio que tiene asignado para ese trabajo.
 * - show: el archivo privado, solo con sesión y permisos (VisitPhoto::
 *   canBeViewedBy). Otra empresa, otro técnico o un id cambiado → 404.
 */
class VisitPhotoController extends Controller
{
    public function store(Request $request, Company $company, VisitPhotoService $photos): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'batch' => ['required', 'uuid'],
            'building_id' => ['required', 'integer'],
            'assignment_type' => ['required', Rule::in(['maintenance', 'inspection'])],
            'photo_section_id' => ['required', 'integer'],
            'stage' => ['required', Rule::in(array_keys(VisitPhoto::STAGES))],
            // Identidad de la foto que genera el celular (reintentos sin duplicar).
            'client_key' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
            'photo' => ['required', 'file', 'max:'.VisitPhoto::MAX_KB, 'mimes:jpg,jpeg,png,webp,heic,heif'],
        ], [
            'photo.required' => 'No se recibió la foto. Probá de nuevo.',
            'photo.file' => 'No se pudo recibir la foto. Probá de nuevo.',
            'photo.uploaded' => 'No se pudo recibir la foto (¿muy pesada?). Probá de nuevo.',
            'photo.max' => 'Cada foto puede pesar hasta 10 MB.',
            'photo.mimes' => 'Formato de imagen no permitido. Usá JPG, PNG, WEBP o HEIC.',
        ]);

        // Edificio de SU empresa y asignado a él para ese trabajo (los admins, cualquiera de la empresa).
        $building = Building::where('company_id', $user->company_id)->findOrFail($data['building_id']);
        abort_unless((int) $building->company_id === (int) $company->id, 404);

        if (! $user->isAdmin()) {
            abort_unless($building->users()->where('users.id', $user->id)->wherePivot('type', $data['assignment_type'])->exists(), 403, 'Este edificio no está asignado a vos para este trabajo.');
        }

        $photo = $photos->stage($user, $building, $data, $request->file('photo'));

        return response()->json([
            'id' => $photo->id,
            'thumb_url' => $photo->thumbUrl(),
            'delete_url' => route('visit-photos.destroy', ['company' => $company->slug, 'visitPhoto' => $photo->id]),
        ], $photo->wasRecentlyCreated ? 201 : 200);
    }

    /** Quitar una foto mientras se completa el remito (después ya es parte del historial). */
    public function destroy(Request $request, Company $company, VisitPhoto $visitPhoto, VisitPhotoService $photos): JsonResponse
    {
        abort_unless((int) $visitPhoto->uploaded_by === (int) $request->user()->id && (int) $visitPhoto->company_id === (int) $company->id, 404);

        // Se vuelve a verificar bajo bloqueo: si el remito se firmó en el
        // medio, la foto ya es historial y no se borra.
        abort_unless($photos->discardStaged($visitPhoto, $request->user()), 404);

        return response()->json(['deleted' => true]);
    }

    public function show(Request $request, VisitPhoto $visitPhoto): StreamedResponse
    {
        abort_unless($visitPhoto->canBeViewedBy($request->user()), 404);

        $path = $request->boolean('thumb') && $visitPhoto->thumb_path && $visitPhoto->hasSafePath($visitPhoto->thumb_path) && Storage::disk('local')->exists($visitPhoto->thumb_path)
            ? $visitPhoto->thumb_path
            : $visitPhoto->path;

        abort_unless($visitPhoto->hasSafePath($path) && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }
}

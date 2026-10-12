<?php

namespace App\Http\Controllers;

use App\Models\BuildingVisit;
use App\Models\Company;
use App\Models\VisitPhoto;
use App\Models\WorkOrder;
use App\Services\Visits\PhotoTarget;
use App\Services\Visits\VisitPhotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fotos de antes y después de visitas y órdenes de trabajo.
 *
 * - store / destroy / section: el técnico (o el admin) las carga, quita o
 *   completa desde la pantalla de la visita o de la orden. Todo se verifica
 *   en el servidor (empresa, asignación, tipo habilitado, límites).
 * - show: el archivo privado, solo con sesión y permisos (VisitPhoto::
 *   canBeViewedBy). Otra empresa, otro técnico o un id cambiado → 404.
 *   ?thumb=1 sirve la miniatura; ?download=1 la descarga.
 */
class VisitPhotoController extends Controller
{
    public function store(Request $request, Company $company, VisitPhotoService $photos): JsonResponse
    {
        $data = $request->validate([
            'visit_id' => ['required_without:work_order_id', 'nullable', 'integer'],
            'work_order_id' => ['required_without:visit_id', 'nullable', 'integer'],
            'photo_section_id' => ['required', 'integer'],
            'stage' => ['required', Rule::in(array_keys(VisitPhoto::STAGES))],
            'notes' => ['nullable', 'string', 'max:500'],
            'elevator_id' => ['nullable', 'integer'],
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

        $photo = $photos->upload($request->user(), $this->target($company, $data), $data, $request->file('photo'));

        return response()->json([
            'id' => $photo->id,
            'thumb_url' => $photo->thumbUrl(),
            'delete_url' => route('visit-photos.destroy', ['company' => $company->slug, 'visitPhoto' => $photo->id]),
        ], $photo->wasRecentlyCreated ? 201 : 200);
    }

    /** Quitar una foto (solo quien la subió). */
    public function destroy(Request $request, Company $company, VisitPhoto $visitPhoto, VisitPhotoService $photos): JsonResponse
    {
        abort_unless((int) $visitPhoto->company_id === (int) $company->id, 404);

        $photos->remove($request->user(), $visitPhoto);

        return response()->json(['deleted' => true]);
    }

    /** Equipo y observación de una sección. */
    public function section(Request $request, Company $company, VisitPhotoService $photos): JsonResponse
    {
        $data = $request->validate([
            'visit_id' => ['required_without:work_order_id', 'nullable', 'integer'],
            'work_order_id' => ['required_without:visit_id', 'nullable', 'integer'],
            'photo_section_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:500'],
            'elevator_id' => ['nullable', 'integer'],
        ]);

        $updated = $photos->updateSection($request->user(), $this->target($company, $data), (int) $data['photo_section_id'], $data['notes'] ?? null, $data['elevator_id'] ?? null);

        return response()->json(['updated' => $updated]);
    }

    public function show(Request $request, VisitPhoto $visitPhoto): StreamedResponse
    {
        abort_unless($visitPhoto->canBeViewedBy($request->user()), 404);

        $path = $request->boolean('thumb') && $visitPhoto->thumb_path && $visitPhoto->hasSafePath($visitPhoto->thumb_path) && Storage::disk('local')->exists($visitPhoto->thumb_path)
            ? $visitPhoto->thumb_path
            : $visitPhoto->path;

        abort_unless($visitPhoto->hasSafePath($path) && Storage::disk('local')->exists($path), 404);

        $headers = [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ];

        if ($request->boolean('download')) {
            // Nombre descriptivo sin datos personales: sección, estado e id.
            $name = 'foto-'.$visitPhoto->id.'-'.Str::slug($visitPhoto->section_name).'-'.($visitPhoto->stage === 'after' ? 'despues' : 'antes').'.jpg';

            return Storage::disk('local')->download($visitPhoto->path, $name, $headers);
        }

        return Storage::disk('local')->response($path, null, $headers);
    }

    /** Visita u orden de ESTA empresa (un id ajeno o cambiado → 404). */
    private function target(Company $company, array $data): PhotoTarget
    {
        if (filled($data['visit_id'] ?? null)) {
            return PhotoTarget::forVisit(BuildingVisit::withoutGlobalScopes()
                ->where('company_id', $company->id)->findOrFail($data['visit_id']));
        }

        return PhotoTarget::forWorkOrder(WorkOrder::withoutGlobalScopes()
            ->where('company_id', $company->id)->whereNull('deleted_at')->findOrFail($data['work_order_id']));
    }
}

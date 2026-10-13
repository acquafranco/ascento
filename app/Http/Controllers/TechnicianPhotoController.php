<?php

namespace App\Http\Controllers;

use App\Enums\PermissionArea;
use App\Models\User;
use App\Services\Technicians\TechnicianPhotoService;
use App\Support\Access\Access;
use App\Support\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Foto de perfil de un técnico (Etapa 8.2). La ve el propio técnico, el
 * dueño, el SuperAdmin dentro de esa empresa y un administrativo vigente con
 * «Técnicos». Cualquier otro caso responde 404, sin pistas.
 *
 * La URL lleva la versión de la foto actual: la de una foto reemplazada o
 * quitada responde 404. Se sirve con «no-cache» y ETag, así el navegador
 * vuelve a preguntar cada vez y nunca muestra una foto que ya no está.
 */
class TechnicianPhotoController extends Controller
{
    public static function canView(?User $viewer, User $technician): bool
    {
        if ($viewer === null || $technician->role !== 'technician' || ! $technician->avatar) {
            return false;
        }

        if ($viewer->is($technician)) {
            return true;
        }

        return Access::panelUser($viewer)
            && CompanyContext::currentId() === (int) $technician->company_id
            && Access::can($viewer, PermissionArea::Technicians);
    }

    /** Solo la carpeta de fotos de SU empresa. */
    private static function servable(User $technician): bool
    {
        return TechnicianPhotoService::hasSafePath($technician->avatar)
            && str_starts_with($technician->avatar, TechnicianPhotoService::DIRECTORY.'/'.(int) $technician->company_id.'/')
            && Storage::disk('local')->exists($technician->avatar);
    }

    public function __invoke(Request $request, User $user): Response|StreamedResponse
    {
        abort_unless(static::canView($request->user(), $user), 404);

        return static::serve($request, $user);
    }

    /** Sirve la foto vigente (también lo usa el portal, con su propio permiso). */
    public static function serve(Request $request, User $user): Response|StreamedResponse
    {
        abort_unless($user->role === 'technician' && $user->avatar, 404);
        $version = $request->query('v');
        abort_unless(is_string($version) && $user->photoVersion() !== null && hash_equals($user->photoVersion(), $version), 404);
        abort_unless(static::servable($user), 404);

        $headers = [
            'Cache-Control' => 'private, no-cache',
            'ETag' => '"'.$user->photoVersion().'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ];

        if (in_array('"'.$user->photoVersion().'"', $request->getETags(), true)) {
            return response('', 304, $headers);
        }

        return Storage::disk('local')->response($user->avatar, 'foto.jpg', ['Content-Type' => 'image/jpeg'] + $headers);
    }
}

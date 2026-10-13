<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Technicians\TechnicianPhotoService;
use App\Support\Access\Access;
use App\Support\CompanyContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Foto de perfil de un técnico (Etapa 8.2). La ve el propio técnico y quien
 * opera el panel de su empresa (dueño, administrativo vigente o SuperAdmin
 * dentro de esa empresa). Cualquier otro caso responde 404, sin pistas.
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

        return Access::panelUser($viewer) && CompanyContext::currentId() === (int) $technician->company_id;
    }

    public function __invoke(Request $request, User $user): StreamedResponse
    {
        abort_unless(static::canView($request->user(), $user), 404);
        abort_unless(TechnicianPhotoService::hasSafePath($user->avatar) && Storage::disk('local')->exists($user->avatar), 404);

        return Storage::disk('local')->response($user->avatar, 'foto.jpg', [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}

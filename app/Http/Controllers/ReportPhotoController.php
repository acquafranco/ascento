<?php

namespace App\Http\Controllers;

use App\Models\Report;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportPhotoController extends Controller
{
    /**
     * Sirve la foto de un reporte solo a quien puede ver el reporte:
     * - el binding usa el scope de empresa: un reporte de otra empresa es 404;
     * - admins (y el SuperAdmin dentro de la empresa elegida) ven todas;
     * - un técnico solo las de sus propios reportes.
     */
    public function __invoke(Request $request, Report $report): StreamedResponse
    {
        $user = $request->user();

        abort_unless(
            $user->isAdmin() || $user->isSuperAdmin() || $report->user_id === $user->id,
            404
        );

        $path = (string) $report->photo;

        // El path lo genera el servidor, pero igual se valida: dentro de la
        // carpeta de la empresa del reporte y sin saltos de directorio.
        abort_unless(
            $path !== ''
                && str_starts_with($path, 'reports/'.$report->company_id.'/')
                && ! str_contains($path, '..'),
            404
        );

        // Fotos nuevas: disco privado. Fotos anteriores: disco público,
        // hasta que se migren con `php artisan reports:move-photos-private`.
        foreach (['local', 'public'] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return Storage::disk($disk)->response($path, null, [
                    'Cache-Control' => 'private, max-age=3600',
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            }
        }

        abort(404);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\ReportPhoto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Las fotos de reportes viven en el disco privado: solo se descargan por
 * acá, con sesión y permisos. Nunca hay una URL pública al archivo.
 *
 * - El binding de {report} usa el scope de empresa: otra empresa → 404.
 * - Admins de la empresa (y el SuperAdmin en la empresa elegida) ven todas;
 *   un técnico, solo las de sus propios reportes. Si no → 404.
 */
class ReportPhotoController extends Controller
{
    public function show(Request $request, Report $report, ReportPhoto $photo): StreamedResponse
    {
        abort_unless($report->canBeViewedBy($request->user()), 404);
        abort_unless((int) $photo->report_id === (int) $report->id, 404);

        return $this->stream($photo);
    }

    /** Link viejo (una foto por reporte): sirve la primera foto. */
    public function first(Request $request, Report $report): StreamedResponse
    {
        abort_unless($report->canBeViewedBy($request->user()), 404);

        $photo = $report->photos()->first();

        abort_unless($photo, 404);

        return $this->stream($photo);
    }

    private function stream(ReportPhoto $photo): StreamedResponse
    {
        $disk = $photo->disk();

        abort_unless($disk, 404);

        return Storage::disk($disk)->response($photo->path, null, [
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }
}

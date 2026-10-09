<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\ReportVideo;
use App\Services\Reports\ReportVideoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Video de un reporte: disco privado, nunca una URL pública.
 *
 * - Ver: los mismos permisos que las fotos (admins de la empresa; el técnico,
 *   sus propios reportes). Otra empresa → 404 (scope de empresa).
 * - Subir / borrar: quien puede ver el reporte, si el plan incluye videos
 *   (validado en el servidor por ReportVideoService).
 * - Respuesta con rangos (BinaryFileResponse): Safari y el iPhone no
 *   reproducen un video sin "Range".
 */
class ReportVideoController extends Controller
{
    public function show(Request $request, Report $report): BinaryFileResponse
    {
        abort_unless($report->canBeViewedBy($request->user()), 404);

        return static::serve($report);
    }

    public function store(Request $request, Report $report): RedirectResponse
    {
        abort_unless($report->canBeViewedBy($request->user()), 404);

        $request->validate(ReportVideoService::rules(), ReportVideoService::messages());
        abort_unless($request->hasFile('video'), 422);

        app(ReportVideoService::class)->store($report, $request->file('video'), $request->user());

        return back()->with('status', 'Video agregado al reporte.');
    }

    public function destroy(Request $request, Report $report): RedirectResponse
    {
        abort_unless($report->canBeViewedBy($request->user()), 404);

        if ($video = $report->video) {
            app(ReportVideoService::class)->delete($video);
        }

        return back()->with('status', 'Video eliminado.');
    }

    public static function serve(Report $report): BinaryFileResponse
    {
        /** @var ReportVideo|null $video */
        $video = ReportVideo::withoutGlobalScopes()->where('report_id', $report->id)->where('company_id', $report->company_id)->first();
        $path = $video?->fullPath();
        abort_unless($path, 404);

        $download = request()->boolean('download');
        $name = 'video-reporte-'.$report->id.'.'.(ReportVideo::MIMES[$video->mime] ?? 'mp4');

        return response()->file($path, [
            'Content-Type' => $video->mime,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => ($download ? 'attachment' : 'inline').'; filename="'.$name.'"',
        ]);
    }
}

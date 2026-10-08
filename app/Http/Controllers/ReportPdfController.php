<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\ReportPhoto;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * PDF del reporte, generado en el servidor. Mismos permisos que ver el
 * reporte (admins de la empresa y el técnico que lo hizo). Las fotos se
 * incrustan leídas del disco privado: el PDF no contiene URLs a archivos.
 */
class ReportPdfController extends Controller
{
    /** Caja máxima de cada foto en el PDF (mm): dos por fila. */
    private const BOX_WIDTH = 84;

    private const BOX_HEIGHT = 105;

    public function __invoke(Request $request, Report $report): Response
    {
        abort_unless($report->canBeViewedBy($request->user()), 404);

        $report->load(['company', 'building.client', 'user', 'photos']);

        $photos = $report->photos
            ->map(fn (ReportPhoto $photo) => $this->embed($photo))
            ->filter()
            ->values();

        $pdf = Pdf::loadView('reports.pdf', [
            'report' => $report,
            'photos' => $photos,
            'logo' => $this->logo($report),
        ])->setPaper('a4');

        $name = 'reporte-'.$report->id.'-'.Str::slug($report->building?->name ?? 'edificio').'.pdf';

        return $pdf->stream($name)->withHeaders([
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array{src: string, width: float, height: float}|null */
    private function embed(ReportPhoto $photo): ?array
    {
        $contents = $photo->contents();

        if ($contents === null) {
            return null;
        }

        $size = @getimagesizefromstring($contents);

        if (! $size || ! in_array($size['mime'], ['image/jpeg', 'image/png'], true)) {
            return null;
        }

        [$width, $height] = [$size[0], $size[1]];
        $scale = min(self::BOX_WIDTH / $width, self::BOX_HEIGHT / $height);

        return [
            'src' => 'data:'.$size['mime'].';base64,'.base64_encode($contents),
            'width' => round($width * $scale, 1),
            'height' => round($height * $scale, 1),
        ];
    }

    /** Logo de la empresa (disco público), solo si es PNG/JPEG. */
    private function logo(Report $report): ?string
    {
        $path = (string) $report->company?->logo;

        if ($path === '' || str_contains($path, '..') || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $contents = Storage::disk('public')->get($path);
        $size = @getimagesizefromstring($contents);

        return $size && in_array($size['mime'], ['image/jpeg', 'image/png'], true)
            ? 'data:'.$size['mime'].';base64,'.base64_encode($contents)
            : null;
    }
}

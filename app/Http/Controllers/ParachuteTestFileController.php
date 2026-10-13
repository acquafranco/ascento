<?php

namespace App\Http\Controllers;

use App\Enums\PermissionArea;
use App\Enums\PlanFeature;
use App\Models\ParachuteTestFile;
use App\Support\Access\Access;
use App\Support\Plans\PlanGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documentos y fotos de las pruebas de paracaídas: dueño, SuperAdmin dentro
 * de la empresa elegida, o administrativo con «Inspecciones y reportes». El
 * binding usa el scope de empresa: otra empresa → 404. Nunca en el QR público.
 */
class ParachuteTestFileController extends Controller
{
    public function __invoke(Request $request, ParachuteTestFile $parachuteTestFile): StreamedResponse
    {
        abort_unless(Access::can($request->user(), PermissionArea::Inspections) && PlanGuard::currentAllows(PlanFeature::ParachuteTests), 404);
        abort_unless($parachuteTestFile->hasSafePath() && Storage::disk('local')->exists($parachuteTestFile->path), 404);

        return Storage::disk('local')->response($parachuteTestFile->path, $parachuteTestFile->original_name ?: basename($parachuteTestFile->path), [
            'Content-Type' => $parachuteTestFile->mime,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ]);
    }
}

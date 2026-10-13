<?php

namespace App\Http\Controllers;

use App\Enums\PermissionArea;
use App\Models\RepairComponentFile;
use App\Support\Access\Access;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documentos y fotos de componentes en reparación (Etapa 8.4): dueño,
 * SuperAdmin dentro de la empresa elegida o administrativo con
 * «Reparaciones». El binding usa el scope de empresa: otra empresa → 404.
 * Nunca en el QR ni en el portal.
 */
class RepairComponentFileController extends Controller
{
    public function __invoke(Request $request, RepairComponentFile $repairComponentFile): StreamedResponse
    {
        abort_unless(Access::can($request->user(), PermissionArea::Repairs), 404);
        abort_unless($repairComponentFile->hasSafePath() && Storage::disk('local')->exists($repairComponentFile->path), 404);

        return Storage::disk('local')->response($repairComponentFile->path, $repairComponentFile->original_name ?: basename($repairComponentFile->path), [
            'Content-Type' => $repairComponentFile->mime,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ]);
    }
}

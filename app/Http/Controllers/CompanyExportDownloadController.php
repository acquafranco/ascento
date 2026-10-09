<?php

namespace App\Http\Controllers;

use App\Models\CompanyExport;
use App\Models\CompanyExportDownload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Descarga de una exportación: solo admins de la empresa dueña (el binding
 * usa el scope de empresa: otra empresa → 404), solo mientras no venza.
 * Cada descarga queda registrada.
 */
class CompanyExportDownloadController extends Controller
{
    public function __invoke(Request $request, CompanyExport $companyExport): StreamedResponse
    {
        $user = $request->user();

        abort_unless($user->isAdmin() && (int) $user->company_id === (int) $companyExport->company_id, 404);
        abort_unless($companyExport->isDownloadable(), 404);

        $download = new CompanyExportDownload;
        $download->forceFill([
            'company_export_id' => $companyExport->id,
            'company_id' => $companyExport->company_id,
            'user_id' => $user->id,
            'downloaded_at' => now(),
        ])->save();

        return Storage::disk('local')->download($companyExport->path, $companyExport->file_name, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Backup;
use App\Models\BackupDownload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Descarga de un backup global: solo SuperAdmin; queda registrado quién y cuándo. */
class BackupDownloadController extends Controller
{
    public function __invoke(Request $request, Backup $backup): StreamedResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 404);
        abort_unless($backup->isDownloadable(), 404);

        $download = new BackupDownload;
        $download->forceFill(['backup_id' => $backup->id, 'user_id' => $request->user()->id, 'ip' => $request->ip(), 'downloaded_at' => now()])->save();

        return Storage::disk('local')->download($backup->path, basename($backup->path), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

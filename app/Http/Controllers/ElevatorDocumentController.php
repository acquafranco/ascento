<?php

namespace App\Http\Controllers;

use App\Models\ElevatorDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documentos del legajo: solo admins de la empresa (y el SuperAdmin dentro de
 * la empresa elegida). El binding usa el scope de empresa: otra empresa → 404.
 */
class ElevatorDocumentController extends Controller
{
    public function __invoke(Request $request, ElevatorDocument $elevatorDocument): StreamedResponse
    {
        $user = $request->user();

        abort_unless($user->isAdmin() || $user->isSuperAdmin(), 404);
        abort_unless($elevatorDocument->hasSafePath() && Storage::disk('local')->exists($elevatorDocument->path), 404);

        return Storage::disk('local')->response($elevatorDocument->path, $elevatorDocument->original_name ?: basename($elevatorDocument->path), [
            'Content-Type' => $elevatorDocument->mime,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ]);
    }
}

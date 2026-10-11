<?php

namespace App\Http\Controllers;

use App\Services\Technicians\TechnicianWorkload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Contadores del propio técnico (solo números; nada de otras personas). */
class TechnicianCountersController extends Controller
{
    public function __invoke(Request $request, TechnicianWorkload $workload): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->role === 'technician', 404);

        return response()->json($workload->counts($user))->header('Cache-Control', 'no-store');
    }
}
